<?php
    /**
     * Allow the pattern to be set differently.
     */

     
function find_all_files($dir) 
{ 
  $result=array();
  $filelist = scandir($dir); 
  foreach($filelist as $file) 
    { 
    if ($file === '.' || $file === '..') 
    	{
    	continue;
    	} 
    if (is_file("$dir/$file")) 
    	{
    	$result[]="$dir/$file";
    	continue;
    	} 
    foreach(find_all_files("$dir/$file") as $file) 
      { 
      $result[]=$file; 
      } 
    } 
  return $result; 
}  
/**
 * Scan the directory with file_scan_directory().
*/
function scanDirectory($target)
  {
  $target_path = realpath($target);
  $directory_contents = find_all_files( $target_path);
  return $directory_contents;
  }
    
    
function filterFiles($fileStorage)
  {
  $grouped = array();

  // turns out we need a file we know will be at the same level as an object id, metadata.xml
  // should reside in the object directory
  // with all parent directories becoming the DigitalCommonsObjectId
  foreach ($fileStorage as $value) 
    {
    $filename = basename($value);

    switch ($filename) 
      {
      case (preg_match("/^\d+\-/", $filename) ? true : false ) :
        { 
        break;
        }
      case ('stamped.pdf') :
        {
        unlink($value);
        break; 
        }
      case ('metadata.xml') :
        {
        break;
        }
      case (preg_match("/\.pdf$/", $filename) ? true : false ) :
        {
        break;
        }
      default:
        {
        unlink($value);
        break;
        }
      }
    }
  return $grouped;
  }
    
/**
 * Perform preprocessing of the scanned resources.
*/
function preprocess($target)
  {
  $fileStorage = scanDirectory($target);

  $grouped = filterFiles($fileStorage);
  }
    
preprocess("/gwork/rwaltz/basex-bepress-to-mods/trace/utk_graddiss");
