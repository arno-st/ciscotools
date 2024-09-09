<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007 The Cacti Group                                      |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/
include_once($config['base_path'] . '/plugins/extenddb/ssh2.php');
include_once($config['base_path'] . '/plugins/extenddb/telnet.php');

set_time_limit(0);

function process_cli() {
    global $config, $item_rows, $cliAction, $cliCommand, $cliBackup, $cliResult, $cliStopOnError;
	
    $filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
			),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => '1'
			),
		'sort_column' => array(
			'filter' => FILTER_DEFAULT,
			'default' => 'host.id'
			),
		'sort_direction' => array(
			'filter' => FILTER_DEFAULT,
			'default' => 'ASC',
			),
		'model' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			),
		'description' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			),
		'selected_items' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true
			),
		'cliAction' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			),
		'cliCommand' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			),
		'cliBackup' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			),
		'cliStopOnError' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			)
	);

	validate_store_request_vars($filters, 'sess_ciscotools_cli');

    /* ================= input validation ================= */
    input_validate_input_number(get_request_var("page"));
    input_validate_input_number(get_request_var("rows"));
    /* ==================================================== */

	// Clean Description
    if(isset_request_var('description')) 
    {
        set_request_var('description', sanitize_search_string(get_request_var("description")));
	}
	// Clean model
    if(isset_request_var('model')) 
    {
        set_request_var('model', sanitize_search_string(get_request_var("model")));
	}
	// Devices list
	if(isset_request_var('selected_items'))
	{
		set_request_var("selected_items", sanitize_search_string(get_request_var("selected_items")));
    }
	// clean up sort_column 
	if (isset_request_var('sort_column')) {
		set_request_var('sort_column', sanitize_search_string(get_request_var("sort_column")) );
	}
	
	// clean up sort direction 
	if (isset_request_var('sort_direction')) {
		set_request_var('sort_direction', sanitize_search_string(get_request_var("sort_direction")) );
	}

	// Action 1=test, 2=execute to execute
	if(isset_request_var('cliAction'))
	{
		set_request_var("cliAction", sanitize_search_string(get_request_var("cliAction")));
    }
	// Command to execute
ciscotools_log( 'CLI: get request: '.print_r(explode(PHP_EOL, get_request_var("cliCommand")),true));
	// Do backup or not
	if(isset_request_var('cliBackup'))
	{
		set_request_var("cliBackup", sanitize_search_string(get_request_var("cliBackup")));
    }
	// Force stop on first error
	if(isset_request_var('cliStopOnError'))
	{
		set_request_var("cliStopOnError", sanitize_search_string(get_request_var("cliStopOnError")));
    }

	/* ===================== SQL Query ===================== */
	// SQLWhere - Where instructions in SQL
	$sqlWhere       = "";
	$description    = get_request_var("description");
	$model    		= get_request_var("model");
	$selected_items	= explode( ' ', get_request_var("selected_items") );
    $cliAction		= get_request_var("cliAction");
    $cliCommand		= get_request_var("cliCommand");
    $cliBackup		= get_request_var("cliBackup");
    $cliStopOnError		= get_request_var("cliStopOnError");
	
	/* setup some variables */
	$host_list = '';
	$host_array = array();
	/* loop through each of the host templates selected on the previous page and get more info about them */
	foreach ($selected_items as $var) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$host_list .= '<li>' . html_escape(db_fetch_cell_prepared('SELECT description FROM host WHERE id = ?', array($matches[1]))) . '</li>';
			$host_array[] = $matches[1];
		}
	}

	$sortColumn		= get_request_var("sort_column");
	switch($sortColumn)
	{	// Precise table and field
		case "host_id":
		default:
			$sortColumn = "host.id";
			break;
		case "description":
			$sortColumn = "host.description";
			break;
		case "hostname":
			$sortColumn = "host.hostname";
			break;
		case "model":
			$sortColumn = "plugin_extenddb_host_model.model";
			break;
	}
	$sortDirection	= get_request_var("sort_direction");
	
	if($description != '')
    {	// Sort all descriptions like URL parameter
        $sqlWhere .= " AND " . "host.description like '%$description%'";
    }
	if($model != '')
    {	// Sort all model like URL parameter
        $sqlWhere .= " AND " . "plugin_extenddb_host_model.model like '%$model%'";
    }
	if($sortColumn != '')
	{	// Order by URL parameter depends on the sort column
		$sort = " ORDER BY " . $sortColumn;
	}
	else $sort = " ORDER BY host.id";
	if($sortDirection != "")
	{	// Precise sort direction
		$sort .= " " . $sortDirection;
	}
	else $sort .= " ASC";
	
	// Count how many devices are in the table
    $totalRows = count( $host_array );

	// If nb rows is -1 > set it to the default
	/* if the number of rows is -1, set it to the default */
	if (get_request_var('rows') == -1) {
		$rows = read_config_option('num_rows_table');
	} else {
		$rows = get_request_var('rows');
	}
	
    $page = ($rows*(get_request_var("page")-1));
    $sqlLimit = $page . "," . $rows;

	if( $sqlWhere != '' ) {
		$sqlWhere = ' WHERE true' . $sqlWhere;
	}		
	
	// SQL Query
	$sqlQuery = "SELECT host.id as id, host.description as description, plugin_extenddb_host_model.model as model, host.status
				FROM host  
				INNER JOIN plugin_ciscotools_backup pctb 
				ON pctb.host_id IN ('".implode("','", $host_array) ."') 
				INNER JOIN plugin_extenddb_host_model ON plugin_extenddb_host_model.host_id IN ('".implode("','", $host_array) ."')
				AND pctb.host_id=host.id
				GROUP BY host.id
                $sort
                LIMIT " . $sqlLimit;
ciscotools_log('CISCOTOOLS query CLI: '.$sqlQuery);
	$devices = db_fetch_assoc( $sqlQuery); 

	general_header();
	
	// Columns displaying text
    $displayText = array(
        "id"		=> array(
			"display" 	=> __("Host ID"),
			"align" 	=> "left",
			"sort" 		=> "ASC",
			"tip" 		=> __('The ID of the device in the database')
		),
        "description"   	=> array(
			"display"	=> __("Description"),
			"align"		=> "left",
			"sort"		=>"ASC",
			"tip"		=> __('The hostname of the device')
		),
		"Command"	=> array(
			"display"	=> __("Command"),
			"align"		=> "left",
			"sort"		=> "ASC",
			"tip"		=> __('Command send to the device')
		),
		"model"	=> array(
			"display"	=> __("Model"),
			"align"		=> "left",
			"sort"		=> "ASC",
			"tip"		=> __('The model of the device')
		),
		"cmd"	=> array(
			"display"	=> __("Cmd Status"),
			"align"		=> "left",
			"sort"		=> "ASC",
			"tip"		=> __('If error, give the line with error')
		),
		"backup"	=> array(
			"display"	=> __("Backup"),
			"align"		=> "left",
			"sort"		=> "ASC",
			"tip"		=> __('Is backup done correctly')
		)
	);
	
	html_start_box("", "100%", "", "3", "center", "");
	
	$displayDeviceText = ($totalRows>1) ? "Devices" : "Device";	// One or more > plural form
	$URL = "ciscotools_tab.php?action=cli";

	$nav = html_nav_bar($URL, MAX_DISPLAY_PAGES, get_request_var("page"), $rows, $totalRows, cacti_sizeof($displayDeviceText)+1, __($displayDeviceText), 'page', "main");
			
	print $nav;

	// Put checkboxes and redirect on cli tab
	html_header_sort($displayText, get_request_var('sort_column'), get_request_var('sort_direction'), 1, "ciscotools_tab.php?action=cli" );
	
	foreach($devices as $device)
	{	// Put records in table
		form_alternate_row('line' . $device['id'], true);	// Alternate color
		form_selectable_cell(filter_value($device['id'], get_request_var('description'), "../../host.php?action=edit&id=" . $device['id']), $device['id']); // ID
		form_selectable_cell(filter_value($device['description'], get_request_var('description')), $device['id']); // Description

		form_selectable_cell(nl2br($cliCommand), $device['id']); // command send

		form_selectable_cell(filter_value($device['model'], get_request_var('model')), $device['id']); // model
		if( process_cmd($device) ) {
			$urlC = "<i class='fa fa-thumbs-up' style='color:green' title='Command ok'></i>";
			if( process_backup($device) ) {
				$urlB = "<i class='fa fa-thumbs-up' style='color:green' title='Backup OK'</i>";
			} else {
				$urlB= "<i class='fa fa-thumbs-down' style='color:red' title='Backup KO'</i>";
			}
		} else {
			if( $device['status'] != '3' ){
				$text = 'Device Unreachable';
			}
			else {
				if( $cliAction == '1' ) {
					$text = 'Test mode';
				}
				else {
					$text = implode(',',$cliResult);
				}
			}
			$urlC= '<i class="fa fa-thumbs-down" style="color:red" title="'.$text.'"></i>';
			$urlB= "<i class='fa fa-thumbs-down' style='color:gray' title='Backup Aborted'</i>";
		}
		
		form_selectable_cell($urlC,  $device['id'], '', 'center');
		form_selectable_cell($urlB,  $device['id'], '', 'center');
		form_end_row();
	}
	
	html_end_box(false);
	print $nav;

}

// process the commande give into the 'command windows'
function process_cmd($deviceid) {
    global $cliAction, $cliCommand, $cliResult, $cliStopOnError;
	$wrongCommand = "Invalid"; // error % Invalid input detected at '^' marker.
	$ret = true;
	
//cacti_log( 'CLI: process_cmd on: '.$deviceid['description'], false, 'CISCOTOOLS' );
	// just test mode
	if( $cliAction == '1' ) {
//cacti_log( 'CLI: process_cmd end test request', false, 'CISCOTOOLS' );
		return false;
	}

	if(empty($deviceid['console_type'])) {
        $console_type = read_config_option('ciscotools_default_console_type');
    } else {
        $console_type = $deviceid['console_type'];
    }
 
	if ( $console_type == 1 ) {
		$stream = create_ssh($deviceid['id']);
	} else {
		$stream = create_telnet($deviceid['id']);
	}
	if( $stream === false ) {
ciscotools_log( 'CLI: process_cmd error on create ssh');
		return false;
	}
	stream_set_timeout($stream, 1);

	if ( $console_type == 1 ) {
		if(ssh_write_stream($stream, 'conf t' ) === false) return false;
		$data = ssh_read_stream($stream);
	} else {
		if(telnet_write_stream($stream, 'conf t' ) === false) return false;
		$data = telnet_read_stream($stream);
	}
	if( $data === false || preg_match("/$wrongCommand/", $data) ){
ciscotools_log( 'CLI: process_cmd Erreur can\'t execute conf t :'.print_r($data, true) );
		return false;
	}
ciscotools_log( 'CLI: process_cmd start process' );

	// Porcess each command and store, command and result to $cliResult array
	$cliResult = array();
	$cliArray = explode( PHP_EOL, $cliCommand);
	foreach( $cliArray as $cli ){
		$cli = trim($cli); //Make some clean up
ciscotools_log( 'CLI: process_cmd process: '.print_r($cli,true));
		
		if ( $console_type == 1 ) {
			ssh_read_stream($stream, '#', 5 ); // empty the buffer
			if ( ssh_write_stream($stream, $cli) === false ) $ret = false;
			$data = ssh_read_stream($stream );
		} else {
			if ( telnet_write_stream($stream, $cli) === false ) $ret = false;
			$data = telnet_read_stream($stream );
		}
		$dataArray = explode( PHP_EOL, $data ); // build an array of the answer
ciscotools_log( 'CLI: process_cmd read :'. print_r($dataArray, true) );
		if( $data === false || preg_match("/$wrongCommand/", $data) || !$ret ){
ciscotools_log( 'CLI: process_cmd Erreur process : '. $cli .' ret: '.print_r($data, true) );
			array_shift( $dataArray );// remove the first part of the result (the command)
			array_pop( $dataArray );// remove the last part of the result (the prompt)
			$cliResult[] = $cli; // store command to result
			$cliResult[] =  implode( ' ', $dataArray );
			if( $cliStopOnError ) {
				return false;
			}
			else {
				$ret = false; // Error but we continue
			}
		}

	}
//cacti_log( 'CLI: process_cmd result: '.print_r($cliResult, true), false, 'CISCOTOOLS' );

	if ( $console_type == 1 ) {
		if(ssh_write_stream($stream, 'exit' ) === false) $ret = false;
		$data = ssh_read_stream($stream);
	} else {
		if(telnet_write_stream($stream, 'exit' ) === false) $ret = false;
		$data = telnet_read_stream($stream);
	}
ciscotools_log( 'CLI: process_cmd exit:'. print_r($data, true) );

ciscotools_log( 'CLI: process_cmd end');

	return $ret;
}

// if requested execute a backup of the config
function process_backup($deviceid) {
    global $cliAction, $cliBackup;
	$wrongCommand = "Invalid"; // error % Invalid input detected at '^' marker.
	$processOK = '[OK]'; // string that come when every thing is done
	$ret = true;

ciscotools_log('CISCOTOOLS process_backup: '.$deviceid['description']);
	if( !$cliBackup ) {
ciscotools_log('CISCOTOOLS process_backup aborted ');
		return false;
	}
	
	if(empty($deviceid['console_type'])) {
        $console_type = read_config_option('ciscotools_default_console_type');
    } else {
        $console_type = $deviceid['console_type'];
    }
 
	if ( $console_type == 1 ) {
		$stream = create_ssh($deviceid['id']);
	} else {
		$stream = create_telnet($deviceid['id']);
	}
	if( $stream === false ) {
ciscotools_log( 'CLI: process_backup error on create ssh');
		return false;
	}
	stream_set_timeout($stream, 30);  // change timeout to action to 30 seconds

	if ( $console_type == 1 ) {
		if(ssh_write_stream($stream, 'wr mem' ) === false) return false;
		$data = ssh_read_stream($stream);
	} else {
		if(telnet_write_stream($stream, 'wr mem' ) === false) return false;
		$data = telnet_read_stream($stream);
	}
	if( $data === false || preg_match("/$wrongCommand/", $data) ){
ciscotools_log( 'CLI: process_backup Erreur can\'t execute wr mem :'.print_r($data, true) );
		return false;
	}

	ciscotools_backup($deviceid['id']); // force backup of the device on cacti
	
	return true;
}
?>