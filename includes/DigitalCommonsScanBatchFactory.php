<?php

/**
 * IslandoraScanBatch is a class used by islandora_batch. It follows a static factory pattern.
 * Instead of directly extending the class to include AWS S3 as a manner to scan and group a
 * new target type (AWS, DIR, ZIP). Target Types are globals that are maintained somewhere.
 *
 * User: rwaltz
 * Date: 1/22/18
 * Time: 2:19 PM
 */

define('DIGITAL_COMMONS_SCAN_BATCH_ZIP','ZIP');
define('DIGITAL_COMMONS_SCAN_BATCH_DIR','DIR');
define('DIGITAL_COMMONS_SCAN_BATCH_AWS','AWS');

class DigitalCommonsScanBatchFactory
{
    /**
     * Constructor for the IslandoraScanBatchObject.
     */

    public function __construct()
    {

        module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsScanBatchBase');
        module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsScanBatchAWS');
#        module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsScanBatchDIR');
#        module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsScanBatchZIP');
        module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsFileInfo');
        module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsObjectInfo');

        module_load_include('php','islandora_batch_digital_commons','includes/IslandoraBatchFedoraObjectModelCache');
        module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsScanBatchFactory');

        module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsScanBatchObject');

        module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsTransformBaseX');

        module_load_include('inc','islandora_batch_digital_commons','includes/ingest_digital_commons.batch');

    }


    /**
     * @param $connection
     * @param $object_model_cache
     * @param $parameters
     * @return FormatterInterface
     * @internal param string $type
     *
     */
    public function getIslandoraScanBatchInstance($connection,  $object_model_cache, $parameters)
    {
        switch($parameters['type'])
        {
            case (DIGITAL_COMMONS_SCAN_BATCH_ZIP) :
                {
                return null;
                break;
                }
            case (DIGITAL_COMMONS_SCAN_BATCH_DIR) :
                {
                return null;
                break;
                }
            case (DIGITAL_COMMONS_SCAN_BATCH_AWS) :
                {
                return new DigitalCommonsScanBatchAWS($connection,  $object_model_cache, $parameters);
                }
            default :
            {
                throw new \InvalidArgumentException("Unable to instantiate a Scan Batch object of type " . $parameters['type']);
            }
        }
    }
}