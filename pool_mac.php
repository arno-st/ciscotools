#!/usr/bin/php -q
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007-2017 The Cacti Group                                 |
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

// do NOT run this script through a web browser
if (!isset($_SERVER['argv'][0]) || isset($_SERVER['REQUEST_METHOD'])  || isset($_SERVER['REMOTE_ADDR'])) {
	die('<br><strong>This script is only meant to run at the command line.</strong>');
}

// let PHP run just as long as it has to
ini_set('max_execution_time', '0');

error_reporting(E_ALL ^ E_DEPRECATED);

include(dirname(__FILE__).'/../../include/global.php');
include_once($config['base_path'].'/lib/utility.php');
include_once($config['base_path'] . '/plugins/ciscotools/setup.php');

// process calling arguments
$parms = $_SERVER['argv'];

//process number
$process_no = $parms[1];

// number of process
$nb_process = $parms[2];
if(empty($nb_process)) $nb_process=2; // just in case the configuration is not saved, nb_process will be empty

/* check which device we have to poll */
    // pool for every Cisco device or not ?, if TRUE, then exclude device that are FALSE or not Cisco
    /*
    By default we take the value from ciscotools_default_keep_mac_track, to decide what to do
    But if a device is volontary setup to disable or down, we don't do the polling.
    If we don't force by default the polling, we do it only on enabled device
    */
    if( read_config_option('ciscotools_default_keep_mac_track') == 'on') { 
        $sqlqueryfilter = "snmp_sysObjectID LIKE 'iso.3.6.1.4.1.9.%' AND disabled !='on' AND status ='3'"; 
    } else {
        $sqlqueryfilter = "keep_mac_track='on' AND snmp_sysObjectID LIKE 'iso.3.6.1.4.1.9.%' AND disabled !='on' AND status ='3'";
    }
    $dbquery = db_fetch_assoc("SELECT * FROM host WHERE ".$sqlqueryfilter );
    if( $dbquery === false ){
        cacti_log('No device to pool', false, 'CISCOTOOLS');
        return; // no host to pool
    }

	$nbdevices_per_process = round(count($dbquery)/$nb_process, 0, PHP_ROUND_HALF_UP);
	$start = $nbdevices_per_process * ($process_no-1);

	$mysql ='SELECT * FROM host WHERE '.$sqlqueryfilter.' LIMIT '.$start.','.$nbdevices_per_process;

	$hostrecord_array = db_fetch_assoc($mysql);
    // do pooling for all device discovered
	foreach( $hostrecord_array as $hostrecord ) {
		cacti_log('Pool mac for host:'.$hostrecord['description'].' process:'.$process_no, TRUE, 'MACTRACK');
		get_mac_table($hostrecord);
	}
	
	$still_process = read_config_option('ciscotools_mac_running');
	if( $still_process > 0 ){
		$still_process--;
		set_config_option('ciscotools_mac_running', $still_process ); // set the end of the process
	}
	
	if( $still_process == 0 ) cacti_log( 'Mac polling ended', false, 'CISCOTOOLS');
?>