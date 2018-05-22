<?php
/**
 * Created by PhpStorm.
 * User: rwaltz
 * Date: 1/23/18
 * Time: 1:36 PM
 */
//This XML module is not provided on a default php (v7) install on Debian GNU/Linux
// sudo apt-get install php-xml

// This module is based on version 2 of the AWS SDK

// As drupal administrator, go to the modules in the administrative toolbar
// click on the install new module button
// you can install from an url provided you use the following (or something similar)
// https://ftp.drupal.org/files/projects/awssdk-7.x-5.4.zip

// Once it is installed (I had to change permissions on the underlying drupal directory for some reason?
// it may be activated and then configured

// If you do not want to save the aws_key and aws_secret in the gui then you may
// place those settings in the php site settings.php file
//
// Place these settings in the site's settings.php file located in
// $DRUPAL_HOME/sites/default/settings.php
// $conf['aws_key'] = '...';
// $conf['aws_secret'] = '...';
// $conf['aws_account_id'] = '...';
// $conf['aws_canonical_id'] = '...';

//

// Include the SDK using the Composer autoloader
require '/usr/local/share/php5/php-aws/aws-autoloader.php';

use Aws\Credentials\CredentialProvider;
use Aws\S3\S3Client;

module_load_include('php', 'islandora_batch_digital_commons', 'includes/DigitalCommonsFedoraObjectModelCache');
module_load_include('php', 'islandora_batch_digital_commons', 'includes/DigitalCommonsFileInfo');
module_load_include('php', 'islandora_batch_digital_commons', 'includes/DigitalCommonsObjectInfo');
module_load_include('php', 'islandora_batch_digital_commons', 'includes/DigitalCommonsScanBatchAWS');
module_load_include('php', 'islandora_batch_digital_commons', 'includes/DigitalCommonsScanBatchBase');
module_load_include('php', 'islandora_batch_digital_commons', 'includes/DigitalCommonsScanBatchFactory');
module_load_include('php', 'islandora_batch_digital_commons', 'includes/DigitalCommonsScanBatchObject');
module_load_include('php', 'islandora_batch_digital_commons', 'includes/DigitalCommonsTransformBaseX');
module_load_include('inc', 'islandora_batch_digital_commons', 'includes/ingest_digital_commons.batch');
module_load_include('inc', 'islandora_batch', 'includes/islandora_scan_batch');
module_load_include('inc', 'islandora_batch', 'includes/islandora_batch_object_base');
module_load_include('inc', 'islandora_batch', 'includes/ingest.batch');

class DigitalCommonsScanBatchAWS extends DigitalCommonsScanBatchBase
{

    // Change to FALSE if one wants to take control over hierarchical structures.
    // @todo Make zip scan respect this.
    public $recursiveScan = TRUE;
    protected $collection_item_namespace;
    private $collection_policy_xpath_str = '/islandora:collection_policy/islandora:content_models/islandora:content_model/@pid';
    private $MAX_SUBDIRECTORY_DEPTH_FOR_COLLECTIONS = 20;
    private $object_model_cache = null;
    private $s3Client = null;
    private $batch_index;
    protected $tmp_scan_directory = null;
    protected $tmp_harvest_directory = null;
    protected $tmp_failed_directory = null;
    protected $persist_listobjects_filepath;
    protected $failed_object_list = array();
    protected $downloaded_object_list = array();

    protected $basex_catalog_name = null;

    protected $master_catalog_fullpath = null;
    protected $digitalCommonsTransformBaseX = null;


    /**
     * Constructor must be able to receive an associative array of parameters.
     *
     * @param array $parameters
     *   An associative array of parameters for the batch process. These will
     *   probably just be the result of a simple transformation from the
     *   command line, or something which could have been constructed from a
     *   form.
     *   Available parameters are from the particular concrete implementation.
     */
    public function __construct($connection, $object_model_cache, $parameters)
    {
        // pass this off to the ancestors for processing
        parent::__construct($connection, $object_model_cache, $parameters);
        // $this->root_pid = variable_get('islandora_repository_pid', 'islandora:root');
        $this->repository = $this->connection->repository;
        $this->object_model_cache = $object_model_cache;
        $this->collection_item_namespace = $parameters['namespace'];
        // Credential provider helps AWS S3 to authenticate
        $provider = CredentialProvider::ini();
// Cache the results in a memoize function to avoid loading and parsing
// the ini file on every API operation.

// the provider file is in the default location,
// ~/.aws/credentials
        $provider = CredentialProvider::memoize($provider);

        // create a reusable client for this object based on the provider property
        $this->s3Client = new Aws\S3\S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => $provider
        ]);
        if (!isset($parameters['basex_bepress_mods_transform_name'])) {
            throw new Exception("basex_bepress_mods_transform_name is not set in dsv file");
        }
        if (isset($parameters['$transform_uri'])) {
            $this->setDigitalCommonsTransformBaseX(new DigitalCommonsTransformBaseX($parameters['basex_bepress_mods_transform_name'], $parameters['$transform_uri']));
        } else {
            $this->setDigitalCommonsTransformBaseX(new DigitalCommonsTransformBaseX($parameters['basex_bepress_mods_transform_name']));
        }
        if (isset($parameters['basex_catalog_name'])) {
            $this->setBasexCatalogName($parameters['basex_catalog_name']);
        } else {
            if (is_null($this->getDigitalCommonsSeriesName())) {
                throw new Exception("Digital Commons Series Name must be set\n");
            }
            $base_catalog_name = str_replace(DIRECTORY_SEPARATOR, "-", $this->getDigitalCommonsSeriesName()) . "-" . $this->generateRandomString(6) . ".xml";

            $this->setBasexCatalogName($base_catalog_name);
        }
    }

    /**
     * That's is correct, I have a private function to generate random strings. but I can't claim credit for writing it.
     *
     * function code stolen from
     * https://stackoverflow.com/questions/4356289/php-random-string-generator
     */
    private function generateRandomString($length = 6)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    /**
     * Get the name of the class to instantiate for the batch operations.
     *
     */
    protected static function getObjectClass()
    {
        return "DigitalCommonsScanBatchObject";
    }

    /**
     * Get a listing of "file-object"-like entries.
     *
     * @return array
     *   An associative array of stdClass objects representing files. Array keys
     *   are URIs relative to the "target", and the objects properties include:
     *   - uri: A string containing the complete URI of the resource.
     *   - filename: The filename.
     *   - name: The filename without its extension.
     */
    protected function scan()
    {
        return $this->harvestAWS();
    }


    /**
     * determine if the filename submitted as filepath conforms to a pattern recognized as not processable.
     *
     * @param $filepath
     * @return bool
     */
    public function canStopProcessingFilter($filepath)
    {


        // turns out we need a file we know will be at the same level as an object id, metadata.xml
        // should reside in the object directory
        // with all parent directories becoming the DigitalCommonsObjectId

        $filename = basename($filepath);
        $return = false;
        switch ($filename) {
            case ('stamped.pdf'):
                {
                    $return = true;
                    break;
                }

/*                
                // XXX this only works for citations and thesis, not binary objects!
            case (preg_match("/^\d+\-/", $filename) ? true : false) :
            case ('metadata.xml') :
            case (preg_match("/\.pdf$/", $filename) ? true : false) :
                break;
*/
            default:
                $return = false;
        }
        return $return;

    }


    /**
     * That's is correct, I have a private function to recursively copy directories. but I can't claim credit for writing it.
     *
     * function code stolen from
     * https://stackoverflow.com/questions/2050859/copy-entire-contents-of-a-directory-to-another-using-php
     * @param $src
     * @param $dst
     */
    function recurse_copy($src, $dst)
    {
        $dir = opendir($src);
        @mkdir($dst);
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                if (is_dir($src . DIRECTORY_SEPARATOR . $file)) {
                    recurse_copy($src . DIRECTORY_SEPARATOR . $file, $dst . DIRECTORY_SEPARATOR . $file);
                } else {
                    copy($src . DIRECTORY_SEPARATOR . $file, $dst . DIRECTORY_SEPARATOR . $file);
                }
            }
        }
        closedir($dir);
    }

    /**
     * That's is correct, I have a private function to recursively remove directories. but I can't claim credit for writing it.
     *
     * function code stolen from
     * https://stackoverflow.com/questions/2050859/copy-entire-contents-of-a-directory-to-another-using-php
     *
     * * @param $src
     */

    function recurse_rmdir($src)
    {
        $dir = opendir($src);
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                $full = $src . DIRECTORY_SEPARATOR . $file;
                if (is_dir($full)) {
                    recurse_rmdir($full);
                } else {
                    unlink($full);
                }
            }
        }
        closedir($dir);
        rmdir($src);
    }

    /**
     *
     * @return SplObjectStorage
     * @throws Exception
     */
    private function harvestAWS()
    {

        $directory_contents = $this->scan_aws_s3();


        $this->downloadAWSFiles($directory_contents);

        $this->create_basex_catalog();

        $this->digitalCommonsTransformBaseX->executeBaseXTransform($this->getMasterCatalogFullpath());
        $this->addGeneratedModsFiles($directory_contents);
        $downloaded_file_list = $this->getFlatDownloadedObjectList();

        $fileStorage = new SplObjectStorage();
        foreach ($downloaded_file_list as $value) {
            $this->logmsg("SplObjectStorage " . $value);
            if ( file_exists($value)) {
                $file = new stdClass();
                $file->uri = $value;
                $file->fullname = pathinfo($value, PATHINFO_BASENAME);
                $file->name = pathinfo($value, PATHINFO_FILENAME);
                $fileStorage->attach($file);
            }
        }
        return $fileStorage;
    }

    /**
     * retrieve from the AWS S3 server a list of filenames that will be downloaded for processing.
     * Each downloaded xml list is serialized and added to a temp file
     * The filenames are stored in a temporary file in the temporary filesystem
     * The temp file is then read and all the entries are grouped together by objectid
     *
     * before the
     *
     * @return array
     */
    function scan_aws_s3()
    {

        $s3Client = $this->getS3Client();
        $persist_listobjects_filepath = tempnam($this->getTmpScanDirectory(), "A" . $this->getSetId());
        $serialize_object_marker = "---XXX---";
        $errors = array();
        $harvest_list = array();
        $prefix = $this->getAWSFilterPath();
        if (isset($prefix)) {
            $prefix .= DIRECTORY_SEPARATOR .$this->getDigitalCommonsSeriesName();
        } else {
            $prefix = $this->getDigitalCommonsSeriesName();
        }
        $max_page_count = 1000;
        $delimiter = ';';
        $marker = null;
        $aws_params = null;

        try {
            do {
                if (isset($aws_params)) {
                    $aws_params['Marker'] = $marker;
                } else {
                    $aws_params = array('Bucket' => $this->getAWSBucketName(),
                        'Delimiter' => $delimiter,
                        'MaxKeys' => $max_page_count,
                        'Prefix' => $prefix);
                }
                $command = $s3Client->getCommand('ListObjects', $aws_params);
                // $command['MaxKeys'] = 100;
                $result = $s3Client->execute($command);
                $marker = $result->get('NextMarker');
                if ($result->get('Contents')) {
                    $serialized_harvest = serialize($result->get('Contents')) . $serialize_object_marker;
                    // where to put the temporary downloaded and serialized list?
                    file_put_contents($persist_listobjects_filepath, ($serialized_harvest), FILE_APPEND);
                }
            } while ($result->get('IsTruncated') && isset($marker));
            if ($result->get('IsTruncated')) {
                $errors[] = sprintf('The number of keys greater than %u, the first part is shown', count($harvest_list));
            }
        } catch (S3Exception $e) {
            $errors[] = sprintf('Cannot retrieve objects: %s', $e->getMessage());
        }
// where to pull the completed serialized list and deserialize it where?
        $file_contents = file_get_contents($persist_listobjects_filepath);
        $exploded_data_list = explode($serialize_object_marker, $file_contents);
        foreach ($exploded_data_list as $data) {
            if (isset($data)) {
                $unserialized_harvest = unserialize($data);
                $harvest_list[] = $unserialized_harvest;
            }
        }
        unlink($persist_listobjects_filepath);
        return $harvest_list;
    }

    public function create_basex_catalog()
    {

        $metadata_xml_writer = new XMLWriter();
        $metadata_xml_writer->openMemory();
        $metadata_xml_writer->setIndent(true);
        $metadata_xml_writer->setIndentString(' ');
        $metadata_xml_writer->startDocument('1.0', 'UTF-8');
        $metadata_xml_writer->startElement('catalog');
        $harvest_array_list = $this->getDownloadedObjectList();
        foreach ($harvest_array_list as $object_id => $file_list) {
            foreach ($file_list as $file_name) {

                if (basename($file_name, ".xml") === 'metadata') {
                    file_put_contents($file_name, str_replace("/[\000-\007\010\013\014\016-\031]/", "", file_get_contents($file_name)));
                    $metadata_xml_writer->startElement('doc');
                    $metadata_xml_writer->startAttribute('href');
                    $metadata_xml_writer->text($file_name);
                    $metadata_xml_writer->endAttribute();
                    $metadata_xml_writer->endElement(); // close doc

                }
            }
        }
        $metadata_xml_writer->endElement(); //close catalog
        $metadata_xml_writer->endDocument(); //close document

        $this->writeHarvestList($metadata_xml_writer);

    }


    public function writeHarvestList($xmlHarvestWriter)
    {
        // now the really silly this is that there are failures that are
        // part of the xml writer document that should be removed
        $xmlHarvestDOM = new DOMDocument("1.0", "UTF-8");
        $xmlHarvestDOM->loadXML($xmlHarvestWriter->outputMemory());
        $xpathHarvest = new DOMXPath($xmlHarvestDOM);

        // remove all the failed objects from writing to the output catalog file
        $failed_object_list = $this->getFailedObjectList();
        foreach ($failed_object_list as $failed_id) {

            $nodes = $xpathHarvest->query("//doc[@*[contains(.,'/{$failed_id}/')]]");

            if (isset($nodes) && $nodes->length > 0) {
                $xmlHarvestDOM->removeChild($nodes);
            }

            if ($this->isInDownloadedObjectList($failed_id)) {
                $this->removeFromDownloadedObjectList($failed_id);
            }
        }
        $this->setMasterCatalogFullpath(sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->getAWSBucketName() . DIRECTORY_SEPARATOR . $this->getBasexCatalogName());
        $master_catalog_fullpath = $this->getMasterCatalogFullpath();
        if (file_exists($master_catalog_fullpath)) {
            unlink($master_catalog_fullpath);
        }
        file_put_contents($master_catalog_fullpath, $xmlHarvestDOM->saveXML());

    }

    public function downloadAWSFiles($harvest_array_list)
    {

        $TMP_DIR_HARVEST = $this->getTmpScanDirectory() . DIRECTORY_SEPARATOR . $this->getDigitalCommonsSeriesName();
        $this->verifyCreateDirectory($TMP_DIR_HARVEST);
        $this->setTmpHarvestDirectory($TMP_DIR_HARVEST);

        $TMP_DIR_FAIL = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "fail" . DIRECTORY_SEPARATOR . $this->getAWSBucketName() . DIRECTORY_SEPARATOR . $this->getDigitalCommonsSeriesName();
        $this->verifyCreateDirectory($TMP_DIR_FAIL);
        $this->setTmpFailedDirectory($TMP_DIR_FAIL);

        $s3Client = $this->getS3Client();

        foreach ($harvest_array_list as $line) {
            if (!(empty($line)) && isset($line) && is_array($line)) {
                foreach ($line as $item) {
                    $key = $item['Key'];
                    // the last segment of the URI key is the full filename
                    $file_name = pathinfo($key, PATHINFO_BASENAME);
                    $object_id = $this->formatObjectId($key);


                    if (!(isset($object_id)) || $this->canStopProcessingFilter($key) || $this->isInFailedObjectList($object_id)) {
                        continue;
                    }

                    $full_object_path = $TMP_DIR_HARVEST . DIRECTORY_SEPARATOR . $object_id;

                    if (!file_exists($full_object_path)) {
                        $this->verifyCreateDirectory($full_object_path);
                    }

                    // Format the name of the file that will be downloaded and stored temporarily on
                    // the filesystem
                    $tmp_file = $full_object_path . DIRECTORY_SEPARATOR . $file_name;
                    $tmp_file = html_entity_decode($tmp_file);
                    $tmp_file = strip_tags($tmp_file);
                    $encoding = mb_detect_encoding($tmp_file);
                    if ($encoding === "UTF-8") {
                        $tmp_file = utf8_decode($tmp_file);
                    } else if ($encoding !== "ISO-8859-1") {
                        $tmp_file = mb_convert_encoding($tmp_file, 'ISO-8859-1');
                    }

                    try {
                        $result = $s3Client->getObject(array(
                            'Bucket' => $this->getAWSBucketName(),
                            'Key' => $key,
                            'SaveAs' => $tmp_file
                        ));
                        // this where the object should be added to an array that manages the downloaded items
                        $content_type = $result->get('ContentType') ;
                        if ( $content_type !== 'application/x-directory') {
                            $this->addDownloadedObjectList($object_id, $tmp_file);
                            $this->logmsg("DOWNLOADED: " . $tmp_file);
                            if ($file_name === 'metadata.xml') {
                              $file_contents = file_get_contents($tmp_file);
                              $file_contents = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $file_contents);
                              if (!file_put_contents($tmp_file, $file_contents)) {
                                throw new Exception("Unable to write " . $tmp_file . " after replacing control characters");
                              }
                            }
                        }
                    } catch (Exception $ex) {
                        if (!file_exists("$TMP_DIR_FAIL/$object_id")) {
                            rename($full_object_path, "$TMP_DIR_FAIL/$object_id");
                        } else {
                            $this->recurse_copy($full_object_path, "$TMP_DIR_FAIL/$object_id");
                            $this->recurse_rmdir($full_object_path);
                        }
                        $this->addFailedObjectList($object_id);
                        continue;

                    }

                }
            }

        }

    }

    private function formatObjectId($location) {
        $object_id = null;
        $prefix = $this->getAWSFilterPath();
        if (isset($prefix)) {
            $prefix .= DIRECTORY_SEPARATOR .$this->getDigitalCommonsSeriesName() . DIRECTORY_SEPARATOR;
        } else {
            $prefix = $this->getDigitalCommonsSeriesName() . DIRECTORY_SEPARATOR;
        }
        $object_dir = pathinfo($location, PATHINFO_DIRNAME);
        // the path that should be created for the object id may be a single subdirectory, or multiple
        // remove the prefix from the start of the path
        // give credit where credit is due: https://stackoverflow.com/questions/4517067/remove-a-string-from-the-beginning-of-a-string
        if (substr($object_dir, 0, strlen($prefix)) == $prefix) {
            $object_id = substr($object_dir, strlen($prefix));
        }
        return $object_id;
    }

    public function addGeneratedModsFiles($harvest_array_list)
    {

        $TMP_DIR_HARVEST = $this->getTmpHarvestDirectory();


        foreach ($harvest_array_list as $line) {
            if (!(empty($line)) && isset($line) && is_array($line)) {
                foreach ($line as $item) {
                    $key = $item['Key'];
                    $file_name = pathinfo($key, PATHINFO_BASENAME);
                    $object_id = $this->formatObjectId($key);


                    $full_object_path = $TMP_DIR_HARVEST . DIRECTORY_SEPARATOR . $object_id;

                    if ($file_name === 'metadata.xml') {
                        if (file_exists($full_object_path)) {
//                            $this->logmsg($full_object_path . DIRECTORY_SEPARATOR . "MODS.xml");
                            $mods_filepath = $full_object_path . DIRECTORY_SEPARATOR . "MODS.xml";
                            $this->addDownloadedObjectList($object_id, $mods_filepath);
                        } else {
                            throw new Exception("Unable to determine location of generated MODS.xml file");
                        }
                    }
                }
            }

        }

    }


    /**
     * Get the target collection name. appended to the target will
     * determine the full path of where to search for target resources
     * this is the AWS S3 bucket name
     */
    protected function getAWSBucketName()
    {
        return $this->parameters['aws_bucket_name'];
    }

    /**
     * Get the target collection name. appended to the target will
     * determine the full path of where to search for target resources
     * this is the AWS S3 bucket name
     */
    protected function getAWSFilterPath()
    {
        return $this->parameters['aws_filter_path'];
    }


    /**
     * Allow the pattern to be set differently.
     */
    protected static function getPattern()
    {
        return '/.*/';
    }

    /**
     * @return S3Client
     */
    public function getS3Client()
    {
        return $this->s3Client;
    }

    /**
     * @param S3Client $s3Client
     */
    public function setS3Client($s3Client)
    {
        $this->s3Client = $s3Client;
    }

    /**
     * sys_get_temp_dir() . DIRECTORY_SEPARATOR . getAWSBucketName()
     * @return mixed
     */
    public function getTmpScanDirectory()
    {
        if (isset($this->parameters['tmp_scan_directory'])) {
            return $this->parameters['tmp_scan_directory'];

        } else {
            return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->getAWSBucketName();
        }
    }

    /**
     *
     * @param mixed $tmp_scan_directory
     */
    public function setTmpScanDirectory($tmp_scan_directory)
    {
        $this->parameters['tmp_scan_directory'] = $tmp_scan_directory;
    }

    /**
     * sys_get_temp_dir() . DIRECTORY_SEPARATOR . getAWSBucketName() . DIRECTORY_SEPARATOR . getDigitalCommonsSeriesName();
     * @return null
     */
    public function getTmpHarvestDirectory()
    {
            return $this->getTmpScanDirectory() . DIRECTORY_SEPARATOR . $this->getDigitalCommonsSeriesName();
    }

    /**
     *
     * @param null $tmp_harvest_directory
     */
    public function setTmpHarvestDirectory($tmp_harvest_directory)
    {
        $this->parameters['tmp_harvest_directory'] = $tmp_harvest_directory;
    }

    /**
     * sys_get_temp_dir() . DIRECTORY_SEPARATOR . getAWSBucketName() . "/Fail"
     * @return null
     */
    public function getTmpFailedDirectory()
    {
        if (isset($this->parameters['tmp_failed_directory'])) {
            return $this->parameters['tmp_failed_directory'];

        } else {
            return $this->getTmpScanDirectory() . DIRECTORY_SEPARATOR . "/Fail";
        }
    }

    /**
     * @param null $tmp_failed_directory
     */
    public function setTmpFailedDirectory($tmp_failed_directory)
    {
        $this->parameters['tmp_failed_directory'] = $tmp_failed_directory;
    }

    public function isInFailedObjectList($pid)
    {
        return in_array($pid, $this->failed_object_list);
    }

    public function addFailedObjectList($pid)
    {
        $this->failed_object_list[] = $pid;
    }

    public function getFailedObjectList()
    {
        return $this->failed_object_list;
    }

    public function resetDownloadedObjectList()
    {
        return $this->downloaded_object_list = array();
    }

    public function isInDownloadedObjectList($pid)
    {
        return array_key_exists($pid, $this->downloaded_object_list);
    }

    public function removeFromDownloadedObjectList($pid)
    {
        unset($this->downloaded_object_list[$pid]);
    }

    /**
     * Download_object_list is an associative array with the pid as the key
     * in order to group all files under an object id
     *
     * @param $object_id
     * @param $filepath
     */
    public function addDownloadedObjectList($object_id, $filepath)
    {
        if ($this->isInDownloadedObjectList($object_id)) {
            $this->downloaded_object_list[$object_id][] = $filepath;
        } else {
            $this->downloaded_object_list[$object_id] = array($filepath);
        }
    }

    public function getDownloadedObjectList()
    {

        return $this->downloaded_object_list;

    }

    public function getFlatDownloadedObjectList()
    {
        $result = array();
        array_walk_recursive($this->downloaded_object_list, function ($v, $k) use (&$result) {
            $result[] = $v;
        });
        return $result;
    }


    public function verifyCreateDirectory($check_directory)
    {
        if (!file_exists($check_directory)) {
            if (!mkdir($check_directory, 0775, true)) {
                throw new ErrorException("Unable to create {$check_directory}");
            }
        }
    }

    /**
     * @return string
     */
    public function getBasexCatalogName()
    {
        return $this->basex_catalog_name;
    }

    /**
     * @param string $basex_catalog_name
     */
    public function setBasexCatalogName($basex_catalog_name)
    {
        $this->basex_catalog_name = $basex_catalog_name;
    }

    /**
     * @return mixed
     */
    public function getMasterCatalogFullpath()
    {
        return $this->master_catalog_fullpath;
    }

    /**
     * @param mixed $master_catalog_fullpath
     */
    public function setMasterCatalogFullpath($master_catalog_fullpath)
    {
        $this->master_catalog_fullpath = $master_catalog_fullpath;
    }

    /**
     * @return DigitalCommonsTransformBaseX|null
     */
    public function getDigitalCommonsTransformBaseX()
    {
        return $this->digitalCommonsTransformBaseX;
    }

    /**
     * @param DigitalCommonsTransformBaseX|null $digitalCommonsTransformBaseX
     */
    public function setDigitalCommonsTransformBaseX($digitalCommonsTransformBaseX)
    {
        $this->digitalCommonsTransformBaseX = $digitalCommonsTransformBaseX;
    }


}
