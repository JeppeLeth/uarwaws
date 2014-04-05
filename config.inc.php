<?php
/**
 * @file
 * Local configuration; requires editing.
 */

define('UARWAWS_S3_BUCKET' , 'image.test.resize');
define('UARWAWS_SDB_DOMAIN' , 'resizedImages');
define('UARWAWS_SQS_QUEUE' , 'images-to-resize');
define('UARWAWS_SNS_TOPIC' , 'image-bad-upload');
