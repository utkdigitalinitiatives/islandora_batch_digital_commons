<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 *
 * Describes a file in a DigitalCommons Dump through various properties
 * 
 * @author rwaltz
 */
class DigitalCommonsFileInfo {
        // The full path to the file
        protected $uri; // String
        
        // full name of the file with extention (no path)
        protected $filename; // String
        
        // the name of the file without extention
        protected $name; //String
        
        // the extention of the file
        protected $ext; //String
        // Each file_object represents a Fedora DataStream
        // The parent directory of each file(DS) indicates an ObjectID in Digital Commons

        
        // A unique identifier for the object that holds the file
        protected $digitalCommonsObjectId; //String
        
        // A boolean indicating if the file representing this object
        // has had a Datastream created for it
        protected $processed = FALSE; //String
         
        public function __construct() {
            
        }        
        public function getUri() {
            return $this->uri;
        }

        public function getFilename() {
            return $this->filename;
        }

        public function getName() {
            return $this->name;
        }

        public function getExt() {
            return $this->ext;
        }


        public function getDigitalCommonsObjectId() {
            return $this->digitalCommonsObjectId;
        }
        
        public function isProcessed() {
            return $this->processed;
        }

        public function setUri($uri) {
            $this->uri = $uri;
        }

        public function setFilename($filename) {
            $this->filename = $filename;
        }

        public function setName($name) {
            $this->name = $name;
        }

        public function setExt($ext) {
            $this->ext = $ext;
        }


        public function setDigitalCommonsObjectId($digitalCommonsObjectId) {
            $this->digitalCommonsObjectId = $digitalCommonsObjectId;
        }
        public function setProcessed($processed) {
            $this->processed = $processed;
        }


        

}
