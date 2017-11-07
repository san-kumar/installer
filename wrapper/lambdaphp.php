<?php

ob_start();

$file = $_SERVER['SCRIPT_PATH'];

if (is_file($file)) {
    try {
        ini_set('display_errors', '1');
        set_include_path(get_include_path() . PATH_SEPARATOR . $_SERVER['DOCUMENT_ROOT']);

        require_once('vendor/autoload.php');

        $ext = pathinfo($file, PATHINFO_EXTENSION);

        if ($ext == 'php') {
            if (!empty($_SERVER['AWS_ACCESS_KEY_ID']) && !empty($_SERVER['AWS_SECRET_ACCESS_KEY'])) {
                $args = ['region' => $_SERVER['AWS_REGION'] ?? 'us-east-1', 'version' => 'latest'];
                $s3Client = new \Aws\S3\S3Client($args);
                $s3Client->registerStreamWrapper();

                $dynamoClient = new \Aws\DynamoDb\DynamoDbClient($args);
                $dynamoClient->registerSessionHandler(['table_name' => $_SERVER['AWS_SESSION_TABLE'] ?? 'lambda_sessions']);
            }

            require_once($file);
        }
    } catch (Throwable $e) {
        //header('Server error', true, 500);
        echo "ERROR: ", $e->getMessage();
    }
} else {
    header('Not found', true, 404);
    echo "Not found";
}

$body = ob_get_contents();
ob_end_clean();

echo base64_encode($body);