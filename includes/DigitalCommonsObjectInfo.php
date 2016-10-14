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
        protected $collection; // String
        
        // The predicate of the relationship to the collection.
        // Defaults to "isMemberOfCollection".
        
        protected $collectionRelationshipPred = "isMemberOfCollection"; // String

        // The namespace URI of the relationship to the collection.' .
        // Defaults to "info:fedora/fedora-system:def/relations-external#".        
        protected $collectionRelationshipUri = "info:fedora/fedora-system:def/relations-external#"; //String
        
        // The namespace of the Object to be created in Fedora
        protected $namespace; // String
        
        // All the DigitalCommonsFileInfo objects that represent Digital Commons file
        protected $fileArray = array();
        
        // The unique identifier for the Object (may be combined with the namespace for 
        // a Fedora PID)
        protected $objectId;
        
        // content models that may be associated with the object
        protected $contentModels = array();

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

        public function getObjectId() {
            return $this->objectId;
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

        public function setObjectId($objectId) {
            $this->objectId = $objectId;
        }
        public function addFileArray($file) {
            $this->fileArray[] = $file;
        }
        public function setContentModels($contentModels) {
            $this->contentModels = $contentModels;
        }
}
