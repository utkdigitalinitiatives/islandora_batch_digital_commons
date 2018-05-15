# Islandora Batch for Bepress Digital Commons

## Introduction

This module implements a batch framework, as well as a basic AWS S3/ZIP/Directory ingester.

It will ingest an export from Bepress Digital Commons. The Bepress Digital Commons export may
 be sent as a Zip file. It may be accessed either as a file to be unzipped or as an unzipped 
  Directory structure.  BePress Digital Commons may export project files via Amazon's AWS S3
  platform. 
The Digital Commons export is anticipated as having a directory structure.  The directory structure 
may change based on the collection content. For Electronic Thesis and Dissertations the organization is:

<pre>
toplevel directory
  -> collection directory (name of the directory is the name of the collection)
    -> object directory (name of the directory is the ordinal value of the object in the collection)
      -> metadata.xml (bepress's propietary xml standard for metadata of the object)
      -> primary datastream filename (a single file, referenced in metadata.xml)
      -> possibly multiple supplimentary datastream filenames (one or more files, referenced in metadata.xml)
      -> MODS (created by post processing of export as a transform from the metadata.xml)
</pre> 
The ingest is a two-step process:

* Preprocessing: The data is scanned, and a number of entries created in the
  Drupal database.  There is minimal processing done at this point, so it can
  complete outside of a batch process.
* Ingest: The data is actually processed and ingested. This happens inside of
  a Drupal batch.

## Requirements

This module requires the following system components to be installed and functional:

* [Java]
* [BaseX]

### Instructions for Installation of Java and BaseX on Ubuntu (debian) and Redhat (rpm) systems.

AWS client needs to be installed

Each release of the AWS SDK for PHP ships with a zip file containing all of the classes and dependencies you need to run the SDK. Additionally, the zip file includes a class autoloader for the AWS SDK for PHP and all of its dependencies.

To get started, you must download the zip file, http://docs.aws.amazon.com/aws-sdk-php/v3/download/aws.zip unzip it into your project to a location of your choosing,  and include the autoloader: require '/path/to/aws-autoloader.php';

<pre>
sudo mkdir -p /usr/local/share/php5/php-aws

sudo curl -L "http://docs.aws.amazon.com/aws-sdk-php/v3/download/aws.zip" > /tmp/aws.zip

sudo unzip /tmp/aws.zip -d /usr/local/share/php5/php-aws

sudo rm /tmp/aws.zip
</pre>

Please note the require at the top of this file. please correct this location to accurately ..

Install baseX to

<pre>

curl -L "http://files.basex.org/releases/8.6.7/BaseX867.zip" >> /tmp/BaseX867.zip

unzip /tmp/BaseX867.zip -d /usr/local/lib/

rm /tmp/BaseX867.zip

</pre>

If you have php7 on ubuntu you are so so very lucky!

<pre>
sudo apt-get install php7.0-zip
</pre>

otherwise, install the the php development package in order to get pear and pecl support
 
 <pre>
 sudo apt-get  install php5-dev
 sudo apt-get install libzip2
 pear config-set php_ini /etc/php5/apache2/php.ini
 pecl config-set php_ini /etc/php5/apache2/php.ini
 sudo apt-get install zip (does not install on ubuntu 14.04 (Trusty)
 sudo pecl install zip-1.13.5 (install on ubuntu 14.04 (Trusty)
 </pre>

 results should look like
 
 <pre>
 Build process completed successfully
 Installing '/usr/lib/php5/20121212/zip.so'
 install ok: channel://pecl.php.net/zip-1.13.5
 Extension zip enabled in php.ini
 </pre>
if you see something similar to the following
<pre>
Build process completed successfully
Installing '/usr/lib/php5/20121212/zip.so'
Install ok: channel://pecl.php.net/zip-1.13.5 configuration option "php_ini" is not set to php.ini location
</pre>
 You should add "extension=zip.so" to php.ini then add to 
  /etc/php5/apache2/php.ini
  or(but not both) 
 /etc/php5/cli/php.ini 
 <pre>extension=/usr/lib/php5/20121212/zip.so  </pre>

## And More Requirements!

This module requires the following modules/libraries:

* [Islandora Batch](https://github.com/islandora/islandora_batch)
* [Islandora](https://github.com/islandora/islandora)
* [Tuque](https://github.com/islandora/tuque)


Additionally, installing and enabling [Views](https://drupal.org/project/views)
will allow additional reporting and management displays to be rendered.


# Installation

Install as usual, see [this](https://drupal.org/documentation/install/modules-themes/modules-7) for further information.

## Configuration

The component relies on configuration via a Delimiter Separated Value(DSV) file. Any type of DSV is supported, but the
the code will need to be extended to override the defaults :( sorry ) . The DSV file requires different configuration
values and structure depending upon whether the export from  BePress Digital Commons is as a downloaded ZIP file, either
expanded into a directory structure or not, or from Amazon's AWS S3 service. 

The input file must be a delimiter separated value file.  The format expected for the file is found in the 
islandora_batch_digital_commons.module with the declaration of three constants:

* ISLANDORA_BATCH_DIGITAL_COMMONS_DELIMITER
* ISLANDORA_BATCH_DIGITAL_COMMONS_ENCLOSURE
* ISLANDORA_BATCH_DIGITAL_COMMONS_ESCAPE

If from a Zip file then the structure (the following 3 columns in order) of the file should be:

digital_commons_series_name, collection_namespace, collection_name

digital_commons_series_name: The name given to the BePress subcollection.
collection_namespace: The namespace of the TRACE collection of which any newly created object will be a child
collection_name: The name/identifier of the TRACE collection of which any newly created object will be a child

For Example:

"utk_gradthes", "utk.ir", "td"
"utk_graddiss", "utk.ir", "td"

If from Amazon's AWS S3 service then the structure (the following 3 columns in order) of the file should be:

aws_bucket_name, aws_filter_path, digital_commons_series_name, collection_namespace, collection_name, basex_bepress_mods_transform

aws_bucket_name: The name of the Bucket on the AWS S3 service that is populated by BePress
aws_filter_path: Sometimes there is more needless directory structure to be navigated before finding the prize, if not leave blank
digital_commons_series_name: The name given to the BePress subcollection.
collection_namespace: The namespace of the TRACE collection of which any newly created object will be a child
collection_name: The name/identifier of the TRACE collection of which any newly created object will be a child
transform_file:  UTK uses XPath and BaseX (which requires Java) to parse BePress's proprietary xml file. 

"bepress-small-test", "archive/trace.tennessee.edu", "utk_gradthes", "utk.ir", "td", "bepress-to-mods.xq"

The DSV file describes the relation between the Digital Common's collections and the new TRACE collections at UTK.  
Every object parsed from Digital Commons will become an object in a UTK TRACE collection.  
Digital Common's collections will be collapsed into fewer UTK TRACE collections. UTK TRACE collections are structured
hierarchically from a singularly rooted namespace.



## Documentation

The general idea  is to take an archival dump of your Digital Commons repository and import all the curated content
to Islandora 7/Fedora 3.

Further documentation for this module is available at 
[our wiki](https://wiki.duraspace.org/display/ISLANDORA/Islandora+Batch)

### Usage

The base ZIP/directory preprocessor can be called as a drush script (see `drush help islandora_batch_scan_preprocess` 
for additional parameters):

`drush ibdcsp --input=/path/to/dsvfile  --type=AWS `




This will populate the queue (stored in the Drupal database) with base entries.

For the base scan, files are grouped according to their basename (without extension). DC, MODS or MARCXML stored in a 
*.xml or binary MARC stored in a *.mrc will be transformed to both MODS and DC, and the first entry with another 
extension will be used to create an "OBJ" datastream. Where there is a basename with no matching .xml or .mrc, some 
XML will be created which simply uses the filename as the title.

The queue of preprocessed items can then be processed:

`drush ibdci`


### Customization

Custom ingests can be written by [extending](http://github.com/Islandora/islandora_batch/wiki/How-To-Extend) any of the 
existing preprocessors and batch object implementations. Checkout the 
[example implemenation](http://github.com/Islandora/islandora_batch/wiki/Example-Implementation-Tutorial) for more 
details.

## Troubleshooting/Issues

Having problems or solved a problem? Check out the Islandora google groups for a solution.

* [Islandora Group](https://groups.google.com/forum/?hl=en&fromgroups#!forum/islandora)
* [Islandora Dev Group](https://groups.google.com/forum/?hl=en&fromgroups#!forum/islandora-dev)

## Maintainers/Sponsors

If you are reading this and wishing it did something different, then you can make it do something different by extending
the code. Or I guess you can try and contact me to see if I have some time to help.

Current maintainers:

* [Robert Patrick Waltz](https://github.com/robert-patrick-waltz)

## Development

If you would like to contribute to this module, please check out [CONTRIBUTING.md](CONTRIBUTING.md). In addition, we 
have helpful 
[Documentation for Developers](https://github.com/Islandora/islandora/wiki#wiki-documentation-for-developers) info, as 
well as our [Developers](http://islandora.ca/developers) section on the [Islandora.ca](http://islandora.ca) site.

## License

[GPLv3](http://www.gnu.org/licenses/gpl-3.0.txt)
