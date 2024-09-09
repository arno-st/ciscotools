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

/* check which device we have to backup */
    // backup for every Cisco device or not ?, if TRUE, then exclude device that are FALSE or not Cisco
    /*
    By default we take the value from ciscotools_default_do_backup, to decide what to do
    But if a device is volontary setup to disable or down, we don't do the backup.
    If we don't force by default the backup, we do it only on enabled device
    */
    if( read_config_option('ciscotools_default_do_backup') == 'on') { 
        $sqlqueryfilter = "(do_backup!='off' OR do_backup IS NULL) AND snmp_sysObjectID LIKE 'iso.3.6.1.4.1.9%' AND disabled !='on' AND status ='3'"; 
    } else {
        $sqlqueryfilter = "do_backup='on' AND snmp_sysObjectID LIKE 'iso.3.6.1.4.1.9%' AND disabled !='on' AND status ='3'";
    }
	$myquery = "SELECT id, description, hostname FROM host WHERE ".$sqlqueryfilter;
    $dbquery = db_fetch_assoc($myquery);
    if( $dbquery === false ){
        cacti_log('No device to backup', false, 'BACKUP');
		set_config_option('ciscotools_backup_running', 'off'); // set the end of the process
        return; // no host to backup
    }
backup_log("check_backup check backup on :". $myquery );
backup_log("check_backup need to check backup on :". count($dbquery)." hosts" );
    
    // do backup for all device discovered
    foreach( $dbquery as $host ){
    // first get the last change 
        $lastchange = ciscotools_lastchange($host['id']);
        if($lastchange === false ) {
			cacti_log('Device: '.$host['description']. ' can not check change', false, 'BACKUP');
			continue;
		}

        // check if it's time to backup, depending of the last change recorded
        $savedchange = db_fetch_cell("SELECT datechange FROM plugin_ciscotools_backup WHERE host_id=".$host['id']." ORDER BY version DESC LIMIT 1");

        if($lastchange > $savedchange || empty($savedchange) ){
backup_log('check_backup  Device: '.$host['description']. ' need backup '.$lastchange .' backup: '.$savedchange);
            ciscotools_backup($host['id']);
        } else backup_log('check_backup  Device: '.$host['description']. ' no diff since last backup');
    }
	
	set_config_option('ciscotools_backup_running', 'off'); // set the end of the process
    cacti_log( 'Check backup ended', false, 'BACKUP');
?>