<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Keep a cache of all Object Model fedora objects download for ease of
 * reuse through iterations of series.
 *
 * Not Thread Safe!
 * 
 * @author rwaltz
 */
class IslandoraBatchFedoraObjectModelCache {

    // hash containing all the object model fedora objects that have been
    // downloaded. No need to download more than once, they are not going to
    // change.
    private $fedoraObjectModelCache = array();
    private $connection;

    public function __construct(IslandoraTuque $tuque_connection) {
        $this->connection = $tuque_connection;
    }

    public function getObjectModelDSIDS($content_model_pid) {
        if (!isset($this->fedoraObjectModelCache[$content_model_pid])) {

            $content_model_dsids = $this->buildContentModel($content_model_pid);
            $this->fedoraObjectModelCache[$content_model_pid] = $content_model_dsids ;
        }
        return $this->fedoraObjectModelCache[$content_model_pid];
    }

    // largely a copy and paste of islandora_get_datastreams_requirements_from_content_model
    private function buildContentModel($content_model_pid) {
        $dsids = array();
        $objectModelXml = $this->connection->repository->api->a->getDatastreamDissemination($content_model_pid, 'DS-COMPOSITE-MODEL', null, null);
        $simpleXmlObjectModel = new SimpleXMLElement($objectModelXml);
        foreach ($simpleXmlObjectModel->dsTypeModel as $ds) {
            $dsid = (string) $ds['ID'];
            $optional = strtolower((string) $ds['optional']);
            $mime = array();
            foreach ($ds->form as $form) {
                $mime[] = (string) $form['MIME'];
            }
            $dsids[$dsid] = array(
                'id' => $dsid,
                'mime' => $mime,
                'optional' => ($optional == 'true') ? TRUE : FALSE,
            );
        }
        return $dsids;
    }

}
