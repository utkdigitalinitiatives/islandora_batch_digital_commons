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
class IslandoraScanBatchDigitalCommons extends IslandoraScanBatch {

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
    public function __construct(IslandoraTuque $connection, IslandoraBatchFedoraObjectModelCache $object_model_cache,  array $parameters) {
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
    protected function scan() {
        $method = "scan" . ucfirst($this->getTargetType());
        return $this->$method($this->getTarget() . "/" . $this->getCollectionName());
    }

    /**
     * Get the fullpath to the target directory in which a collection resides.
     */
    protected function getTarget() {
        return $this->parameters['target'];
    }
    /**
     * Get the target collection name. appended to the target will
     * determine the full path of where to search for target resources
     * 
     */
    protected function getCollectionPID() {
        return $this->parameters['collection_pid'];
    }
    /**
     * Get the target collection name. appended to the target will
     * determine the full path of where to search for target resources
     * 
     */
    protected function getCollectionName() {
        return $this->parameters['collection_name'];
    }
    /**
     * Get the target collection namespace. 
     * 
     */
    protected function getCollectionNamespace() {
        return $this->parameters['collection_namespace'];
    }
    /**
     * Get the type of the target resource.
     *
     * Prefixed with "scan_" to determine which method gets called to generate
     * the list of resource.
     */
    protected function getTargetType() {
        return $this->parameters['type'];
    }

    /**
     * Allow the pattern to be set differently.
     */
    protected static function getPattern() {
        return '/.*/';
    }

    /**
     * Scan the directory with file_scan_directory().
     */
    protected function scanDirectory($target) {
        $files = array();
        $target_path = drupal_realpath($target);
        $target_length = strlen($target_path) + 1;
        $directory_contents = file_scan_directory(
            $target_path, $this->getPattern(), array('recurse' => $this->recursiveScan)
        );
        foreach ($directory_contents as $uri => $value) {
            $files[] = $value;
        }
        return $files;
    }

    /**
     * Generate output analogous to file_scan_directory().
     */
    protected function scanZip($target) {
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
    function groupFiles($files) {
        $grouped = array();

        foreach ($files as $file) {
            $file_object = $this->buildFileObject($file);
            if (isset($grouped[$file_object->getObjectId()])) {
                $grouped[$file_object->getObjectId()]->addFileArray($file_object);
            } else {
                $object_info = $this->buildObjectInfo($file_object);
                $grouped[$file_object->getObjectId()] = $object_info;
            }
        }

        return $grouped;
    }

    protected function buildObjectInfo($file_object) {
        $object_info = new DigitalCommonsObjectInfo();
        // The collection PID to which the object belongs, is
        // passed in as a parameter to IslandoraScanBatchDigitalCommons
        $object_info->setCollection($this->getCollectionPID());
        if (isset($this->parameters['collection_relationship_pred'])) {
            $object_info->setCollectionRelationshipPred($this->parameters['collection_relationship_pred']);
        }
        if (isset($this->parameters['collection_relationship_uri'])) {
            $object_info->setCollectionRelationshipUri($this->parameters['collection_relationship_uri']);
        }
        $object_info->setNamespace($this->collection_item_namespace);

        

        $object_info->setObjectId($file_object->getObjectId());
        
        $object_info->addFileArray($file_object);
        return $object_info;
    }

    protected function buildFileObject($file) {
        // counter for ascending the path towards the collection level directory
        $i = 0;
        $file_object = new DigitalCommonsFileInfo();
        $file_object->setUri($file->uri);
        $file_object->setFilename($file->filename);
        $file_object->setName($file->name);
        $file_object->setExt(pathinfo($file->filename, PATHINFO_EXTENSION));
        // Each file_object represents a Fedora DataStream
        // The collection directory of each file(DS) indicates an ObjectID in Digital Commons
        $file_object->setObjectDirectory(pathinfo($file_object->getUri(), PATHINFO_DIRNAME));
        $file_object->setObjectId(pathinfo($file_object->getObjectDirectory(), PATHINFO_FILENAME));

        //Many times the Collection directory is the paretn of the ObjectId directory
        //However, on occassion the Collection directory is several levels up.
        $collectionDirectory = pathinfo($file_object->getObjectDirectory(), PATHINFO_DIRNAME);
        $collectionName = pathinfo($collectionDirectory, PATHINFO_FILENAME);


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
        while ($collectionName !== $this->getCollectionName()) {
            $file_object->setObjectId($collectionName . "." . $file_object->getObjectId());
            // do not want to have an infinite loop attempting to build a unique object id
            if ($i >= DIGITAL_COMMONS_MAX_SUBDIRECTORY_DEPTH_FOR_COLLECTIONS || $collectionDirectory === "/") {
                throw new Exception("target collection directory " . $this->getTarget() . " invalid for Digital Commons Dump");
            }
            $collectionDirectory = pathinfo($collectionDirectory, PATHINFO_DIRNAME);
            $collectionName = pathinfo($collectionDirectory, PATHINFO_FILENAME);
            ++$i;
        }

        return $file_object;
    }

    /**
     * Get the name of the class to instantiate for the batch operations.
     */
    protected static function getObjectClass() {
        return "IslandoraScanBatchObjectDigitalCommons";
    }

    /**
     * Perform preprocessing of the scanned resources.
     */
    public function preprocess() {
        $files = $this->scan();

        if ($files === FALSE) {
            // Failed to scan.
            // TODO: Pop an error.
        } elseif (empty($files)) {
            // No contents.
            // TODO: Pop a warning.
        }

        $added = array();

        $grouped = $this->groupFiles($files);


        $added = array_merge($added, $this->preprocessCollectionLevel($grouped));


        return $added;
    }

    protected function preprocessCollectionLevel($collection_array) {
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
            \drupal_set_message(t('Error ingesting Islandora collection %t : %e.', array('%t' => $this->getCollectionPID(), '%e' => $e->getMessage()), 'error'));
            \watchdog('islandora_scan_batch_ditigal_commons', 'Error ingesting Islandora collection %t : %e.',  array('%t' => $this->getCollectionPID(), '%e' => $e->getMessage()), WATCHDOG_ERROR);
        }
        return $added;
    }

    protected function preprocessItemLevel($object_id, $object_info) {
        $object_class = static::getObjectClass();
        $added = array();
        // second level is grouped by digital commons object_name (typically 001, 002, etc)
        $ingest_object = new $object_class($this->connection, $object_id, $object_info);
        // XXX: Might be better to have this actually handled as another
        // "preprocessor", so arbitrary "tree" structures might be built?
        $added = array_merge($added, $this->preprocessChildren($ingest_object));
        return $added;
    }

    protected function getContentModelArray($fedora_object_id) {
        $content_models = array();

        //$this->connection->repository->api->a->getDatastreamDissemination($this->parent->id, $this->id,null, null)
        $collectionPolicyXml = $this->connection->repository->api->a->getDatastreamDissemination($fedora_object_id, 'COLLECTION_POLICY',null, null);
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

}

class IslandoraScanBatchObjectDigitalCommons extends IslandoraScanBatchObject {

    // need to create an __autoload function (?)
    protected $baseName;
    protected $objectInfo;
    // Keys in objectInfo that are not to be datastreams.
    // Path to MODS->DC XSLT.
    public $modsToDcTransform;
    public $mods;
    public $dc;
    private $digital_commons_document_type;
    public $title;
    // confirm this[MODS] is complete
    private $mods_dom;
    /**
     * Constructor for the IslandoraScanBatchObject.
     */
    public function __construct(IslandoraTuque $connection, $base_name, $object_info) {
        parent::__construct($connection, $base_name, null, null);
        $this->modsToDcTransform = drupal_get_path('module', 'islandora_batch') . '/transforms/mods_to_dc.xsl';
        $this->baseName = $base_name;
        $this->objectInfo = $object_info;
        $this->resources = array();
    }

    /**
     * Function batch_process.
     */
    public function batchProcess() {
        // Use object_info to create some datastreams.
        $this->getDigitalCommonsXML();
        $this->getMods();
        $this->getDc();
        $this->objectProfile['objLabel'] = substr($this->title, 0, 254);
        $this->state =  "A"; // Active, not all will be active, must determine state from metadata.xml
        $this->objectProfile['objState'] = 'A';
        $this->addSupplementalFiles();
        $this->getThesisPDF_tmp();
/*
        if (!isset($this['OBJ'])) {
            $file_array = $this->objectInfo->fileArray;
            foreach ($file_array as $file_object) {
                list($dsid, $mimetype) = determineDSIDAndMimetype($file_object);
                $obj_datastream = $this->constructDatastream($dsid);
                $obj_datastream->mimetype = $mimetype;
                $obj_datastream->label = $obj->filename;
                $obj_datastream->setContentFromFile($obj->uri, FALSE);

                $this->ingestDatastream($obj_datastream);
            }
        }
*/
        return ISLANDORA_BATCH_STATE__DONE;
    }

    /**
     * Determine the datastream ID for the given file.
     *
     * Gets info from the DS-COMPOSITE-MODELs for the selected content models
     * and attempts to match based on detected MIME-Types.
     *
     * @param string $name
     *   A filename for which to determine a mimetype.
     *
     * @return array
     *   An array containing two values:
     * - Either boolean FALSE or the datastream ID, as mapped in the
     * DS-COMPOSITE-MODELs of the selected object.
     * - A string containing the detected mimetype of the file.
     */
    protected function determineDSIDAndMimetype($file_object) {
        // Something like this would be nice to have... Need a way to indicate
        // primary assets, though... Think about basic image, where the content
        // model can contain "OBJ" image/jpeg, "TN" image/jpeg and so on...
        $name = $file_object->getFilename();
        static $mime_detect = NULL;
        static $dsids = NULL;

        if ($mime_detect === NULL || $dsids === NULL) {
            $mime_detect = new MimeDetect();
            module_load_include('inc', 'islandora', 'includes/utilities');
            $models = (array) $this->objectInfo->getContentModels();
            $dsids = islandora_get_datastreams_requirements_from_models($models);
        }

        $mimetype = $mime_detect->getMimetype($file_object->getUri());
        if (preg_match('/^\d+\-/', $name)) {
            
        }
        $dsid = FALSE;
        // Determine which stream this should be... Uses the first matching, as
        // received from the DC-COMPOSITE.
        foreach ($dsids as $ds => $info) {
            if (in_array($mimetype, $info['mime'])) {
                $dsid = $ds;
                break;
            }
        }
        return array($dsid, $mimetype);
    }

    /**
     * Get resources for the current object.
     */
    public function getResources() {
        return $this->resources;
    }
    /**
     * will scan the list of files for any that comform to the
     * supplementary file pattern \d+-xxx.xx .
     * 
     * Each file has a corresponding record in both the metadata.xml
     * and the MODS.xml The MODS.xml provides the Datastram id 
     * 
     */
    protected function addSupplementalFiles() {
                          $mods_xpath = new DOMXPath($this->mods_dom);
                  $mods_xpath->registerNamespace('m', 'http://www.loc.gov/mods/v3');
                  
                  $mods_title = $mods_xpath->evaluate('string(//m:mods/m:titleInfo/m:title/text())');
                  $mods_xpath->evaluate('/m:mods/m:relatedItem[@type=\"constituent\"]/(concat(m:titleInfo/m:title/text(), \' ::  \', m:physicalDescription/m:internetMediaType/text(), \' ::  \', m:note[@displayLabel=\"supplemental_file\"]/text()))');
                  // Assign the label of the object based on the full title;
                  $this->title = $mods_title;
        $thesis_file_info;
        foreach ($this->objectInfo->getFileArray() as $file_info) {
            if ($file_info->getName() === 'fulltext') {
                $thesis_file_info = $file_info;
                break;
            }
        }
        $thesis = file_get_contents($thesis_file_info->getUri());
        if ($thesis) {
            $thesis_datastream = $this->constructDatastream('PDF', 'M');
            $thesis_datastream->mimetype = 'application/pdf';
            $thesis_datastream->label = $this->label;
            
            $thesis_datastream->checksumType = 'SHA-256';
            $thesis_datastream->state = "A";
            $thesis_datastream->setContentFromFile($thesis_file_info->getUri());

        } else {
            throw new Exception($objectInfo->getCollection() . " " . getObjectId() . " Does not have a fulltext file!");
        }
    }
    
    /**
     * Function to get the Digital Commons metadata document
     * it is always named metadata.xml.
     * 
     */
    protected function getThesisPDF_tmp() {
        $thesis_file_info;
        foreach ($this->objectInfo->getFileArray() as $file_info) {
            if ($file_info->getName() === 'fulltext') {
                $thesis_file_info = $file_info;
                break;
            }
        }
        $thesis = file_get_contents($thesis_file_info->getUri());
        if ($thesis) {
            $thesis_datastream = $this->constructDatastream('PDF', 'M');
            $thesis_datastream->mimetype = 'application/pdf';
            $thesis_datastream->label = $this->label;
            
            $thesis_datastream->checksumType = 'SHA-256';
            $thesis_datastream->state = "A";
            $thesis_datastream->setContentFromFile($thesis_file_info->getUri());

        } else {
            throw new Exception($objectInfo->getCollection() . " " . getObjectId() . " Does not have a fulltext file!");
        }
    }
    /**
     * Function to get the Digital Commons metadata document
     * it is always named metadata.xml.
     * 
     */
    protected function getDigitalCommonsXML() {
        $digital_commons_xml_file_info;
        foreach ($this->objectInfo->getFileArray() as $file_info) {
            if ($file_info->getName() === 'metadata') {
                $digital_commons_xml_file_info = $file_info;
                break;
            }
        }
        $digital_commons_xml = file_get_contents($digital_commons_xml_file_info->getUri());
        if ($digital_commons_xml) {
            $digital_commons_xml_datastream = $this->constructDatastream('DIGITAL_COMMONS_MD', 'X');
            $digital_commons_xml_datastream->mimetype = 'application/xml';
            $digital_commons_xml_datastream->label = 'digital_commons_metadata';
            $digital_commons_xml_datastream->checksumType = 'SHA-256';
            $digital_commons_xml_datastream->state = "A";
            $digital_commons_xml_datastream->setContentFromFile($digital_commons_xml_file_info->getUri());
            $digital_commons_xml_dom = new DOMDocument();
            $digital_commons_xml_dom->loadXml($digital_commons_xml);
            $digital_commons_xml_xpath = new DOMXPath($digital_commons_xml_dom);
            $document_type_nodes = $digital_commons_xml_xpath->evaluate("/documents/document/document-type");

            foreach ($document_type_nodes as $document_type_node) {
                $this->digital_commons_document_type = $document_type_node->nodeValue;
            }
        } else {
            throw new Exception($objectInfo->getCollection() . " " . getObjectId() . " Does not have a metadata.xml file!");
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
    protected function getMods() {
        $mods_file_info;
        if (!isset($this['MODS'])) {
            foreach ($this->objectInfo->getFileArray() as $file_info) {
                
                if ($file_info->getName() === 'MODS') {
                    $mods_file_info = $file_info;
                    break;
                }
            }
            // TODO: Create the MODS datastream on this object.
            $mods_datastream = $this->constructDatastream('MODS', 'X');
            $mods_datastream->mimetype = 'application/xml';
            $mods_datastream->label = 'mods_metadata';
            $mods_datastream->checksumType = 'SHA-256';
            $mods_datastream->state = "A";
            // If we have an XML stream which is:
            if ($mods_file_info) {
                $xml = file_get_contents($mods_file_info->getUri());
                if ($xml) {
                  $this->mods_dom = new DOMDocument();
                  $this->mods_dom->loadXML($xml);
                  $mods_xpath = new DOMXPath($this->mods_dom);
                  $mods_xpath->registerNamespace('m', 'http://www.loc.gov/mods/v3');
                  
                  $mods_title = $mods_xpath->evaluate('string(//m:mods/m:titleInfo/m:title/text())');
                  // Assign the label of the object based on the full title;
                  $this->title = $mods_title;
                // extract the first five works, replaceing any non-word character with _
                // note that any non-ascii character will be listed as _, and that is unfortunate
                  $titleArray = array_slice(preg_split('/[\W]+/', $mods_title , 6), 0 , 5);
                  $this->label = trim(join("_", $titleArray), "_");
 

                  $mods_datastream->label = $this->label . "_MODS";
                  
                  // we need to build a structure based from the mods
                  // relatedItem type="constituent"
                  // that will help identify information about 
                  // supplementary files associated with the object
                  
                }
                $mods_datastream->setContentFromFile($mods_file_info->getUri(), FALSE);

                }

            if (!isset($mods_datastream->content)) {
                // If we do not yet have any XML, create a dumb MODS with just the
                // title, and set it...
                $title = $this->baseName;

                $mods_datastream->content = <<<EOXML
<mods xmlns:mods="http://www.loc.gov/mods/v3" xmlns="http://www.loc.gov/mods/v3">
  <titleInfo>
    <title>$title</title>
  </titleInfo>
</mods>
EOXML;
            }

            $this->ingestDatastream($mods_datastream);
        }
            
        return $this['MODS']->content;
    }

    /**
     * Function to get dc.
     */
    protected function getDc() {
        if (!isset($this['DC'])) {
            $ingest_dc = FALSE;
            // TODO: Create the DC datastream on this object.
            $dc_datastream = $this->constructDatastream('DC', 'X');
            $dc_datastream->mimetype = 'application/xml';
            $dc_datastream->label = 'dc_metadata';
            $dc_datastream->checksumType = 'SHA-256';
            $dc_datastream->state = "A";
            // Get the DC by transforming from MODS.

            // XXX: Might want to make this use a file, instead of staging the DC
            // in the database table (inside the object we serialize).
            $mods_content = $this->getMods();
            if ($mods_content) {
                $new_dc = static::runXslTransform(
                        array(
                            'xsl' => $this->modsToDcTransform,
                            'input' => $mods_content,
                        )
                );
            }
            if (isset($new_dc)) {
                $ingest_dc = TRUE;
                $dc_datastream->content = $new_dc;
            }
            
            if ($ingest_dc) {
                $this->ingestDatastream($dc_datastream);
            }
        }

        return isset($this['DC']) ? $this['DC']->content : FALSE;
    }

    /**
     * Add collection and content model relationships.
     */
    public function addRelationships() {
        $collection = $this->objectInfo->getCollection();
        $predicate = $this->objectInfo->getCollectionRelationshipPred();
        $uri = $this->objectInfo->getCollectionRelationshipUri();

        $this->relationships->add($uri, $predicate, $collection);
        $this->addContentModelRelationships();
        $this->inheritXacmlPolicies();
    }

    /**
     * Add inheritXacmlFrom relationship.
     */
    protected function inheritXacmlPolicies() {
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
     */
    protected function addContentModelRelationships() {
        $this->models = array_keys($this->objectInfo->getContentModels());
    }

}
spl_autoload_register(function ($name) {
    echo "Want to load $name.\n";
});
