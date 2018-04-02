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
      format_string('drush -v  ibdcsp --user=admin   --type=AWS --input=/var/www/drupal/sites/all/modules/islandora_batch_digital_commons/config/trace_ingest_bepress.csv',
      array(
        '@target' => DRUSH_VERSION >= 7 ? 'scan_target' : 'target',
      )),
    ),
    'options' => array(
      'input' => array(
        'description' => 'The absolute path to the delimiter-separated file. ' ,
        'required' => TRUE,
          'value' => 'required',
      ),
      'target' => array(
        'description' => 'The target to directory to scan ' .
        'models which are all applied to each ingested object.',
        'required' => FALSE,
          'value' => 'required',
      ),
      'type' => array(
        'description' => 'Either "ZIP", "DIR", "AWS". The zip importer is' .
        " unstable with large files (~2GB). Consider alternative methods" .
        " such as unzipping your Zip file and using Islandora Batch's" .
        "`--directory` option.",
        'required' => TRUE,
          'value' => 'required',
      ),
        'transform_uri' => array(
            'description' => 'The URI that holds a zip file containing all the transforms needed by the project. ' .
                " The module will attempt to download and extract a zip file that contains all transforms" .
                " needed by your migration. Default is https://github.com/utkdigitalinitiatives/basex-bepress-to-mods/archive/master.zip" ,
            'required' => FALSE,
            'value' => 'required',
        ),
        'tmp_scan_directory' => array(
            'description' => 'The full path to the tmp directory that all scanned directories should be downloaded to,. ' .
                ' if using AWS',
            'required' => FALSE,
            'value' => 'required'
        ),
        'java' => array(
            'description' => 'The full path to the Java installation that should be used to run BaseX. ' .
                ' BaseX needs to be installed and configured properly too.',
            'required' => FALSE,
            'value' => 'required'
        ),
      'zip_encoding' => array(
        'description' => 'The encoding of filenames contained in ZIP ' .
        'archives:Only relevant with --scan_target=zip. Defaults to the native ' .
        'encoding being used by PHP.',
          'required' => FALSE,
        'value' => 'required',
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
    module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsFedoraObjectModelCache');
    module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsFileInfo');
    module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsObjectInfo');
    module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsScanBatchAWS');
    module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsScanBatchBase');
    module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsScanBatchFactory');
    module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsScanBatchObject');
    module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsTransformBaseX');
    module_load_include('inc','islandora_batch_digital_commons','includes/ingest_digital_commons.batch');
    putenv( "HOME=/etc/php5" );

  drupal_static_reset('islandora_get_tuque_connection');
  $connection = islandora_get_tuque_connection();
  $java_option = drush_get_option('java');
  if (isset($java_option)) {
      variable_set('islandora_batch_java', $java_option);
  }
  $parameters = array(
    'type' => drush_get_option('type'),
    'input' => drush_get_option('input'),
    'target' => drush_get_option('target'),
    'zip_encoding' => drush_get_option('zip_encoding'),
    'transform_uri' => drush_get_option('transform_uri'),
    'tmp_scan_directory' => drush_get_option('tmp_scan_directory'),
  );
    $scan_factory = new DigitalCommonsScanBatchFactory();
    $cache = new DigitalCommonsFedoraObjectModelCache ($connection);
    $collections_data = islandora_batch_digital_commons_retrieveDataFromDsvFile($parameters['input']);
    foreach ($collections_data as $collection) {
        $parameters['digital_commons_series_name'] = $collection->digitalCommonsSeries;
        $parameters['collection_namespace'] = $collection->namespace;
        $parameters['collection_name'] = $collection->objectId;
        $parameters['collection_pid'] = $collection->pid;
        $parameters['namespace'] = $collection->namespace . "." . $collection->objectId;

        $parameters['aws_bucket_name'] = $collection->aws_bucket_name;
        $parameters['aws_filter_path'] = $collection->aws_filter_path;

        $parameters['basex_bepress_mods_transform_name'] = $collection->basex_bepress_mods_transform_name;
          try {
            // Could use a drush_multiple_choice() or whatever to get the list of
            // content models, if none were provided?

            $preprocessor = $scan_factory->getIslandoraScanBatchInstance($connection, $cache, $parameters);

            // Pass the preprocessor off to run.
            $preprocessed = islandora_batch_digital_commons_handle_preprocessor($preprocessor);
            drush_log(t("SetId: @s", array('@s' => $preprocessor->getSetId())), "ok");
          } catch (Exception $e) {
              drupal_set_message(t('Error Processing Islandora collection objects from (PID %p).',
                  array('%p' =>  $parameters['collection_pid'])), 'error');
              watchdog('islandora_batch_digital_commons', 'Error Processing Islandora collection objects from (PID %p).',
                  array('%p' => $parameters['collection_pid']), WATCHDOG_ERROR);
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
  module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsScanBatchObject');
  module_load_include('php','islandora_batch_digital_commons','includes/DigitalCommonsFedoraObjectModelCache');
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

