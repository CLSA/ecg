<?php

$util_path = '../../../php_util/';
require_once( $util_path . 'phplot-6.2.0/phplot.php' );
require_once( $util_path . 'util.class.php' );
util::initialize();

function xml_attribute($object, $attribute)
{
  if(isset($object[$attribute]))
    return (string) $object[$attribute];
  else
    return false;
}

function annotate_plot($img, $plot)
{
  global $point_data;

  $red = imagecolorresolve($img,255,0,0);
  foreach($point_data as $values)
  {
    list($x, $y) = $plot->GetDeviceXY($values['x'], $values['y']);
    imagefilledellipse($img, $x, $y, 6, 6, $red);
    $plot->DrawText('', 0, $x, $y, $red, $values['symbol'], 'center', 'top');
  }
}

if(1 == count($argv) || '' == $argv[1] || empty($argv[1]))
{
  util::out('usage: plotvcg input_file [outfile]');
  exit;
}
$infile = $argv[1];
$simple_xml_obj = simplexml_load_file( $infile );
$verbose = true;

$pqrst_xml=
  array(
    'root'=>'VectorLoops',
    'num'=>'ChannelSampleCountTotal',
    'res'=>'Resolution',
    'pts'=>array(
      'POnset'=>'P0',
      'POffset'=>'P1',
      'QOnset'=>'Q0',
      'QOffset'=>'Q1',
      'TOffset'=>'T')
     );

$group_xml=array();
$group_xml['frontal']=
  array(
    'root'=>'VectorLoops',
    'data'=>'Frontal',
    'id'=>'Lead',
    'axes'=>array('X','-Y')
     );
$group_xml['horizontal']=
  array(
    'root'=>'VectorLoops',
    'data'=>'Horizontal',
    'id'=>'Lead',
    'axes'=>array('X','Z')
     );
$group_xml['sagittal']=
  array(
    'root'=>'VectorLoops',
    'data'=>'Sagittal',
    'id'=>'Lead',
    'axes'=>array('-Z','-Y')
    );

  // get the sampling conversion from uVperLsb to mV
  //
  $path = $pqrst_xml['root'] . '/' . $pqrst_xml['res'];
  $data = current($simple_xml_obj->xpath('//' . $path));
  $y_resolution = $data->__toString();
  $y_resolution = $y_resolution / 1000.0;

  // get the axis values for the planes
  //
  $axis_values=array();
  foreach($group_xml as $plane=>$values)
  {
    $path = $values['root'] . '/' . $values['data'];
    $match = $simple_xml_obj->xpath('//' . $path);
    foreach($match as $data)
    {
      $attr = xml_attribute($data, $values['id']);
      $axis_values[$attr] = explode(',', preg_replace('/\s+/', '', $data->__toString()));
      for($i = 0;$i < count($axis_values[$attr]); $i++) $axis_values[$attr][$i] *= $y_resolution;
    }
  }

  // get the pqrst point indexes
  $path = $pqrst_xml['root'] . '/' . $pqrst_xml['num'];
  $data = current($simple_xml_obj->xpath('//' . $path));
  $sample_count = $data->__toString();

  $path = $pqrst_xml['root'] . '/' . $pqrst_xml['res'];
  $data = current($simple_xml_obj->xpath('//' . $path));
  $resolution = $data->__toString();

  $point_values = array();
  foreach($pqrst_xml['pts'] as $key => $symbol)
  {
    $path = $pqrst_xml['root'] . '/' . $key;
    $data = current($simple_xml_obj->xpath('//' . $path));
    $point_values[$key]['index']=$data->__toString();
    $point_values[$key]['symbol']=$symbol;
  }

  $num_plot_u = 3;
  $num_plot_v = 1;
  $width_plot = 600;
  $height_plot = 600;

  $width_total = $num_plot_u*$width_plot;
  $height_total = $num_plot_v*$height_plot;

  $du = $width_plot;
  $xmargin = 50;
  $ymargin = 50;

  $plot = new PHPlot($width_total, $height_total);
  $plot->SetPlotType('lines');
  $plot->SetDataType('data-data');
  $plot->SetPrintImage(0);
  $plot->SetIsInline(true);

  $outfile = 'vectorloops.jpg';
  if(3 == count($argv) && !empty($argv[2]))
  {
    $outfile = $argv[2];
  }
  $plot->SetOutputFile($outfile);

  $u = 0;
  foreach($group_xml as $plane=>$values)
  {
    $xkey = $values['axes'][0];
    $ykey = $values['axes'][1];
    $plot->SetPlotAreaPixels(
      $u + $xmargin,
      $ymargin,
      $u + $du - $xmargin,
      $height_plot - $ymargin );
    $xmin = min($axis_values[$xkey]);
    $xmax = max($axis_values[$xkey]);
    $ymin = min($axis_values[$ykey]);
    $ymax = max($axis_values[$ykey]);
    $xrange = max(array(abs($xmin),abs($xmax)));
    $yrange = max(array(abs($ymin),abs($ymax)));

    $plot->SetPlotAreaWorld(-$xrange,-$yrange,$xrange,$yrange);
    $plot->SetYAxisPosition(0);

    $plot->SetLegend( $plane );
    $plot->SetLegendStyle('left', 'none');
    $plot->SetLegendPosition(0, 0, 'plot', 0.5, 0);
    $plot->SetXTitle($xkey . ' mV');
    $plot->SetYTitle($ykey . ' mV');

    if($verbose)
      util::out('plotting ' . $plane . '(' . $xkey . ',' . $ykey . ') vector loop with '. $sample_count . ' points');
    $xydata = array();
    for($i=0; $i<$sample_count; $i++)
    {
      $xydata[] = array('',$axis_values[$xkey][$i],$axis_values[$ykey][$i]);
    }

    $plot->SetDataValues($xydata);

    // label the key P, Q, T event locations
    $point_data = array();
    foreach($point_values as $variable=>$data)
    {
      $index = $data['index']-1;
      $symbol = $data['symbol'];
      $point_data[] = array(
        'x'=>$xydata[$index][1],
        'y'=>$xydata[$index][2],
        'symbol'=>$symbol);

      if($verbose)
        util::out(
          'point attribute: ' .  $variable . ' at index ' . $index );

    }

    $plot->SetCallback('draw_all', 'annotate_plot', $plot);
    $plot->DrawGraph();
    $plot->RemoveCallback('draw_all');
    $plot->SetLegend(NULL);
    $u += $du;
  }

  $plot->PrintImage();

?>
