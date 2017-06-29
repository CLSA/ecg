<?php

require_once( 'phplot-6.2.0/phplot.php' );
require_once( 'util.class.php' );
util::initialize();

function xml_attribute($object, $attribute)
{
  if(isset($object[$attribute]))
    return (string) $object[$attribute];
  else
    return false;
}

if(1 == count($argv) || '' == $argv[1] || empty($argv[1]))
{
  util::out('usage: plotecgstrip input_file [output_file]');
  exit;
}

$infile = $argv[1];
$simple_xml_obj = simplexml_load_file( $infile );

$verbose = true;
$optimize_voltage_scale = false; // compute voltage scale to prevent peak overlaps between rows
$optimize_voltage_scale_nesting = false; // compute voltage scale to prevent peak overlaps and nesting between rows
$optimize_row_scale = true; // adjust row spacing for best non-uniform converage

function scaleFunc(&$value, $key, $scale)
{
  $value *= $scale;
}

function shiftScaleFunc(&$value, $key, $params)
{
  $value = $value*$params[0] + $params[1];
}

function sumFunc()
{
  return array_sum(func_get_args());
}

function diffFunc($a,$b,$params)
{
  $num_a = count($a);
  $num_b = count($b);
  if($num_a>$num_b)
    $b = array_pad($b, $num_a, 0);
  else if($num_a<$num_b)
    $a = array_pad($a, $num_b, 0);

  array_walk($a,'shiftScaleFunc',$params);
  array_walk($b,'scaleFunc',-$params[0]);

  return min(array_map('sumFunc', $a, $b));
}

function plotEmptyBox(&$plot, $pixel_area)
{
  $plot->SetPlotBorderType('none');
  $plot->SetPlotType('points');
  $plot->SetDrawXAxis(false);
  $plot->SetDrawYAxis(false);
  $plot->SetDrawXGrid(false);
  $plot->SetDrawYGrid(false);
  $plot->SetXTickLabelPos('none');
  $plot->SetYTickLabelPos('none');
  $plot->SetXTickPos('none');
  $plot->SetYTickPos('none');
  $plot->SetPointSizes(1); // in pixels
  $plot->SetLineWidths(1);

  $plot->SetPlotAreaPixels(
    $pixel_area['x0'],
    $pixel_area['y0'],
    $pixel_area['x1'],
    $pixel_area['y1']);

  $plot->SetPlotAreaWorld(0, 0, 1, 1);
  $plot->SetDataValues(array(array('', 0, 0),array('', 1, 1)));
  $plot->DrawGraph();
}

$group_xml = array();
$group_xml['patient'] =
  array(
    'root'=>'PatientInfo',
    'attributes'=>false,
    'keys'=>array('PID'));
$group_xml['datetime'] =
  array(
    'root'=>'ObservationDateTime',
    'attributes'=>false,
    'keys'=>array(
      'Day',
      'Month',
      'Year',
      'Hour',
      'Minute',
      'Second'
    ));
$group_xml['diagnosis'] =
  array(
    'root'=>'Interpretation/Diagnosis',
    'attributes'=>false,
    'keys'=>array('DiagnosisText'));
$group_xml['measurements']=
  array(
    'root'=>'RestingECGMeasurements',
    'attributes'=>true,
    'keys'=>array(
      'VentricularRate',
      'QRSDuration',
      'PQInterval',
      'QTInterval',
      'QTCInterval',
      'PDuration',
      'RRInterval',
      'PPInterval',
      'PAxis',
      'RAxis',
      'TAxis'));

$strip_xml =
  array(
    'root'=>'StripData',
    'data'=>'WaveformData',
    'id'=>'lead',
    'num'=>'ChannelSampleCountTotal',
    'rate'=>'SampleRate',
    'resolution'=>'Resolution',
    'points'=>null
    );

  $path = $strip_xml['root'] . '/' . $strip_xml['data'];
  $match = $simple_xml_obj->xpath('//' . $path);
  if(false === $match)
  {
    util::out('cannot find ' . $path);
    die();
  }
  // lead sampling rate in Hz
  $rate = $strip_xml['rate'];
  if(!is_null($rate))
  {
    $rate = current($simple_xml_obj->xpath('//' . $strip_xml['root'] . '/' . $rate));
  }
  $dt = is_null($rate) ? 1.0 : 1.0/floatval($rate); // seconds per sample
  util::out('sampling rate = ' . $dt . ' sec' );

  // default file name
  $title = $strip_xml['root'] . '/' . $strip_xml['data'];
  $outfile = str_replace('/', '_', $title) . '.jpg';
  if(3 == count($argv) && !empty($argv[2]))
  {
    $outfile = $argv[2];
  }

  util::out('plotting to file ' . $outfile);

  $single_lead = 'II';
  $single_lead_id = 0;
  $first = true;
  $num = current($simple_xml_obj->xpath('//' . $strip_xml['root'] . '/' . $strip_xml['num']));
  $y_resolution = current($simple_xml_obj->xpath('//' . $strip_xml['root'] . '/' . $strip_xml['resolution']));
  $y_resolution = $y_resolution / 1000.0;
  $legend = array();
  $xy_plotdata = array();
  $num_total = $num;
  foreach($match as $data)
  {
    // lead label
    $attr = xml_attribute($data, $strip_xml['id']);
    // lead voltages
    $ydata = explode(',', preg_replace('/\s+/', '', $data->__toString()));

    if($verbose)
      util::out(
        $strip_xml['root'] . '/' . $strip_xml['data'] . ': ' .
        $strip_xml['id'] . ' = ' . $attr . ' size = ' . count($ydata) . ' ('.$dt.') ' );

    $xdata = array();
    for($i = 0; $i < $num; $i++)
    {
      $ydata[$i] *= $y_resolution;
      $xdata[$i] = $i*$dt;
    }
    $xy_plotdata[$attr] = array(
      'x_values' => $xdata,
      'y_values' => $ydata);

    $legend[] = $attr;
    if($single_lead == $attr)
    {
      $single_lead_id = count($legend) - 1;
      $num_total = count($xdata);
    }
  }
  $num_plot = count($legend);
  if($verbose)
    util::out('plotting ' . $title .  ' with '. $num_plot . ' leads');

  // grid (partial) plotting of 12 ECG leads
  // The standard ECG paper plot runs a single
  // continuous plot of lead II along the bottom row. Above
  // lead II, the 12 leads (including lead II again) are plotted in a 4 column
  // by 3 row grid such that each sub-plot in the
  // x direction corresponds to a quarter of the time
  // plotted along the continuous lead II plot in the
  // bottom row.
  $num_plot_u = 4;
  $num_plot_v = 4;

  // the background mm resolution grid for time and voltage
  // each block is 5 mm
  $x_num_block = 56;
  $y_num_block = 31;
  $block_resolution_mm = 5;
  $x_total_mm = $x_num_block * $block_resolution_mm;
  $y_total_mm = $y_num_block * $block_resolution_mm;

  $total_data_time = $dt * $num_total;
  $x_plot_rate = 25; // mm/sec
  $x_margin_left_block = 3; // 3 blocks
  $x_margin_right_block = 2; // 2 blocks

  $total_plot_time = ($x_num_block - ($x_margin_left_block + $x_margin_right_block)) *
    $block_resolution_mm / $x_plot_rate;
  util::out('total data time: ' . $total_data_time . ' total plot time: ' . $total_plot_time);
  if($total_plot_time < $total_data_time)
  {
    $num_total = intval(round($total_plot_time/$dt,0));
    foreach($xy_plotdata as $key => $values)
    {
      $xy_plotdata[$key]['x_values'] = array_slice($values['x_values'],0,$num_total);
      $xy_plotdata[$key]['y_values'] = array_slice($values['y_values'],0,$num_total);
    }
  }
  $num_time_per_plot = intval(round($num_total / $num_plot_u,0));
  $plot_columns = array(
    array('I','II','III','II'),
    array('aVR','aVL','aVF','II'),
    array('V1','V2','V3','II'),
    array('V4','V5','V6','II'));

  $default_dv = 6 * $block_resolution_mm;
  $x_offset_default = $x_margin_left_block * $block_resolution_mm;
  $y_offset_default = 4 * $block_resolution_mm;

  $time_start = 0;
  $plot_row_ranges = array();
  $row_min = array();
  $row_max = array();
  foreach($plot_columns as $col=>$column)
  {
    // get the min and max for the current range of values
    $row = 3;
    if(!array_key_exists($row,$row_min))
    {
      $row_min[$row] = array();
      $row_max[$row] = array();
    }
    foreach($column as $lead)
    {
      $offset = $time_start;
      $length = ($offset + $num_time_per_plot < $num_total) ? $num_time_per_plot : NULL;
      $y_values = array_slice($xy_plotdata[$lead]['y_values'], $offset, $length);
      $plot_row_ranges[$row][$lead]['min'] = min($y_values);
      $plot_row_ranges[$row][$lead]['max'] = max($y_values);
      $plot_row_ranges[$row][$lead]['values'] = $y_values;
      $row_min[$row][] = min($y_values);
      $row_max[$row][] = max($y_values);
      $row--;
    }
    $time_start += $num_time_per_plot;
  }

  $y_plot_rate = 10; // default rate
  $y_margin_mm = 5;
  if($optimize_voltage_scale)
  {
    $voltage_scales = array(10,15,20,25);
    $bottom_min = min($row_min[0]);
    $top_max = max($row_max[3]);
    foreach($voltage_scales as $rate)
    {
      $condition = array();
      $params = array($rate,$default_dv);
      foreach($plot_columns as $column)
      {
        $condition[] =
          $y_offset_default + $rate*$bottom_min - $y_margin_mm;

        $j = 1;
        $k = 2;
        if($optimize_voltage_scale_nesting)
        {
          while($j < 4)
          {
            $upper_values = $plot_row_ranges[$j][$column[$k]]['values'];
            $lower_values = $plot_row_ranges[$j-1][$column[$k+1]]['values'];
            $condition[] = diffFunc($upper_values,$lower_values,$params);
            $k--;
            $j++;
          }
        }
        else
        {
          while($j < 4)
          {
            $condition[] =
              $default_dv + $rate*($plot_row_ranges[$j][$column[$k]]['min']-$plot_row_ranges[$j-1][$column[$k+1]]['max']);
            $k--;
            $j++;
          }
        }
        $condition[] =
          $y_total_mm - $y_offset_default - 3*$default_dv - $rate*$top_max - $y_margin_mm;
      }
      $optimum = true;
      if(min($condition) < 0)
      {
        $optimum = false;
        if($verbose)
          util::out('fail condition for scale ' . $rate);
      }
      if($optimum)
      {
        if($verbose)
          util::out('success condition for scale ' . $rate);
        $y_plot_rate = $rate;
      }
    }
  }

  if($optimize_row_scale)
  {
    for($i = 0; $i < 4; $i++)
    {
      array_walk($row_min[$i],'scaleFunc',$y_plot_rate);
      array_walk($row_max[$i],'scaleFunc',$y_plot_rate);
    }
    $bottom_min = intval(round(min($row_min[0]),0));
    $top_max = intval(round(max($row_max[3]),0));

    $dv_found = $default_dv;
    $y_offset_found = $y_offset_default;
    $found = false;
    $row_offsets =
      range(4*$block_resolution_mm, 7.4*$block_resolution_mm, 1);
    $base_offsets =
      range(2*$block_resolution_mm, 4*$block_resolution_mm, 1);

    // do a fast check using no nesting
    foreach($base_offsets as $base_offset)
    {
      $test_y_offset = intval($base_offset);
      foreach($row_offsets as $row_offset)
      {
        $test_dv = intval($row_offset);
        $condition = array();
        foreach($plot_columns as $column)
        {
          $condition[] =
            $test_y_offset + $bottom_min - $y_margin_mm;

          $j = 1;
          $k = 2;
          while($j < 4)
          {
            $condition[] =
              $test_dv + $y_plot_rate*($plot_row_ranges[$j][$column[$k]]['min']-$plot_row_ranges[$j-1][$column[$k+1]]['max']);
            $j++;
            $k--;
          }

          $condition[] =
            $y_total_mm - $y_margin_mm - $test_y_offset - 3*$test_dv - $top_max;
        }
        $optimum = true;
        if(min($condition) < 0)
        {
          $optimum = false;
        }
        if($optimum)
        {
          if(!$found)
            $dv_found = $test_dv;
          else
            $dv_found = $dv_found < $default_dv ? $test_dv : $dv_found;

          $y_residual = $y_total_mm - $y_margin_mm - $test_y_offset - 3*$dv_found - $top_max;
          if($y_residual > 0)
          {
            $y_offset_found = min(array($test_y_offset + $y_residual, $y_offset_default));
          }
          else
            $y_offset_found = min(array($test_y_offset, $y_offset_default));
          $found = true;
        }
      }
    }
    if($found)
    {
      $default_dv = $dv_found;
      $y_offset_default = $y_offset_found;
      if($verbose)
        util::out('success condition for offset and spacing ' . $y_offset_found . ' ' . $dv_found . ' (no nesting)');
    }
    else
    {
      $fallback_penalty = array();
      $fallback_condition = array();
      foreach($base_offsets as $base_offset)
      {
        $test_y_offset = intval($base_offset);
        foreach($row_offsets as $row_offset)
        {
          $test_dv = intval($row_offset);
          $condition = array();
          $params = array($y_plot_rate,$test_dv);
          foreach($plot_columns as $column)
          {
            $condition[] =
              $test_y_offset + $bottom_min - $y_margin_mm;

            $j = 1;
            $k = 2;
            while($j < 4)
            {
              $upper_values = $plot_row_ranges[$j][$column[$k]]['values'];
              $lower_values = $plot_row_ranges[$j-1][$column[$k+1]]['values'];
              $condition[] = diffFunc($upper_values,$lower_values,$params);
              $j++;
              $k--;
            }

            $condition[] =
              $y_total_mm - $y_margin_mm - $test_y_offset - 3*$test_dv - $top_max;
          }
          $optimum = true;
          $penalty = min($condition);
          if($penalty < 0)
          {
            $optimum = false;
          }
          $y_residual_top = $y_total_mm - $y_margin_mm - ($test_y_offset + 3*$test_dv + $top_max);
          $y_residual_bottom = $test_y_offset + $bottom_min - $y_margin_mm;
          $fb_y_off = $test_y_offset;
          $fb_dv = $test_dv;
          if($y_residual_bottom > 0)
          {
            $fb_y_off -= $y_residual_bottom;
            $fb_dv = intval(floor(($y_total_mm - $y_margin_mm - $top_max - $fb_y_off) / 3.0));
          }
          else
          {
            if($y_residual_top > 0)
            {
              $fb_dv = intval(floor(($y_total_mm - $y_margin_mm - $top_max - $fb_y_off) / 3.0));
            }
          }
          if($optimum)
          {
            $found = true;
            $y_offset_found = $fb_y_off;
            $dv_found = $fb_dv;
          }
          else
          {
            $fallback_penalty[] = $penalty;
            $fallback_condition[] = array('dv'=>$fb_dv,'y_off'=>$fb_y_off);
          }
        }
      }
      if($found)
      {
        $default_dv = $dv_found;
        $y_offset_default = $y_offset_found;
        if($verbose)
          util::out('success condition for offset / spacing ' . $y_offset_found . ' / ' . $dv_found . ' (with nesting)');
      }
      else
      {
        if(0 < count($fallback_penalty))
        {
          $opt = current(array_keys($fallback_penalty,max($fallback_penalty)));
          $default_dv = $fallback_condition[$opt]['dv'];
          $y_offset_default = $fallback_condition[$opt]['y_off'];
        }

        util::out('WARNING: using non-optimal offset / spacing: '. $y_offset_default . ' / ' . $default_dv );
      }
    }
  }
  util::out('plotting rates: ' . $x_plot_rate . ' mm/s, ' . $y_plot_rate . ' mm/mV');

  // plot lead II
  $du = $num_time_per_plot * $dt * $x_plot_rate;  // mm
  $dv = $default_dv;

  util::out('row spacing: ' . $y_offset_default . ' mm (lead II) ' . $dv . ' mm');
  $plot_data = array();
  $x_values = $xy_plotdata['II']['x_values'];
  $y_values = $xy_plotdata['II']['y_values'];
  $x_offset = $x_offset_default;
  $y_offset = $y_offset_default;

  // plot lead II along the bottom row entirely
  $xy_values = array();
  for($i = 0 ; $i < count($x_values); $i++)
  {
    $xy_values[] = array('',
      $x_values[$i] * $x_plot_rate + $x_offset_default,
      $y_values[$i] * $y_plot_rate + $y_offset);
  }
  $plot_data[] = array(
    'legend'=>'II',
    'xlegend'=>$x_offset,
    'ylegend'=>$y_offset + $block_resolution_mm,
    'xy_values'=>$xy_values,
    'line_size'=>3);

  for($col = 0; $col < count($plot_columns); $col++)
  {
    $plot_columns[$col] = array_reverse($plot_columns[$col]);
  }

  $y_offset_row_1 = $y_offset_default + $dv;
  for($col = 0; $col < $num_plot_u; $col++)
  {
    $y_offset = $y_offset_row_1;
    $offset =  $col * $num_time_per_plot;
    $length = (($offset + $num_time_per_plot) < $num_total) ? $num_time_per_plot : NULL;
    for($row = 1;$row < $num_plot_v; $row++)
    {
      $key = $plot_columns[$col][$row];
      $x_values = array_slice($xy_plotdata[$key]['x_values'], $offset, $length);
      $y_values = array_slice($xy_plotdata[$key]['y_values'], $offset, $length);
      $xy_values = array();
      for($i = 0 ; $i < count($x_values); $i++)
      {
        $xy_values[] = array('',
          $x_values[$i] * $x_plot_rate + $x_offset_default,
          $y_values[$i] * $y_plot_rate + $y_offset);
      }

      $plot_data[] = array(
        'legend'=>$key,
        'xlegend'=>$x_offset,
        'ylegend'=>$y_offset + $block_resolution_mm,
        'xy_values'=>$xy_values,
        'line_size'=>3);
      $y_offset += $dv;
    }
    $x_offset += $du;
  }

  $xy_values = array();
  $waveform_offsets['x'] = array(0,1,0,$block_resolution_mm,0,1);
  $waveform_offsets['y'] = array(0,0,$y_plot_rate,0,-$y_plot_rate,0);
  $x_waveform = $block_resolution_mm;
  $y_waveform = $y_offset_default;
  for($i = 0; $i < count($waveform_offsets['x']); $i++)
  {
    $x_waveform += $waveform_offsets['x'][$i];
    $y_waveform += $waveform_offsets['y'][$i];
    $xy_values[] = array('',$x_waveform,$y_waveform);
  }
  $plot_data[] = array(
    'legend'=>NULL,
    'xlegend'=>NULL,
    'ylegend'=>NULL,
    'xy_values'=>$xy_values,
    'line_size'=>3);

  $text_total_mm = 55; // mm
  $dpi = 300;

  $resolution = $dpi / 25.4;  //pixels per mm
  $width_total = intval(round($x_total_mm * $resolution,0));
  $height_grid = intval(round($y_total_mm * $resolution,0));
  $height_text = intval(round($text_total_mm * $resolution,0));

  $num_yticks = $y_total_mm;
  $num_xticks = $x_total_mm;

  // final image size and partitionings
  $height_plot = intval(round($y_total_mm * $resolution / $num_plot_v,0));
  $height_total = $height_text + $height_grid;

  $strip_mm = 5;
  $strip_height = intval(round($strip_mm * $resolution,0));

  // setup the grid plots with no axes labels or ticks
  $plot = new PHPlot($width_total, $height_total + $strip_height);
  $plot->SetPrintImage(0);
  $plot->SetIsInline(true);
  $plot->SetPlotType('lines');
  $plot->SetDataType('data-data');
  $plot->SetXTickPos('none');
  $plot->SetXTickLabelPos('none');
  $plot->SetXDataLabelPos('none');
  $plot->SetYTickPos('none');
  $plot->SetYTickLabelPos('none');
  $plot->SetYDataLabelPos('none');
  $plot->SetPlotBorderType('none');
  $plot->SetDrawXAxis(false);
  $plot->SetDrawYAxis(false);

  // set the font size to work with our dpi resolution
  $plot->SetTTFPath('/usr/share/fonts/truetype/msttcorefonts');
  $plot->SetFontTTF('title','Verdana.ttf',34);
  $plot->SetFontTTF('legend','Verdana.ttf',28);

  $xydata=array();
  $xydata[]=array('', 0, 0);
  $xydata[]=array('', 0, $x_total_mm);
  $plot->SetPlotAreaPixels(
    0, $height_text, $width_total, $height_total);
  $plot->SetPlotAreaWorld(0, 0, $x_total_mm, $y_total_mm);

  // draw a red background grid
  $plot->SetLineWidths(1);
  $plot->SetXTickIncrement( 1 );
  $plot->SetYTickIncrement( 1 );
  $plot->SetDrawDashedGrid(false);
  $plot->SetDrawXGrid(true);
  $plot->SetDrawYGrid(true);
  $plot->SetLightGridColor('red');
  $plot->SetDataValues($xydata);
  $plot->DrawGraph();

  // draw the course grid
  $plot->SetLineWidths(2);
  $plot->SetDrawXGrid(false);
  $plot->SetDrawYGrid(false);
  $plot->SetDataColors('red');
  for($i = 0; $i <= $x_num_block; $i++)
  {
    $xydata=array();
    $xydata[]=array('', $i * $block_resolution_mm, 0);
    $xydata[]=array('', $i * $block_resolution_mm, $y_total_mm );
    $plot->SetDataValues($xydata);
    $plot->DrawGraph();
  }

  for($i = 0; $i <= $y_num_block; $i++)
  {
    $xydata=array();
    $xydata[]=array('', 0, $i * $block_resolution_mm);
    $xydata[]=array('', $x_total_mm, $i * $block_resolution_mm);
    $plot->SetDataValues($xydata);
    $plot->DrawGraph();
  }

  // overlay waveform plots on the background grid
  $plot->SetDrawXGrid(false);
  $plot->SetDrawYGrid(false);
  $plot->SetDrawPlotAreaBackground(false);
  $plot->SetDataColors('black');

  $plot->SetOutputFile($outfile);
  $black = imagecolorresolve($plot->img, 0, 0, 0);

  foreach($plot_data as $values)
  {
    $plot->SetLineWidths($values['line_size']);
    $plot->SetDataValues($values['xy_values']);
    if(!is_null($values['legend']))
    {
      list($x,$y) = $plot->GetDeviceXY($values['xlegend'],$values['ylegend']);
      $plot->DrawText('legend', 0, $x, $y, $black, $values['legend'], 'left', 'top');
    }
    $plot->DrawGraph();
  }

  // text components
  $plot->SetDrawPlotAreaBackground(true);

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
        if($verbose)
          util::out($str);
        $group_data[$group_key][$data_key]['value'][] = $value;
      }
    }
  }

  $datekeys = array('Day'=>null,'Month'=>null,'Year'=>null);
  $timekeys = array('Hour'=>null,'Minute'=>null,'Second'=>null);
  foreach($group_data['datetime'] as $key=>$data)
  {
    $value = current($data['value']);
    if(1 == strlen($value)) $value = '0' . $value;
    if(array_key_exists($key,$datekeys))
    {
      $datekeys[$key] = $value;
    }
    else if(array_key_exists($key,$timekeys))
      $timekeys[$key] = $value;
  }

  $date_str =
    util::flatten(array_values($datekeys),'.') . ' ' .
    util::flatten(array_values($timekeys),':');
  $pid_str = current($group_data['patient']['PID']['value']);
  $rate_str = current($group_data['measurements']['VentricularRate']['value']);

  $pixel_area = array(
    'x0'=>0, 'y0'=>0, 'x1'=>$width_total, 'y1'=>($height_text-1));
  plotEmptyBox($plot,$pixel_area);

  $plot->SetLegend(NULL);
  $plot->SetPlotAreaPixels(0, 0, $width_total, $height_text);
  $spc = ':   ';

  $yoffset = 0.05 * $height_text;
  $xoffset = 0.125 * $width_total;
  $str = 'Date ' . "\n" . 'ID ' . "\n" . 'BPM ' . "\n" . 'Diagnosis';
  $plot->DrawText('title', 0, $xoffset, $yoffset, $black, $str, 'right', 'top');

  $str = $spc . $date_str . "\n" . $spc . $pid_str . "\n" . $spc . $rate_str;

  // diagnosis

  foreach($group_data['diagnosis']['DiagnosisText']['value'] as $diag_str)
  {
    $str .= "\n";
    $str .= $spc;
    $str .= $diag_str;
  }
  $plot->DrawText('title', 0, $xoffset, $yoffset, $black, $str, 'left', 'top');

  // QRS: QRSDuration ms
  // QT / QTcBaz: QTInterval / QTCInterval ms
  // PR : PQInterval ms
  // P: PDuration ms
  // RR / PP: RRInterval / PPInterval ms
  // P / QRS / T: PAxis / RAxis / TAxis degrees

  $xoffset = 0.7 * $width_total;
  $str =
    'QRS ' . "\n" . 'QT / QTcBaz ' . "\n" .
    'PR ' . "\n" . 'P ' . "\n" .
    'RR / PP ' . "\n" . 'P / QRS / T ';
  $plot->DrawText('title', 0, $xoffset, $yoffset, $black, $str, 'right', 'top');

  $str =
    $spc .
    current($group_data['measurements']['QRSDuration']['value']) . ' ' .
    current($group_data['measurements']['QRSDuration']['units']) . ' ' . "\n" .
    $spc .
    current($group_data['measurements']['QTInterval']['value']) . ' / ' .
    current($group_data['measurements']['QTCInterval']['value']) . ' ' .
    current($group_data['measurements']['QTInterval']['units']) . ' ' . "\n" .
    $spc .
    current($group_data['measurements']['PQInterval']['value']) . ' ' .
    current($group_data['measurements']['PQInterval']['units']) . ' ' . "\n" .
    $spc .
    current($group_data['measurements']['PDuration']['value']) . ' ' .
    current($group_data['measurements']['PDuration']['units']) . ' ' . "\n" .
    $spc .
    current($group_data['measurements']['RRInterval']['value']) . ' / ' .
    current($group_data['measurements']['PPInterval']['value']) . ' ' .
    current($group_data['measurements']['RRInterval']['units']) . ' ' . "\n" .
    $spc .
    current($group_data['measurements']['PAxis']['value']) . ' / ' .
    current($group_data['measurements']['RAxis']['value']) . ' / ' .
    current($group_data['measurements']['TAxis']['value']) . ' ' .
    current($group_data['measurements']['PAxis']['units']);

  $plot->DrawText('title', 0, $xoffset, $yoffset, $black, $str, 'left', 'top');

  // bottom strip text
  $pixel_area = array(
    'x0'=>0, 'y0'=>($height_total+1), 'x1'=>$width_total, 'y1'=>($height_total + $strip_height));
  plotEmptyBox($plot,$pixel_area);

  $plot->SetPlotAreaPixels(0, $height_text, $width_total, $height_total + $strip_height);
  $xoffset = intval(round(120 * $resolution,0));
  $str =
    $x_plot_rate . ' ' . 'mm/s' . '     ' .  $y_plot_rate . ' ' . 'mm/mV';
  $plot->DrawText('title', 0, $xoffset, $height_total+1, $black, $str, 'left', 'top');

  $xoffset = intval(round(25 * $resolution,0));
  $str = 'GE MAC1600';
  $plot->DrawText('title', 0, $xoffset, $height_total+1, $black, $str, 'left', 'top');

  $plot->PrintImage();
?>
