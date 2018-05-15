<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Keep a non expiring cache of all Object Model fedora objects download. Should reduce # of times fedora is queried
 * for the same information. It allows for reuse of static data through iterations of migrations of series.
 *
 * Decided not to use cache_set and cache_get due to possibility of the cache being flushed
 * while the object is still in use. This implementation should expire the cache whenever the
 * object is garbage collected by php after the drush execution has ended
 *
 * Not Thread Safe!
 * 
 * @author rwaltz
 */
class DigitalCommonsFedoraObjectModelCache {

    // hash containing all the object model fedora objects that have been
    // downloaded. No need to download more than once, they are not going to
    // change.
    private $fedoraObjectModelCache = array();
    private $connection;

    public function __construct(IslandoraTuque $tuque_connection) {
        $this->connection = $tuque_connection;
    }

    /**
     * Retrieve the Object for the PID of the Object Model provided as a parameter
     *
     * The method will first check in a cache before retrieving the data from Fedora
     *
     * @param $content_model_pid
     * @return mixed
     */
    public function getObjectModelDSIDS($content_model_pid) {
        if (!isset($this->fedoraObjectModelCache[$content_model_pid])) {

            $content_model_dsids = $this->buildContentModel($content_model_pid);
            $this->fedoraObjectModelCache[$content_model_pid] = $content_model_dsids ;
        }
        return $this->fedoraObjectModelCache[$content_model_pid];
    }

    // largely a copy and paste of islandora_get_datastreams_requirements_from_content_model

    /**
     * Retrieve Content Model XML from Fedora
     *
     * @param $content_model_pid
     * @return array
     */
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
