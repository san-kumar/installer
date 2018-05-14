<?php
/**
 * Created by PhpStorm.
 * User: san
 * Date: 24/10/17
 * Time: 6:16 PM
 */
namespace Console\App {

    use Piwik\Ini\IniReader;
    use Piwik\Ini\IniWriter;

    class Config {
        /**
         * @var string
         */
        private $baseDir;
        /**
         * @var IniReader
         */
        private $reader;
        /**
         * @var IniWriter
         */
        private $writer;

        /**
         * Config constructor.
         *
         * @param string    $baseDir
         * @param IniReader $reader
         * @param IniWriter $writer
         */
        public function __construct($baseDir, IniReader $reader, IniWriter $writer) {
            $this->baseDir = $baseDir;
            $this->reader = $reader;
            $this->writer = $writer;
        }

        public function setAwsConfig(array $conf) {
            foreach ($conf as $key => $value) {
                $this->updateIniFile($this->getConfigFile($key), ['default' => [$this->mapKey($key) => $value]]);
            }

            return $conf;
        }

        public function getIniFile() {
            return sprintf('%s/%s', $this->getBaseDir(), 'lambdaphp.ini');
        }

        public function getJobs() {
            if ($file = realpath($this->getIniFile())) {
                if ($data = $this->reader->readFile($file)) {
                    if (!empty($data['crontab'])) {
                        foreach ($data['crontab'] as $name => $value) {
                            @list($cron, $path, $state) = preg_split('/,\s*/', $value, 3);
                            $jobs[] = @['name' => $name, 'schedule' => $cron, 'path' => $path, 'state' => preg_match('/^disabled/i', $state) ? 'DISABLED' : 'ENABLED'];
                        }
                    }
                }
            }

            return $jobs ?? [];
        }

        public function getAwsConfig() {
            $conf = ['key' => getenv('AWS_ACCESS_KEY_ID') ?: '', 'secret' => getenv('AWS_SECRET_ACCESS_KEY') ?: '', 'region' => 'us-east-1', 'name' => basename($this->getBaseDir()), 'ram' => 128];

            foreach ($conf as $key => $value) {
                if ($file = realpath($this->getConfigFile($key))) {
                    $ini = $this->reader->readFile($file);
                    $conf[$key] = $ini['default'][$this->mapKey($key)] ?? $conf[$key];
                }
            }

            return $conf;
        }

        /**
         * @return string
         */
        public function getBaseDir(): string {
            return $this->baseDir;
        }

        protected function getConfigFile(string $key) {
            return preg_match('/^(name|ram)$/', $key) ? $this->getIniFile() : sprintf('%s/%s', $this->getAwsDir(), preg_match('/key|secret/', $key) ? 'credentials' : 'config');
        }

        protected function getAwsDir() {
            $dir = isset($_SERVER['HOME']) ? $_SERVER['HOME'] : (isset($_SERVER['HOMEPATH']) ? sprintf('%s/%s', $_SERVER['HOMEDRIVE'], $_SERVER['HOMEPATH']) : posix_getpwuid(posix_getuid()));
            $aws = sprintf('%s/.aws', realpath($dir) ?: $this->getBaseDir());

            if (!is_dir($aws)) {
                mkdir($aws, 0777, TRUE);
            }

            return realpath($aws);
        }

        private function updateIniFile($iniFile, $newValues) {
            $data = array_replace_recursive(is_file($iniFile) ? $this->reader->readFile($iniFile) : [], $newValues);
            $this->writer->writeToFile($iniFile, $data);
        }

        private function mapKey($key) {
            $map = ['key' => 'aws_access_key_id', 'secret' => 'aws_secret_access_key'];

            return $map[$key] ?? $key;
        }
    }
}
