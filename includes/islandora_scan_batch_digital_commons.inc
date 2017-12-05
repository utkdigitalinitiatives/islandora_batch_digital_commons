<?php

/**
 * @file
 * A basic batch ingest implementation (preprocessor and object).
 *
 * Works much the same as the zip importer in the islandora_importer module,
 * or the old D6 zip batch ingester.
 */

/**
 * A class which is capable of getting the listing of a directory or zip file.
 *
 * Used as a basis for the book batch. Really, just adds a couple methods to
 * help out.
 */
class IslandoraScanBatchDigitalCommons extends IslandoraScanBatch
{

    // Change to FALSE if one wants to take control over hierarchical structures.
    // @todo Make zip scan respect this.
    public $recursiveScan = TRUE;
    protected $collection_item_namespace;
    private $collection_policy_xpath_str = '/islandora:collection_policy/islandora:content_models/islandora:content_model/@pid';
    private $MAX_SUBDIRECTORY_DEPTH_FOR_COLLECTIONS = 20;
    private $object_model_cache;

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
    public function __construct( $connection,  $object_model_cache, $parameters)
    {
        parent::__construct($connection, $parameters);
        // $this->root_pid = variable_get('islandora_repository_pid', 'islandora:root');
        $this->repository = $this->connection->repository;
        $this->object_model_cache = $object_model_cache;
        $this->collection_item_namespace = $parameters['namespace'];
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
        $method = "scan" . ucfirst($this->getTargetType());
        return $this->$method($this->getTarget() . "/" . $this->getDigitalCommonsSeriesName());
    }

    /**
     * Get the fullpath to the target directory in which a collection resides.
     */
    protected function getTarget()
    {
        return $this->parameters['target'];
    }

    /**
     * Get the target collection name. appended to the target will
     * determine the full path of where to search for target resources
     *
     */
    protected function getCollectionPID()
    {
        return $this->parameters['collection_pid'];
    }

    /**
     * Get the target collection name. appended to the target will
     * determine the full path of where to search for target resources
     *
     */
    protected function getCollectionName()
    {
        return $this->parameters['collection_name'];
    }
    /**
     * Get the target collection name. appended to the target will
     * determine the full path of where to search for target resources
     *
     */
    protected function getDigitalCommonsSeriesName()
    {
        return $this->parameters['digital_commons_series_name'];
    }
    /**
     * Get the target collection namespace.
     *
     */
    protected function getCollectionNamespace()
    {
        return $this->parameters['collection_namespace'];
    }

    /**
     * Get the type of the target resource.
     *
     * Prefixed with "scan_" to determine which method gets called to generate
     * the list of resource.
     */
    protected function getTargetType()
    {
        return $this->parameters['type'];
    }

    /**
     * Allow the pattern to be set differently.
     */
    protected static function getPattern()
    {
        return '/.*/';
    }

    /**
     * Scan the directory with file_scan_directory().
     */
    protected function scanDirectory($target)
    {

        $fileStorage = new SplObjectStorage();
        $target_path = drupal_realpath($target);
        $target_length = strlen($target_path) + 1;
        $directory_contents = file_scan_directory(
            $target_path, $this->getPattern(), array('recurse' => $this->recursiveScan)
        );
        foreach ($directory_contents as $uri => $value) {
            $fileStorage->attach($value);
        }
        return $fileStorage;
    }

    /**
     * Generate output analogous to file_scan_directory().
     */
    protected function scanZip($target)
    {
        throw Exception("scanZip Does no work with islandora_scan_batch_digital_commons");
    }

    /**
     * Group file entries logically, to pass off to the import object.
     *
     * The data structure will organize objects into collections.
     * [collection_name_key][object_name_key] = object_info
     * The object_info is an object with the following properties:
     * objectId (the key and potential object identifier for the object)
     * namespace (the namespace to be assigned for the pid of the object)
     * collection (the pid of the collection object)
     * collection_relationship_pred
     * collection_relationship_uri
     * fileArray The files that will eventually turn into datastreams.
     *
     *
     * @param array $files
     *   An array, as returned by file_scan_directory().
     *
     */
    function groupFiles(SplObjectStorage $fileStorage)
    {
        $grouped = array();
        $digitalCommonsMetadata = null;
        $digitalCommonsMetadataFileObject = null;
        $digitalCommonsMetadataObjectInfo = null;
        // turns out we need a file we know will be at the same level as an object id, metadata.xml
        // should reside in the object directory
        // with all parent directories becoming the DigitalCommonsObjectId
        foreach ($fileStorage as $storedFile) {
            if ($storedFile->filename == "metadata.xml") {
                $digitalCommonsMetadata = $storedFile;
                break;
            }
        }
        if (isset($digitalCommonsMetadata)) {
            $digitalCommonsMetadataFileObject = $this->buildFileObject($digitalCommonsMetadata);
            if (isset($digitalCommonsMetadataFileObject)) {
                $digitalCommonsMetadataObjectInfo = $this->buildObjectInfo($digitalCommonsMetadataFileObject);
            }
            $grouped[$digitalCommonsMetadataFileObject->getDigitalCommonsObjectId()] = $digitalCommonsMetadataObjectInfo;
        } else {
            $debug = var_export($fileStorage, true);
            throw new Exception("File metadata.xml could not be found\n{$debug}\n"  );
        }
        $fileStorage->detach($digitalCommonsMetadata);

        foreach ($fileStorage as $storedFile) {
            $file_object = $this->buildFileObject($storedFile, $digitalCommonsMetadataObjectInfo);
            if (isset($grouped[$file_object->getDigitalCommonsObjectId()])) {
                $grouped[$file_object->getDigitalCommonsObjectId()]->addFileArray($file_object);
            }
        }

        return $grouped;
    }

    protected function buildObjectInfo($file_object)
    {
        $object_info = new DigitalCommonsObjectInfo();
        // The collection PID to which the object belongs, is
        // passed in as a parameter to IslandoraScanBatchDigitalCommons
        $object_info->setCollection($this->getCollectionPID());
        $object_info->setDigitalCommonsSeries($this->getDigitalCommonsSeriesName());
        if (isset($this->parameters['collection_relationship_pred'])) {
            $object_info->setCollectionRelationshipPred($this->parameters['collection_relationship_pred']);
        }
        if (isset($this->parameters['collection_relationship_uri'])) {
            $object_info->setCollectionRelationshipUri($this->parameters['collection_relationship_uri']);
        }
        $object_info->setNamespace($this->collection_item_namespace);


        $object_info->setDigitalCommonsObjectId($file_object->getDigitalCommonsObjectId());

        $object_info->addFileArray($file_object);
        return $object_info;
    }

    protected function buildFileObject($file, DigitalCommonsObjectInfo $digitalCommonsMetadataObjectInfo = null)
    {
        // counter for ascending the path towards the collection level directory
        $i = 0;
        $file_object = new DigitalCommonsFileInfo();
        $file_object->setUri($file->uri);
        $file_object->setFilename($file->filename);
        $file_object->setName($file->name);
        $file_object->setExt(pathinfo($file->filename, PATHINFO_EXTENSION));
        $file_object->setObjectDirectory(pathinfo($file_object->getUri(), PATHINFO_DIRNAME));
        if (isset($digitalCommonsMetadataObjectInfo)) {
            $file_object->setDigitalCommonsObjectId($digitalCommonsMetadataObjectInfo->getDigitalCommonsObjectId());
        } else {
            // Each file_object represents a Fedora DataStream
            // The collection directory of each file(DS) indicates an ObjectID in Digital Commons

            $file_object->setDigitalCommonsObjectId(pathinfo($file_object->getObjectDirectory(), PATHINFO_FILENAME));

            //Many times the Digital Commons Series directory is the parent of the DigitalCommonsObjectId directory
            //However, on occassion the Series directory is several levels up.
            $digitalCommonsSeriesDirectory = pathinfo($file_object->getObjectDirectory(), PATHINFO_DIRNAME);
            $digitalCommonsSeriesName = pathinfo($digitalCommonsSeriesDirectory, PATHINFO_FILENAME);


            // The collection name may be several directories above the directory
            // representing the object id
            // The ObjectID grouping all the datastreams needs to be unique
            //  for the collection.
            //
            // Concatentating all the subjectories together along with the
            // object id will make the object id unique.
            // For example, the name of the collection may be journalx
            // but underneath the directory journalx may be
            // vol1/iss1/1 where 1 is an object id, but the object id
            // is not yet unique for the collection journalx
            // by creating an object id of vol1.iss1.1, then we are
            // able to maintain a unique key for the identity of the
            // object located in vol1/iss1/1
            while ($digitalCommonsSeriesName !== $this->getDigitalCommonsSeriesName()) {
                $file_object->setDigitalCommonsObjectId($digitalCommonsSeriesName . "." . $file_object->getDigitalCommonsObjectId());
                // do not want to have an infinite loop attempting to build a unique object id
                if ($i >= DIGITAL_COMMONS_MAX_SUBDIRECTORY_DEPTH_FOR_COLLECTIONS || $digitalCommonsSeriesDirectory === "/") {
                    throw new Exception("target collection directory " . $this->getTarget() . " invalid for Digital Commons Dump");
                }
                $digitalCommonsSeriesDirectory = pathinfo($digitalCommonsSeriesDirectory, PATHINFO_DIRNAME);
                $digitalCommonsSeriesName = pathinfo($digitalCommonsSeriesDirectory, PATHINFO_FILENAME);
                ++$i;
            }
        }
        return $file_object;
    }

    /**
     * Get the name of the class to instantiate for the batch operations.
     */
    protected static function getObjectClass()
    {
        return "IslandoraScanBatchObjectDigitalCommons";
    }

    /**
     * Perform preprocessing of the scanned resources.
     */
    public function preprocess()
    {
        $fileStorage = $this->scan();

        $added = array();
        if ($fileStorage->count() > 0) {
            $grouped = $this->groupFiles($fileStorage);

            $added = array_merge($added, $this->preprocessCollectionLevel($grouped));
        }

        return $added;
    }

    protected function preprocessCollectionLevel($collection_array)
    {
        try {
            $added = array();
            // get the collecton's content_models and add them to the parameters.
            $fedora_object = $this->connection->repository->getObject($this->getCollectionPID());
            if (!$fedora_object) {
                throw Exception($this->getCollectionPID() . " is not found. Can not proceed to ingest collection!");
            }

            $content_models = $this->getContentModelArray($this->getCollectionPID());

            foreach ($collection_array as $object_id => $object_info) {
                $object_info->setContentModels($content_models);
                $added = array_merge($added, $this->preprocessItemLevel($object_id, $object_info));
            }
        } catch (Exception $e) {
            \drupal_set_message(t('Error ingesting Islandora collection %t : %e.', array('%t' => $this->getCollectionPID(), '%e' => $e->getMessage())), 'error');
            \watchdog('islandora_scan_batch_ditigal_commons', 'Error ingesting Islandora collection %t : %e.', array('%t' => $this->getCollectionPID(), '%e' => $e->getMessage()), WATCHDOG_ERROR);
        }
        return $added;
    }

    protected function preprocessItemLevel($object_id, $object_info)
    {
        $object_class = static::getObjectClass();
        $added = array();
        // second level is grouped by digital commons object_name (typically 001, 002, etc)
        $ingest_object = new $object_class($this->connection, $object_id, $object_info);
        // XXX: Might be better to have this actually handled as another
        // "preprocessor", so arbitrary "tree" structures might be built?
        $added = array_merge($added, $this->preprocessChildren($ingest_object));
        return $added;
    }

    /*
     * retrieve all the content models that may be applied to this object
     * as specified by the collection_policy of the containing collection
     *
     * Each content model describes the valid Datastream IDs that may be
     * added to an object conforming to the content model.
     *
     * The associative array returned will have each content Model pid
     * as the key, and the Datastreams id as an array of values
     */
    protected function getContentModelArray($fedora_object_id)
    {
        $content_models = array();

        //$this->connection->repository->api->a->getDatastreamDissemination($this->parent->id, $this->id,null, null)
        $collectionPolicyXml = $this->connection->repository->api->a->getDatastreamDissemination($fedora_object_id, 'COLLECTION_POLICY', null, null);
        $collection_policy_dom = new DOMDocument();
        $collection_policy_dom->loadXml($collectionPolicyXml);
        $collection_policy_xpath = new DOMXPath($collection_policy_dom);

        $collection_policy_xpath->registerNamespace('islandora', "http://www.islandora.ca");

        $content_model_pid_nodes = $collection_policy_xpath->evaluate($this->collection_policy_xpath_str);

        foreach ($content_model_pid_nodes as $content_model_pid_node) {
            $content_model_pid = $content_model_pid_node->nodeValue;

            $dsids = $this->object_model_cache->getObjectModelDSIDS($content_model_pid);

            // The AUDIT Datastream can not really be added, so it can't really be
            // missing.
            unset($dsids['AUDIT']);
            $content_models[$content_model_pid] = $dsids;
        }
        return $content_models;
    }
  /**
   * Recursively attempt to preprocess children.
   */
  protected function preprocessChildren($object, $parent = NULL) {
    $to_return = array();

    // XXX: Squash exceptions and log 'em.
    try {
      $this->addToDatabase($object, $object->getResources(), $parent);
      $to_return[] = $object;

      foreach ($object->getChildren($this->connection) as $child) {
        $to_return = array_merge($to_return, $this->preprocessChildren($child, $object->id));
      }
    }
    catch (Exception $e) {
      watchdog_exception('islandora_scan_batch_digital_commons', $e);
    }

    return $to_return;
  }
}

class IslandoraScanBatchObjectDigitalCommons extends IslandoraScanBatchObject
{

    private $CORRESPONDENCE_DSID = "MESSAGES";
    private $CORRESPONDENCE_DSTYPE = "M";
    private $CORRESPONDENCE_MIMETYPE = "text/plain";
    private $CORRESPONDENCE_LABEL = "messages.txt";
    private $CACHE_EXPIRY_SECONDS = 120;

    // need to create an __autoload function (?)
    protected $baseName = null;
    // DigitalCommonObjectInfo
    protected $objectInfo = null;
    // Keys in objectInfo that are not to be datastreams.
    // Path to MODS->DC XSLT.
    public $modsToDcTransform = null;
    public static $BATCH_OBJECT_PREFIX = "IslandoraScanBatchObjectDigitalCommons";

    /**
     * Constructor for the IslandoraScanBatchObject.
     */
    public function __construct($connection, $base_name, $object_info)
    {
        parent::__construct($connection, $base_name, null, null);
        $this->modsToDcTransform = drupal_get_path('module', 'islandora_batch') . '/transforms/mods_to_dc.xsl';
        $this->baseName = $base_name;
        $this->objectInfo = $object_info;
        $this->resources = array();
    }

    private function initializeObjectInfo() {
        $this->objectProfile['objLabel'] = substr($this->getModsTitle(), 0, 254);
        // not all objects will be active, some may be in progress to publication.
        $this->objState = "A";
        $this->state = "A"; // Active, not all will be active, must determine state from metadata.xml
        $this->objectProfile['objState'] = "A";
        $this->objectProfile['objOwnerId'] = 'admin';
    }
    /**
     * Function batch_process.
     */
    public function batchProcess()
    {
        try {
            echo "Batch Process begins with the ID " . $this->id . "\n";
            // Use object_info to create some datastreams.
            $this->addDigitalCommonsXMLDatastream();
            $this->addModsDatastream();
            $this->addDCDatastream();
            $this->initializeObjectInfo();

            $this->correlateModelDatastreams();

            $this->addRelationships();
            print date(DATE_ATOM) . 
                " Batch Process Completed. Series name: " . $this->objectInfo->getDigitalCommonsSeries() . 
                " - DigitalCommonsObjectId: " . $this->objectInfo->getDigitalCommonsObjectId() . "\n";

            $key =  IslandoraScanBatchObjectDigitalCommons::$BATCH_OBJECT_PREFIX . $this->id;
            $expire_datetime = time() + $this->CACHE_EXPIRY_SECONDS;
            cache_set($key, $this, 'cache_field', $expire_datetime);
            return ISLANDORA_BATCH_STATE__DONE;
        } catch (Exception $e) {
            $message = t(date(DATE_ATOM) ." Series name: " . $this->objectInfo->getDigitalCommonsSeries() . " - DigitalCommonsObjectId: " . $this->objectInfo->getDigitalCommonsObjectId() . " - " . $e->getMessage());
            \drupal_set_message($message, 'error');
            \watchdog($message, WATCHDOG_ERROR);
            \watchdog('islandora_scan_batch_digital_commons', $message, null, WATCHDOG_ERROR);

            return ISLANDORA_BATCH_STATE__ERROR;
        }
    }

    /**
     * will scan the list of files for any that comform to the
     * supplementary file pattern \d+-xxx.xx .
     *
     * Each file has a corresponding record in both the metadata.xml
     * and the MODS.xml The MODS.xml provides the Datastram id
     *
     * Note that not all supplementary files are represented in the MODS.xml
     *
     */
    protected function addSupplementalFiles()
    {
        $modsSupplementaryFileMap = $this->getModsSupplementalFileMap();
        $filesystemSupplementalFiles = array();
        // use this index for tracking the prefix integer that is 
        // attached to begining of any DS name
        $suppl_index = 0;

        $this->addSupplementalModsFiles($modsSupplementaryFileMap);
    }

    /*
     * iterate through all the files that conform to the pattern
     * for supplemental files
     * Add them as datastreams
     */
    protected function addSupplementalModsFiles($modsSupplementaryFileMap)
    {
        // get a list of files that conform to the supplementary file pattern
        // that have been found in the zip/filesystem path
        foreach ($this->objectInfo->getFileArray() as $file_info) {
            // supplemental files will begin with digits
            // these digits need to be stripped off in order to conform
            // to the files listed in the mods record
            $supplFileInfoMatch = array();

            if (preg_match("/^\d+\-(.+)$/", $file_info->getFilename(), $supplFileInfoMatch) && !$file_info->isProcessed()) {
                $canonicalSupplFileName = $supplFileInfoMatch[1];
                if (! isset($modsSupplementaryFileMap[$canonicalSupplFileName]) && isset($modsSupplementaryFileMap[$file_info->getFilename()])) {
                    $canonicalSupplFileName =$file_info->getFilename();
                }
                if (isset($modsSupplementaryFileMap[$canonicalSupplFileName])) {
                    // add the stream and mark it as done, increment suppl_index to a higher value
                    // if the next suppl is higher than the current suppl_index
                    $dsName = $modsSupplementaryFileMap[$canonicalSupplFileName]->ds_name;
                    $dsMimetype = $modsSupplementaryFileMap[$canonicalSupplFileName]->mime_type;
                    $this->addNewDatastreamFileInfo($dsName, $dsMimetype, $canonicalSupplFileName, $file_info);
                }
            }
        }
    }

    /*
     * parse the Mods file for information about Supplementary Files
     * The results should be an Associative Array or Map
     * of key value pairs. 
     * The key will be the mods titleInfo/title of the relatedItem 
     * while the value will be an anonymous object encapsulating
     * the MimeTYPE and and Datastream ID for the file
     * $[title] => {[ds_name] =>,
                   [mime_type] =>,}
     *
     */
    protected function getModsSupplementalFileMap()
    {
        $modsSupplementalFiles = array();
        $missingDSNameSupplimentalFiles = array();
        $mods_dom = $this->getModsDom();
        $mods_xpath = new DOMXPath($mods_dom);
        $mods_xpath->registerNamespace('m', 'http://www.loc.gov/mods/v3');
        $mods_related_items = $mods_xpath->query("/m:mods/m:relatedItem[@type='constituent']");
        foreach ($mods_related_items as $related_item) {
            $related_item_title_info_list = $related_item->getElementsByTagName('titleInfo');
            if ($related_item_title_info_list->length != 1) {
                $this->printWarningMessage( "mods xml has more than one titleInfo for a related item");
                continue;
            }
            $related_item_title_info_node = $related_item_title_info_list->item(0);
            $related_item_title_list = $related_item_title_info_node->getElementsByTagName('title');
            if ($related_item_title_list->length != 1) {

                $this->printWarningMessage("The mods xml has more than one title for a related item");
                continue;
            }
            $title_node_node = $related_item_title_list->item(0);
            $item_title = $title_node_node->textContent;

            $physical_description_list = $related_item->getElementsByTagName('physicalDescription');
            if ($physical_description_list->length != 1) {
                $this->printWarningMessage("The mods xml has more than one physicalDescription for a related item");
                continue;
            }
            $physical_description_node = $physical_description_list->item(0);
            $internet_media_type_list = $physical_description_node->getElementsByTagName('internetMediaType');
            if ($internet_media_type_list->length != 1) {
                $this->printWarningMessage("The mods xml has more than one internetMediaType for a related item");
                continue;
            }
            $internet_media_type_node = $internet_media_type_list->item(0);
            $item_mime_type = $internet_media_type_node->textContent;

            $item_ds_name = null;
            $note_list = $related_item->getElementsByTagName('note');
            foreach ($note_list as $note) {
                if ($note->hasAttribute('displayLabel') && $note->getAttribute('displayLabel') === 'supplemental_file') {
                    $item_ds_name = $note->textContent;
                }
            }
            
            if (isset($item_ds_name)) {
                $modsSupplementalFile = new stdClass();
                $modsSupplementalFile->ds_name = $item_ds_name;
                $modsSupplementalFile->mime_type = $item_mime_type;
                $modsSupplementalFiles[$item_title] = $modsSupplementalFile;
            } else {
                $modsSupplementalFile = new stdClass();
                $modsSupplementalFile->mime_type = $item_mime_type;
                $modsSupplementalFiles[$item_title] = $modsSupplementalFile;
                $missingDSNameSupplimentalFiles[] = $modsSupplementalFile;
            }
        }
        
        // find the max integer appended to the datastream name, then
        // start adding in SUPPL_X to reach missing missingDSNameSupplimentalFiles
        // increasing the digit as processed.
        return $modsSupplementalFiles;
    }
    /*
     * iterate through all the files that conform to the pattern
     * for supplemental files
     * Add them as datastreams
     */
    protected function addCorrespondence()
    {
        // get a list of files that conform to the supplementary file pattern
        // that have been found in the zip/filesystem path
        $messages = "";
        foreach ($this->objectInfo->getFileArray() as $file_info) {
            // supplemental files will begin with digits
            // these digits need to be stripped off in order to conform
            // to the files listed in the mods record
            $filename= $file_info->getFilename();

            if (preg_match("/^decision\-\d+\.txt$/", $file_info->getFilename()) && !$file_info->isProcessed()) {

                $correspondence = file_get_contents($file_info->getUri());
                $messages.= $this->constructEmail($correspondence);

            }
        }
        if (!empty($messages)) {

            $this->addNewDatastreamContent($this->CORRESPONDENCE_DSID, $this->CORRESPONDENCE_DSTYPE,
                $this->CORRESPONDENCE_MIMETYPE, $this->CORRESPONDENCE_LABEL, $messages);
        }

    }
    protected function retrieveFileInfoFromName($name)
    {
        $returnFileInfo = null;
        foreach ($this->objectInfo->getFileArray() as $file_info) {
            if ($file_info->getName() === $name) {
                $returnFileInfo = $file_info;
                break;
            }
        }
        return $returnFileInfo;
    }

    /**
     * Function to get the Digital Commons metadata document
     * it is always named metadata.xml.
     *
     */
    protected function addFullTextPDF()
    {

        $thesis_file_info = $this->retrieveFileInfoFromName('fulltext');
        if (!isset($thesis_file_info)) {
          $this->printWarningMessage("Does not have a fulltext file!");

        } else {
            if (!$thesis_file_info->isProcessed()) {
                $this->addNewDatastreamFileInfo('PDF', 'application/pdf', $this->objectInfo->getTitle(), $thesis_file_info);
            }
        }
    }

    protected function addNewDatastreamFileInfo($dsName, $dsMimetype, $dsLabel, $file_info)
    {
        $datastream = $this->constructDatastream($dsName, 'm');
        $datastream->mimetype = $dsMimetype;
        $datastream->label = $dsLabel;

        $datastream->checksumType = 'SHA-256';
        $datastream->state = "A";
        $datastream->setContentFromFile($file_info->getUri());
        if (!isset($datastream->content)) {
            $this->printWarningMessage($file_info->getUri() . " Unable to read content!");
        } else {
            $this->ingestDatastream($datastream);
            $file_info->setProcessed(TRUE);
        }
    }

    protected function addNewDatastreamContent($dsName, $dsType, $dsMimetype, $dsLabel, $xmlContent, $file_info = null)
    {
        $datastream = $this->constructDatastream($dsName, $dsType);
        $datastream->mimetype = $dsMimetype;
        $datastream->label = $dsLabel;

        $datastream->checksumType = 'SHA-256';
        $datastream->state = "A";
        $datastream->content = $xmlContent;

        $this->ingestDatastream($datastream);
        if (isset($file_info)) {
            $file_info->setProcessed(TRUE);
        }
    }

    /**
     * Function to get the Digital Commons metadata document
     * it is always named metadata.xml.
     * The function will also add the Digital Commons metadata as
     * a datastream to the object
     *
     */
    protected function addDigitalCommonsXMLDatastream()
    {
        $digital_commons_xml_file_info = $this->retrieveFileInfoFromName('metadata');


        if (!isset($digital_commons_xml_file_info)) {
            $this->printWarningMessage("Does not have a digital commons metadata file!");
        } else {
            $digital_commons_xml = file_get_contents($digital_commons_xml_file_info->getUri());
            $this->objectInfo->setDigitalCommonsMetadata($digital_commons_xml);
            $this->addNewDatastreamFileInfo('DIGITAL_COMMONS_MD', 'application/xml', 'digital_commons_metadata', $digital_commons_xml_file_info);
        }
    }

    protected function parseDigitalCommonsDocumentType()
    {
        $digital_commons_xml_file_info = $this->retrieveFileInfoFromName('metadata');
        if (!isset($digital_commons_xml_file_info)) {
            $this->printWarningMessage("Does not have a digital commons metadata file!");
        } else {
            $digital_commons_xml = file_get_contents($digital_commons_xml_file_info->getUri());
            $digital_commons_document_type = null;
            if ($digital_commons_xml) {
                $digital_commons_xml_dom = new DOMDocument();
                $digital_commons_xml_dom->loadXml($digital_commons_xml);
                $digital_commons_xml_xpath = new DOMXPath($digital_commons_xml_dom);
                $document_type_nodes = $digital_commons_xml_xpath->evaluate("/documents/document/document-type");

                foreach ($document_type_nodes as $document_type_node) {
                    $digital_commons_document_type = $document_type_node->nodeValue;
                }
            } else {
                $exception = $this->getFormattedException("Does not have a metadata.xml file!");
                throw $exception;
            }
            $this->objectInfo->setDigitalCommonsDocumentType($digital_commons_document_type);
        }
    }

    /**
     * Function to get the mods.
     *
     * for the digital commons batch upload to work correctly,
     * There must be a MODS record in the directory representing
     * the object to upload. The MODS record was transformed from
     * the metadata.xml file
     */
    protected function addModsDatastream()
    {
        $mods_file_info = $this->retrieveFileInfoFromName('MODS');
        if (!isset($mods_file_info)) {
            $this->printWarningMessage("Does not have a MODS metadata file!");
        } else {
            $mods_content = $this->getModsXML();

            $dsLabel = $this->getModsTitle();
            // extract the first five words, replaceing any non-word character with _
            // note that any non-ascii character will be listed as _, and that is unfortunate
            $dsLabel = $this->formatDatastreamLabel($dsLabel);
            $dsLabel = $dsLabel . "_MODS";
            $this->addNewDatastreamContent('MODS', 'm', "application/xml", $dsLabel, $mods_content, $mods_file_info);
        }
    }

    /**
     * Function to get the title of the mods.
     *
     * for the digital commons batch upload to work correctly,
     * There must be a MODS record in the directory representing
     * the object to upload. The MODS record was transformed from
     * the metadata.xml file
     */
    protected function getModsTitle()
    {
        $mods_title = $this->objectInfo->getTitle();
        if (!isset($mods_title)) {
            $mods_dom = $this->getModsDom();
            if (isset($mods_dom)) {

                $mods_xpath = new DOMXPath($mods_dom);
                $mods_xpath->registerNamespace('m', 'http://www.loc.gov/mods/v3');

                $mods_title = $mods_xpath->evaluate('string(//m:mods/m:titleInfo/m:title/text())');
                // Assign the label of the object based on the full title;
                $mods_title = strip_tags($mods_title);
                $this->objectInfo->setTitle($mods_title);

            } else {
                $this->objectInfo->setTitle("Descriptive Metadata Unavailable");

            }
        }

        return $this->objectInfo->getTitle();
    }

    /**
     * Function to get the Owner of the mods record.
     *
     * for the digital commons batch upload to work correctly,
     * There must be a MODS record in the directory representing
     * the object to upload. The MODS record was transformed from
     * the metadata.xml file
     */
    protected function getModsOwner()
    {
            $owner= "";
            $mods_dom = $this->getModsDom();
            if (isset($mods_dom)) {

                $mods_xpath = new DOMXPath($mods_dom);
                $mods_xpath->registerNamespace('m', 'http://www.loc.gov/mods/v3');

                $mods_givenName = $mods_xpath->evaluate('string(//m:mods/m:name/m:namePart[@type="given"]/text())');
                $mods_familyName = $mods_xpath->evaluate('string(//m:mods/m:name/m:namePart[@type="family"]/text())');
                // Assign the label of the object based on the full title;
                if (! empty($mods_givenName) && ! empty($mods_familyName)) {
                $owner = $mods_familyName . ", " . $mods_givenName;
                } else {
                    $owner = "Not Available";
                }
            } else {
                $owner = "Not Available";

            }


        return $owner;
    }

    /**
     * This associates the main file to the prime datastream of the Object Model being created.
     * It also may perform any Object Model specific processing.
     * Consider this the extention point in the code.
     * The function should be overwritten upon use by another institution other than
     * the Univserity of Tennessee. I assume that institutions may define or discover
     * their main datastream differently than we do, thus I have made the function public
     * so that it may be overwritten in a descendant.
     *
     * Currently all associations between prime datastream and Object Model
     * must be made in code logic. It would be nice to set up
     * a data structure that would list the ContentModels with the primary datastream
     * and a filename regex that could be used for discovering the file to use as the primary datastream.
     *
     * Copied the below from other islandora code bases
     * define('ISLANDORA_OAI_DOWLOAD_LINK_DEFAULT_CMODEL_LINK_MAPPINGS',
     * "
     * islandora:sp_large_image_cmodel => JP2
     * ir:thesisCModel => PDF
     * ir:citationCModel => PDF
     * islandora:bookCModel => PDF
     * islandora:newspaperIssueCModel => PDF
     * islandora:sp_pdf => OBJ
     * islandora:sp_videoCModel => MP4");
     * $default_dsids = <<<CMODELS

     * ir:citationCModel => PDF
     * ir:thesisCModel => PDF
     * islandora:sp_pdf => OBJ
     * islandora:sp_basic_image => OBJ
     * islandora:sp_large_image_cmodel => OBJ
     * islandora:binaryObjectCModel => OBJ
     * islandora:sp_videoCModel => MP4
     * islandora:sp-audioCModel => PROXY_MP3
     * islandora:collectionCModel => FALSE
     * islandora:compoundCModel => FALSE
     * islandora:newspaperIssueCModel => PDF
     * islandora:newspaperPageCModel => OBJ
     * islandora:sp_web_archive => OBJ
     * islandora:sp_disk_image => OBJ
     * islandora:pageCModel => OBJ
     * islandora:bookCModel => PDF
     * islandora:newspaperCModel => PDF
     * CMODELS;
     */
    public function correlateModelDatastreams() {

        $content_models = array_keys($this->objectInfo->getContentModels());
        foreach ($content_models as $content_model) {
            switch ($content_model) {
                case "ir:thesisCModel": {
                    $this->addFullTextPDF();
                    $this->addSupplementalFiles();
                    $this->addCorrespondence();
                }
                case "ir:citationCModel": {

                }
                case "islandora:sp_large_image_cmodel";
                /*
                    cModel is large image and the main dsid for delivery is JP2
                          The obj is typically a TIFF */
                case "islandora:bookCModel";
                case "islandora:compoundCModel";
                case "islandora:collectionCModel";
                case "islandora:sp-audioCModel";
                case "islandora:transformCModel";
                case "islandora:sp_videoCModel";
                default: {

                }
            }
        }
    }
    /**
     * @return mixed
     */
    public function getModsDom()
    {
        $modsDOM = $this->objectInfo->getModsDOM();
        if (!isset($modsDOM)) {
            $modsXML = $this->getModsXML();
            $modsDOM = new DOMDocument();
            $modsDOM->loadXML($modsXML);
            $this->objectInfo->setModsDOM($modsDOM);
        }
        return $modsDOM;
    }
    /**
     * Function to get the mods.
     *
     * for the digital commons batch upload to work correctly,
     * There must be a MODS record in the directory representing
     * the object to upload. The MODS record was transformed from
     * the metadata.xml file
     */
    protected function getModsXML()
    {
        $mods_xml = $this->objectInfo->getModsXml();
        if (!isset($mods_xml)) {
            $mods_file_info = $this->retrieveFileInfoFromName('MODS');
            if (isset($mods_file_info)) {
                $mods_xml = file_get_contents($mods_file_info->getUri());
            } else {
                $mods_xml = <<<EOXML
<mods xmlns:mods="http://www.loc.gov/mods/v3" xmlns="http://www.loc.gov/mods/v3">
  <titleInfo>
    <title>Title Unavailable</title>
  </titleInfo>
</mods>
EOXML;
            }
            $this->objectInfo->setModsXml($mods_xml);

        }
        return $this->objectInfo->getModsXml();
    }
    protected function formatDatastreamLabel($dsLabel) {
        // extract the first five words, replaceing any non-word character with _
        // note that any non-ascii character will be listed as _, and that is unfortunate
        $dsLabel = strip_tags($dsLabel);
        $dsLabel = array_slice(preg_split('/[\W]+/', $dsLabel, 6), 0, 5);
        $dsLabel = trim(join("_", $dsLabel), "_");
        return $dsLabel;
    }
    /**
     * Function to get dc.
     */
    protected function addDCDatastream()
    {
        if (!isset($this['DC'])) {
            $ingest_dc = FALSE;
            // Get the DC by transforming from MODS.
            // XXX: Might want to make this use a file, instead of staging the DC
            // in the database table (inside the object we serialize).
            $mods_content = $this->getModsXML();
            if ($mods_content) {
                $new_dc = static::runXslTransform(
                    array(
                        'xsl' => $this->modsToDcTransform,
                        'input' => $mods_content,
                    )
                );
            }

            if (isset($new_dc)) {
                $dsLabel = $this->getModsTitle();
                // extract the first five words, replaceing any non-word character with _
                // note that any non-ascii character will be listed as _, and that is unfortunate
                $dsLabel = $this->formatDatastreamLabel($dsLabel);
                $dsLabel = $dsLabel . "_DC";
                $this->addNewDatastreamContent('DC', 'x', 'application/xml', $dsLabel, $new_dc);
            }
        }

        return isset($this['DC']) ? $this['DC']->content : FALSE;
    }

    /**
     * Add collection and content model relationships.
     */
    public function addRelationships()
    {
        $collection = $this->objectInfo->getCollection();
        $predicate = $this->objectInfo->getCollectionRelationshipPred();
        $uri = $this->objectInfo->getCollectionRelationshipUri();

        $this->relationships->add($uri, $predicate, $collection);
        $this->addContentModelRelationships();

    }

    /**
     * Add inheritXacmlFrom relationship.
     */
    protected function inheritXacmlPolicies()
    {
        if (module_exists('islandora_xacml_editor')) {
            $collection = $this->objectInfo->getCollection();
            $collection_object = islandora_object_load($collection);
            if ($collection_object) {
                islandora_xacml_editor_apply_parent_policy($this, $collection_object);
            }
        }
    }

    /**
     * Add the content model relationship(s).
     * Each object ingested should only have a single model applied
     * How do we determine which the the correct content model to apply
     * from the list of content models that the parent collection
     * supports?
     */
    protected function addContentModelRelationships() {
        $content_models = array_keys($this->objectInfo->getContentModels());
        // remove the collectionCModel, and maybe some othere?
        $this->models = array_diff($content_models, array('islandora:collectionCModel'));
    }
    protected function constructEmail($correspondence) {
        $ownermail = $this->getModsOwner();
        $contructedEmail = "";
        $contructedEmail.= "-------------------------------------------------\n";
        $contructedEmail.= "FROM: Thesis Manager\n";
        $contructedEmail.= "TO: $ownermail\n";
        $contructedEmail.= "SUBJECT: Message from the Thesis Manager\n";
        $contructedEmail.= "$correspondence \n";
        return $contructedEmail;
    }
    protected function getFormattedException($comment ) {
        return new Exception("Series name: " . $this->objectInfo->getDigitalCommonsSeries() . " - DigitalCommonsObjectId: " . $this->objectInfo->getDigitalCommonsObjectId() . " - " .$comment);

    }
    
    protected function printWarningMessage($comment ) {
        $message = t(date(DATE_ATOM) ." Series name: " . $this->objectInfo->getDigitalCommonsSeries() . " - DigitalCommonsObjectId: " . $this->objectInfo->getDigitalCommonsObjectId() . " - " . $comment);
        \drupal_set_message($message, 'warning');
        \watchdog('islandora_scan_batch_ditigal_commons', $message, null, WATCHDOG_WARNING);
    }

    /*
     * correlate the content model to datastreams found on the object that should be embargoed
    */
    public function correlateModelEmbargoDatastreams() {
        $embargo_datastreams = array();
        $content_models = array_keys($this->objectInfo->getContentModels());
        foreach ($content_models as $content_model) {
            switch ($content_model) {
                case "ir:thesisCModel": {
                    $datastream_names = array_keys($this->datastreams);
                    foreach ($datastream_names as $datastream_name) {
                        if (preg_match("/^(PDF)|(FULLTEXT)|(BIN)|(SUPPL_\d+)$/", $datastream_name)) {
                            $embargo_datastreams[] = $datastream_name;
                        }
                    }
                }
                case "ir:citationCModel": {

                }
                case "islandora:sp_large_image_cmodel";
                    /*
                        cModel is large image and the main dsid for delivery is JP2
                              The obj is typically a TIFF */
                case "islandora:bookCModel";
                case "islandora:compoundCModel";
                case "islandora:collectionCModel";
                case "islandora:sp-audioCModel";
                case "islandora:transformCModel";
                case "islandora:sp_videoCModel";
                default: {

                }
            }
        }
        return $embargo_datastreams;
    }
    /**
     * @return null
     */
    public function getObjectInfo()
    {
        return $this->objectInfo;
    }

    /**
     * @return null
     */
    public function getMods()
    {
        return $this->mods;
    }
}

spl_autoload_register(function ($name) {
    echo "Want to load $name.\n";
});
