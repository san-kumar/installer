<?php
/**
 * Created by PhpStorm.
 * User: san
 * Date: 24/10/17
 * Time: 5:42 PM
 */

namespace Console\Command {

    use Aws\ApiGateway\ApiGatewayClient;
    use Aws\CloudWatchEvents\CloudWatchEventsClient;
    use Aws\DynamoDb\DynamoDbClient;
    use Aws\Iam\IamClient;
    use Aws\Lambda\LambdaClient;
    use Console\App\Config;
    use Console\Utils\ZipMaker;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Input\InputOption;
    use Symfony\Component\Console\Output\OutputInterface;
    use function file_get_contents;
    use function filesize;

    class Deploy extends Command {
        /**
         * @var Config
         */
        private $config;
        /**
         * @var ZipMaker
         */
        private $zipMaker;

        /**
         * Deploy constructor.
         *
         * @param Config   $config
         * @param ZipMaker $zipMaker
         *
         * @internal param Finder $finder
         */
        public function __construct(Config $config, ZipMaker $zipMaker) {
            parent::__construct();

            $this->config = $config;
            $this->zipMaker = $zipMaker;
        }

        protected function configure() {
            $this
                ->setName('deploy')
                ->setDescription('Deploy all php files to AWS lambda and generate links')
                ->setHelp('This command will deploy all your PHP files to AWS lambda and create web accessible links for them')
                ->addOption('rebuild', 'r', InputOption::VALUE_OPTIONAL, 'Force rebuild?');
        }

        protected function execute(InputInterface $input, OutputInterface $output) {
            if ($dir = $this->config->getBaseDir()) {
                $debug = function ($msg) use ($output) { $output->isVerbose() ? $output->writeln($msg) : NULL; };
                $public = "$dir/public";

                $Layers[] = $this->config->getPhpLayerArn('arn:aws:lambda:us-east-1:322173628904:layer:php:10');

                $debug('Gathering AWS credentials');

                while ($config = $this->config->getAwsConfig()) {
                    if (empty($config['key']) || empty($config['secret'])) {
                        $output->writeln("Please configure AWS credentials before deployment!\n");
                        $command = $this->getApplication()->find('config');
                        $command->run($input, $output);
                    } else {
                        break;
                    }
                }

                $args = ['credentials' => ['key' => $config['key'], 'secret' => $config['secret'],], 'region' => $config['region'], 'version' => 'latest'];
                $fn = $config['name'];

                if (!preg_match('/^[a-zA-Z0-9\_]{3,}$/', $fn)) {
                    throw new \Exception("\"$fn\" is not a valid project name.\nProject name must be alphanumeric (i.e. A-Z, 0-9, _).\nPlease change $dir/lambdaphp.ini to update project name\n\n");
                }

                $lambdaClient = new LambdaClient($args);
                $composer = realpath("$public/composer.lock");
                $availableLayers = $lambdaClient->listLayers(['CompatibleRuntime' => 'nodejs8.10',]);
                $vendorVersion = $input->getOption('rebuild') ? md5(microtime()) : ($composer ? md5(file_get_contents($composer)) : 'stock');

                list($vendorLayerName, $vendorLayerId) = ["layer-$fn", $vendorVersion];

                foreach ($availableLayers->get('Layers') as $layerInfo) {
                    if ($layerInfo['LayerName'] === $vendorLayerName) {
                        $layerArn = @($layerInfo["LatestMatchingVersion"]["LayerVersionArn"]);
                        $layerId = @($layerInfo["LatestMatchingVersion"]["Description"]);
                    }
                }

                $updateVendor = @((empty($layerArn) || empty($layerId) || ($layerId !== $vendorLayerId)));

                if ($updateVendor) {
                    system(sprintf('cd "%s" && composer dumpautoload -o', $public));
                }

                if (list($publicZip, $vendorZip) = $this->zipMaker->zipDir($public, $updateVendor)) {
                    $debug("Created zip: $publicZip, $vendorZip");

                    if ($updateVendor) {
                        $debug("Creating vendor layer: $vendorLayerName:$vendorLayerId");

                        $result = $lambdaClient->publishLayerVersion([
                            'LayerName'          => $vendorLayerName,
                            'Description'        => $vendorLayerId,
                            'CompatibleRuntimes' => ['nodejs8.10'],
                            'Content'            => ['ZipFile' => file_get_contents($vendorZip)],
                        ]);

                        if (!empty($layerArn = $result->get("LayerVersionArn"))) {
                            $Layers[] = $layerArn;

                            try {
                                $lambdaClient->deleteFunction(['FunctionName' => $fn]);
                            } catch (\Throwable $e) {
                                //maybe first timer?
                            }
                        }
                    } else {
                        $Layers[] = $layerArn;
                    }

                    try {
                        $result = $lambdaClient->getFunction(['FunctionName' => $fn]);
                        $func = $result->get('Configuration');
                        $debug(sprintf("Updating lambda function: %.02f Kb (this may take a while)", filesize($publicZip) / 1024));

                        $lambdaClient->updateFunctionCode([
                            'FunctionName' => $fn,
                            'ZipFile'      => file_get_contents($publicZip),
                            'Publish'      => TRUE,
                        ]);
                    } catch (\Exception $e) {
                        $debug("Setting up IAM permissions");

                        $iam = new IamClient($args);
                        $role = "{$fn}Role";

                        try {
                            $roleObj = $iam->getRole(['RoleName' => $role]);
                            $debug("Found existing IAM role");
                        } catch (\Exception $e) {
                            $debug("Creating new IAM role");

                            $baseTrustPolicy = [
                                'Version'   => '2012-10-17',
                                'Statement' => [
                                    [
                                        'Effect'    => 'Allow',
                                        'Principal' => [
                                            'Service' => 'lambda.amazonaws.com',
                                        ],
                                        'Action'    => 'sts:AssumeRole',
                                    ],
                                ],
                            ];

                            $roleObj = $iam->createRole(['RoleName' => $role, 'AssumeRolePolicyDocument' => json_encode($baseTrustPolicy),]);

                            $debug("Setting S3 and DynamoDB access");

                            $policy = '{
                              "Version": "2012-10-17",
                              "Statement": [
                                {
                                  "Effect": "Allow",
                                  "Action": [
                                    "logs:CreateLogGroup",
                                    "logs:CreateLogStream",
                                    "logs:PutLogEvents"
                                  ],
                                  "Resource": "arn:aws:logs:*:*:*"
                                },
                                {
                                  "Effect": "Allow",
                                  "Action": [
                                    "s3:*"
                                  ],
                                  "Resource": "*"
                                },
                                {
                                  "Effect": "Allow",
                                  "Action": [
                                    "dynamodb:*"
                                  ],
                                  "Resource": "*"
                                },
                                {
                                  "Effect": "Allow",
                                  "Action": [
                                    "polly:*"
                                  ],
                                  "Resource": "*"
                                },
                                {
                                  "Effect": "Allow",
                                  "Action": [
                                    "cognito-identity:*"
                                  ],
                                  "Resource": "*"
                                },
                                {
                                  "Effect": "Allow",
                                  "Action": [
                                    "cognito-sync:*"
                                  ],
                                  "Resource": "*"
                                },
                                {
                                  "Effect": "Allow",
                                  "Action": [
                                    "cognito-idp:*"
                                  ],
                                  "Resource": "*"
                                },
                                {
                                  "Effect": "Allow",
                                  "Action": [
                                    "transcribe:*"
                                  ],
                                  "Resource": "*"
                                },
                                {
                                  "Effect": "Allow",
                                  "Action": [
                                    "ses:*"
                                  ],
                                  "Resource": "*"
                                }
                              ]
                            }';

                            $iam->putRolePolicy([
                                'PolicyDocument' => $policy, // REQUIRED
                                'PolicyName'     => "{$fn}Policy", // REQUIRED
                                'RoleName'       => $role, // REQUIRED
                            ]);

                            $debug("waiting for IAM permissions to propagate (one time only)");
                            sleep(10); //aws bug (https://stackoverflow.com/a/37438525/1031454)
                        }

                        $debug("Creating new lambda function (this may take a while)");

                        $func = $lambdaClient->createFunction([
                            'FunctionName' => $fn,
                            'Runtime'      => 'nodejs8.10',
                            'Role'         => $roleObj->get('Role')['Arn'],
                            'Handler'      => 'index.handler',
                            'Code'         => ['ZipFile' => file_get_contents($publicZip),],
                            'Layers'       => $Layers,
                            'Timeout'      => $config['timeout'] ?? 60,
                            'MemorySize'   => round((($config['ram'] ?? 0) >= 128) ? $config['ram'] : 128),
                            'Publish'      => TRUE,
                        ]);

                        $debug("Setting permissions for lambda function");

                        $lambdaClient->addPermission([
                            'FunctionName' => $fn,
                            'StatementId'  => 'ApiInvokeAccess',
                            'Action'       => 'lambda:InvokeFunction',
                            'Principal'    => 'apigateway.amazonaws.com',
                        ]);

                        $lambdaClient->addPermission([
                            'FunctionName' => $fn,
                            'StatementId'  => "CronInvokeAccess",
                            'Action'       => 'lambda:InvokeFunction',
                            'Principal'    => 'events.amazonaws.com',
                        ]);
                    }

                    $jobs = $this->config->getJobs();
                    $cloudWatchEventsClient = new CloudWatchEventsClient($args);
                    $result = $cloudWatchEventsClient->ListRuleNamesByTarget(['TargetArn' => $func['FunctionArn'],]);
                    $cwPrefix = function ($name) use ($config) { return sprintf('LambdaPHP-cron-%s-%s', $config['name'], $name); };

                    foreach ($result->get('RuleNames') as $ruleName) {
                        $found = FALSE;

                        foreach ($jobs as $job) {
                            if ($cwPrefix($job['name']) === $ruleName) {
                                $found = TRUE;
                                break;
                            }
                        }

                        if (!$found) {
                            $debug("Deleting cron job: $ruleName");

                            $result = $cloudWatchEventsClient->listTargetsByRule(['Rule' => $ruleName]);
                            $targets = array_map(function ($t) { return $t['Id']; }, $result->get('Targets') ?? []);
                            if (!empty($targets)) {
                                $cloudWatchEventsClient->removeTargets(['Ids' => $targets, 'Rule' => $ruleName]);
                            }

                            $cloudWatchEventsClient->deleteRule(['Name' => $ruleName]);
                        }
                    }

                    foreach ($jobs as $job) {
                        $debug("Creating/Updating cron job: " . $job['name']);

                        $cwName = $cwPrefix($job['name']);
                        $rule = $cloudWatchEventsClient->putRule([
                            'Name'               => $cwName, // REQUIRED
                            'ScheduleExpression' => $job['schedule'],
                            'State'              => $job['state'],
                        ]);

                        $result = $cloudWatchEventsClient->putTargets([
                            'Rule'    => $cwName,
                            'Targets' => [[
                                'Arn'   => $func['FunctionArn'],
                                'Id'    => $config['name'],
                                'Input' => json_encode(['path' => $job['path'], 'cron' => TRUE]),
                            ]],
                        ]);
                    }

                    $debug('Creating sessions table');

                    $dynamoDbClient = new DynamoDbClient($args);
                    try {
                        $dynamoDbClient->createTable($params = [
                            'TableName'             => 'lambda_sessions',
                            'ProvisionedThroughput' => [
                                'ReadCapacityUnits'  => 1,
                                'WriteCapacityUnits' => 1,
                            ],
                            'AttributeDefinitions'  => [
                                [
                                    'AttributeName' => 'id',
                                    'AttributeType' => 'S',
                                ],
                            ],
                            'KeySchema'             => [
                                [
                                    'AttributeName' => 'id',
                                    'KeyType'       => 'HASH',
                                ],
                            ],
                            'ua.append'             => 'SES',
                        ]);
                    } catch (\Throwable $e) {
                    }

                    $apiClient = new ApiGatewayClient($args);
                    $apiName = "$fn server";

                    $apis = $apiClient->getIterator('GetRestApis');

                    foreach ($apis as $api) {
                        if (strcasecmp($api['name'], $apiName) === 0) {
                            $apiId = $api['id'];
                        }
                    }

                    if (!empty($apiId)) {
                        $items = $apiClient->getIterator('GetDeployments', ['restApiId' => $apiId]);

                        foreach ($items as $item) {
                            $stage = $apiClient->getStages(['restApiId' => $apiId, 'deploymentId' => $item['id']])->get('item');

                            if (!empty($stage)) {
                                $debug('Found previous REST API');

                                if ($stage[0]['stageName'] == 'web') {
                                    $uri = sprintf('https://%s.execute-api.%s.amazonaws.com/%s', $apiId, $config['region'], 'web');
                                    break;
                                }
                            }
                        }

                        if (empty($uri)) {
                            $debug('Deleting REST API (your URL will change)');

                            $apiClient->deleteRestApi(['restApiId' => $apiId]);
                            $apiId = NULL;
                        }
                    }

                    if (empty($apiId)) {
                        $debug('Creating new REST API');

                        $result = $apiClient->createRestApi(['name' => $apiName, 'binaryMediaTypes' => ['*/*']]);
                        $apiId = $result->get('id');

                        $debug('Connecting REST API to Lambda function');

                        $resources = $apiClient->getIterator('GetResources', ['restApiId' => $apiId]);
                        $createMethod = function ($parentId) use ($apiClient, $apiId, $config, $func, $debug) {
                            $debug("Creating API methods for $parentId");

                            $apiClient->putMethod([
                                'apiKeyRequired'    => FALSE,
                                'authorizationType' => 'NONE',
                                'httpMethod'        => 'ANY',
                                'resourceId'        => $parentId,
                                'restApiId'         => $apiId,
                            ]);

                            $apiClient->putIntegration([
                                'httpMethod'            => 'ANY',
                                'resourceId'            => $parentId,
                                'restApiId'             => $apiId,
                                'type'                  => 'AWS_PROXY',
                                'integrationHttpMethod' => 'POST',
                                'uri'                   => sprintf('arn:aws:apigateway:%s:lambda:path/2015-03-31/functions/%s/invocations', $config['region'], $func['FunctionArn']),
                            ]);
                        };

                        foreach ($resources as $resource) {
                            if ($resource['path'] == '/') {
                                $parentId = $resource['id'];

                                if (empty($resource['resourceMethods'])) {
                                    $createMethod($parentId);
                                }

                                break;
                            }
                        }

                        if (!empty($parentId)) {
                            $result = $apiClient->createResource([
                                'parentId'  => $parentId,
                                'pathPart'  => '{proxy+}',
                                'restApiId' => $apiId,
                            ]);

                            $createMethod($result['id']);
                        }

                        $debug("Creating deployment for Rest API");

                        $apiClient->createDeployment(['restApiId' => $apiId, 'stageName' => 'web',]);
                        $uri = sprintf('https://%s.execute-api.%s.amazonaws.com/%s', $apiId, $config['region'], 'web');
                    }

                    if (!empty($uri)) {
                        $output->writeln("Website deployed!\nTo access your site visit:\n$uri");
                    } else {
                        $output->writeln("Could not access deployment uri.\nPlease check your AWS credentials!");
                    }
                } else {
                    $output->writeln("Failed to create zip file.");
                }
            }
        }
    }
}