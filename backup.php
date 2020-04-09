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
include($config['base_path'] . '/plugins/ciscotools/ssh2.php');

/* SNMP commande need to  backup */
$snmpciscocopyTable         = '1.3.6.1.4.1.9.9.96.1.1.1'; // Cisco Copy Table SNMP base ccCopyTable
$snmpsetcopyentry           = $snmpciscocopyTable.'.1'; // copy config request CcCopyEntry
$snmpsetcopyindex           = $snmpsetcopyentry.'.1'; // random number for copy entry ccCopyIndex
$snmpsetcopysrcfiletype     = $snmpsetcopyentry.'.3'; // Source tpe file: runningConfig', 'startupConfig' or 'iosFile' ccCopySourceFileType
$snmpsetcopydsctfiletype    = $snmpsetcopyentry.'.4'; // Dest file type ccCopyDestFileType
$snmpsetcopysrvaddr         = $snmpsetcopyentry.'.5'; // ip of the server address ccCopyServerAddress
$snmpsetcopyfilename        = $snmpsetcopyentry.'.6'; // If necessary the file name ccCopyFileName
$snmpsetcopyusername        = $snmpsetcopyentry.'.7'; // for proto 'rcp', 'scp', 'ftp', or 'sftp' ccCopyUserName
$snmpsetcopypassword        = $snmpsetcopyentry.'.8'; // ccCopyUserPassword

$snmpgetcopystate           = $snmpsetcopyentry.'.10'; // state of this config-copy request ccCopyState
$snmpgetcopyfail            = $snmpsetcopyentry.'.13'; // ccCopyFailCause
$snmpgetcopystatus          = $snmpsetcopyentry.'.14'; // ccCopyEntryRowStatus

/* function called to do the backup of the device
At the call we receive just the ID of the device.*/
function ciscotools_backup($deviceid=1) {
    $dbquery = db_fetch_row_prepared("SELECT description, hostname, login, password FROM host WHERE id=?", array($deviceid));

    /* if $device is 1 that mean the function is called from the tab Cisco Tools, instead of the Action backup */
    $connection = open_ssh($dbquery['hostname'], $dbquery['login'], $dbquery['password']);
    if($connection === false) {
        return;
    }
    
//    $data = ssh_read_stream($connection, 'term length 0' );
    ciscotools_log("Test 1 ".$data);
    $data = ssh_read_stream($connection, 'sh run' ); // show the current config
    ciscotools_log("Test 2".$data);
    // retrieve previous version, if exist, and add 1 to it.
    $querycell = db_fetch_cell("SELECT version FROM plugin_ciscotools_backup WHERE id=".$deviceid.' ORDER BY version DESC LIMIT 1');
    ciscotools_log('querycell: ' .$querycell);
    $version = $querycell + 1; // just add one the the last receive.
    
    $ret = db_execute("INSERT INTO plugin_ciscotools_backup(host_id,version,diff,datechange) VALUES('".
    $deviceid. "', '".
    $version. "', '".
    $data. "', '".
    date("d-m-Y")."')");
    
    cacti_log('config backup : '.$ret, false, 'CISCOTOOLS');
}

function ciscotools_diff($deviceid=1) {
}


?>