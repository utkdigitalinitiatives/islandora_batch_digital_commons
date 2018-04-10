<?php

/**
 * DigitalCommonsScanBatchObject
 *
 * User: rwaltz
 * Date: 1/22/18
 * Time: 3:02 PM
 */
class DigitalCommonsScanBatchObject extends IslandoraScanBatchObject
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
    // BATCH_OBJECT_PREFIX is used to group cached objects in drupal's cache.inc module
    public static $BATCH_OBJECT_PREFIX = "DigitalCommonsScanBatchObject";
    private $batchProcessLogFileName = "DigitalCommonsScanBatchObject.log";
    protected $dsLabelToURI = array();
    private $digitalCommonsFulltextURL = null;

    private $supplementalFilenameToDatastream = array();


    private $contentModelsToRemove = array('islandora:collectionCModel');

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
     *
     * for most descendants, this function is the starting point for the batchProcessing after the object
     * has been deserialized from the database. So, overwrite this if  you need a different workflow.
     * please note that either ISLANDORA_BATCH_STATE__DONE or ISLANDORA_BATCH_STATE__ERROR should be returned
     *
     */
    public function batchProcess()
    {
        try {
            $log_message = " Batch Process Start. Series name: " . $this->getObjectInfo()->getDigitalCommonsSeries() .
                " - DigitalCommonsObjectId: " . $this->getObjectInfo()->getDigitalCommonsObjectId() .
                " - TRACE Id: " . $this->id ;
            $this->logDigitalCommonsBatch($log_message, __LINE__);
            // Use object_info to create some datastreams.
            $this->addDigitalCommonsXMLDatastream();
            $this->addModsDatastream();
            $this->addDCDatastream();
            $this->initializeObjectInfo();
            $this->parseDigitalCommonsFulltextURL();

            $this->addModelDatastreams();

            $this->addRelationships();

            $log_message = " Batch Process Completed. Series name: " . $this->getObjectInfo()->getDigitalCommonsSeries() .
                " - DigitalCommonsObjectId: " . $this->getObjectInfo()->getDigitalCommonsObjectId() .
                " - TRACE Id: " . $this->id ;
            $this->logDigitalCommonsBatch($log_message, __LINE__);


            $key =  DigitalCommonsScanBatchObject::$BATCH_OBJECT_PREFIX . $this->id;
            $expire_datetime = time() + $this->CACHE_EXPIRY_SECONDS;
            cache_set($key, $this, 'cache_field', $expire_datetime);
            return ISLANDORA_BATCH_STATE__DONE;
        } catch (Exception $e) {
            $message = t(date(DATE_ATOM) ." Series name: " . $this->getObjectInfo()->getDigitalCommonsSeries() . " - DigitalCommonsObjectId: " . $this->getObjectInfo()->getDigitalCommonsObjectId() . " - " . $e->getMessage());
            \drupal_set_message($message, 'error');
            \watchdog($message, WATCHDOG_ERROR);
//            \watchdog('islandora_scan_batch_digital_commons', $message, null, WATCHDOG_ERROR);

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
        // The Mods file is the definitive resource for supplemental file organization.
        // The metadata.xml and the filesytem are subordinate to the information in the MODS record
        // First read the MODS file mapping as taken from the MODS record
        // attempt to find the corresponding file on the filesystem
        // to determine the absolute URI

        foreach ($modsSupplementaryFileMap as $suppl_file) {
            $ds_name = $suppl_file->ds_name;
            $ds_filename = $this->supplementalFilenameToDatastream[$ds_name];

            $found_suppl = false;
            foreach ($this->objectInfo->getFileArray() as $file_info) {

                // The $file_info will have the file's FullName that is the name of the downloaded file on the filesystem
                // The file_num is the number that corresponds to the integer that forms the prefix of the name of
                // filesystem file

                if ($file_info->getFullname() === $ds_filename && !$file_info->isProcessed()) {

                    $dsTitle = $suppl_file->ds_title;
                    $dsName = $suppl_file->ds_name;
                    $dsMimetype = $suppl_file->mime_type;
                    $this->logDigitalCommonsBatch("uploading $dsName $dsMimetype, $ds_filename", __LINE__);

                    $this->addNewDatastreamFileInfo($dsName, $dsMimetype, $dsTitle, $file_info);
                    $found_suppl = true;
                    break;
                }
            }
            if (! $found_suppl) {
                $suppl_file_string = print_r($suppl_file, true);
                $exception = new Exception("Unable to determine supplemental file number from $suppl_file_string");
                throw $exception;
            }
        }
    }

    /*
     * The Mods file is the definitive resource for supplemental file organization.
     * The metadata.xml and the filesytem are subordinate to the information in the MODS record
     *
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
                $this->printWarningMessage( "mods xml has more than one titleInfo for a related item", __LINE__);
                continue;
            }
            $related_item_title_info_node = $related_item_title_info_list->item(0);
            $related_item_title_list = $related_item_title_info_node->getElementsByTagName('title');
            if ($related_item_title_list->length != 1) {

                $this->printWarningMessage("The mods xml has more than one title for a related item", __LINE__);
                continue;
            }
            $title_node_node = $related_item_title_list->item(0);
            $item_title = $title_node_node->textContent;
            $item_title = html_entity_decode ($item_title);
            $item_title = strip_tags($item_title);
            $encoding = mb_detect_encoding($item_title);
            if ($encoding === "UTF-8") {
                $item_title = utf8_decode( $item_title);
            } else if ($encoding !== "ISO-8859-1") {
                $item_title =  mb_convert_encoding($item_title, 'ISO-8859-1');
            }
            $physical_description_list = $related_item->getElementsByTagName('physicalDescription');
            if ($physical_description_list->length != 1) {
                $this->printWarningMessage("The mods xml has more than one physicalDescription for a related item", __LINE__);
                continue;
            }
            $physical_description_node = $physical_description_list->item(0);
            $internet_media_type_list = $physical_description_node->getElementsByTagName('internetMediaType');
            if ($internet_media_type_list->length != 1) {
                $this->printWarningMessage("The mods xml has more than one internetMediaType for a related item", __LINE__);
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
                $this->printWarningMessage("The mods xml has does not have a Suppl File DataStream name and is not ordered", __LINE__);
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
     *
     * Not needed, there is no way we are able to retrieve correspondences now, but if they ever become available...
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
            $filename= $file_info->getFullname();

            if (preg_match("/^decision\-\d+\.txt$/", $file_info->getFullname()) && !$file_info->isProcessed()) {

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
    protected function retrieveThesisCModelDocumentFileInfo()
    {
        $returnFileInfo = null;
        $returnFileInfoList = array();
        foreach ($this->objectInfo->getFileArray() as $file_info) {
            $file_fullname = $file_info->getFullName();
            $is_processed = $file_info->isProcessed();
            $is_processed = is_null($is_processed) ? FALSE : $is_processed;
            $this->logDigitalCommonsBatch("File Full Name to Match Content Model: {$file_fullname} and is processed? " . $is_processed ? 'true' : 'false', __LINE__);
            if ( preg_match('/.+\.pdf$/', $file_fullname) && ! preg_match('/^\d{1,3}\-.+/', $file_fullname) && ! $file_info->isProcessed() )  {
                $returnFileInfoList[] = $file_info;
            }
        }
        $sizeOfFileInfoList = count($returnFileInfoList);
        if ($sizeOfFileInfoList > 1) {
            // here is a conflict
            // note it in the log file and go on
            $exception = $this->getFormattedException("Found more than 1 PDF for thesis cModel. Count:  " . $sizeOfFileInfoList, __LINE__);
            throw $exception;

        }
        if ( $sizeOfFileInfoList == 1) {
            $returnFileInfo = $returnFileInfoList[0];
        }
        return $returnFileInfo;
    }

    protected function retrieveCitationCModelDocumentFileInfo()
    {
        $returnFileInfo = null;
        $returnFileInfoList = array();
        foreach ($this->objectInfo->getFileArray() as $file_info) {
            $file_fullname = $file_info->getFullName();
            $this->logDigitalCommonsBatch("File Full Name to Match Content Model: {$file_fullname}", __LINE__);
            if (  preg_match('/.+\.pdf$/', $file_fullname) &&  ! preg_match('/^\d{1,3}\-.+/', $file_fullname) && ! $file_info->isProcessed() )  {
                $returnFileInfoList[] = $file_info;
            }
        }
        $sizeOfFileInfoList = count($returnFileInfoList);
        if ($sizeOfFileInfoList > 1) {
            // here is a conflict
            // note it in the log file and go on
            $exception = $this->getFormattedException("Found more than 1 PDF for citation cModel. Count:  " . $sizeOfFileInfoList, __LINE__);
            throw $exception;

        }
        if ( $sizeOfFileInfoList == 1) {
            $returnFileInfo = $returnFileInfoList[0];
        }
        return $returnFileInfo;
    }
    protected function retrieveBinaryObjectCModelOBJFileInfo()
    {
        $returnFileInfo = null;
        $returnFileInfoList = array();
        foreach ($this->objectInfo->getFileArray() as $file_info) {
            $file_fullname = $file_info->getFullName();
            if (! preg_match('/^\d{1,3}\-.+/', $file_fullname) && ! $file_info->isProcessed() )  {
                $returnFileInfoList[] = $file_info;
            }
        }
        $sizeOfFileInfoList = count($returnFileInfoList);
        if ($sizeOfFileInfoList > 1) {
            // here is a conflict, because two files match the name of the file as listed by MODS
            // take the list and send it to conflict resolution
            $exception = $this->getFormattedException("Found more than 1 FILE for binary cModel. Count:  " . $sizeOfFileInfoList, __LINE__);
            throw $exception;
        }
        if ( $sizeOfFileInfoList == 1) {
            $returnFileInfo = $returnFileInfoList[0];
        }
        return $returnFileInfo;
    }
    /**
     * Function to get the Digital Commons metadata document
     * it is always named metadata.xml.
     *
     */
    protected function addThesisCModelDocument()
    {

        $thesis_file_info = $this->retrieveThesisCModelDocumentFileInfo();

        if (isset($thesis_file_info)) {

            if (!$thesis_file_info->isProcessed()) {
                $this->addNewDatastreamFileInfo('PDF', 'application/pdf', $this->objectInfo->getTitle(), $thesis_file_info);
                $this->setFileInfoProcessed($thesis_file_info);
            }
        } else {
            $this->printWarningMessage("Does not have a ThesisCModel fileinfo for Primary Document!", __LINE__);
            return FALSE;
        }
        return $thesis_file_info->isProcessed();
    }

    protected function addCitationBinaryObjectCModelDocument() {
        if (! $this->addCitationDocument()) {
            if (! $this->addBinaryObjectCModelOBJDocument()) {
                throw new Exception("The Primary Document file cannot be found");
            }
        }
    }
    /**
     * Function to get the Digital Commons metadata document
     * it is always named metadata.xml.
     *
     */
    protected function addCitationDocument()
    {
        $file_info = $this->retrieveCitationCModelDocumentFileInfo();

        if (isset($file_info)) {
            if (!$file_info->isProcessed()) {
                $this->addNewDatastreamFileInfo('PDF', 'application/pdf', $this->objectInfo->getTitle(), $file_info);
                // Object models are found at the parent collection model level and applied at the object that resides in the collection
                // we only have 3 Object Models to deal with, in this instance, we only want a citation model applied to the object
                // therefore, specifically exclude binary object model
                $this->addExcludedContentModels("islandora:binaryObjectCModel");
                $this->setFileInfoProcessed($file_info);
            }
        } else {
            return FALSE;
        }
        $return = $file_info->isProcessed();
        return  $return ;
    }
    /**
     * Function to get the Digital Commons metadata document
     * it is always named metadata.xml.
     *
     */
    protected function addBinaryObjectCModelOBJDocument()
    {
        $file_info = $this->retrieveBinaryObjectCModelOBJFileInfo();

        if (isset($file_info)) {
            if (!$file_info->isProcessed()) {

                $file = new stdClass();
                $file->uri = $file_info->getUri();
                $file->filename = $file_info->getFullname();
                $file->name = $file_info->getName();
                $mime_type = mime_content_type($file->uri);
                $this->addNewDatastreamFileInfo('OBJ', $mime_type, $this->objectInfo->getTitle(), $file_info);
                // Object models are found at the parent collection model level and applied at the object that resides in the collection
                // we only have 3 Object Models to deal with, in this instance, we only want a binary model applied to the object
                // therefore, specifically exclude citation object model
                $this->addExcludedContentModels("ir:citationCModel");
                $this->setFileInfoProcessed($file_info);
            }
        } else {
            return FALSE;
        }
        return $file_info->isProcessed();
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
            $this->printWarningMessage($file_info->getUri() . " Unable to read content!", __LINE__);
        } else {
            $this->ingestDatastream($datastream);
            $this->setFileInfoProcessed($file_info);
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
            $this->setFileInfoProcessed($file_info);
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
            $this->printWarningMessage("Does not have a digital commons metadata file!", __LINE__);
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
            $this->printWarningMessage("Does not have a digital commons metadata file!", __LINE__);
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
                $exception = $this->getFormattedException("Does not have a metadata.xml file!", __LINE__);
                throw $exception;
            }
            $this->objectInfo->setDigitalCommonsDocumentType($digital_commons_document_type);
        }
    }

    /**
     * load the xml from the metadata.xml file into a DOM and parse the DOM for significant properties of this object
     *
     * @throws Exception
     */
    protected function parseDigitalCommonsFulltextURL()
    {
        $digital_commons_xml_file_info = $this->retrieveFileInfoFromName('metadata');
        if (!isset($digital_commons_xml_file_info)) {
            $this->printWarningMessage("Does not have a digital commons metadata file!", __LINE__);
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
                $exception = $this->getFormattedException("Does not have a metadata.xml file!", __LINE__);
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
            $this->printWarningMessage("Does not have a MODS metadata file!", __LINE__);
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
            $exception = $this->getFormattedException("Unable to retrieve a MODS document model (DOM)!", __LINE__);
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
                $encoding = mb_detect_encoding($mods_title);
                if ($encoding === "UTF-8") {
                    $mods_title = utf8_decode( $mods_title);
                } else if ($encoding !== "ISO-8859-1") {
                    $mods_title =  mb_convert_encoding($mods_title, 'ISO-8859-1');
                }
                $this->logDigitalCommonsBatch("title encoding is " . $encoding);

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
                    // run through the supplemental files first to exclude
                    // possibilities for discovering the primary document
                    $this->addSupplementalFiles();
                    $this->addThesisCModelDocument();
//                  $this->addCorrespondence();
                    break 2;

                }
                case "ir:citationCModel":
                case "islandora:binaryObjectCModel" : {
                    $this->addSupplementalFiles();
                    $this->addCitationBinaryObjectCModelDocument();
                    break 2;
                }
/*                case "islandora:sp_large_image_cmodel" :
                case "islandora:bookCModel" :
                case "islandora:compoundCModel" :
                case "islandora:collectionCModel" :
                case "islandora:sp-audioCModel" :
                case "islandora:transformCModel" :
                case "islandora:sp_videoCModel" :
                default: {

                }
 */
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
            // the below regular expressions may not work
            switch ($content_model) {
                case "ir:thesisCModel": {
                    $correlations[] = array('PDF' => '^(\w+|\d+\_\w+.pdf+$');

                    break;
                }
                case "ir:citationCModel": {
                    $correlations[] = array('PDF' => '^(\w+|\d+\_\w+.pdf+$');
                    break;
                }
                case "islandora:binaryObjectCModel" : {
                    $correlations[] = array('OBJ' => '^(\w+|\d+\_\w+.+$');
                }
/*                case "islandora:sp_large_image_cmodel";
                case "islandora:bookCModel";
                case "islandora:compoundCModel";
                case "islandora:collectionCModel";
                case "islandora:sp-audioCModel";
                case "islandora:transformCModel";
                case "islandora:sp_videoCModel";
                default: {

                }
*/
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
                // the below regular expressions may not work
                case "ir:thesisCModel": {
                    // For example, (?<!foo)bar does find an occurrence of "bar" that is not preceded by "foo".
                    // We want any file that ends with .pdf but does not begin with between 1 and 3 digits followed
                    // by a dash, based on premise that there will not be more than 1000 supplemental files
                    $correlations[] = array('PDF' => '^(?<![0-9]{1,3}\-).*\.pdf+$');
                }
                case "ir:citationCModel": {
                    $correlations[] = array('PDF' => '^(?<![0-9]{1,3}\-).*\.pdf+$');
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

            $modsXML = $this->buildSupplMapRemoveDOMNotes($mods_xml);
            if ($modsXML) {
                $mods_xml = $modsXML;
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
    protected function buildSupplMapRemoveDOMNotes($modsXML) {
        $modsDOM = new DOMDocument();
        $modsDOM->loadXML($modsXML);
        $modsRootNode = $modsDOM->documentElement;
        if (! isset($modsRootNode->childNodes)) {
            $exception = $this->getFormattedException("Unable to retrieve child nodes of the MODS document model (DOM)!", __LINE__);
            throw $exception;
        }
        $toplevelChildren = $modsRootNode->childNodes;
        if (! isset($toplevelChildren)) {
            $exception = $this->getFormattedException("Unable to retrieve toplevel child nodes of the MODS document model (DOM)!", __LINE__);
            throw $exception;
        }
        for ($i = $toplevelChildren->length - 1; $i > 0; --$i) {
            $childNode = $toplevelChildren->item($i);
            if ( $childNode->nodeType == XML_ELEMENT_NODE && $childNode->nodeName === 'mods:relatedItem' &&
                ($childNode->attributes->getNamedItem('type')->nodeValue === 'constituent') ) {
                $relatedItemChildren = $childNode->getElementsByTagName('note');
                $noteNodeListCount = $relatedItemChildren->length;
                if ($noteNodeListCount == 2) {
                    // the key is the supplemental datastream name assigned in the MODS.
                    $key = null;
                    $value = null;
                    $removeNode = null;
                    //
                    // displayLabel="supplemental_file"

                    for ($j = 0; $j < $noteNodeListCount; $j++) {
                        if ($relatedItemChildren->item($j)->hasAttribute('displayLabel') && $relatedItemChildren->item($j)->getAttribute('displayLabel') === 'supplemental_file') {
                            $key = $relatedItemChildren->item($j)->nodeValue;
                        }
                        if ($relatedItemChildren->item($j)->hasAttribute('displayLabel') && $relatedItemChildren->item($j)->getAttribute('displayLabel') === 'supplemental_filename') {
                            $value = $relatedItemChildren->item($j)->nodeValue;
                            $removeNode = $relatedItemChildren->item($j);
                        }
                    }
                    if (isset($key) && isset($value)) {
                        $this->supplementalFilenameToDatastream[$key] = $value;
                    } else {
                        $exception = $this->getFormattedException("Unable to find 2 note attribute displayLabel on a constituent note node, one with a value of supplemental_file and the other with a value of supplemental_filename.", __LINE__);
                        throw $exception;
                    }
                    if (isset($removeNode)) {
                        $childNode->removeChild($removeNode);
                    } else {
                        $exception = $this->getFormattedException("Unable to find a note node to remove.", __LINE__);
                        throw $exception;
                    }
                } else {
                    $exception = $this->getFormattedException("Unable to find 2 note elements on a constituent note node! Instead found " . $noteNodeListCount, __LINE__);
                    throw $exception;
                }
            }
        }
        return $modsDOM->saveXML();
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
        $excludedModelsLlist = $this->getExcludedContentModels();
        $this->models = array_diff($content_models, $excludedModelsLlist);
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
    protected function getFormattedException($comment, $line_number = 'N/A' ) {

        return new Exception($this->getLogFormattedPreamble($line_number)  .$comment);

    }
    private function logmsg($message, $line_number = 'N/A') {
        $date = date(DATE_W3C);
        $current_file = __FILE__;

        $includes_dir =  pathinfo($current_file, PATHINFO_DIRNAME);
        $toplevel_dir =  pathinfo($includes_dir, PATHINFO_DIRNAME);
        $logFile = $toplevel_dir . DIRECTORY_SEPARATOR . $this->batchProcessLogFileName;

        $format_message = $this->getLogFormattedPreamble($line_number) . "${message}".PHP_EOL;
        return file_put_contents($logFile, $format_message, FILE_APPEND);
    }

    public function logDigitalCommonsBatch($message, $line_number = 'N/A') {
        $this->logmsg($message, $line_number);
    }
    protected function printWarningMessage($message,$line_number = 'N/A') {
        $this->logmsg($message, $line_number);
        $format_message = $this->getLogFormattedPreamble($line_number) . $message;


        \drupal_set_message($format_message, 'warning');
        \watchdog('islandora_scan_batch_ditigal_commons', $format_message, null, WATCHDOG_WARNING);
    }

    private function getLogFormattedPreamble($line_number) {
        $date = date(DATE_W3C);
        return t("[{$date}] [" . __FILE__ . "] [$line_number] Series name: " . $this->objectInfo->getDigitalCommonsSeries() . " - DigitalCommonsObjectId: " . $this->objectInfo->getDigitalCommonsObjectId() . " - " );
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
            $filename = $file_info->getFullname();
            if (preg_match("/{$pattern}/", $filename)) {
                $supplFileInfoMatch[] = $file_info->getUri();
            }
        }
        return $supplFileInfoMatch;
    }

    private function setFileInfoProcessed($file_info) {
        // get to the corresponding file_info in the object structure and set it to processed.
        $file_info->setProcessed(TRUE);
        $number_of_files = count($this->objectInfo->getFileArray());
        for ($i = 0; $i < $number_of_files; ++$i) {
            $iterate_file_info = $this->objectInfo->getFileArray()[$i];
            if ($iterate_file_info->getURI() === $file_info->getURI()) {
                if (! $this->objectInfo->getFileArray()[$i]->isProcessed()) {
                    $this->logDigitalCommonsBatch("having to set processed when it should already be set", __LINE__);
                    $this->objectInfo->getFileArray()[$i]->setProcessed(TRUE);
                }
            }
        }
    }

    public function getExcludedContentModels() {
        return $this->contentModelsToRemove;
    }
    public function addExcludedContentModels($exclusion) {
        $this->contentModelsToRemove[] = $exclusion;
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
