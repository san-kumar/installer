<?php
/**
 * Created by PhpStorm.
 * User: san
 * Date: 24/10/17
 * Time: 10:30 PM
 */
namespace Console\Utils {

    use Console\App\Config;
    use const DIRECTORY_SEPARATOR;
    use function dirname;
    use RecursiveDirectoryIterator;
    use RecursiveIteratorIterator;
    use Symfony\Component\Finder\SplFileInfo;
    use ZipArchive;

    class ZipMaker {
        /**
         * @var Config
         */
        private $config;

        /**
         * ZipMaker constructor.
         *
         * @param Config $config
         */
        public function __construct(Config $config) {
            $this->config = $config;
        }

        public function zipDir($public, $withVendor) {
            try {
                $self = realpath(sprintf('%s/../../wrapper', __DIR__));
                $userVendor = realpath("$public/vendor");

                $vendorZipPath = tempnam(sys_get_temp_dir(), 'zip') . '.zip';
                $publicZipPath = tempnam(sys_get_temp_dir(), 'zip') . '.zip';

                $publicZip = new ZipArchive();
                $res = $publicZip->open($publicZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

                if (!is_dir($userVendor)) {
                    copy("$self/vendor.zip", $vendorZipPath);
                } else {
                    if (!is_dir("$userVendor/aws/aws-sdk-php")) {
                        exit("Please install AWS SDK in your $public (composer require aws/aws-sdk-php)");
                    }

                    $vendorZip = new ZipArchive();
                    $vendorZip->open($vendorZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                }

                if ($res === TRUE) {
                    foreach (glob("$self/*") as $file) {
                        if (!preg_match('/.zip$/', $file))
                            $publicZip->addFile($file, basename($file));
                    }

                    /** @var SplFileInfo[] $files */
                    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($public), RecursiveIteratorIterator::LEAVES_ONLY);

                    foreach ($files as $name => $file) {
                        if (!$file->isDir()) {
                            $filePath = $file->getRealPath();
                            $relativePath = strtr(substr($filePath, strlen($public) + 1), ['\\' => '/']);

                            if (preg_match('#^vendor/#', $relativePath)) {
                                if ($withVendor)
                                    $vendorZip->addFile($filePath, $relativePath);
                            } else {
                                $publicZip->addFile($filePath, $relativePath);
                            }
                        }
                    }

                    $publicZip->close();
                    if (!empty($vendorZip)) $vendorZip->close();
                }
            } catch (\Exception $e) {
            }

            return filesize($publicZipPath) > 0 ? [$publicZipPath, $vendorZipPath] : FALSE;
        }
    }
}