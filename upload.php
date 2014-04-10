<?php
/**
 * @file
 * Front upload controller.
 */
define('HIDE_HTML' , TRUE);
require_once 'config.inc.php';
require_once 'util.inc.php';
require_once 'AWSSDKforPHP/sdk.class.php';
header('Access-Control-Allow-Origin: *'); 
include_once 'pages/upload.php';
?>

