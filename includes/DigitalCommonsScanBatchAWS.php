<?php
/**
 * Created by PhpStorm.
 * User: rwaltz
 * Date: 1/23/18
 * Time: 1:36 PM
 */
//This XML module is not provided on a default php (v7) install on Debian GNU/Linux
// sudo apt-get install php-xml


// As drupal administrator, go to the modules in the administrative toolbar
// click on the install new module button
// you can install from an url provided you use the following (or something similar)
// https://ftp.drupal.org/files/projects/awssdk-7.x-5.4.zip

// Once it is installed (I had to change permissions on the underlying drupal directory for some reason?
// it may be activated and then configured

// If you do not want to save the aws_key and aws_secret in the gui then you may
// place those settings in the php site settings.php file
//
// Place these settings in the site's settings.php file located in
// $DRUPAL_HOME/sites/default/settings.php
// $conf['aws_key'] = '...';
// $conf['aws_secret'] = '...';
// $conf['aws_account_id'] = '...';
// $conf['aws_canonical_id'] = '...';

//

// Include the SDK using the Composer autoloader

use Aws\Credentials\CredentialProvider;
use Aws\S3\S3Client;

class IslandoraScanBatchDigitalCommonsAWS extends IslandoraScanBatchDigitalCommonsBase
{

    // Change to FALSE if one wants to take control over hierarchical structures.
    // @todo Make zip scan respect this.
    public $recursiveScan = TRUE;
    protected $collection_item_namespace;
    private $collection_policy_xpath_str = '/islandora:collection_policy/islandora:content_models/islandora:content_model/@pid';
    private $MAX_SUBDIRECTORY_DEPTH_FOR_COLLECTIONS = 20;
    private $object_model_cache;
    private $s3Client;
    private $batch_index;
    private $tmp_directory;
    /**
     * Constructor must be able to receive an associative array of parameters.
     *
     * @param array $parameters
     *   An associative array of parameters for the batch process. These will
     *   probably just be the result of a simple transformation from the
     *   command line, or something which could have been constructed from a
     *   form.
     *   Available parameters are from the particular concrete implementation.
     */
    public function __construct( $connection,  $object_model_cache, $parameters)
    {
        parent::__construct($connection,  $object_model_cache, $parameters);
        // $this->root_pid = variable_get('islandora_repository_pid', 'islandora:root');
        $this->repository = $this->connection->repository;
        $this->object_model_cache = $object_model_cache;
        $this->collection_item_namespace = $parameters['namespace'];
        $provider = CredentialProvider::ini();
// Cache the results in a memoize function to avoid loading and parsing
// the ini file on every API operation.

// the provider file is in the default location,
// ~/.aws/credentials
        $provider = CredentialProvider::memoize($provider);

        $this->s3Client = new Aws\S3\S3Client([
            'version' => 'latest',
            'region'  => 'us-east-1',
            'credentials' => $provider
        ]);

    }

    /**
     * Get a listing of "file-object"-like entries.
     *
     * @return array
     *   An associative array of stdClass objects representing files. Array keys
     *   are URIs relative to the "target", and the objects properties include:
     *   - uri: A string containing the complete URI of the resource.
     *   - filename: The filename.
     *   - name: The filename without its extension.
     */
    protected function scan()
    {
        return $this->harvestAWS();
    }

    /**
     * Get the fullpath to the target directory in which a collection resides.
     * Not certain if this is even needed anymore.
     *
     */
    protected function getTarget()
    {
        return $this->parameters['target'];
    }

    /**
     * Get the target collection pid
     *
     */
    protected function getCollectionPID()
    {
        return $this->parameters['collection_pid'];
    }


    /**
     * Get the target collection namespace. The namespace + the PID will make the unique identifier for
     * the new objects.
     *
     */
    protected function getCollectionName()
    {
        return $this->parameters['collection_name'];
    }
    /**
     * This should be the last item on the filter path
     */
    protected function getDigitalCommonsSeriesName()
    {
        return $this->parameters['digital_commons_series_name'];
    }
    /**
     * Get the target collection name. appended to the target will
     * determine the full path of where to search for target resources
     * this is the AWS S3 bucket name
     */
    protected function getAWSBucketName()
    {
        return $this->parameters['aws_bucket_name'];
    }
    /**
     * Get the target collection name. appended to the target will
     * determine the full path of where to search for target resources
     * this is the AWS S3 bucket name
     */
    protected function getAWSFilterPath()
    {
        return $this->parameters['aws_filter_path'];
    }
    /**
     * Get the target collection namespace.
     *
     */
    protected function getCollectionNamespace()
    {
        return $this->parameters['collection_namespace'];
    }

    /**
     * Get the type of the target resource.
     *
     * Prefixed with "scan_" to determine which method gets called to generate
     * the list of resource.
     */
    protected function getTargetType()
    {
        return $this->parameters['type'];
    }

    /**
     * Allow the pattern to be set differently.
     */
    protected static function getPattern()
    {
        return '/.*/';
    }

    private function harvestAWS($target)
    {
        $fileStorage = new SplObjectStorage();
        $directory_contents = scan_aws_s3();
        foreach ($directory_contents as $value) {
            $file = new stdClass();
            $file->uri = $value;
            $file->filename = $value;
            $file->name = pathinfo($value, PATHINFO_FILENAME);
            $fileStorage->attach($value);
        }
        return $fileStorage;
    }

    private function scan_aws_s3() {

        $batch_temp_file = tempnam(sys_get_temp_dir(), "A" . getSetId());
        $serialize_object_marker = "---XXX---";
        $errors = array();
        $harvest_list = array();
        $prefix = $this->getAWSFilterPath() . '/' . $this->getDigitalCommonsSeriesName();
        $max_page_count = 1000;
        $delimiter = ';';
        $marker = null;
        $params = null;
        $iteration = 0;
        try {
            do {
                if (isset($params)) {
                    $params['Marker'] = $marker;
                } else {
                    $params = array('Bucket' => $this->getAWSBucketName(),
                        'Delimiter' => $delimiter,
                        'MaxKeys' => $max_page_count,
                        'Prefix'  =>  $prefix);
                }
                $command = $this->s3Client->getCommand('ListObjects', $params);
                // $command['MaxKeys'] = 100;
                $result = $this->s3Client->execute($command);
                $marker = $result->get('NextMarker');
                if ($result->get('Contents')) {
                    $serialized_harvest = serialize($result->get('Contents')) . $serialize_object_marker;
                    // where to put the temporary downloaded and serialized list?
                    file_put_contents($batch_temp_file, ($serialized_harvest), FILE_APPEND);
                }
            } while ($result->get('IsTruncated') && isset($marker));
            if ($result->get('IsTruncated') ) {
                $errors[] = sprintf('The number of keys greater than %u, the first part is shown', count($harvest_list));
            }
        } catch (S3Exception $e) {
            $errors[] = sprintf('Cannot retrieve objects: %s', $e->getMessage());
        }
// where to pull the completed serialized list and deserialize it where?
        $file_contents = file_get_contents($batch_temp_file);
        $data_for_life = explode($serialize_object_marker, $file_contents);
        foreach ($data_for_life as $data)
        {
            $unserialized_harvest = unserialize($data);
            $harvest_list[] = $unserialized_harvest;
        }
        unlink($batch_temp_file);
        return harvest_list;
    }

}
