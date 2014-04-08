<?php
/**
 * @file
 * Show all resized images.
 */

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

$limit = 50;
 // Ensure that the caller can specify a max number of images. The number must be between 1 and 250. Default is 50.
if (isset($_REQUEST['limit']) && ctype_digit($_REQUEST['limit'])) {
	$limit = ($_REQUEST['limit'] > 0 && $_REQUEST['limit'] <= 250 ? $_REQUEST['limit'] : $limit);
}

// Build select query.
$query  = 'SELECT * ';
// SimpleDB requires `, not " when specifying the domain.
$query .= 'FROM `' . UARWAWS_SDB_DOMAIN . '` ';
$query .= 'WHERE processed = "y" AND `uploadedDate` is not null order by `uploadedDate` DESC limit ' . $limit;

// Execute select query.
$select_response = $sdb->select($query);

if ($select_response->isOK()) {
  // If there are more than one item...
  if (count($select_response->body->SelectResult->Item)) {
    // Display in a fluid row.
	if (!HIDE_HTML) {
		echo '<div class="row-fluid">';
	}
	
	$src = 'https://' . UARWAWS_S3_BUCKET . '.s3.amazonaws.com/';
	$imageList = array();
    foreach ($select_response->body->SelectResult->Item as $item) {
      // CFSimpleXML and SimpleDB makes it a little difficult to just access
      // attributes by key / value, so I'm just arbitrarily adding them all
      // to an array.
      $item_attributes = array();
      foreach ($item->Attribute as $attribute) {
        $attribute_stdClass = $attribute->to_stdClass();
        $item_attributes[$attribute_stdClass->Name] = $attribute_stdClass->Value;
      }
	  
	  if (!HIDE_HTML) {
		  // Render image with height and width.
		  echo '<div class="span2">';
		  echo '<a href="' . $src . $item->Name . '" target="_blank" >';
		  echo '<img alt="' . $item->Name . '" class="img-polaroid" src="' . $src . $item_attributes['processedName'] . '" height="' . $item_attributes['processedHeight'] . '" width="' . $item_attributes['processedWidth'] . '"/>';
		  echo '</a>';
		  echo '</div>';
	  } else {
			$imageList[] = array(
				'image' => array( 'url' => $src . $item->Name, 'height' => $item_attributes['orgHeight'], 'width' => $item_attributes['orgWidth']),
				'thumb' => array( 'url' => $src . $item_attributes['processedName'], 'height' => $item_attributes['processedHeight'], 'width' => $item_attributes['processedWidth'])
			);
	  }
    }
	if (!HIDE_HTML) {
		echo '</div>';
	} else {
		header('Content-Type: application/json');
		echo json_encode(array( 'imageList' => $imageList)); 
	}
    
  }
  // No items.
  else {
  	if (!HIDE_HTML) {
		renderMsgAndEcho('info', array(
      'heading' => 'No resized images found.',
      'body' => 'If you have uploaded an image, remember to process it.',
    ));
	} else {
		header('Content-Type: application/json');
		echo json_encode(array( 'imageList' => array())); 
	}
  }
}
else {
  echo renderMsg('error', array(
    'heading' => 'Unable to get resized images from SimpleDB!',
    'body' => getAwsError($select_response),
  ));
  return;
}
