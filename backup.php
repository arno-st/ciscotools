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

include_once($config['base_path'] . '/plugins/ciscotools/ssh2.php');

/* function called to do the backup of the device
At the call we receive the ID of the device.
*/
function ciscotools_backup($deviceid) {
	$querybackupversion = db_fetch_cell("SELECT version FROM plugin_ciscotools_backup WHERE host_id=".$deviceid." ORDER BY version DESC LIMIT 1");

	$stream = create_ssh($deviceid);
	if( $stream === false ) {
		return;
	}

	if(ssh_write_stream($stream, 'term length 0' ) === false) return;
	$data = ssh_read_stream($stream);
	if( $data === false ){
		ciscotools_log( 'Erreur can\'t read term length 0');
		return;
	}
	
	if ( ssh_write_stream($stream, 'sh run') === false ) return;
	$data = ssh_read_stream($stream);
	if( $data === false ){
		ciscotools_log( 'Erreur can\'t read sh run');
		return;
	}
	
    // clean up config
    $data = substr($data, strpos($data,'version')); // remove the banner and version from config
	$data = substr($data, strpos($data,"\n")+1); // remove the line after config before the first 0d0a, +1 is for the 0a
	$data = addslashes(substr($data, 0, strrpos($data, "\n",0)-2 )); // remove the end of the config, -2 is for 0d0a

// 0d0a is present, why not after the read !!
    $version = (empty($querybackupversion))?1:$querybackupversion + 1; // just add one the the last receive.
    $ret = db_execute_prepared('INSERT INTO plugin_ciscotools_backup(host_id,version,diff,datechange) VALUES(?,?,?,?)',
    array($deviceid,$version,$data,date("Ymd")) );
    
    cacti_log($ret?($deviceid.' config backup done'):($deviceid.' config backup error'), false, 'CISCOTOOLS');
}

function ciscotools_lastchange($deviceid) {
    /* retreive the last change date:
    sh run | inc configuration change            D   M   d y
    ! Last configuration change at 12:28:03 LSN Wed Apr 8 2020 by a_soi_0518
    */
    $dbquery = db_fetch_row_prepared("SELECT description, hostname FROM host WHERE id=?", array($deviceid));
    if( $dbquery === false ){
        return false; // no host to backup
    }

	$stream = create_ssh($deviceid);
	if($stream === false){
		return false;
	}
	
	if(ssh_write_stream($stream, 'sh run | inc change|!Time' ) === false) return;
	$data = ssh_read_stream($stream);
	if( $data === false ){
		ciscotools_log( 'Erreur can\'t read version');
		return false;
	}
	
	if($data !== false ) {
		$data = substr($data, strpos($data, "\n")+1); // clean up start of the string +1 for 0A
		$data = substr($data, 0, strpos($data, "\n")-1); // clean up end of the string, -2 for 0D0A
		$date = format_date($data); // Apr272020
	} else {
		$date = $data;
	}
	
    return $date;
}

/* purge backup based on current date
Take car if only one backup exist, do not delete it 
*/
function purge_backup() {
	$datetopurge = date('Ymd',strtotime(read_config_option('ciscotools_retention_duration')) );
	cacti_log( 'Backup Purge Before: '.$datetopurge, false, 'CISCOTOOLS' );

	$sqlquery = "SELECT host.description as description, plugin_ciscotools_backup.datechange as date,
			plugin_ciscotools_backup.id as dateid
			FROM host 
			INNER JOIN  plugin_ciscotools_backup ON plugin_ciscotools_backup.host_id=host.id
			WHERE plugin_ciscotools_backup.datechange < '".$datetopurge."'";
	$sqlret = db_fetch_assoc($sqlquery);
	if($sqlret > 0 ) {
		foreach( $sqlret as $backup ) {
			cacti_log('Remove Backup of : '.$backup['description'].' date: '.$datetopurge, false, 'CISCOTOOLS' );
			db_execute( "DELETE FROM plugin_ciscotools_backup WHERE id='".$backup['dateid']."'");
		}
	}
}

function create_ssh($deviceid) {
	$dbquery = db_fetch_row_prepared("SELECT description, hostname, login, password FROM host WHERE id=?", array($deviceid));
    if( $dbquery === false ){
        return false; // no host to backup
    }
	
	// look for the login/password on the device, or take the default one
	$account=array();
    if(empty($dbquery['login'])) {
        $account['login'] = read_config_option('ciscotools_default_login');
        $account['password'] = read_config_option('ciscotools_default_password');
    } else {
        $account['login'] = $dbquery['login'];
        $account['password'] = $dbquery['password'];
    }
 	
	// open the ssh stream to the device
 	$stream = open_ssh($dbquery['hostname'], $account['login'], $account['password']);
	if($stream !== false){
		$data = ssh_read_stream($stream );
		if( $data === false ){
			ciscotools_log( 'Erreur can\'t read login prompt');
			return false;
		}
	}
	return $stream;
}

function format_date($string) {
	// if no backup, just fake the date
	if( strpos( $string, 'configuration change since last restart' ) ) {
		return '19700101';
	}
	
    $regex = "/(Mon|Tue|Wed|Thu|Fri|Sat|Sun) (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)(.*?)((0?[1-9]|[1-9][0-9]|100))( [0-9]{2}:[0-9]{2}:[0-9]{2} | )([0-9]{4})/";
    preg_match($regex, $string, $result);

    $regexHour = "/[0-9]{2}:[0-9]{2}:[0-9]{2} /";
    $date = preg_replace($regexHour, '', $result[0]);

    return date("Ymd", strtotime($date));
}
?>