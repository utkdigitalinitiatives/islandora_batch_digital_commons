# Islandora Batch from bepress Digital Commons

## Introduction

This module implements a batch framework, as well as a basic ZIP/directory ingester.

It will ingest an export from bepress Digital Commons.  The Digital Commons
export is anticipated as having a directory structure.  The directory structure may change based on the collection content. For Electronic Thesis and Dissertations the organization is:

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

This module requires the following modules/libraries:

* [Islandora Batch](https://github.com/islandora/islandora_batch)
* [Islandora](https://github.com/islandora/islandora)
* [Tuque](https://github.com/islandora/tuque)
* [AWS S3 PHP SDK](https://github.com/aws/aws-sdk-php)

Additionally, installing and enabling [Views](https://drupal.org/project/views)
will allow additional reporting and management displays to be rendered.


# Installation

Install as usual, see [this](https://drupal.org/documentation/install/modules-themes/modules-7) for further information.

All pre-required modules must be properly installed before installing islandora_batch_digital_commons

## Configuration

## Getting Started With AWS S3 PHP Client

1. **Sign up for AWS** – Before you begin, you need to
   sign up for an AWS account and retrieve your [AWS credentials][docs-signup].
2. **Minimum requirements** – To run the SDK, your system will need to meet the
   [minimum requirements][docs-requirements], including having **PHP >= 5.5**
   compiled with the cURL extension and cURL 7.16.2+ compiled with a TLS
   backend (e.g., NSS or OpenSSL).
3. **Install the SDK** – Using [Composer] is the recommended way to install the
   AWS SDK for PHP. The SDK is available via [Packagist] under the
   [`aws/aws-sdk-php`][install-packagist] package. Please see the
   [Installation section of the User Guide][docs-installation] for more
   detailed information about installing the SDK through Composer and other
   means.
4. **Using the SDK** – The best way to become familiar with how to use the SDK
   is to read the [User Guide][docs-guide]. The
   [Getting Started Guide][docs-quickstart] will help you become familiar with
   the basic concepts.


## Documentation

Further documentation for this module is available at [our wiki](https://wiki.duraspace.org/display/ISLANDORA/Islandora+Batch)

### Usage

The base ZIP/directory preprocessor can be called as a drush script (see `drush help islandora_batch_scan_preprocess` for additional parameters):

`drush ibdcsp --input=/path/to/dsvfile  --type=directory --target=/path/to/archive `

The input file must be a delimiter separated value file.  The format expected for the file is found in the islandora_batch_digital_commons.module with the declaration of three constants:
* ISLANDORA_BATCH_DIGITAL_COMMONS_DELIMITER
* ISLANDORA_BATCH_DIGITAL_COMMONS_ENCLOSURE
* ISLANDORA_BATCH_DIGITAL_COMMONS_ESCAPE

The file describes the relation between the Digital Common's collections and the new TRACE collections.  Every object parsed from Digital Commons will become an object in a TRACE collection.  Digital Common's collections will be collapsed into fewer TRACE collections. 
The file expects the following 4 columns in order:
digitalCommonsSeries parent namespace objectId

digitalCommonsSeries: The name given to the BePress subcollection.
parent:  The parent object that any parsed object in the digitalCommonsSeries will become a child of(currently unused, and should be deprecated)
namespace: The namespace of the TRACE collection of which any newly created object will be a child
objectId: The name/identifier of the TRACE collection of which any newly created object will be a child


This will populate the queue (stored in the Drupal database) with base entries.

For the base scan, files are grouped according to their basename (without extension). DC, MODS or MARCXML stored in a *.xml or binary MARC stored in a *.mrc will be transformed to both MODS and DC, and the first entry with another extension will be used to create an "OBJ" datastream. Where there is a basename with no matching .xml or .mrc, some XML will be created which simply uses the filename as the title.

The queue of preprocessed items can then be processed:

`drush ibdci`


### Customization

Custom ingests can be written by [extending](http://github.com/Islandora/islandora_batch/wiki/How-To-Extend) any of the existing preprocessors and batch object implementations. Checkout the [example implemenation](http://github.com/Islandora/islandora_batch/wiki/Example-Implementation-Tutorial) for more details.

## Troubleshooting/Issues

Having problems or solved a problem? Check out the Islandora google groups for a solution.

* [Islandora Group](https://groups.google.com/forum/?hl=en&fromgroups#!forum/islandora)
* [Islandora Dev Group](https://groups.google.com/forum/?hl=en&fromgroups#!forum/islandora-dev)

## Maintainers/Sponsors

Current maintainers:

* [Robert Patrick Waltz](https://github.com/robert-patrick-waltz)

## Development

If you would like to contribute to this module, please check out [CONTRIBUTING.md](CONTRIBUTING.md). In addition, we have helpful [Documentation for Developers](https://github.com/Islandora/islandora/wiki#wiki-documentation-for-developers) info, as well as our [Developers](http://islandora.ca/developers) section on the [Islandora.ca](http://islandora.ca) site.

## License

[GPLv3](http://www.gnu.org/licenses/gpl-3.0.txt)
