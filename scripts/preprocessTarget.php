<?php
//require '//home/rwaltz/Share/aws/aws-autoloader.php';
//require '/home/vagrant/Share/aws/aws-autoloader.php';

use Aws\Credentials\CredentialProvider;
use Aws\S3\S3Client;


function getSetId() {
	return "13";
}

function getAWSFilterPath() {
	return null;
}

function getDigitalCommonsSeriesName() {
	return "utk_gradthes";
}

function getAWSBucketName() {
	return 'bepress-failure-test';
}
function logmsg($message, $logFile = "messages.log") {    

	$date = date("Y-m-d h:m:s");
	$current_file = __FILE__;

 
	$message = "[{$date}] [{$current_file}] ${message}".PHP_EOL;
    return file_put_contents($logFile, $message, FILE_APPEND); 
} 

function isFilenameFilterGated($filepath) {


  // turns out we need a file we know will be at the same level as an object id, metadata.xml
  // should reside in the object directory
  // with all parent directories becoming the DigitalCommonsObjectId

    $filename = basename($filepath);
    $return = false;
    switch ($filename) 
      {
      case ('stamped.pdf'): {
      		  $return = true;
      		  break;
      }
      case (preg_match("/^\d+\-/", $filename) ? true : false ) :
      case ('metadata.xml') :
      case (preg_match("/\.pdf$/", $filename) ? true : false ) :
        break;
      default:
        $return = true;
      }
      return $return;

}

// https://stackoverflow.com/questions/2050859/copy-entire-contents-of-a-directory-to-another-using-php
function recurse_copy($src,$dst) { 
    $dir = opendir($src); 
    @mkdir($dst); 
    while(false !== ( $file = readdir($dir)) ) { 
        if (( $file != '.' ) && ( $file != '..' )) { 
            if ( is_dir($src . '/' . $file) ) { 
                recurse_copy($src . '/' . $file,$dst . '/' . $file); 
            } 
            else { 
                copy($src . '/' . $file,$dst . '/' . $file); 
            } 
        } 
    } 
    closedir($dir); 
} 

function recurse_rmdir($src) {
    $dir = opendir($src);
    while(false !== ( $file = readdir($dir)) ) {
        if (( $file != '.' ) && ( $file != '..' )) {
            $full = $src . '/' . $file;
            if ( is_dir($full) ) {
                recurse_rmdir($full);
            }
            else {
                unlink($full);
            }
        }
    }
    closedir($dir);
    rmdir($src);
}

function scan_aws_s3() 
{
	global $provider;
	global $s3Client;
	$batch_temp_file = tempnam(sys_get_temp_dir(), "A" . getSetId());
	$serialize_object_marker = "---XXX---";
	$errors = array();
	$harvest_list = array();
	$prefix =  getAWSFilterPath();
	if (isset($prefix )) {
			$prefix .= '/' . getDigitalCommonsSeriesName(); 
	} else {
		$prefix = getDigitalCommonsSeriesName(); 
	}
	$max_page_count = 1000;
	$delimiter = ';';
	$marker = null;
	$params = null;
	$iteration = 0;
	try {
		do {
			if (isset($params)) {
				$params['Marker'] = $marker;
			} else {
				$params = array('Bucket' => getAWSBucketName(),
					'Delimiter' => $delimiter,
					'MaxKeys' => $max_page_count,
					'Prefix'  =>  $prefix);
			}
			$command = $s3Client->getCommand('ListObjects', $params);
			// $command['MaxKeys'] = 100;
			$result = $s3Client->execute($command);
			$marker = $result->get('NextMarker');
			if ($result->get('Contents')) {
				$serialized_harvest = serialize($result->get('Contents')) . $serialize_object_marker;
				// where to put the temporary downloaded and serialized list?
				file_put_contents($batch_temp_file, ($serialized_harvest), FILE_APPEND);
			}
		} while ($result->get('IsTruncated') && isset($marker));
		if ($result->get('IsTruncated') ) {
			$errors[] = sprintf('The number of keys greater than %u, the first part is shown', count($harvest_list));
		}
	} catch (S3Exception $e) {
		$errors[] = sprintf('Cannot retrieve objects: %s', $e->getMessage());
	}
// where to pull the completed serialized list and deserialize it where?
	$file_contents = file_get_contents($batch_temp_file);
	$data_for_life = explode($serialize_object_marker, $file_contents);
	foreach ($data_for_life as $data)
	{
		$unserialized_harvest = unserialize($data);
		$harvest_list[] = $unserialized_harvest;
	}
	unlink($batch_temp_file);
	return $harvest_list;
}

$provider = CredentialProvider::ini();
// Cache the results in a memoize function to avoid loading and parsing
// the ini file on every API operation.

// the provider file is in the default location, 
// ~/.aws/credentials
$provider = CredentialProvider::memoize($provider);

$s3Client = new Aws\S3\S3Client([
    'version' => 'latest',
    'region'  => 'us-east-1',
     'credentials' => $provider
]);

$harvest_array_list = scan_aws_s3();

$TMP_DIR_HARVEST= sys_get_temp_dir() . DIRECTORY_SEPARATOR . getAWSBucketName() . DIRECTORY_SEPARATOR . getDigitalCommonsSeriesName();

if (! file_exists($TMP_DIR_HARVEST)) {
	if (! mkdir($TMP_DIR_HARVEST, 0775, true)) {
			throw new ErrorException("Unable to create ${TMP_DIR_HARVEST}");
	}
}
$TMP_DIR_FAIL= sys_get_temp_dir() . "/fail/" . getAWSBucketName() . DIRECTORY_SEPARATOR . getDigitalCommonsSeriesName();
if (! file_exists($TMP_DIR_FAIL)) {
	if (! mkdir ($TMP_DIR_FAIL, 0775,  true)) {
		throw new ErrorException("Unable to create ${$TMP_DIR_FAIL}");
	}
}


$master_catalog_doc= sys_get_temp_dir() . DIRECTORY_SEPARATOR . getAWSBucketName() . "/basex_catalog.xml";
if ( file_exists($master_catalog_doc) ) {
	unlink($master_catalog_doc);
}
$xw = new XMLWriter();
$xw->openMemory();
$xw->setIndent(true);
$xw->setIndentString(' ');
$xw->startDocument('1.0', 'UTF-8');
$xw->startElement('catalog');


$failed_objectid = array();
foreach ($harvest_array_list as $line) {
	if ( !(empty($line)) && isset($line) && is_array($line) ) {
		foreach($line as $item) {
			logmsg(print_r($item, true));
			$key = $item['Key'];
			if ( isFilenameFilterGated($key)) {
				continue;
			}

			$file_name = pathinfo($key, PATHINFO_BASENAME);
			$object_dir =  pathinfo($key, PATHINFO_DIRNAME);
			$objectid = pathinfo($object_dir, PATHINFO_FILENAME);
			if (in_array($objectid, $failed_objectid)) {
				continue;
			}
			$full_object_path = $TMP_DIR_HARVEST . DIRECTORY_SEPARATOR . $objectid;
	
			if (! file_exists($full_object_path)) {
				pathinfo($full_object_path, PATHINFO_DIRNAME);
				if (! mkdir($full_object_path, 0775, true)) {
						throw new ErrorException("Unable fto create ${full_object_path}");
				}
			}
				
	
			$tmp_file = $full_object_path . DIRECTORY_SEPARATOR . $file_name;
			$tmp_file = html_entity_decode ($tmp_file);
			try {
				$result = $s3Client->getObject(array(
					'Bucket' => getAWSBucketName(),
					'Key'    => $key,
					'SaveAs' => $tmp_file
				));
				/*
				// if the result is good, then add it to an object SplObjectStorage
				$fileStorage = new SplObjectStorage();
	
				$file = new stdClass();
				$file->uri = $uri;
				$file->filename = $filename;
				$file->name = pathinfo($filename, PATHINFO_FILENAME);
			  */
			} catch (Exception $ex) {
				if (! file_exists("$TMP_DIR_FAIL/$objectid")) {
					rename($full_object_path, "$TMP_DIR_FAIL/$objectid");
				} else {
					recurse_copy($full_object_path, "$TMP_DIR_FAIL/$objectid");
					recurse_rmdir($full_object_path);
				}
				$failed_objectid[]=$objectid;
				logmsg($ex->getMessage());
				continue;
				
			}
			if ($file_name === 'metadata.xml') {
				file_put_contents($tmp_file,str_replace("/[\000-\007\010\013\014\016-\031]/","",file_get_contents($tmp_file)));
				$xw->startElement('doc');
				$xw->startAttribute('href');
				$xw->text($tmp_file);
				$xw->endAttribute();
				$xw->endElement(); // close doc
	
			}
		}
	} else {
		logmsg("Error: Failure: Line is empty or null or something " . print_r($line, true));
	}
}
$xw->endElement(); //close catalog
$xw->endDocument(); //close document

// now the really silly this is that there are failures that are 
// part of the xml writer document that should be removed
$xml = new DOMDocument( "1.0", "UTF-8" );
$xml->loadXML($xw->outputMemory());
$xpath = new DOMXPath($xml);
foreach ($failed_objectid as $failedid) {
	$nodes = $xpath->query("//doc[@*[contains(.,'/{$failedid}/')]]");
	print_r($nodes);
	if (isset($nodes) && $nodes->length > 0) {
		logmsg($print_r($nodes, true));
		$xml->removeChild($nodes);
	}

}

file_put_contents($master_catalog_doc,$xml->saveXML());