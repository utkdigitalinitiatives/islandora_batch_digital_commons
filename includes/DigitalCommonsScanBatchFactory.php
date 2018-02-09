s<?php

/**
 * IslandoraScanBatch is a class used by islandora_batch. It follows a static factory pattern.
 * Instead of directly extending the class to include AWS S3 as a manner to scan and group a
 * new target type (AWS, DIR, ZIP). Target Types are globals that are maintained somewhere.
 *
 * User: rwaltz
 * Date: 1/22/18
 * Time: 2:19 PM
 */
class IslandoraScanBatchFactory
{
    /**
     * Constructor for the IslandoraScanBatchObject.
     */

    public function __construct()
    {

        module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsScanBatchBase');
        module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsScanBatchAWS');
        module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsScanBatchDIR');
        module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsScanBatchZIP');
        module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsFileInfo');
        module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsObjectInfo');
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
            case (ISLANDORA_SCAN_BATCH_ZIP) :
                {
                return new IslandoraScanBatch($connection,  $object_model_cache, $parameters);
                }
            case (ISLANDORA_SCAN_BATCH_DIR) :
                {
                return new IslandoraScanBatch($connection,  $object_model_cache, $parameters);
                }
            case (ISLANDORA_SCAN_BATCH_AWS) :
                {
                return new IslandoraScanBatchAWS($connection,  $object_model_cache, $parameters);
                }
            default :
            {
                throw new \InvalidArgumentException("Unable to instantiate a Scan Batch object of type " . $type);
            }
        }
    }
}