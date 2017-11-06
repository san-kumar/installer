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
         * @param string $baseDir
         * @param IniReader $reader
         * @param IniWriter $writer
         */
        public function __construct($baseDir, IniReader $reader, IniWriter $writer) {
            $this->baseDir = $baseDir;
            $this->reader = $reader;
            $this->writer = $writer;
        }

        protected function getConfigFile(string $key) {
            return preg_match('/^(name|ram)$/', $key) ? sprintf('%s/%s', $this->getBaseDir(), 'lambdaphp.ini') : sprintf('%s/%s', $this->getAwsDir(), preg_match('/key|secret/', $key) ? 'credentials' : 'config');
        }

        public function setAwsConfig(array $conf) {
            foreach ($conf as $key => $value) {
                $this->updateIniFile($this->getConfigFile($key), ['default' => [$this->mapKey($key) => $value]]);
            }

            return $conf;
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

        protected function getAwsDir() {
            $dir = isset($_SERVER['HOME']) ? $_SERVER['HOME'] : (isset($_SERVER['HOMEPATH']) ? sprintf('%s/%s', $_SERVER['HOMEDRIVE'], $_SERVER['HOMEPATH']) : posix_getpwuid(posix_getuid()));
            $aws = sprintf('%s/.aws', realpath($dir) ?: $this->getBaseDir());

            if (!is_dir($aws)) {
                mkdir($aws, 0777, true);
            }

            return realpath($aws);
        }

        /**
         * @return string
         */
        public function getBaseDir(): string {
            return $this->baseDir;
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
