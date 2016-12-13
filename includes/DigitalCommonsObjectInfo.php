<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * A simple data object that contains properties used to help build a 
 * NewFedoraObject to be ingested by islandora/tuque
 *
 * @author rwaltz
 */
class DigitalCommonsObjectInfo {
        // The collection object PID of this Object
        protected $collection = null; // String
        
        // The predicate of the relationship to the collection.
        // Defaults to "isMemberOfCollection".
        
        protected $collectionRelationshipPred = "isMemberOfCollection"; // String

        // The namespace URI of the relationship to the collection.' .
        // Defaults to "info:fedora/fedora-system:def/relations-external#".        
        protected $collectionRelationshipUri = "info:fedora/fedora-system:def/relations-external#"; //String
        
        // The namespace of the Object to be created in Fedora
        protected $namespace = null; // String
        
        // All the DigitalCommonsFileInfo objects that represent Digital Commons file
        protected $fileArray = array();
        
        // The unique identifier for the Object (may be combined with the namespace for 
        // a Fedora PID)
        protected $digitalCommonsObjectId = null;
        
        // content models that may be associated with the object
        protected $contentModels = array();

        // the document type assigned by Digital Commons, it may assist in choosing content models
        protected $digitalCommonsDocumentType = null;

        // the xml returned from the mods file, cache it so that it can be re-used in several methods
        protected $modsXML = null;

        // the dom representation of the modsXML assigned to this object
        protected $modsDOM = null;

        // the title of the fedora object as extracted from the mods document
        protected $title = null;

        protected $digitalCommonsSeries = null;
        
        public function __construct() {
            
        }

        public function getCollection() {
            return $this->collection;
        }

        public function getCollectionRelationshipPred() {
            return $this->collectionRelationshipPred;
        }

        public function getCollectionRelationshipUri() {
            return $this->collectionRelationshipUri;
        }

        public function getNamespace() {
            return $this->namespace;
        }

        public function getFileArray() {
            return $this->fileArray;
        }

        public function getDigitalCommonsObjectId() {
            return $this->digitalCommonsObjectId;
        }

        /**
         * @return mixed
         */
        public function getDigitalCommonsDocumentType()
        {
            return $this->digitalCommonsDocumentType;
        }

        /**
         * @return mixed
         */
        public function getModsXML()
        {
            return $this->modsXML;
        }

        /**
         * @return null
         */
        public function getModsDOM()
        {
            return $this->modsDOM;
        }


        /**
         * @return mixed
         */
        public function getTitle()
        {
            return $this->title;
        }
        
        public function getDigitalCommonsSeries() {
            return $this->digitalCommonsSeries;
        }

        public function getContentModels() {
            return $this->contentModels;
        }

        public function setCollection($collection) {
            $this->collection = $collection;
        }

        public function setCollectionRelationshipPred($collectionRelationshipPred) {
            $this->collectionRelationshipPred = $collectionRelationshipPred;
        }

        public function setCollectionRelationshipUri($collectionRelationshipUri) {
            $this->collectionRelationshipUri = $collectionRelationshipUri;
        }

        public function setNamespace($namespace) {
            $this->namespace = $namespace;
        }

        public function setFileArray($fileArray) {
            $this->fileArray = $fileArray;
        }

        public function setDigitalCommonsObjectId($digitalCommonsObjectId) {
            $this->digitalCommonsObjectId = $digitalCommonsObjectId;
        }
        public function addFileArray($file) {
            $this->fileArray[] = $file;
        }
        public function setContentModels($contentModels) {
            $this->contentModels = $contentModels;
        }

        /**
         * @param mixed $digitalCommonsDocumentType
         */
        public function setDigitalCommonsDocumentType($digitalCommonsDocumentType)
        {
            $this->digitalCommonsDocumentType = $digitalCommonsDocumentType;
        }

        /**
         * @param mixed $modsXML
         */
        public function setModsXML($modsXML)
        {
            $this->modsXML = $modsXML;

        }

        /**
         * @param null $modsDOM
         */
        public function setModsDOM($modsDOM)
        {
            $this->modsDOM = $modsDOM;
        }

        /**
         * @param mixed $title
         */
        public function setTitle($title)
        {
            $this->title = $title;
        }
        
        public function setDigitalCommonsSeries($digitalCommonsSeries) {
            $this->digitalCommonsSeries = $digitalCommonsSeries;
        }



}
