<?php
/**
 * Created by PhpStorm.
 * User: san
 * Date: 24/10/17
 * Time: 5:42 PM
 */

namespace Console\Command {

    use Aws\ApiGateway\ApiGatewayClient;
    use Aws\DynamoDb\DynamoDbClient;
    use Aws\Iam\IamClient;
    use Aws\Lambda\LambdaClient;
    use Console\App\Config;
    use Console\Utils\ZipMaker;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Output\OutputInterface;
    use Symfony\Component\Finder\Finder;

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
         * @param Config $config
         * @param ZipMaker $zipMaker
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
                ->setHelp('This command will deploy all your PHP files to AWS lambda and create web accessible links for them');
        }

        protected function execute(InputInterface $input, OutputInterface $output) {
            if ($dir = $this->config->getBaseDir()) {
                $phpFiles = (new Finder())->in($dir)->notPath("(^vendor)")->ignoreUnreadableDirs();
                $debug = function ($msg) use ($output) {
                    if ($output->isVerbose()) {
                        $output->writeln($msg);
                    }
                };

                $debug('Creating zip file');

                if ($zipFile = $this->zipMaker->createZipFile(array_merge(iterator_to_array($phpFiles)), "payload.zip", true)) {
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

                    try {
                        $result = $lambdaClient->getFunction(['FunctionName' => $fn]);
                        $func = $result->get('Configuration');
                        $debug("Updating lambda function (this may take a while)");

                        $lambdaClient->updateFunctionCode([
                            'FunctionName' => $fn,
                            'ZipFile' => file_get_contents($zipFile),
                            'Publish' => true,
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
                                'Version' => '2012-10-17',
                                'Statement' => [
                                    [
                                        'Effect' => 'Allow',
                                        'Principal' => [
                                            'Service' => 'lambda.amazonaws.com',
                                        ],
                                        'Action' => 'sts:AssumeRole',
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
                                }
                              ]
                            }';

                            $iam->putRolePolicy([
                                'PolicyDocument' => $policy, // REQUIRED
                                'PolicyName' => "{$fn}Policy", // REQUIRED
                                'RoleName' => $role, // REQUIRED
                            ]);
                        }

                        $debug("Creating new lambda function (this may take a while)");

                        $func = $lambdaClient->createFunction([
                            'FunctionName' => $fn,
                            'Runtime' => 'nodejs6.10',
                            'Role' => $roleObj->get('Role')['Arn'],
                            'Handler' => 'index.handler',
                            'Code' => [
                                'ZipFile' => file_get_contents($zipFile),
                            ],
                            'Timeout' => 60,
                            'MemorySize' => round((($config['ram'] ?? 0) >= 128) ? $config['ram'] : 128),
                            'Publish' => true,
                        ]);

                        $debug("Setting permissions for lambda function");

                        $lambdaClient->addPermission([
                            'FunctionName' => $fn,
                            'StatementId' => 'ManagerInvokeAccess',
                            'Action' => 'lambda:InvokeFunction',
                            'Principal' => 'apigateway.amazonaws.com',
                        ]);
                    }

                    $debug('Creating sessions table');

                    $dynamoDbClient = new DynamoDbClient($args);
                    try {
                        $dynamoDbClient->createTable($params = [
                            'TableName' => 'lambda_sessions',
                            'ProvisionedThroughput' => [
                                'ReadCapacityUnits' => 5,
                                'WriteCapacityUnits' => 5,
                            ],
                            'AttributeDefinitions' => [
                                [
                                    'AttributeName' => 'id',
                                    'AttributeType' => 'S',
                                ],
                            ],
                            'KeySchema' => [
                                [
                                    'AttributeName' => 'id',
                                    'KeyType' => 'HASH',
                                ],
                            ],
                            'ua.append' => 'SES',
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
                                    $uri = sprintf('https://%s.execute-api.us-east-1.amazonaws.com/%s', $apiId, 'web');
                                    break;
                                }
                            }
                        }

                        if (empty($uri)) {
                            $debug('Deleting REST API (your URL will change)');

                            $apiClient->deleteRestApi(['restApiId' => $apiId]);
                            $apiId = null;
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
                                'apiKeyRequired' => false,
                                'authorizationType' => 'NONE',
                                'httpMethod' => 'ANY',
                                'resourceId' => $parentId,
                                'restApiId' => $apiId,
                            ]);

                            $apiClient->putIntegration([
                                'httpMethod' => 'ANY',
                                'resourceId' => $parentId,
                                'restApiId' => $apiId,
                                'type' => 'AWS_PROXY',
                                'integrationHttpMethod' => 'POST',
                                'uri' => sprintf('arn:aws:apigateway:%s:lambda:path/2015-03-31/functions/%s/invocations', $config['region'], $func['FunctionArn']),
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
                                'parentId' => $parentId,
                                'pathPart' => '{proxy+}',
                                'restApiId' => $apiId,
                            ]);

                            $createMethod($result['id']);
                        }

                        $debug("Creating deployment for Rest API");

                        $apiClient->createDeployment(['restApiId' => $apiId, 'stageName' => 'web',]);
                        $uri = sprintf('https://%s.execute-api.us-east-1.amazonaws.com/%s', $apiId, 'web');
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