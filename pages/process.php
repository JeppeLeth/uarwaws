<?php
/**
 * @file
 * Resize images.
 */

// ImageMagick is required to process images.
if (!extension_loaded('imagick')) {
  echo renderMsg('error', array(
    'heading' => 'ImageMagick is not installed!',
    'body' => 'Images cannot be processed without ImageMagick.',
  ));
  return;
}

// Use long pulling except if the caller want immediate response, which can be set in the request parameters
$waitTimeSeconds = ( isset($_REQUEST['immediately']) ? 2 : 20 );

// Connect to Amazon Simple Queue Service.
try {
  $sqs = new AmazonSQS();
  $sqs->set_region(AmazonSQS::REGION_IRELAND);
}
catch (Exception $e) {
  echo renderMsg('error', array(
    'heading' => 'Unable to connect to Amazon Simple Queue Service!',
    'body' => var_export($e->getMessage(), TRUE),
  ));
  return;
}

$queue_url = getAwsSqsQueueUrl($sqs, UARWAWS_SQS_QUEUE);
$received_sqs_response = $sqs->receive_message($queue_url, array(
  'MaxNumberOfMessages' => 10,
  'WaitTimeSeconds' => $waitTimeSeconds,
));

if (!$received_sqs_response->isOK()) {
  echo renderMsg('error', array(
    'heading' => 'Unable to get messages from SQS queue!',
    'body' => getAwsError($received_sqs_response),
  ));
  return;
}

$count = count($received_sqs_response->body->ReceiveMessageResult->Message);
if ( $count == 0) {
  renderMsgAndEcho('info', array(
    'heading' => 'No images to process.',
    'body' => 'Upload images that need resized before processing the queue.',
  ));
  return;
}
else {
  renderMsgAndEcho('info', "Processing number of messages : $count");
}

$i = 0;
foreach ($received_sqs_response->body->ReceiveMessageResult->Message as $index => $message) { 
	$image_filename = (string) $message->Body;
	renderMsgAndEcho('info', array(
	  'heading' => 'Processing image no. ' . (++$i),
	  'body' => "File name = $image_filename",
	));
	
	// Get the receipt handle; required when deleting a message.
	$receipthandle = (string) $message->ReceiptHandle;
	renderMsgAndEcho('info', array(
	  'heading' => 'ReceiptHandle:',
	  'body' => substr($receipthandle, 0, 80) . ' ...',
	));

	// Connect to Amazon S3.
	try {
	  $s3 = new AmazonS3();
	  //$s3->set_region(AmazonS3::REGION_IRELAND_WEBSITE);
	}
	catch (Exception $e) {
	  echo renderMsg('error', array(
		'heading' => 'Unable to connect to Amazon S3!',
		'body' => var_export($e->getMessage(), TRUE),
	  ));
	  return;
	}

	// Create temporary file based on original filename.
	$file_name_array = explode('.', $image_filename);
	$temporary_file_name = tempnam(sys_get_temp_dir(), array_pop($file_name_array));

	// Download image from S3 for resizing.
	$file_resource = fopen($temporary_file_name, 'w+');
	$s3_get_response = $s3->get_object(UARWAWS_S3_BUCKET, $image_filename, array(
	  'fileDownload' => $temporary_file_name,
	));
	fclose($file_resource);

	if ($s3_get_response->isOK()) {
	  renderMsgAndEcho('success', array(
		'body' => 'Downloaded image from Amazon S3.',
	  ));
	}
	else {
	  echo renderMsg('error', array(
		'heading' => 'Unable to download image from Amazon S3!',
		'body' => getAwsError($s3_get_response),
	  ));
	  return;
	}

	// Get the content type of the file; required to upload resized image to S3.
	$finfo = finfo_open(FILEINFO_MIME_TYPE);
	$content_type = finfo_file($finfo, $temporary_file_name);
	finfo_close($finfo);

	// Attempt to resize image.
	try {
	  $image_to_be_resized = new Imagick($temporary_file_name);
	}
	catch (Exception $e) {
	  echo renderMsg('error', array(
		'heading' => 'Unable to read processed file as an image!',
		'body' => var_export($e->getMessage(), TRUE),
	  ));
	  return;
	}
	addResize($image_to_be_resized);
	$image_to_be_resized->writeImages($temporary_file_name, TRUE);

	$ext   = pathinfo($image_filename, PATHINFO_EXTENSION);
	$thumb = basename($image_filename, ".$ext") . '_thumb.' . $ext;

	// Upload resized thumb image to S3.
	$s3_create_response = $s3->create_object(UARWAWS_S3_BUCKET, $thumb, array(
	  'fileUpload' => $temporary_file_name,
	  'contentType' => $content_type,
	  'acl' => AmazonS3::ACL_PUBLIC,
	));

	if ($s3_create_response->isOK()) {
	  renderMsgAndEcho('success', array(
		'body' => 'Uploaded resized thumb image to Amazon S3.',
	  ));
	}
	else {
	  echo renderMsg('error', array(
		'heading' => 'Unable to upload resized thumb image to Amazon S3!',
		'body' => getAwsError($s3_create_response),
	  ));
	  return;
	}

	// Image has been processed; delete from SQS queue.
	$sqs_delete_response = $sqs->delete_message($queue_url, $receipthandle);
	if ($sqs_delete_response->isOK()) {
	  renderMsgAndEcho('success', array(
		'body' => 'Deleted message from queue.',
	  ));
	}
	else {
	  echo renderMsg('error', array(
		'heading' => 'Unable to delete message from queue!',
		'body' => getAwsError($sqs_delete_response),
	  ));
	  return;
	}

	// Connect to Amazon SimpleDB.
	try {
	  $sdb = new AmazonSDB();
	  $sdb->set_region(AmazonSDB::REGION_IRELAND);
	}
	catch (Exception $e) {
	  echo renderMsg('error', array(
		'heading' => 'Unable to connect to Amazon SimpleDB!',
		'body' => var_export($e->getMessage(), TRUE),
	  ));
	  return;
	}

	// Update SimpleDB item to reflect that image has been processed.
	$keypairs = array(
	  'processed' => 'y',
	  'processedName' => $thumb,
	  'processedHeight' => $image_to_be_resized->getImageHeight(),
	  'processedWidth' => $image_to_be_resized->getImageWidth(),
	);
	$sdb_put_response = $sdb->put_attributes(UARWAWS_SDB_DOMAIN, $image_filename, $keypairs);
	if ($sdb_put_response->isOK()) {
	  renderMsgAndEcho('success', array(
		'body' => 'Item updated in Amazon SimpleDB.',
	  ));
	}
	else {
	  echo renderMsg('error', array(
		'heading' => 'Unable to update item in Amazon SimpleDB!',
		'body' => getAwsError($sdb_put_response),
	  ));
	  return;
	}
}



// Connect to Amazon CloudWatch.
try {
  $cw = new AmazonCloudWatch();
  $cw->set_region(AmazonCloudWatch::REGION_IRELAND);
}
catch (Exception $e) {
  echo renderMsg('error', array(
    'heading' => 'Unable to connect to Amazon CloudWatch Service!',
    'body' => var_export($e->getMessage(), TRUE),
  ));
  return;
}

// Processed file count metric.
$cw_put_metric_response = $cw->put_metric_data('Resize', array(
  array(
    'MetricName' => 'ProcessedFiles',
    'Unit' => 'Count',
    'Value' => $count,
  ),
));

if ($cw_put_metric_response->isOK()) {
  renderMsgAndEcho('success', array(
    'body' => 'Processed file metric added to CloudWatch.',
  ));
}
else {
  echo renderMsg('error', array(
    'heading' => 'Unable to update process file count with CloudWatch',
    'body' => getAwsError($cw_put_metric_response),
  ));
  return;
}
