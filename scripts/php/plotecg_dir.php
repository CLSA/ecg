<?php

$util_path = '../../../php_util/';
require_once($util_path . 'util.class.php');
util::initialize();

function rsearch($folder, $pattern) {
  $dir = new RecursiveDirectoryIterator($folder);
  $ite = new RecursiveIteratorIterator($dir);
  $reg = new RegexIterator($ite, $pattern, RegexIterator::GET_MATCH);
  $reg->next();
  $fileList = array();
  while($reg->valid()) {
    $item = current($reg->current());
    if(file_exists($item))
      $fileList[] = $item;
    $reg->next();
  }
  return $fileList;
}

$process = 'plotecgstrip.php';

if(2 != count($argv))
{
  util::out('usage: plotecg_dir <path_to_xml_files>');
  exit;
}

$dataRoot = $argv[1];
$files = rsearch($dataRoot, '/^.+\\.(xml)$/');

// for each UID, read the xml file
foreach($files as $filename)
{
  if(file_exists($filename))
  {
    // process the file
    $outfile = str_replace('.xml', '.jpg', $filename);
    $str = 'php ' . $process . ' ' . $filename . ' ' . $outfile;
    exec( $str );
    util::out('plotting report file ' . $outfile . ' from input ' . $filename);
  }
}

?>
