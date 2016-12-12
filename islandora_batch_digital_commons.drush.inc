<?php

/**
 * @file
 * Drush hooks.
 */

/**
 * Implements hook_drush_command().
 */
function islandora_batch_digital_commons_drush_command() {
  $items = array();

  $items['islandora_batch_digital_commons_ingest'] = array(
    'aliases' => array('ibdci'),
    'description' => 'Process and ingest preprocessed entries.',
    'drupal dependencies' => array('islandora_batch_digital_commons', 'islandora_batch'),
    'examples' => array(
      'drush -v --user=admin --uri=http://digital.library.yorku.ca islandora_batch_digital_commons_ingest',
    ),
    'options' => array(
      'timeout' => array(
        'description' => 'The max amount of time (in seconds) for which we ' .
        'should process.  When the time is exceeded, the current object ' .
        'will complete before terminating execution. If not provided, ' .
        'should process until all available preprocessed objects are ' .
        'exhausted.',
        'value' => 'optional',
      ),
      'ingest_set' => array(
        'description' => 'Identify a particular set to process. The default is to process ALL objects in the queue, independent of sets.',
        'value' => 'optional',
      ),
    ),
    'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_LOGIN,
  );

  $items['islandora_batch_digital_commons_scan_preprocess'] = array(
    'aliases' => array('ibdcsp'),
    'description' => 'Preprocess assets by scanning either a directory or a ' .
    'ZIP archive.',
    'drupal dependencies' => array('islandora_batch_digital_commons', 'islandora_batch'),
    'examples' => array(
      format_string('drush -v --user=admin --uri=http://digital.library.yorku.ca islandora_batch_digital_commons_scan_preprocess --content_models=islandora:sp_large_image_cmodel --parent=yul:F0433 --parent_relationship_pred=isMemberOfCollection --type=directory --@target=/tmp/batch_ingest',
      array(
        '@target' => DRUSH_VERSION >= 7 ? 'scan_target' : 'target',
      )),
    ),
    'options' => array(
      'input' => array(
        'description' => 'The absolute path to the delimiter-separated file. ' ,
        'required' => 'TRUE',
      ),
      'target' => array(
        'description' => 'The target to directory to scan ' .
        'models which are all applied to each ingested object.',
        'required' => 'TRUE',
      ),
      'type' => array(
        'description' => 'Either "directory" or "zip". The zip importer is' .
        " unstable with large files (~2GB). Consider alternative methods" .
        " such as unzipping your Zip file and using Islandora Batch's" .
        "`--directory` option.",
        'required' => TRUE,
      ),
//      'content_models' => array(
//        'description' => 'Supports one or multiple comma-separated content ' .
//        'models which are all applied to each ingested object.',
//        'value' => 'optional',
//      ),
//      'parent' => array(
//        'description' => 'The collection to which the generated items should ' .
//        'be added. Defaults to the root Islandora repository PID.',
//        'value' => 'optional',
//      ),
//      'parent_relationship_uri' => array(
//        'description' => 'The namespace URI of the relationship to the parent.' .
//        ' Defaults to "info:fedora/fedora-system:def/relations-external#".',
//        'value' => 'optional',
//      ),
//      'parent_relationship_pred' => array(
//        'description' => 'The predicate of the relationship to the parent. ' .
//        'Defaults to "isMemberOfCollection".',
//        'value' => 'optional',
//      ),
//      'namespace' => array(
//        'description' => 'Namespace of objects to create. ' .
//        'Defaults to namespace specified in Fedora configuration.',
//        'value' => 'optional',
//      ),
      'zip_encoding' => array(
        'description' => 'The encoding of filenames contained in ZIP ' .
        'archives:Only relevant with --scan_target=zip. Defaults to the native ' .
        'encoding being used by PHP.',
        'value' => 'optional',
      ),
    ),
    'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_LOGIN,
  );
  // XXX: The target parameter became reserved in Drush 7 and above, for
  // backwards compatibility both will be supported. Not using
  // strict-option-handling (http://www.drush.org/en/master/strict-options) as
  // it requires manual argument parsing.

  $items['islandora_batch_digital_commons_cleanup_processed_sets'] = array(
    'aliases' => array('ibdccps'),
    'description' => dt('Cleans up processed sets that have existed greater than a specified time.'),
    'drupal dependencies' => array('islandora_batch_digital_commons', 'islandora_batch'),
    'examples' => array(
      'drush -v -u1 islandora_batch_digital_commons_cleanup_processed_sets --time=1438179447',
    ),
    'options' => array(
      'time' => array(
        'description' => dt('The amount of time since completion to compare against. The timestamp to be used to query against. Can use date +%s on the command line to get the current time.'),
        'required' => TRUE,
      ),
    ),
    'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_LOGIN,
  );
  return $items;
}


/**
 * Implements hook_islandora_batch_digital_commons_scan_preprocess().
 *
 * Builds a preprocessor, and passes it off to a preprocessor handler.
 */
function drush_islandora_batch_digital_commons_scan_preprocess() {
  // XXX: Due to how Drush bootstrapping works, the connection may be created
  // without credentials (when your site's front page is
  // 'islandora/object/some:object', for example). Resetting to ensure a new
  // connection gets created should fix it.
  // module_load_include('inc', 'islandora_batch', 'includes/utilities');
  module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsFileInfo');
  module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsObjectInfo');
  module_load_include('php','islandora_batch_digital_commons','includes/IslandoraBatchFedoraObjectModelCache');
  module_load_include('inc','islandora_batch_digital_commons','includes/islandora_scan_batch_digital_commons');
  drupal_static_reset('islandora_get_tuque_connection');
  $connection = islandora_get_tuque_connection();
  
  $parameters = array(
    'type' => drush_get_option('type'),
    'input' => drush_get_option('input'),
    'target' => drush_get_option('target'),
    'zip_encoding' => drush_get_option('zip_encoding'),
  );
    $cache = new IslandoraBatchFedoraObjectModelCache ($connection);
    $collections_data = retrieveDataFromDsvFile($parameters['input']);
    foreach ($collections_data as $collection) {
        $parameters['digital_commons_series_name'] = $collection->digitalCommonsSeries;
        $parameters['collection_namespace'] = $collection->namespace;
        $parameters['collection_name'] = $collection->objectId;
        $parameters['collection_pid'] = $collection->pid;
        $parameters['namespace'] = $collection->namespace . "." . $collection->objectId;
          try {
            // Could use a drush_multiple_choice() or whatever to get the list of
            // content models, if none were provided?
            $preprocessor = new IslandoraScanBatchDigitalCommons($connection, $cache, $parameters);

            // Pass the preprocessor off to run.
            $preprocessed = islandora_batch_digital_commons_handle_preprocessor($preprocessor);
            drush_log(t("SetId: @s", array('@s' => $preprocessor->getSetId())), "ok");
          } catch (Exception $e) {
              drupal_set_message(t('Error Processing Islandora collection objects from (PID %p).',
                  array('%p' =>  $parameters[collection_pid])), 'error');
              watchdog('islandora_batch_digital_commons', 'Error Processing Islandora collection objects from (PID %p).',
                  array('%p' => $parameters[collection_pid]), WATCHDOG_ERROR);
          }
    }


}

/**
 * Implements hook_islandora_batch_digital_commons_ingest().
 *
 * Ingests for the specified amount of time, if there is anything already
 * preprocessed.
 */
function drush_islandora_batch_digital_commons_ingest() {
  module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsFileInfo');
  module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsObjectInfo');
  module_load_include('php','islandora_batch_digital_commons','includes/IslandoraBatchFedoraObjectModelCache');
  module_load_include('inc','islandora_batch_digital_commons','includes/islandora_scan_batch_digital_commons');

  $parameters = array(
    'timeout' => drush_get_option('timeout', NULL),
    'ingest_set' => drush_get_option('ingest_set', NULL),
  );

  $parameters = array_filter($parameters);

  islandora_batch_digital_commons_set_operations($parameters);

  drush_backend_batch_process();
}

/**
 * Command callback; clean up processed sets.
 */
function drush_islandora_batch_digital_commons_cleanup_processed_sets() {
  $batch = array(
    'operations' => array(
      array(
        'islandora_batch_cleanup_processed_sets_batch_operation',
        array(
          drush_get_option('time', TRUE),
        ),
      ),
    ),
  );
  batch_set($batch);
  drush_backend_batch_process();
}

