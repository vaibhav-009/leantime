<?php

namespace Leantime\Core;

use GuzzleHttp\Exception\RequestException;
use Leantime\Core\Eventhelpers;
use Aws\S3\Exception\S3Exception;
use Aws\S3;
use Aws\S3\S3Client;
use Exception;

/**
 * Fileupload class - Data filuploads
 *
 * @package    leantime
 * @subpackage core
 */
class Fileupload
{
    use Eventhelpers;

    /**
     * @var    string path on the server
     */
    private mixed $path;

    /**
     * @var int max filesize in kb
     */
    public int $max_size = 10000;

    /**
     * @var string filename in a temporary variable
     */
    private string $file_tmp_name;

    /**
     * @var int
     */
    public int $file_size;

    /**
     * @var string give the file-type (not extension)
     */
    public string $file_type;

    /**
     * @var string - Name of file after renaming and on server
     */
    public string $file_name;

    /**
     * @var string
     */
    public string $error = '';

    /**
     * @var string name of file after by upload
     */
    public string $real_name = '';

    /**
     * @var array parts of the path
     */
    public array $path_parts = array();

    /**
     * @var Environment configuration object
     */
    public Environment $config;

    /**
     * @var S3Client|string
     */
    public string|S3Client $s3Client = "";

    /**
     * fileupload constructor.
     *
     * @param Environment $config
     * @return void
     */
    public function __construct(Environment $config)
    {
        $this->config = $config;
        $this->path = $this->config->userFilePath;

        if ($this->config->useS3) {
            $s3Config = [
                'version' => 'latest',
                'region' => $this->config->s3Region,
                'credentials' => [
                    'key' => $this->config->s3Key,
                    'secret' => $this->config->s3Secret,
                ],
            ];

            if ($this->config->s3EndPoint != "" && $this->config->s3EndPoint !== false && $this->config->s3EndPoint != null) {
                $s3Config['endpoint'] = $this->config->s3EndPoint;
            }

            if ($this->config->s3UsePathStyleEndpoint === true || $this->config->s3UsePathStyleEndpoint === "true") {
                $s3Config['use_path_style_endpoint'] = true;
            }


            // Instantiate the S3 client with your AWS credentials
            $this->s3Client = new S3Client($s3Config);
        }
    }

    /**
     * This function returns the maximum files size that can be uploaded in PHP
     *
     * @return int File size in bytes
     */
    public static function getMaximumFileUploadSize(): int
    {
        return min(self::convertPHPSizeToBytes(ini_get('post_max_size')), self::convertPHPSizeToBytes(ini_get('upload_max_filesize')));
    }

    /**
     * This function transforms the php.ini notation for numbers (like '2M') to an integer (2*1024*1024 in this case)
     *
     * @param string $sSize
     * @return int The value in bytes
     */
    private static function convertPHPSizeToBytes(string $sSize): int
    {
        $sSuffix = strtoupper(substr($sSize, -1));
        if (!in_array($sSuffix, array('P','T','G','M','K'))) {
            return (int)$sSize;
        }
        $iValue = substr($sSize, 0, -1);
        switch ($sSuffix) {
            case 'P':
                $iValue *= 1024;
            // Fallthrough intended
            // no break
            case 'T':
                $iValue *= 1024;
            // Fallthrough intended
            // no break
            case 'G':
                $iValue *= 1024;
            // Fallthrough intended
            // no break
            case 'M':
                $iValue *= 1024;
            // Fallthrough intended
            // no break
            case 'K':
                $iValue *= 1024;
                break;
        }
        return (int)$iValue;
    }

    /**
     * @return string
     * @throws Exception
     */
    public function getAbsolutePath(): string
    {
        $path = realpath(__DIR__ . "/../../" . $this->path);
        if ($path === false) {
            throw new Exception("Path not valid");
        } else {
            return $path;
        }
    }

    /**
     * @return string
     * @throws Exception
     */
    public function getPublicFilesPath(): string
    {
        $relative_path = self::dispatch_filter('relative_path', "/../../public/userfiles");

        $path = realpath(__DIR__ . $relative_path);
        if ($path === false) {
            throw new Exception("Path not valid");
        } else {
            return $path;
        }
    }


    /**
     * initFile - init variables of file
     *
     * @access public
     * @param  $file $file from Post
     */
    public function initFile($file): void
    {
        $this->file_tmp_name = $file['tmp_name'];
        $this->file_size = $file['size'];
        $this->file_type = $file['type'];
        $this->file_name = $file['name'];
        $this->path_parts = pathinfo($file['name']);
    }

    /**
     * checkFileSize - Checks if filesize is ok
     *
     * @access public
     * @return bool
     */
    public function checkFileSize(): bool
    {
        if ($this->file_size <= $this->max_size * 1024) {
            return true;
        }

        return false;
    }

    /**
     * renameFile
     *
     * @param  $name
     * @return bool
     */
    public function renameFile($name): bool
    {
        $this->real_name = $this->file_name;

        if ($name == '') {
            return false;
        }

        $this->file_name = $name;

        if (isset($this->path_parts['extension'])) {
            $this->file_name .= '.' . $this->path_parts['extension'];
        }

        return true;
    }

    /**
     * upload - move file from tmp-folder to S3
     *
     * @access public
     * @return bool
     */
    public function upload(): bool
    {
        //S3 upload
        if ($this->config->useS3) {
            return $this->uploadToS3();
        }

        //Local upload
        return $this->uploadLocal();
    }

    /**
     * uploadPublic - move file from tmp-folder to public folder
     *
     * @access public
     * @return string|false
     */
    public function uploadPublic(): false|string
    {
        if ($this->config->useS3) {
            try {
                // Upload data.

                if ($this->file_tmp_name == null || $this->file_tmp_name == '') {
                    return false;
                }

                $file = fopen($this->file_tmp_name, "rb");
                // implode all non-empty elements to allow s3FolderName to be empty.
                // otherwise you will get an error as the key starts with a slash
                $fileName = implode('/', array_filter(array($this->config->s3FolderName, $this->file_name)));

                $this->s3Client->upload($this->config->s3Bucket, $fileName, $file, "public-read");
                $url = $this->s3Client->getObjectUrl($this->config->s3Bucket, $fileName);

                return $url;
            } catch (S3Exception $e) {
                error_log($e, 0);
                return false;
            }
        } else {
            try {
                if (move_uploaded_file($this->file_tmp_name, $this->getPublicFilesPath() . "/" . $this->file_name)) {
                    return "/userfiles/" . $this->file_name;
                }
            } catch (Exception $e) {
                error_log($e, 0);
                return false;
            }
        }

        return false;
    }

    /**
     * uploadToS3 - move file from tmp-folder to S3
     *
     * @access private
     * @return bool
     */
    private function uploadToS3(): bool
    {
        try {
            // Upload data.
            $file = fopen($this->file_tmp_name, "rb");
            // implode all non-empty elements to allow s3FolderName to be empty.
            // otherwise you will get an error as the key starts with a slash
            $fileName = implode('/', array_filter(array($this->config->s3FolderName, $this->file_name)));

            $this->s3Client->upload($this->config->s3Bucket, $fileName, $file, "authenticated-read");

            return true;
        } catch (S3Exception $e) {
            error_log($e, 0);
            return false;
        } catch (RequestException $e) {
            error_log($e, 0);
            return false;
        }
    }

    /**
     * @return bool
     */
    /**
     * @return bool
     */
    private function uploadLocal(): bool
    {

        try {
            if (move_uploaded_file($this->file_tmp_name, $this->getAbsolutePath() . "/" . $this->file_name)) {
                return true;
            }
        } catch (Exception $e) {
            error_log($e, 0);
            return false;
        }

        return false;
    }

    /**
     * displayImageFile - display image file
     *
     * @param string $imageName
     * @param string $fullPath
     * @return void
     */
    public function displayImageFile(string $imageName, string $fullPath = ''): void
    {
        $mimes = array(
            'jpg' => 'image/jpg',
            'jpeg' => 'image/jpg',
            'gif' => 'image/gif',
            'png' => 'image/png',
        );

        if ($this->config->useS3 && $fullPath == '') {
            try {
                // implode all non-empty elements to allow s3FolderName to be empty.
                // otherwise you will get an error as the key starts with a slash
                $fileName = implode('/', array_filter(array($this->config->s3FolderName, $imageName)));
                $result = $this->s3Client->getObject([
                    'Bucket' => $this->config->s3Bucket,
                    'Key' => $fileName,
                ]);

                header('Content-Type: ' . $result['ContentType']);
                header('Pragma: public');
                header('Cache-Control: max-age=86400');
                header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 86400));
                header('Content-disposition: inline; filename="' . $imageName . '";');

                $body = $result->get('Body');

                echo $body->getContents();
            } catch (S3Exception $e) {
                echo $e->getMessage() . "\n";
            }
        } else {
            if ($fullPath == '') {
                $path = realpath(APP_ROOT . "/" . $this->config->userFilePath . "/");
                $fullPath = $path . "/" . $imageName;
            }

            if (file_exists(realpath($fullPath))) {
                $path_parts = pathinfo($fullPath);
                $ext = $path_parts["extension"];

                if ($ext == 'jpg' || $ext == 'jpeg' || $ext == 'gif' || $ext == 'png') {
                    header('Content-type: ' . $mimes[$ext]);
                    header('Content-disposition: inline; filename="' . $imageName . '";');
                    header('Pragma: public');
                    header('Cache-Control: max-age=900');
                    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 900));


                    readfile($fullPath);
                }
            }
        }
    }
}
