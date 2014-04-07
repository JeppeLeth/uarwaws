<?php
/**
 * @file
 * Upload files to be resized.
 */

// ImageMagick is required to upload images.
if (!extension_loaded('imagick')) {
  echo renderMsg('error', array(
    'heading' => 'ImageMagick is not installed!',
    'body' => 'Images cannot be uploaded without ImageMagick.',
  ));
  return;
}

$show_form = TRUE;

// If a file has been uploaded...
if (isset($_FILES['image']['tmp_name']) && $_FILES['image']['tmp_name']) {
  // Try to read file as an image.
  try {
    $imagick = new Imagick($_FILES['image']['tmp_name']);
    $file_upload_success = TRUE;
	if (!$hide_html) {
		echo renderMsg('success', array(
		  'heading' => 'Success',
		  'body' => 'Image file upload to server',
		));
	}
  }
  catch (Exception $e) {
    $file_upload_success = FALSE;
	echo renderMsg('error', array(
      'heading' => 'Unable to retrieve uploaded image file',
      'body' => 'Image file upload to server failed',
    ));
  }

 
  if (!$file_upload_success) {
    echo renderMsg('error', array(
      'heading' => 'Unable to read uploaded file as an image!',
      'body' => var_export($e->getMessage(), TRUE),
    ));

    // Connect to Amazon SNS.
    try {
      $sns = new AmazonSNS();
      $sns->set_region(AmazonSNS::REGION_IRELAND);
    }
    catch (Exception $e) {
      echo renderMsg('error', array(
        'heading' => 'Unable to connect to Amazon SNS!',
        'body' => var_export($e->getMessage(), TRUE),
      ));
      return;
    }

    // Search topics for specific topic to get ARN.
    $sns_topic_list = $sns->get_topic_list('/' . UARWAWS_SNS_TOPIC . '/i');
    if (count($sns_topic_list)) {
      $topic_arn = array_pop($sns_topic_list);
      $sns_publish_response = $sns->publish($topic_arn, 'Upload failed attempt from ' . $_SERVER['REMOTE_ADDR'] . ' - ' . $_FILES['image']['type']);
      if ($sns_publish_response->isOK()) {
        echo renderMsg('success', 'Sent notification via Amazon SNS.');
      }
      else {
        echo renderMsg('error', array(
          'heading' => 'Unable to send notification via Amazon SNS!',
          'body' => getAwsError($sns_publish_response),
        ));
      }
    }
    else {
      echo renderMsg('error', 'Unable to find SNS topic; ensure that it has been created.');
    }
    return;
  }

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

  // Generate filename for uploaded file.
  // -- Salted MD5 with the file's original name, appended with the file format.
  $filename = md5(time() . $_FILES['image']['name']);
  $filename .= '.' . strtolower($imagick->getImageFormat());
  
  echo renderMsg('success', array(
      'heading' => 'Success',
      'body' => 'New file name for S3 = '.$filename . ' and temp_name = ' . $_FILES['image']['tmp_name'],
   ));

  // Upload file to S3.
  try {
	  $s3_upload_response = $s3->create_object(UARWAWS_S3_BUCKET, $filename, array(
		'fileUpload' => $_FILES['image']['tmp_name'],
		'contentType' => $_FILES['image']['type'],
		'acl' => AmazonS3::ACL_PUBLIC,
	  ));
    }
  catch (Exception $e) {
    echo renderMsg('error', array(
      'heading' => 'Unable to upload file to Amazon S3 bucket ' . UARWAWS_S3_BUCKET,
      'body' => var_export($e->getMessage(), TRUE),
    ));
    return;
  }
  
  if ($s3_upload_response->isOK()) {
    echo renderMsg('success', array(
      'body' => 'Uploaded image to Amazon S3.',
    ));
    $show_form = FALSE;
  }
  else {
    echo renderMsg('error', array(
      'heading' => 'Unable to upload image file to Amazon S3!',
      'body' => getAwsError($s3_upload_response),
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

  // Create attributes.
  $keypairs = array(
    // By default, not processed.
    'processed' => 'n',
	'uploadedDate' => convertTimeToISO8601($s3_upload_response->header['x-aws-requestheaders']['Date']),
    // Use ImageMagick to determine height, width of uploaded image.
    'orgHeight' => $imagick->getImageHeight(),
    'orgWidth' => $imagick->getImageWidth(),
  );

  // Save item, keyed by the filename, in SimpleDB.
  $sdb_put_response = $sdb->put_attributes(UARWAWS_SDB_DOMAIN, $filename, $keypairs);
  if ($sdb_put_response->isOK()) {
    echo renderMsg('success', array(
      'body' => 'Item added to Amazon SimpleDB.',
    ));
  }
  else {
    echo renderMsg('error', array(
      'heading' => 'Unable to add item to Amazon SimpleDB!',
      'body' => getAwsError($sdb_put_response),
    ));
    return;
  }

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

  // Send a message to the SQS queue
  $queue_url = getAwsSqsQueueUrl($sqs, UARWAWS_SQS_QUEUE);
  $sqs_send_response = $sqs->send_message($queue_url, $filename);
  if ($sqs_send_response->isOK()) {
    echo renderMsg('success', array(
      'body' => 'Filename added to SQS queue for processing.',
    ));
  }
  else {
    echo renderMsg('error', array(
      'heading' => 'Unable to send message with SQS!',
      'body' => getAwsError($sqs_send_response),
    ));
    return;
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

  // Uploaded file count metric.
  $cw_put_metric_response = $cw->put_metric_data('Resize', array(
    array(
      'MetricName' => 'UploadedFiles',
      'Unit' => 'Count',
      'Value' => 1,
    ),
  ));

  if ($cw_put_metric_response->isOK()) {
    echo renderMsg('success', array(
      'body' => 'Uploaded file metric added to CloudWatch.',
    ));
  }
  else {
    echo renderMsg('error', array(
      'heading' => 'Unable to update uploaded file count with CloudWatch',
      'body' => getAwsError($cw_put_metric_response),
    ));
    return;
  }
}

// Display form if there was no upload attempt.
if ($show_form) { ?>
<form class="form-horizontal" method="POST" enctype="multipart/form-data">
  <div class="well">
    <label class="control-label" for="file"><span class="label">Image to upload</span></label>
    <div class="controls">
      <input type="file" name="image"/>
    </div>
	<div class="form-actions">
	  <button type="submit" class="btn btn-primary">Upload image</button>
	</div>
  </div>
</form>
<?php }
