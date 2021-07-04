<?php
/**
 * Plugin Name: C4C Budget Form
 * Plugin URI: https://www.cooperative4thecommunity.com/c4c-budget-form
 * Description: A plugin to fill out the Oakland participatory budgeting form
 * Version: 1.0
 * Author: Kyle Donnelly
 * Author URI: https://www.cooperative4thecommunity.com
 */

defined( 'ABSPATH' ) || exit;

# database column names, must match cdp_echo_results_html()
define("QUERY_COLUMNS", ['department_name', 'description', 'current_allocation']);

function c4c_department_post_key($department_name) {
  $key = str_replace(' ', '_', $department_name);
  $key = str_replace('.', '_', $key);
  return $key;
}

function c4c_dropdown_district_html($id) {
  $council_members = ["Kalb", "Bas", "Fife", "Thao", "Gallo", "Taylor", "Reid"];
  $html = "<select name=\"$id\" id=\"$id\" style=\"width: 60%;\">\n";

  $html .= "<option disabled value=\"0\"";
  if (empty($_POST[$id])) {
    $html .= " selected";
  }
  $html .= ">--</option>\n";

  foreach ($council_members as $i => $name) {
    $option = $i + 1;
    $html .= "<option value=\"" . $option . "\"";
    if ($option == $_POST[$id]) {
      $html .= " selected";
    }
    $html .= ">District " . $option . " (" . $name . ")</option>\n";
  }

  $opt_out_value = count($council_members) + 1;
  $html .= "<option value=\"$opt_out_value\"";
  if ($_POST[$id] == $opt_out_value) {
    $html .= " selected";
  }
  $html .= ">Oakland (prefer not to say)</option>\n";

  $html .= "<option value=\"-1\"";
  if ($_POST[$id] == -1) {
    $html .= " selected";
  }
  $html .= ">Outside Oakland</option>\n";

  $html .= "</select>\n";
  return $html;
}

function c4c_echo_form_html($results) {
  # toggle department-info row beneath department-detail rows https://stackoverflow.com/a/43299472
  echo "<style type=\"text/css\">.department-info ~ .department-detail { display: none; } .open .department-info ~ .department-detail { display: table-row; } .department-info { cursor: pointer; } .department-info .department-name .department-expand-toggle { transform: rotate(0deg); } .open .department-info .department-name .department-expand-toggle { transform: rotate(90deg); } .open .department-detail { background-color: #fbfbfb; } </style>";

  echo '<form action="" id="budget_form" method="post">';
  echo '<table style="width:100%">';
  echo '<tbody><tr class="department-info"><td class="department-name"><a href="http://gisapps1.mapoakland.com/councildistricts/">Find your district here</a></td><td class="department-name" style="text-align:right;"><i>I live in: </i></td><td>' . c4c_dropdown_district_html('district') . '</td></tr>';
  echo '<tr class="department-detail"><td colspan="2">Entering your district helps us build a People\'s Budget specifically for each council member.</td></tr></tbody>';
  echo '<tr><th width="45%">Department</th><th width="25%">Current Allocation</th><th width="30%">My Allocation</th></tr>';
  $sum = 0.0;
  foreach ($results as $result) {
    $post_key = c4c_department_post_key($result->department_name);
    $my_allocation = $_POST[$post_key];
    $sum += $my_allocation;
    echo '<tbody>';
    echo '<tr class="department-info" id="' . $result->department_name . '">';
    echo '<td class="department-name"><label class="department-expand-toggle">&#x25BA</label> ' . $result->department_name . '</td>';
    echo '<td class="department-name">' . number_format($result->current_allocation, 2) . '%</td>'; 
    echo '<td><input type="number" style="width:60%;" id="' . $result->department_name . '" name="' . $result->department_name . '" min="0" max="100" step="any" onChange="updateTotal(this);" placeholder="' . $result->current_allocation . '" value="' . $my_allocation . '"></td>';
    echo '</tr>';

    echo '<tr class="department-detail">';
    echo '<td colspan="2">' . nl2br($result->description) . '</td><td />';
    echo '</tr>';
    echo '</tbody>';
  }
  echo '<tr><td /><td /><td><label id="input_sum_label">Total: ' . number_format($sum, 1) . '%</label></td></tr>';
  echo '</table>';
  echo '<p><input style="background-color:#005248" type="submit" name="submit" id="submitButton" value="Submit">';
  echo '<input style="background-color:#F2BC40" type="button" name="normalize" id="normalize" value="Snap to 100%" onclick="return normalizeInput(this.form);">';
  echo '<input style="background-color:#c7c7c7" type="button" name="clear" id="clear_form" value="Clear" onclick="return clearForm(this.form);"></p>';
  echo '</form>';
}

function c4c_submit_survey_form($should_print = true) {
  $session_id = $_COOKIE['PHPSESSID'];
  if (empty($session_id)) {
    if ($should_print) {
      echo '<p style="color:red;">Sorry, this survey isn\'t compatible with Incognito Mode or disabled cookies.</p>';
      echo '<p>Please use a regular browser or enable cookies. This is to help prevent spam and show your personal budget on the <a href="https://www.cooperative4thecommunity.com/oakland-peoples-budget-results">Results</a> page.</p>';
    }
    return false;
  }

  // ignore initial (or any) page load where the user didn't submit anything
  if (empty($_POST['submit'])) {
    return false;
  }

  if (empty($_POST['district'])) {
    if ($should_print) {
      echo '<p style="color:red;"><b>Could not submit!</b> Please select which district you live in, or if you live outside Oakland.</p>';
    }
    return false;
  }

  $district = intval($_POST['district']);
  $date = "'" . date('Y-m-d H:i:s') . "'";
  $columns = ['session_id', 'first_timestamp', 'updated_timestamp', 'location_id'];
  $values = ["'$session_id'", $date, $date, $district];

  global $wpdb;
  $department_table_name = $wpdb->prefix . "oakland_survey_budgets";
  $query = "SELECT department_name from $department_table_name;";
  $results = $wpdb->get_results($query);

  $sum = 0.0;
  foreach ($results as $result) {
    $post_key = c4c_department_post_key($result->department_name);
    if (isset($_POST[$post_key])) {
      $amount = floatval($_POST[$post_key]);
      $sum += $amount;
      array_push($columns, '`' . $result->department_name . '`');
      array_push($values, $amount);
    }
  }

  if (abs(100.0 - $sum) > 0.5) {
    if ($should_print) {
      echo '<p style="color:red;"><b>Could not submit!</b> Your budget adds up to <b>' . number_format($sum, 1) . '%</b> – please make sure the total is 100%</p>';
    }
    return false;
  }

  $responses_table_name = $wpdb->prefix . "oakland_survey_responses";
  $update_columns = array_diff($columns, ['session_id', 'first_timestamp']);
  $update_subquery = implode(', ', array_map(function($c) { return "$c=VALUES($c)"; }, $update_columns));
  $query = "INSERT INTO `$responses_table_name` (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ') ON DUPLICATE KEY UPDATE ' . $update_subquery . ';';
  $wpdb->query($query);
  // echo '<p"><b>Successfully submitted!</b><br />Now make the People\'s Budget a reality by joining our friends at the <a href="https://communitydemocracyproject.org/get-involved/">Community Democracy Project!</a></p>';
  // print($query);
  return true;
}

function c4c_survey_form_init() {
  if (session_status() == PHP_SESSION_NONE) {
    session_name('PHPSESSID');
    $did_start = session_start();
    if ($did_start) {
      $session_id = session_id();
      if (setcookie('PHPSESSID', $session_id)) {
        $_COOKIE['PHPSESSID'] = $session_id;
      }
    }
  }

  if (c4c_submit_survey_form(false)) {
    wp_redirect( 'https://www.cooperative4thecommunity.com/oakland-peoples-budget-results' );
    die();
  }
}

function c4c_survey_form_code() {
  global $wpdb;
  $table_name = $wpdb->prefix . "oakland_survey_budgets";
  $query = "SELECT " . implode(', ', QUERY_COLUMNS) . " from $table_name ORDER BY current_allocation DESC;";
  $results = $wpdb->get_results($query);
  c4c_echo_form_html($results);
}

function cf_budget_form_shortcode() {
  // wordpress entry point
  ob_start();

  $session_id = session_id();

  c4c_submit_survey_form();
  c4c_survey_form_code();

  return ob_get_clean();
}

add_action( 'init', 'c4c_survey_form_init' );
add_shortcode( 'c4c_budget_form', 'cf_budget_form_shortcode' );

?>
