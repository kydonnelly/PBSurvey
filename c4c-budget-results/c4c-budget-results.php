<?php
/**
 * Plugin Name: C4C Budget Survey Results
 * Plugin URI: https://www.cooperative4thecommunity.com/c4c-budget-results
 * Description: A plugin to show the results of the Oakland People's Budget Survey
 * Version: 1.0
 * Author: Kyle Donnelly
 * Author URI: https://www.cooperative4thecommunity.com
 */

defined( 'ABSPATH' ) || exit;

function c4c_departments_by_current_allocation() {
  global $wpdb;
  $department_table_name = $wpdb->prefix . "oakland_survey_budgets";
  $query = "SELECT department_name from $department_table_name ORDER BY current_allocation;";
  $department_results = $wpdb->get_results($query);

  return array_map(function($r) { return $r->department_name; }, $department_results);
}

function c4c_compare_budget_allocations($session_id) {
  $my_allocations = c4c_my_budget_allocations($session_id);
  $peoples_allocations = c4c_peoples_budget_graph_allocations();
  $city_allocations = c4c_city_budget_graph_allocations();

  $allocations = ["People's Budget" => $peoples_allocations];
  if (!empty($my_allocations)) {
    $allocations["My Vote"] = $my_allocations;
  }
  $allocations["City Budget"] = $city_allocations;
  return $allocations;
}

function c4c_compare_budget_graph_load($session_id) {
  $allocations = c4c_compare_budget_allocations($session_id);
  echo c4c_compare_budget_graph_html($session_id, $allocations);
}

function c4c_compare_budget_graph_html($session_id, $allocations) {
  // Load mpld3 js libraries and then draw the graph
  $html = "<h2>Side by Side</h2>";
  $html .= "<div id=\"fig_el745837658237169676128317468\"></div>";
  $html .= c4c_budget_results_share_html($session_id, 6);
  $html .= "<script>\n";
  $html .= "c4c_load_graph_library(\"https://mpld3.github.io/js/d3.v3.min.js\", function(){\n";
  $html .= "  c4c_load_graph_library(\"https://mpld3.github.io/js/mpld3.v0.3.js\", function(){\n";
  $html .= "    mpld3.draw_figure(\"fig_el745837658237169676128317468\",\n";
  $html .= c4c_load_mpld3_graph($allocations, "Oakland Budget Survey Comparison", 'stack_bar.py');
  $html .= "    )\n";
  $html .= "  })\n";
  $html .= "});\n";
  $html .= "</script>\n";

  return $html;
}

function c4c_my_budget_allocations($session_id) {
  $department_names = c4c_departments_by_current_allocation();

  global $wpdb;
  $responses_table_name = $wpdb->prefix . "oakland_survey_responses";
  $sum_subquery = array_map(function($d) { return "`$d`"; }, $department_names);
  $query = "SELECT " . implode(', ', $sum_subquery) . " FROM $responses_table_name WHERE session_id = \"$session_id\";";
  $results = $wpdb->get_results($query);

  if (empty($results)) {
    return [];
  }

  $result = $results[0];
  return array_reduce($department_names, function($accumulator, $name) use ($result) {
    $accumulator[$name] = floatval($result->$name);
    return $accumulator;
  }, []);
}

function c4c_my_budget_graph_load($session_id) {
  $allocations = c4c_my_budget_allocations($session_id);

  if (!empty($allocations)) {
    echo c4c_my_budget_graph_html($session_id, $allocations);
  }
}

function c4c_my_budget_graph_html($session_id, $allocations) {
  // Load mpld3 js libraries and then draw the graph
  $html = "<h2>My Vote</h2>";
  $html .= "<div id=\"fig_el473658710992383905802113951\"></div>";
  $html .= c4c_budget_results_share_html($session_id, 0);
  // $html .= "<div id=\"fig_el125897347289742309909437495\"></div>";
  // $html .= c4c_budget_results_share_html($session_id, 1);
  $html .= "<script>\n";
  $html .= "c4c_load_graph_library(\"https://mpld3.github.io/js/d3.v3.min.js\", function(){\n";
  $html .= "  c4c_load_graph_library(\"https://mpld3.github.io/js/mpld3.v0.3.js\", function(){\n";
  $html .= "    mpld3.draw_figure(\"fig_el473658710992383905802113951\",\n";
  $html .= c4c_load_mpld3_graph($allocations, "My Oakland Budget Vote", 'bar.py');
  $html .= "    )\n";
  // $html .= "    mpld3.draw_figure(\"fig_el125897347289742309909437495\",\n";
  // $html .= c4c_load_mpld3_graph($allocations, "My Oakland Budget Vote", 'pie.py');
  // $html .= "    )\n";
  $html .= "  })\n";
  $html .= "});\n";
  $html .= "</script>\n";

  return $html;
}

function c4c_peoples_budget_graph_allocations(&$num_votes = null) {
  $department_names = c4c_departments_by_current_allocation();

  global $wpdb;
  $responses_table_name = $wpdb->prefix . "oakland_survey_responses";
  $sum_subquery = array_map(function($d) { return "SUM(`$d`) AS `$d`"; }, $department_names);
  $query = "SELECT COUNT(*) AS num_votes, " . implode(', ', $sum_subquery) . " FROM $responses_table_name WHERE location_id != -1;";
  $results = $wpdb->get_results($query);

  $result = $results[0];
  $num_votes = $result->num_votes;

  // Selected SUM() instead of AVG() to reduce any floating point drift, since it's not required to submit exactly 100%.
  // Recalculate AVG, which should be pretty close to each result divided by the total sum.
  $total_sum = array_reduce($department_names, function($accumulator, $name) use ($result) { return $accumulator + $result->$name; }, 0);
  return array_reduce($department_names, function($accumulator, $name) use ($result, $total_sum) {
    $accumulator[$name] = $result->$name * 100 / $total_sum;
    return $accumulator;
  }, []);
}

function c4c_peoples_budget_graph_medians() {
  $department_names = c4c_departments_by_current_allocation();
  $medians = array();

  global $wpdb;
  $responses_table_name = $wpdb->prefix . "oakland_survey_responses";

  $median_sum = 0;
  foreach ($department_names as $department_name) {
    $query = "SELECT `$department_name` as amount FROM $responses_table_name WHERE location_id != -1 ORDER BY amount;";
    $results = $wpdb->get_results($query);
    $num_votes = count($results);
    if ($num_votes > 0) {
      $median = $results[intval($num_votes / 2)]->$amount;
      if ($num_votes % 2 == 0) {
        $median = ($median + $results[intval($num_votes / 2 - 1)]->$amount) * 0.5;
      }

      $medians[$department_name] = $median;
      $median_sum += $median;
    }
  }

  return array_map(function ($m) { return $m * 100.0; } / $median_sum, $medians);
}

function c4c_peoples_budget_graph_load($session_id) {
  $num_votes = 0;
  $allocations = c4c_peoples_budget_graph_allocations($num_votes);
  echo c4c_peoples_budget_graph_html($session_id, $num_votes, $allocations);
}

function c4c_peoples_budget_graph_html($session_id, $num_votes, $allocations) {
  // Load mpld3 js libraries and then draw the graph
  $html = "<h2>People's Budget</h2>";
  $html .= "<p><b>Votes: </b><label>$num_votes</label></p>";
  $html .= "<div id=\"fig_el689748379487276179582983952\"></div>";
  $html .= c4c_budget_results_share_html($session_id, 2);
  // $html .= "<div id=\"fig_el983972583928719487394832094\"></div>";
  // $html .= c4c_budget_results_share_html($session_id, 3);
  $html .= "<script>\n";
  $html .= "c4c_load_graph_library(\"https://mpld3.github.io/js/d3.v3.min.js\", function(){\n";
  $html .= "  c4c_load_graph_library(\"https://mpld3.github.io/js/mpld3.v0.3.js\", function(){\n";
  $html .= "    mpld3.draw_figure(\"fig_el689748379487276179582983952\",\n";
  $html .= c4c_load_mpld3_graph($allocations, "Oakland People's Budget Survey", 'bar.py');
  $html .= "    )\n";
  // $html .= "    mpld3.draw_figure(\"fig_el983972583928719487394832094\",\n";
  // $html .= c4c_load_mpld3_graph($allocations, "Oakland People's Budget Survey", 'pie.py');
  // $html .= "    )\n";
  $html .= "  })\n";
  $html .= "});\n";
  $html .= "</script>\n";

  return $html;
}

function c4c_city_budget_graph_allocations() {
  global $wpdb;
  $department_table_name = $wpdb->prefix . "oakland_survey_budgets";
  $query = "SELECT department_name, current_allocation from $department_table_name;";
  $results = $wpdb->get_results($query);

  $allocations = [];
  foreach ($results as $result) {
    $allocations[$result->department_name] = floatval($result->current_allocation);
  }
  return $allocations;
}

function c4c_city_budget_graph_load($session_id) {
  $allocations = c4c_city_budget_graph_allocations();
  echo c4c_city_budget_graph_html($session_id, $allocations);
}

function c4c_city_budget_graph_html($session_id, $allocations) {
  // Load mpld3 js libraries and then draw the graph
  $html = "<h2>Current City Budget</h2>";
  $html .= "<div id=\"fig_el823579827391875894718437685\"></div>";
  $html .= c4c_budget_results_share_html($session_id, 4);
  // $html .= "<div id=\"fig_el325974893719837598379817395\"></div>";
  // $html .= c4c_budget_results_share_html($session_id, 5);
  $html .= "<script>\n";
  $html .= "c4c_load_graph_library(\"https://mpld3.github.io/js/d3.v3.min.js\", function(){\n";
  $html .= "  c4c_load_graph_library(\"https://mpld3.github.io/js/mpld3.v0.3.js\", function(){\n";
  $html .= "    mpld3.draw_figure(\"fig_el823579827391875894718437685\",\n";
  $html .= c4c_load_mpld3_graph($allocations, 'Oakland General Fund', 'bar.py');
  $html .= "    )\n";
  // $html .= "    mpld3.draw_figure(\"fig_el325974893719837598379817395\",\n";
  // $html .= c4c_load_mpld3_graph($allocations, 'Oakland General Fund', 'pie.py');
  // $html .= "    )\n";
  $html .= "  })\n";
  $html .= "});\n";
  $html .= "</script>\n";

  return $html;
}

function c4c_fetch_department_abbreviations() {
  global $wpdb;
  $table_name = $wpdb->prefix . "oakland_survey_budgets";
  $query = "SELECT department_name, department_abbreviation from $table_name;";
  $results = $wpdb->get_results($query);
  return array_reduce($results, function($accumulator, $r) {
    $accumulator[$r->department_name] = $r->department_abbreviation;
    return $accumulator;
   }, []);
}

function c4c_load_mpld3_graph($allocations, $title, $script_name) {
  return c4c_load_matplotlib_graph($allocations, $title, $script_name, true);
}

function c4c_load_png_graph($session_id, $allocations, $title, $script_name, $horizontal = true) {
  $filename = '/tmp/' . $session_id . '_' . str_replace(' ', '_', $title) . '_' . $script_name . '_' . $horizontal;
  c4c_load_matplotlib_graph($allocations, $title, $script_name, false, $filename, $horizontal);
  return $filename;
}

function c4c_load_matplotlib_graph($allocations, $title, $script_name, $interactive, $filename = '', $horizontal = true) {
  // Send info to the python script as JSON
  $metadata = [
    'title' => $title,
    'filename' => $filename,
    'horizontal' => $horizontal,
    'allocations' => $allocations,
    'interactive' => $interactive,
    'abbreviations' => c4c_fetch_department_abbreviations(),
    'sorted_departments' => c4c_departments_by_current_allocation(),
  ];
  if (isset($content_width)) {
    $metadata['max_width'] = $content_width;
  }
  $metajson = json_encode($metadata);
  $script_path = __DIR__ . '/' . $script_name;

  // proc_open the script so we can write the metadata then read the output
  $descriptorspec = array(
    0 => array("pipe", "r"),
    1 => array("pipe", "w"),
    2 => array("pipe", "w")
  );

  // If python goes missing, refer to:
  // https://www.godaddy.com/garage/how-to-install-and-configure-python-on-a-hosted-server/
  // https://tecadmin.net/install-python-3-7-on-centos/
  $script_cmd = 'python3.7 ' . $script_path;
  $process = proc_open($script_cmd, $descriptorspec, $pipes);

  fwrite($pipes[0], $metajson . "\n");
  $output = '';
  while (!feof($pipes[1])) {
    $output .= fgets($pipes[1]);
  }

  // Uncomment this to debug any setup errors
  // while (!feof($pipes[2])) {
  //   echo 'error ' .  fgets($pipes[2]);
  // }

  fclose($pipes[2]); 
  fclose($pipes[1]);
  fclose($pipes[0]);
  $ret_close = proc_close($process);

  return $output;
}

function c4c_budget_results_shortcode() {
  // wordpress entry point
  ob_start();

  $session_id = $_COOKIE['PHPSESSID'];

  c4c_download_budget_images_html($session_id);

  if (!empty($session_id)) {
    c4c_my_budget_graph_load($session_id);
  }

  c4c_peoples_budget_graph_load($session_id);
  c4c_city_budget_graph_load($session_id);
  c4c_compare_budget_graph_load($session_id);

  return ob_get_clean();
}



function c4c_budget_results_share_html($session_id, $graph_id, $horizontal = true) {
  $share_key = base64_encode($session_id . '_' . $graph_id . '_' . intval($horizontal));
  $main_url = 'https://cooperative4thecommunity.com/oakland-peoples-budget-results/';
  $full_url = $main_url . '?share_key=' . $share_key;

  $html .= '<a class="twitter-share-button" style="vertical-align: top;" href="https://twitter.com/intent/tweet?hashtags=OurCityOurBudget,PeoplesBudget,Oakland&url=' . urlencode($full_url) . '"> Tweet</a>';
  $html .= '   ';
  $html .= '<div class="fb-share-button" style="vertical-align: top;" data-href="' . $full_url . '" data-layout="button" data-size="small"><a target="_blank" href="https://www.facebook.com/sharer/sharer.php?u=https%3A%2F%2Fdevelopers.facebook.com%2Fdocs%2Fplugins%2F&amp;src=sdkpreparse" class="fb-xfbml-parse-ignore">Share</a></div>';
  $html .= '   ';
  $html .= '<input style="background-color: #a7a7a7; padding: 0 6px; margin: 0 0; font-size: 11px; font-weight: 500; vertical-align: top; line-height: 1.28; text-transform: none; width: 69px; height: 20px;" type="button" name="copy-link" id="' . $share_key . '" value="Copy Link" onclick="return c4c_share_url_click(this, \'' . $full_url . '\');">';

  return $html;
}

function c4c_download_budget_images_html($session_id) {
  if (!empty($session_id)) {
    echo '<p><form action="" id="download_form" method="post">';
    echo '<input style="background-color:#005248;  padding: 4px 8px; margin: 0 0; font-size: 14px; font-weight: 500; vertical-align: top; line-height: 1.33; text-transform: none; width: 128px; height: 42px;" type="submit" name="download" id="downloadBudgetImages" value="Download All">';
    echo '</form></p>';
  }
}

function c4c_download_all_budget_images() {
  $session_id = $_COOKIE['PHPSESSID'];

  $peoples_allocations = c4c_peoples_budget_graph_allocations();
  $city_allocations = c4c_city_budget_graph_allocations();
  $peoples_medians = c4c_peoples_budget_graph_medians();

  // Load images
  $peoples_bar_file = c4c_load_png_graph($session_id, $peoples_allocations, "Oakland People's Budget Survey", 'bar.py');
  $peoples_pie_file = c4c_load_png_graph($session_id, $peoples_allocations, "Oakland People's Budget Survey", 'pie.py');
  $medians_bar_file = c4c_load_png_graph($session_id, $peoples_medians, "Oakland People's Budget Survey Medians (test)", 'bar.py');
  $medians_pie_file = c4c_load_png_graph($session_id, $peoples_medians, "Oakland People's Budget Survey Medians (test)", 'pie.py');
  $city_bar_file = c4c_load_png_graph($session_id, $city_allocations, 'Oakland General Fund', 'bar.py');
  $city_pie_file = c4c_load_png_graph($session_id, $city_allocations, 'Oakland General Fund', 'pie.py');

  $png_files = ['PeoplesBudgetBar.png' => $peoples_bar_file, 'PeoplesBudgetPie.png' => $peoples_pie_file,
                'PeoplesMedianBar.png' => $medians_bar_file, 'PeoplesMedianPie.png' => $medians_pie_file,
                'OaklandGeneralFundBar.png' => $city_bar_file, 'OaklandGeneralFundPie.png' => $city_pie_file];

  if (!empty($session_id)) {
    $my_allocations = c4c_my_budget_allocations($session_id);
    $my_bar_file = c4c_load_png_graph($session_id, $my_allocations, "My Oakland Budget Vote", 'bar.py');
    $my_pie_file = c4c_load_png_graph($session_id, $my_allocations, "My Oakland Budget Vote", 'pie.py');

    $allocations = ["People's Budget" => $peoples_allocations];
    if (!empty($my_allocations)) {
      $allocations["My Vote"] = $my_allocations;
    }
    $allocations["City Budget"] = $city_allocations;
    $comparison_h_file = c4c_load_png_graph($session_id, $allocations, "Oakland Budget Survey Comparison", 'stack_bar.py');
    $comparison_v_file = c4c_load_png_graph($session_id, $allocations, "Oakland Budget Survey Comparison", 'stack_bar.py', false);

    $png_files = array_merge($png_files, ['MyBudgetBar.png' => $my_bar_file, 'MyBudgetPie.png' => $my_pie_file,
                                          'BudgetSurveyComparisonHorizontal.png' => $comparison_h_file, 'BudgetSurveyComparisonVertical.png' => $comparison_v_file]);
  }

  // Start download
  // required for IE
  if (ini_get('zlib.output_compression')) { ini_set('zlib.output_compression', 'Off'); }

  $filename = 'OaklandPeoplesBudget.zip';
  $zip = new ZipArchive;
  $zip->open($filename, ZipArchive::CREATE);
  foreach ($png_files as $zip_name => $tmp_name) {
    $zip->addFile($tmp_name, $zip_name);
  }
  $zip->close();

  foreach ($png_files as $zip_name => $tmp_name) {
    unlink($tmp_name);
  }

  header('Pragma: public');                               // required
  header("Expires: Tue, 27 Aug 2012 06:00:00 GMT");       // no cache
  header("Cache-Control: max-age=0, no-cache, must-revalidate, proxy-revalidate");
  header('Cache-Control: private', false);
  header("Last-Modified: {$now} GMT");
  header('Content-Type: ' . 'application/zip');
  header('Content-Type: ' . 'application/octet-stream');
  header("Content-Disposition: attachment; filename={$filename}");
  header('Content-Transfer-Encoding: binary');
  header('Content-Length: ' . filesize($filename));

  readfile($filename);
  unlink($filename);
}

function c4c_display_budget_image($params) {
  if (count($params) != 3) {
    return false;
  }

  $session_id = $params[0];
  if (empty($session_id)) {
    return false;
  }

  $script_id = intval($params[1]);
  $horizontal = boolval($params[2]);

  $titles = ["My Oakland Budget", "My Oakland Budget",
             "Oakland People's Budget Survey", "Oakland People's Budget Survey",
             "Oakland General Fund", "Oakland General Fund",
             "Oakland People's Budget Survey Results", "Oakland People's Budget Survey Results"];
  $script_names = ["bar.py", "pie.py",
                   "bar.py", "pie.py",
                   "bar.py", "pie.py",
                   "stack_bar.py", "stack_bar.py"];

  if ($script_id < 0 || $script_id >= count($titles)) {
    return false;
  }

  $allocations = [];
  if ($script_id == 0 || $script_id == 1) {
    $allocations = c4c_my_budget_allocations($session_id);
  } else if ($script_id == 2 || $script_id == 3) {
    $allocations = c4c_peoples_budget_graph_allocations();
  } else if ($script_id == 4 || $script_id == 5) {
    $allocations = c4c_city_budget_graph_allocations();
  } else if ($script_id == 5 || $script_id == 6) {
    $allocations = c4c_compare_budget_allocations($session_id);
  }
  
  $title = $titles[$script_id];
  $script_name = $script_names[$script_id];
  $filename = c4c_load_png_graph($session_id, $allocations, $title, $script_name, $horizontal);

  header('Pragma: public');                               // required
  header("Expires: Tue, 27 Aug 2012 06:00:00 GMT");       // no cache
  header("Cache-Control: max-age=0, no-cache, must-revalidate, proxy-revalidate");
  header('Cache-Control: private', false);
  header("Last-Modified: {$now} GMT");
  header('Content-Type: image/png');

  $image = imagecreatefrompng($filename);
  imagepng($image);

  imagedestroy($image);
  unlink($filename);

  return true;
}

function c4c_budget_results_init() {
  if (isset($_POST['download'])) {
    $session_id = $_COOKIE['PHPSESSID'];
    c4c_download_all_budget_images($session_id);
    die();
  } else if (isset($_GET['thumbnail'])) {
    if (c4c_display_budget_image(explode('_', base64_decode($_GET['thumbnail'])))) {
      die();
    }
  }
}

function c4c_budget_results_opengraph_meta($value, $type, $field, $v, $extra_params) {
  if (isset($_GET['share_key'])) {
    $share_key = $_GET['share_key'];
    
    if ($field == 'thumbnail' || $field == 'thumbnail_1' || $field == 'twitter_thumbnail') {
      return 'https://cooperative4thecommunity.com/oakland-peoples-budget-results/?thumbnail=' . $share_key;
    } else if ($field == 'url') {
      return 'https://cooperative4thecommunity.com/oakland-peoples-budget-results/?share_key=' . $share_key;
    } else if ($field == 'width') {
      return "1080";
    } else if ($field == 'height') {
      $share_params = explode('_', base64_decode($share_key));
      if (count($share_params) == 3) {
        $script_id = intval(intval($share_params[1]) / 2);
        if ($script_id == 3) {
          return "800";
        }
      }
      return "600";
    } else if ($field == 'title') {
      $share_params = explode('_', base64_decode($share_key));
      if (count($share_params) == 3) {
        $script_id = intval(intval($share_params[1]) / 2);
        $titles = ["My Oakland Budget", "Oakland People's Budget", "Current Oakland General Fund", "People's Budget Survey Results"];
        if ($script_id < count($titles)) {
          return $titles[$script_id];
        }
      }
    }
  }
  
  return $value;
}



// Check for file download before loading any other page data
add_action( 'init', 'c4c_budget_results_init' );
add_shortcode( 'c4c_budget_results', 'c4c_budget_results_shortcode' );
add_filter('aiosp_opengraph_meta','c4c_budget_results_opengraph_meta', 10, 5);

?>
