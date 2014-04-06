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

// Build select query.
$query  = 'SELECT * ';
// SimpleDB requires `, not " when specifying the domain.
$query .= 'FROM `' . UARWAWS_SDB_DOMAIN . '` ';
$query .= 'WHERE processed = "y" ';

// Execute select query.
$select_response = $sdb->select($query);

if ($select_response->isOK()) {
  // If there are more than one item...
  if (count($select_response->body->SelectResult->Item)) {
    // Display in a fluid row.
    echo '<div class="row-fluid">';
	
	$src = 'https://' . UARWAWS_S3_BUCKET . '.s3.amazonaws.com/';
    foreach ($select_response->body->SelectResult->Item as $item) {
      // CFSimpleXML and SimpleDB makes it a little difficult to just access
      // attributes by key / value, so I'm just arbitrarily adding them all
      // to an array.
      $item_attributes = array();
      foreach ($item->Attribute as $attribute) {
        $attribute_stdClass = $attribute->to_stdClass();
        $item_attributes[$attribute_stdClass->Name] = $attribute_stdClass->Value;
      }
      // Render image with height and width.
      echo '<div class="span2">';
	  echo '<a href="' . $src . $item->Name . '" target="_blank" >';
      echo '<img alt="' . $item->Name . '" class="img-polaroid" src="' . $src . $item_attributes['processedName'] . '" height="' . $item_attributes['processedHeight'] . '" width=' . $item_attributes['processedWidth'] . '"/>';
	  echo '</a>';
      echo '</div>';
    }
    echo '</div>';
  }
  // No items.
  else {
    echo renderMsg('info', array(
      'heading' => 'No resized images found.',
      'body' => 'If you have uploaded an image, remember to process it.',
    ));
  }
}
else {
  echo renderMsg('error', array(
    'heading' => 'Unable to get resized images from SimpleDB!',
    'body' => getAwsError($select_response),
  ));
  return;
}