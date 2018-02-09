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
final class IslandoraScanBatchFactory
{
    /**
     * @param string $type
     *
     * @return FormatterInterface
     */
    public static function getIslandoraScanBatchInstance($type)
    {
        switch($type)
        {
            case (ISLANDORA_SCAN_BATCH_ZIP) :
                {
                return new IslandoraScanBatch();
                }
            case (ISLANDORA_SCAN_BATCH_DIR) :
                {
                return new IslandoraScanBatch();
                }
            case (ISLANDORA_SCAN_BATCH_AWS) :
                {
                return new IslandoraScanBatchAWS();
                }
            default :
            {
                throw new \InvalidArgumentException("Unable to instantiate a Scan Batch object of type " . $type);
            }
        }
    }
}