<?php

/**
 * IslandoraScanBatchObjectDigitalCommons
 *
 * User: rwaltz
 * Date: 1/22/18
 * Time: 3:02 PM
 */
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
    private $batchProcessLogFileName = "IslandoraScanBatchObjectDigitalCommons.log";
    private  $batchProcessLogFile;
    protected $dsLabelToURI = array();
    private $digitalCommonsFulltextURL = null;

    /**
     * Constructor for the IslandoraScanBatchObject.
     */
    public function __construct($connection, $base_name, $object_info)
    {
        parent::__construct($connection, $base_name, null, null);
        $this->modsToDcTransform = drupal_get_path('module', 'islandora_batch') . '/transforms/mods_to_dc.xsl';
        $this->baseName = $base_name;
        $this->objectInfo = $object_info;
        $this->batchProcessLogFile = $object_info->getArchiveTopLevelDirectoryFullPath() . "/{$this->batchProcessLogFileName}";
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
            // Use object_info to create some datastreams.
            $this->addDigitalCommonsXMLDatastream();
            $this->addModsDatastream();
            $this->addDCDatastream();
            $this->initializeObjectInfo();
            $this->parseDigitalCommonsFulltextURL();

            $this->addModelDatastreams();

            $this->addRelationships();

            $log_message = date(DATE_ATOM) . " writing to " . $this->batchProcessLogFile .
                ": Batch Process Completed. Series name: " . $this->objectInfo->getDigitalCommonsSeries() .
                " - TRACE Id: " . $this->id .
                " - DigitalCommonsObjectId: " . $this->objectInfo->getDigitalCommonsObjectId() . "\n";
            $this->logDigitalCommonsBatch($log_message);


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
        foreach ($modsSupplementaryFileMap as $suppl_file) {
            $suppl_file_string = print_r($suppl_file, true);
            $this->logDigitalCommonsBatch($suppl_file_string . "\n");
            $suppl_file_num = null;
            $underscore_Position = strpos($suppl_file->ds_name, '_');
            if (isset($underscore_Position) && $underscore_Position > 0) {
                $suppl_file_num = substr($suppl_file->ds_name, $underscore_Position + 1);
            } else {
                $suppl_file_string = print_r($suppl_file, true);
                $exception = new Exception("Unable to determine supplemental file from $suppl_file_string");
                throw $exception;
            }
            // our naming of the supplemental DS Name using an origin index of 1
            // while the name of the filesystem files using an origin index of 0
            $suppl_file_num = $suppl_file_num - 1;
            $this->logDigitalCommonsBatch("suppl number = $suppl_file_num \n");
            if (isset($suppl_file_num)) {
                $found_suppl = false;
                foreach ($this->objectInfo->getFileArray() as $file_info) {
                    $file_info_string = print_r($file_info, true);
                    $this->logDigitalCommonsBatch($file_info_string . "\n");
                    if (preg_match("/^${suppl_file_num}\-(.+)$/", $file_info->getFilename(), $supplFileInfoMatch) && !$file_info->isProcessed()) {
                        $canonicalSupplFileName = $supplFileInfoMatch[1];
                        $this->logDigitalCommonsBatch("$suppl_file->ds_title === $canonicalSupplFileName \n");
                        if ($suppl_file->ds_title === $canonicalSupplFileName) {
                            $dsName = $suppl_file->ds_name;
                            $dsMimetype = $suppl_file->mime_type;
                            $this->logDigitalCommonsBatch("uploading $dsName $dsMimetype, $canonicalSupplFileName \n");
                            $this->addNewDatastreamFileInfo($dsName, $dsMimetype, $canonicalSupplFileName, $file_info);
                            $found_suppl = true;
                            break;
                        }
                    }
                }
                if (! $found_suppl) {
                    $exception = new Exception("Unable to determine supplemental file number from $suppl_file_string");
                    throw $exception;
                }
            } else {
                $exception = new Exception("Unable to determine supplemental file number from $suppl_file_string");
                throw $exception;
            }
        }
        /*       // get a list of files that conform to the supplementary file pattern
               // that have been found in the zip/filesystem path
               foreach ($this->objectInfo->getFileArray() as $file_info) {
                   // supplemental files will begin with digits
                   // these digits need to be stripped off in order to conform
                   // to the files listed in the mods record
                   $file_info_string = print_r($file_info, true);
                   $this->logDigitalCommonsBatch($file_info_string);
                   $supplFileInfoMatch = array();

                   if (preg_match("/^\d+\-(.+)$/", $file_info->getFilename(), $supplFileInfoMatch) && !$file_info->isProcessed()) {
                       $canonicalSupplFileName = $supplFileInfoMatch[1];
                       $this->logDigitalCommonsBatch($canonicalSupplFileName);
                       if (! isset($modsSupplementaryFileMap[$canonicalSupplFileName]) && isset($modsSupplementaryFileMap[$file_info->getFilename()])) {
                           $canonicalSupplFileName =$file_info->getFilename();
                       }
                       if (isset($modsSupplementaryFileMap[$canonicalSupplFileName])) {
                           // add the stream and mark it as done, increment suppl_index to a higher value
                           // if the next suppl is higher than the current suppl_index

                           $dsName = $modsSupplementaryFileMap[$canonicalSupplFileName]->ds_name;
                           $dsMimetype = $modsSupplementaryFileMap[$canonicalSupplFileName]->mime_type;
                           $this->logDigitalCommonsBatch($dsName);
                           $this->addNewDatastreamFileInfo($dsName, $dsMimetype, $canonicalSupplFileName, $file_info);
                       }
                   }
               } */
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
        $missingDSNameSupplementalFiles = array();
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
                $modsSupplementalFile->ds_title = $item_title;
                $modsSupplementalFile->mime_type = $item_mime_type;
                $modsSupplementalFiles[] = $modsSupplementalFile;
            } else {
                $modsSupplementalFile = new stdClass();
                $modsSupplementalFile->mime_type = $item_mime_type;
                $modsSupplementalFile->ds_title = $modsSupplementalFile;
                $missingDSNameSupplementalFiles[] = $modsSupplementalFile;
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
    protected function parseDigitalCommonsFulltextURL()
    {
        $digital_commons_xml_file_info = $this->retrieveFileInfoFromName('metadata');
        if (!isset($digital_commons_xml_file_info)) {
            $this->printWarningMessage("Does not have a digital commons metadata file!");
        } else {
            $digital_commons_xml = file_get_contents($digital_commons_xml_file_info->getUri());
            $digital_commons_fulltext_url = null;
            if ($digital_commons_xml) {
                $digital_commons_xml_dom = new DOMDocument();
                $digital_commons_xml_dom->loadXml($digital_commons_xml);
                $digital_commons_xml_xpath = new DOMXPath($digital_commons_xml_dom);
                $document_type_nodes = $digital_commons_xml_xpath->evaluate("/documents/document/fulltext-url");

                foreach ($document_type_nodes as $document_type_node) {
                    $digital_commons_fulltext_url = $document_type_node->nodeValue;
                }
            } else {
                $exception = $this->getFormattedException("Does not have a metadata.xml file!");
                throw $exception;
            }
            $this->setFulltextURL($digital_commons_fulltext_url);
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
            $this->setModsLocalIdentifier();
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
     * Function to set the identifier of the mods.
     * The mods identifier is a pid generated by fedora
     *
     */
    protected function setModsLocalIdentifier()
    {

        $mods_dom = $this->getModsDom();
        if (isset($mods_dom)) {

            $root_mods = $mods_dom->documentElement;
            $peerElement = null;
            // where to attach the new identifier. should be under the top level mods, but position
            // in the document may be a consideration not governed by validity

            $orderedPeerElementCheckList = array(0 => 'identifier', 1 => 'originInfo', 2 => 'abstract', 3 => 'titleInfo');
            $peerElementCheckListSize = count($orderedPeerElementCheckList);
            for ($i = 0; $i < $peerElementCheckListSize; ++$i) {
                $peerElementForInfix = $root_mods->getElementsByTagName($orderedPeerElementCheckList[$i]);
                if ($peerElementForInfix->length > 0) {
                    $peerElement = $peerElementForInfix->item(0);
                    break;
                }
            }

            $pid_node_element = $mods_dom->createElement("identifier");

            $pid_node_text = $mods_dom->createTextNode($this->id);

            if (is_null($peerElement)) {
                $pid_node_element = $root_mods->appendChild($mods_dom->importNode($pid_node_element, true));
            } else {
                $pid_node_element = $root_mods->insertBefore($mods_dom->importNode($pid_node_element, true), $peerElement);
            }
            $pid_node_element->appendChild($pid_node_text);
            $pid_node_element->setAttribute("type", "PID");

            $this->objectInfo->setModsDom($mods_dom);
            $this->objectInfo->setModsXml($mods_dom->saveXML());

        } else {
            //throw an error?
            $exception = $this->getFormattedException("Unable to retrieve a MODS document model (DOM)!");
            throw $exception;
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
    public function addModelDatastreams() {

        $content_models = array_keys($this->objectInfo->getContentModels());
        foreach ($content_models as $content_model) {
            switch ($content_model) {
                case "ir:thesisCModel": {
                    $this->addFullTextPDF();
                    $this->addSupplementalFiles();
//                    $this->addCorrespondence();
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
     * This associates the main file to the prime datastream of the Object Model being created.
     * It also may perform any Object Model specific processing.
     * Consider this the extention point in the code.
     * The function should be overwritten upon use by another institution other than
     * the Univserity of Tennessee. I assume that institutions may define or discover
     * their main datastream differently than we do, thus I have made the function public
     * so that it may be overwritten in a descendant.
     *
     * returns an array of keyvalue pairs
     */
    public function correlateModelDatastreams() {
        $correlations=array();
        $content_models = array_keys($this->objectInfo->getContentModels());
        foreach ($content_models as $content_model) {
            switch ($content_model) {
                case "ir:thesisCModel": {
                    $correlations[] = array('PDF' => '^(\w+|\d+\_\w+.pdf+$');
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
        return $correlations;
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
     *
     * returns an array of keyvalue pairs
     */
    public function associateModelDatastreamsToRegex() {
        $correlations=array();
        $content_models = array_keys($this->objectInfo->getContentModels());
        foreach ($content_models as $content_model) {
            switch ($content_model) {
                case "ir:thesisCModel": {
                    // For example, (?<!foo)bar does find an occurrence of "bar" that is not preceded by "foo".
                    // We want any file that ends with .pdf but does not begin with between 1 and 3 digits followed
                    // by a dash, based on premise that there will not be more than 1000 supplemental files
                    $correlations[] = array('PDF' => '^(?<![0-9]{1,3}\-).*\.pdf+$');
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
        return $correlations;
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

    public function getURIforPDF() {
        return $this->uriforPDF;
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
                        if (preg_match("/^(PDF)|(FULL_TEXT)|(SUPPL_\d+)$/", $datastream_name)) {
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

    /*
     * iterate through all the files that conform to the pattern
     * for supplemental files
     * Add them as datastreams
     */
    public function findRevisionFiles($pattern)
    {
        $supplFileInfoMatch = array();
        // get a list of files that conform to the supplementary file pattern
        // that have been found in the zip/filesystem path
        $fileInfoArray = $this->objectInfo->getFileArray();
        foreach ($fileInfoArray as $file_info) {
            // supplemental files will begin with digits
            // these digits need to be stripped off in order to conform
            // to the files listed in the mods record
            $filename = $file_info->getFilename();
            if (preg_match("/{$pattern}/", $filename)) {
                $supplFileInfoMatch[] = $file_info->getUri();
            }
        }
        return $supplFileInfoMatch;
    }

    public function logDigitalCommonsBatch($message) {
        $resource = fopen ( $this->batchProcessLogFile, "a+" );
        if ($resource) {
            fwrite($resource, $message);
            fclose($resource);
        } else {
            \watchdog($message, WATCHDOG_INFO);
        }
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
    /**
     * @return null
     */
    public function getFulltextURL()
    {
        return $this->digitalCommonsFulltextURL;
    }

    /**
     * @param null $digitalCommonsFulltextURL
     */
    public function setFulltextURL($digitalCommonsFulltextURL)
    {
        $this->digitalCommonsFulltextURL = $digitalCommonsFulltextURL;
    }

}

spl_autoload_register(function ($name) {

});
