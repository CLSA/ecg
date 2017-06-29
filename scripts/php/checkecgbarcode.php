<?php

require_once( 'util.class.php' );
util::initialize();

function xml_attribute($object, $attribute)
{
  if(isset($object[$attribute]))
    return (string) $object[$attribute];
  else
    return false;
}

function get_barcode($infile)
{
  $simple_xml_obj = simplexml_load_file( $infile );
  $group_xml = array();
  $group_xml['patient'] =
    array(
      'root'=>'PatientInfo',
      'attributes'=>false,
      'keys'=>array('PID'));
  $group_data = array();
  foreach($group_xml as $group_key=>$group_values)
  {
    $group_data[$group_key] = array();
    foreach($group_values['keys'] as $data_key)
    {
      $path = $group_values['root'] .'/'. $data_key;
      $match = $simple_xml_obj->xpath('//' . $path);
      if(false === $match)
      {
        util::out('cannot find ' . $path);
        die();
      }

      $group_data[$group_key][$data_key]['value'] = array();
      if($group_values['attributes'])
        $group_data[$group_key][$data_key]['units'] = array();
      foreach($match as $data)
      {
        $value = $data->__toString();
        $str =  $group_key . ': '. $data_key . ' = ' . $value;
        $attr = null;
        if($group_values['attributes'])
        {
          $attr = xml_attribute($data, 'units');
          $str = $str . ' ('. $attr .')';
          $group_data[$group_key][$data_key]['units'][] = $attr;
        }
        $group_data[$group_key][$data_key]['value'][] = $value;
      }
    }
  }

  return current($group_data['patient']['PID']['value']);
}

if(1 == count($argv) || '' == $argv[1] || empty($argv[1]))
{
  util::out('usage: checkecgbarcode input_file.csv data_dir');
  exit;
}

$fdata = $argv[1];
$datadir = $argv[2];

$participant_list = array();
$file = fopen($fdata,'r');
if(false === $file)
{
  out('file ' . $fdata . ' cannot be opened');
  die();
}

$line = NULL;
$line_count = 0;
$nlerr = 0;

while(false !== ($line = fgets($file)))
{
  $line_count++;
  $line = trim($line, "\n\"\t");
  $line = explode('","',$line);
  $uid = $line[0];
  $barcode = $line[1];
  $participant_list[$uid] = $barcode;
}
fclose($file);

// for each UID, download the xml file
$err_count=0;
foreach($participant_list as $uid=>$barcode)
{
  $filename = $datadir . '/' . $uid . '.xml';
  if(!file_exists($filename))
  {
    util::out('Error: cannot find ' . $filename);
    continue;
  }
  $check = get_barcode($filename);
  if($check != $barcode)
  {
    util::out($uid  . ': error ' . $barcode . ' < > ' . $check);
    $err_count++;
  }
  else
  {
    util::out($uid  . ': OK ' . $barcode . ' == ' . $check);
  }
}

if(0 == $err_count) util::out('no errors');
else util::out('found ' . $err_count . ' errors in ' . count($participant_list) . ' files');

?>
