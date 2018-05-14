<?php
/**
 * Created by PhpStorm.
 * User: san
 * Date: 24/10/17
 * Time: 5:42 PM
 */

namespace Console\Command {

    use Console\App\Config;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\Input;
    use Symfony\Component\Console\Input\InputArgument;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Input\InputOption;
    use Symfony\Component\Console\Input\StringInput;
    use Symfony\Component\Console\Output\OutputInterface;

    class ConfigAws extends Command {
        /**
         * @var Config
         */
        private $config;

        /**
         * ConfigAws constructor.
         * @param Config $config
         */
        public function __construct(Config $config) {
            parent::__construct();
            $this->config = $config;
        }

        protected function configure() {
            $this
                ->setName('config')
                ->setDescription('Configure AWS access settings')
                ->setHelp('This command helps to configure aws settings to access your AWS account (see AWS IAM)')
                ->addOption('key', '', InputOption::VALUE_REQUIRED, 'Your AWS Access Key ID')
                ->addOption('secret', '', InputOption::VALUE_REQUIRED, 'Your AWS Secret Access Key')
                ->addOption('region', '', InputOption::VALUE_REQUIRED, 'Your default region name (us-east-1)', '')
                ->addOption('name', '', InputOption::VALUE_REQUIRED, 'Your project name (or identifier)', '');
        }

        protected function execute(InputInterface $input, OutputInterface $output) {
            $aws = $this->config->getAwsConfig();
            $conf = ['key' => $aws['key'], 'secret' => $aws['secret'], 'name' => $aws['name'], 'region' => $aws['region'] ?: 'us-east-1'];
            $map = ['key' => 'AWS Access Key ID', 'secret' => 'AWS Access Key Secret', 'region' => 'AWS default region name', 'name' => 'Project name'];

            foreach (array_keys($conf) as $key) {
                if ($value = $input->getOption($key)) {
                    $conf[$key] = $value;
                } else {
                    if (empty($helped)) {
                        $helped = true;
                        printf("Configure AWS default profile\n");
                        printf("http://docs.aws.amazon.com/cli/latest/userguide/cli-chap-getting-started.html\n\n");
                    }

                    printf('%s [%s]: ', $map[$key], (preg_match('/key|secret/', $key) ? preg_replace('/(.*)(\w\w\w\w)$/', '*****\\2', $conf[$key]) : $conf[$key]) ?: 'none');
                    $line = fgets(STDIN);
                    $conf[$key] = trim($line) ?: $conf[$key];
                }
            }

            return $this->config->setAwsConfig($conf);
        }
    }
}