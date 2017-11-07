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

    class Remove extends Command {
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
                ->setName('remove')
                ->setDescription('Frees all AWS resources created by lambdaphp')
                ->setHelp('This command will delete all lambda functions, api gateways and policies created by lambdaphp');
        }

        protected function execute(InputInterface $input, OutputInterface $output) {
            $config = $this->config->getAwsConfig();
            $args = ['credentials' => ['key' => $config['key'], 'secret' => $config['secret'],], 'region' => $config['region'], 'version' => 'latest'];
            $fn = $config['name'];
            $debug = function ($msg) use ($output) {
                if ($output->isVerbose()) {
                    $output->writeln($msg);
                }
            };

            $lambdaClient = new LambdaClient($args);

            try {
                $debug("Removing lambda function");
                $lambdaClient->deleteFunction(['FunctionName' => $fn]);
            } catch (\Exception $e) {
            }

            $debug("Removing IAM permissions");

            $iam = new IamClient($args);
            $role = "{$fn}Role";

            try {
                $debug("Deleting role policy");

                try {
                    $iam->deleteRolePolicy(['RoleName' => $role, 'PolicyName' => "{$fn}Policy"]);
                } catch (\Exception $e) {
                }

                $debug("Deleting role");
                $iam->deleteRole(['RoleName' => $role]);
            } catch (\Exception $e) {
            }

            $dynamoDbClient = new DynamoDbClient($args);

            try {
                $debug('Removing sessions table');
                $dynamoDbClient->deleteTable(['TableName' => 'lambda_sessions']);
            } catch (\Throwable $e) {
            }

            $apiClient = new ApiGatewayClient($args);
            $apiName = "$fn server";
            $apis = $apiClient->getIterator('GetRestApis');

            foreach ($apis as $api) {
                if (strcasecmp($api['name'], $apiName) === 0) {
                    $debug('Removing API gateway');
                    $apiClient->deleteRestApi(['restApiId' => $api['id']]);
                }
            }
        }
    }
}