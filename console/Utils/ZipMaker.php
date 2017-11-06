<?php
/**
 * Created by PhpStorm.
 * User: san
 * Date: 24/10/17
 * Time: 10:30 PM
 */
namespace Console\Utils {

    use Console\App\Config;
    use Symfony\Component\Finder\SplFileInfo;
    use ZipArchive;

    class ZipMaker {
        /**
         * @var Config
         */
        private $config;

        /**
         * ZipMaker constructor.
         * @param Config $config
         */
        public function __construct(Config $config) {
            $this->config = $config;
        }

        public function createZipFile(array $files, string $name = "payload.zip", bool $withWrapper = false) {
            $zip = new ZipArchive;
            $tmpDir = sys_get_temp_dir();
            $zipFile = "$tmpDir/$name";
            $wrapperZip = sprintf('%s/../../wrapper/wrapper.zip', __DIR__); #faster than creating it!

            if ($withWrapper) {
                foreach (glob(dirname($wrapperZip) . "/*") as $file) {
                    if (is_file($file) && (basename($file) != 'php')) {
                        array_unshift($files, new SplFileInfo($file, "", basename($file)));
                    }
                }
            }

            copy($wrapperZip, $zipFile);
            $res = $zip->open($zipFile);

            if ($res === true) {
                /** @var SplFileInfo $file */
                foreach ($files as $file) {
                    $zip->addFile($file->getRealPath(), $file->getRelativePathname());
                }

                $zip->close();

                return $zipFile;
            }

            return false;
        }
    }
}