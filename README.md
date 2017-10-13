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


Additionally, installing and enabling [Views](https://drupal.org/project/views)
will allow additional reporting and management displays to be rendered.


# Installation

Install as usual, see [this](https://drupal.org/documentation/install/modules-themes/modules-7) for further information.

## Configuration

N/A

## Documentation

Further documentation for this module is available at [our wiki](https://wiki.duraspace.org/display/ISLANDORA/Islandora+Batch)

### Usage

The base ZIP/directory preprocessor can be called as a drush script (see `drush help islandora_batch_scan_preprocess` for additional parameters):

Drush made the `target` parameter reserved as of Drush 7. To allow for backwards compatability this will be preserved.

Drush 7 and above:

`drush -v -u 1 --uri=http://localhost islandora_batch_scan_preprocess --type=zip --scan_target=/path/to/archive.zip`

Drush 6 and below:

`drush -v -u 1 --uri=http://localhost islandora_batch_scan_preprocess --type=zip --target=/path/to/archive.zip`

This will populate the queue (stored in the Drupal database) with base entries.

For the base scan, files are grouped according to their basename (without extension). DC, MODS or MARCXML stored in a *.xml or binary MARC stored in a *.mrc will be transformed to both MODS and DC, and the first entry with another extension will be used to create an "OBJ" datastream. Where there is a basename with no matching .xml or .mrc, some XML will be created which simply uses the filename as the title.

The queue of preprocessed items can then be processed:

`drush -v -u 1 --uri=http://localhost islandora_batch_ingest`

A fuller example, which preprocesses large image objects for inclusion in the collection with PID "yul:F0433", is:

Drush 7 and above:

`drush -v -u 1 --uri=http://digital.library.yorku.ca islandora_batch_scan_preprocess --content_models=islandora:sp_large_image_cmodel --parent=yul:F0433 --parent_relationship_pred=isMemberOfCollection --type=directory --target=/tmp/batch_ingest`

Drush 6 and below:

`drush -v -u 1 --uri=http://digital.library.yorku.ca islandora_batch_scan_preprocess --content_models=islandora:sp_large_image_cmodel --parent=yul:F0433 --parent_relationship_pred=isMemberOfCollection --type=directory --scan_target=/tmp/batch_ingest`

then, to ingest the queued objects:

`drush -v -u 1 --uri=http://digital.library.yorku.ca islandora_batch_ingest`

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
