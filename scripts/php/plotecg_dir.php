<?php

$util_path = '../../../php_util/';
require_once($util_path . 'util.class.php');
util::initialize();

$process = 'plotecgstrip.php';

if(2 != count($argv))
{
  util::out('usage: plotecg_dir <path_to_xml_files>');
  exit;
}

$dataRoot = $argv[1];
$files = util::rsearch($dataRoot, '/^.+\\.(xml)$/');

// for each UID, read the xml file
//
foreach($files as $filename)
{
  if(file_exists($filename))
  {
    // process the file
    //
    $outfile = str_replace('.xml', '.jpg', $filename);
    $str = 'php ' . $process . ' ' . $filename . ' ' . $outfile;
    exec( $str );
    util::out('plotting report file ' . $outfile . ' from input ' . $filename);
  }
}

?>
