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
/*
If needed to run from CLI, in case it take toolong

// do NOT run this script through a web browserf (!isset($_SERVER['argv'][0]) || isset($_SERVER['REQUEST_METHOD'])  || isset($_SERVER['REMOTE_ADDR'])) {
	die('<br><strong>This script is only meant to run at the command line.</strong>');
}

// let PHP run just as long as it has to
ini_set('max_execution_time', '0');

error_reporting(E_ALL ^ E_DEPRECATED);
include(dirname(__FILE__).'/../../include/global.php');
include_once($config['base_path'].'/lib/utility.php');
include_once($config['base_path'].'/lib/snmp.php');
include_once($config['base_path'].'/lib/data_query.php');
include_once($config["base_path"] . '/lib/ping.php');
include_once($config['base_path'] . '/lib/poller.php');
*/

include_once($config['base_path'] . '/plugins/ciscotools/ssh2.php');
use phpsnmp\SNMP;

/* SNMP commande need to  backup */
$snmpciscocopyTable         = '1.3.6.1.4.1.9.9.96.1.1.1'; // Cisco Copy Table SNMP base ccCopyTable
$snmpsetcopyentry           = $snmpciscocopyTable.'.1'; // copy config request CcCopyEntry
$snmpsetcopyindex           = $snmpsetcopyentry.'.1'; // random number for copy entry ccCopyIndex
$snmpsetcopysrcfiletype     = $snmpsetcopyentry.'.3'; // Source tpe file: runningConfig', 'startupConfig' or 'iosFile' ccCopySourceFileType
$snmpsetcopydsctfiletype    = $snmpsetcopyentry.'.4'; // Dest file type ccCopyDestFileType
$snmpsetcopysrvaddrtype     = $snmpsetcopyentry.'.15'; // type of ip address ccCopyServerAddressType
$snmpsetcopysrvaddr         = $snmpsetcopyentry.'.16'; // ip of the server address ccCopyServerAddressRev1
$snmpsetcopyfilename        = $snmpsetcopyentry.'.6'; // If necessary the file name ccCopyFileName
$snmpsetcopyusername        = $snmpsetcopyentry.'.7'; // for proto 'rcp', 'scp', 'ftp', or 'sftp' ccCopyUserName
$snmpsetcopypassword        = $snmpsetcopyentry.'.8'; // ccCopyUserPassword

$snmpgetcopystate           = $snmpsetcopyentry.'.10'; // state of this config-copy request ccCopyState
$snmpgetcopyfail            = $snmpsetcopyentry.'.13'; // ccCopyFailCause
$snmpgetcopystatus          = $snmpsetcopyentry.'.14'; // ccCopyEntryRowStatus

/* check which device we have to backup */
function ciscotools_checkbackup() {
    // backup for every Cisco device or not ?, if TRUE, then exclude device that are FALSE or not Cisco
    /*
    By default we take the value from ciscotools_default_do_backup, to decide what to do
    But if a device is volontary setup to disable, we don't do the backup.
    If we don't force by default the backup, we do it only on enabled device
    */
    if( read_config_option('ciscotools_default_do_backup') == 'on') { 
        $sqlqueryfilter = "do_backup!='off' AND snmp_sysObjectID LIKE 'iso.3.6.1.4.1.9%' AND disabled !='on'"; 
    } else {
        $sqlqueryfilter = "do_backup='on' AND snmp_sysObjectID LIKE 'iso.3.6.1.4.1.9%' AND disabled !='on'";
    }
    $dbquery = db_fetch_assoc("SELECT id, description, hostname FROM host WHERE ".$sqlqueryfilter);
    if( $dbquery === false ){
        ciscotools_log('No device to backup');
        return; // no host to backup
    }
    ciscotools_log("need to backup :". count($dbquery)." hosts" );
    
    // do backup for all device discovered
    foreach( $dbquery as $host ){
    // first get the last change to see if the request is a full one or a diff
        $lastchange = ciscotools_lastchange($host['id']);
        
        // check if it's time to backup, depending of the last change recorded
        $savedchange = db_fetch_cell("SELECT datechange FROM plugin_ciscotools_backup WHERE host_id=".$host['id']." ORDER BY version DESC LIMIT 1");

        if($lastchange > $savedchange ){
            ciscotools_log('Device: '.$host['description']. ' need backup '.$lastchange .' backup: '.$savedchange);
            //ciscotools_backup($host['id'];
        } else ciscotools_log('Device: '.$host['description']. ' no diff since last backup');
    }        
}

function ciscotools_displaybackup() {
}

/* function called to do the backup of the device
At the call we receive just the ID of the device.
and we did a full backup
*/
function ciscotools_backup($deviceid) {
     // retrieve previous version, if exist, and add 1 to it.
    $querybackupcell = db_fetch_row("SELECT version, datechange as date FROM plugin_ciscotools_backup WHERE host_id=".$deviceid." ORDER BY version DESC LIMIT 1");

    $dbquery = db_fetch_row_prepared("SELECT description, hostname FROM host WHERE id=?", array($deviceid));
    if( $dbquery === false ){
        return; // no host to backup
    }
    $account = check_login_password($deviceid);

    /* if $device is 1 that mean the function is called from the tab Cisco Tools, instead of the Action backup */
    $connection = open_ssh($dbquery['hostname'], $account['login'], $account['password']);
    if($connection === false) {
        return;
    }
    
    $data = ssh_read_stream($connection, 'sh run' ); // show the current config
    if($data===false){
        close_ssh($connection);
        return;
    }
    close_ssh($connection);
    
    // remove all before version
    $data = addslashes(substr($data, strpos($data,'version')+12)); // remove the banner and version from config    ciscotools_log("Test 2".$data);
    $version = $querybackupcell + 1; // just add one the the last receive.
    
    $ret = db_execute("INSERT INTO plugin_ciscotools_backup(host_id,version,diff,datechange) VALUES('".
    $deviceid. "', '".
    $version. "', '".
    $data. "', '".
    date("dmY")."')");
    
    cacti_log($ret?'config backup done':'config backup error', false, 'CISCOTOOLS');
    
}

function ciscotools_lastchange($deviceid) {
    /* retreive the last change date:
    sh run | inc configuration change            D   M   d y
    ! Last configuration change at 12:28:03 LSN Wed Apr 8 2020 by a_soi_0518
    */
     $dbquery = db_fetch_row_prepared("SELECT description, hostname FROM host WHERE id=?", array($deviceid));
    if( $dbquery === false ){
        return; // no host to backup
    }
    $account = check_login_password($deviceid);
    
    $connection = open_ssh($dbquery['hostname'], $account['login'], $account['password']);
    if($connection === false) {
        return;
    }
    $data = ssh_read_stream($connection, 'sh run | inc configuration change' ); // show the current config
    close_ssh($connection);
    
    $data = substr($data, strpos($data,'LSN')+8, 11);
    $date = date( "dmY", strtotime($data) );

    return $date;
}

function ciscotools_diff($deviceid) {
}

function check_login_password( $deviceid){
    $def_login = read_config_option('ciscotools_default_login');
    $def_password = read_config_option('ciscotools_default_password');
    
    $dbquery = db_fetch_row_prepared("SELECT login, password FROM host WHERE id=?", array($deviceid));
    if( $dbquery === false ){
        return; // no host to backup
    }
    
    $account=array();
    if(empty($dbquery['login'])) {
        $account['login'] = $def_login;
        $account['password'] = $def_password;
    } else {
        $account['login'] = $dbquery['login'];
        $account['password'] = $dbquery['password'];
    }
 
    return $account;
}

function snmp_set($hostname, $community, $oid, $version, $auth_user = '', $auth_pass = '',
	$auth_proto = '', $priv_pass = '', $priv_proto = '', $context = '',
	$port = 161, $timeout = 500, $retries = 0, $environ = SNMP_POLLER,
	$engineid = '', $value_output_format = SNMP_STRING_OUTPUT_GUESS) {
        
    $snmp_value = snmpset($hostname . ':' . $port, $community, $oid, ($timeout * 1000), $retries);
    return $snmp_value;
    
}
?>