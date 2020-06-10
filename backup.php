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

include_once($config['base_path'] . '/plugins/extenddb/ssh2.php');

/* function called to do the backup of the device
At the call we receive the ID of the device.
*/
function ciscotools_backup($deviceid) {
	$querybackupversion = db_fetch_cell("SELECT version FROM plugin_ciscotools_backup WHERE host_id=".$deviceid." ORDER BY version DESC LIMIT 1");

	$host = db_fetch_row( 'SELECT description, hostname, console_type  FROM host where id='.$deviceid );
	if(empty($host['console_type'])) {
        $console_type = read_config_option('ciscotools_default_console_type');
    } else {
        $console_type = $host['console_type'];
    }
 
	if ( $console_type == 1 ) {
		$stream = create_ssh($deviceid);
	} else {
		$stream = create_telnet($deviceid);
	}
	if( $stream === false ) {
		return;
	}

	if ( $console_type == 1 ) {
		if(ssh_write_stream($stream, 'term length 0' ) === false) return;
		$data = ssh_read_stream($stream);
	} else {
		if(telnet_write_stream($stream, 'term length 0' ) === false) return;
		$data = telnet_read_stream($stream);
	}
	if( $data === false ){
		ciscotools_log( 'Erreur can\'t read term length 0');
		return;
	}
	
	if ( $console_type == 1 ) {
		if ( ssh_write_stream($stream, 'sh start') === false ) return;
		$data = ssh_read_stream($stream);
	} else {
		if ( telnet_write_stream($stream, 'sh start') === false ) return;
		$data = telnet_read_stream($stream);
	}
	if( $data === false ){
		ciscotools_log( 'Erreur can\'t read sh start');
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
    
    cacti_log($ret?($host['description'].' config backup done '.$console_type):($host['description'].' config backup error '.$console_type), false, 'CISCOTOOLS');
}

function ciscotools_lastchange($deviceid) {
    /* retreive the last change date:
    sh start | inc configuration change            D   M   d y
    ! Last configuration change at 12:28:03 LSN Wed Apr 8 2020 by a_soi_0518
    */
    $host = db_fetch_row_prepared("SELECT description, hostname, console_type FROM host WHERE id=?", array($deviceid));
    if( $host === false ){
        return false; // no host to backup
    }

	if ( $host['console_type'] == 1 ) {
		$stream = create_ssh($deviceid);
	} else {
		$stream = create_telnet($deviceid);
	}
	if($stream === false){
		return false;
	}
	
	if ( $host['console_type'] == 1 ) {
		if(ssh_write_stream($stream, 'sh start | inc change|!Startup' ) === false) return;
			$data = ssh_read_stream($stream);
	} else {
		if(telnet_write_stream($stream, 'sh start | inc change|!Startup' ) === false) return;
			$data = telnet_read_stream($stream);
	}

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

// return the number of backup per host
	$sqlquery = "SELECT plugin_ciscotools_backup.host_id, host.description as description,
			plugin_ciscotools_backup.datechange as date, plugin_ciscotools_backup.id as backupid,
			count(plugin_ciscotools_backup.host_id) as count
			FROM host 
			INNER JOIN  plugin_ciscotools_backup ON plugin_ciscotools_backup.host_id=host.id
			WHERE plugin_ciscotools_backup.datechange < '".$datetopurge."'
			 GROUP BY plugin_ciscotools_backup.host_id";

	$sqlret = db_fetch_assoc($sqlquery); // if empty no backup
	if($sqlret > 0 ) {
		/* then each row contain id of host that has a backup, remove all host with backup of no more than 1
		end delete, other until we keep only the last one
		host_id	description	date	backupid	count
		*/
		foreach( $sqlret as $backup ) {
			if( $backup['count'] == '1' ) {
				ciscotools_log('Only one backup for '. $backup['description']);
				continue;
			}
			$sqlquery = "SELECT plugin_ciscotools_backup.host_id, host.description as description, plugin_ciscotools_backup.datechange as date, plugin_ciscotools_backup.id as backupid 
			FROM host 
			INNER JOIN plugin_ciscotools_backup ON plugin_ciscotools_backup.host_id=host.id 
			WHERE plugin_ciscotools_backup.datechange < '".$datetopurge."'  
			AND plugin_ciscotools_backup.host_id='".$backup['host_id']."' 
			ORDER BY plugin_ciscotools_backup.datechange DESC LIMIT 1, 400";

			$sqlbackup = db_fetch_assoc( $sqlquery );

			// return an array of backup, and keep the first row [0]
			$array_backup = array_column($sqlbackup, NULL, 'date');
			foreach( $array_backup as $backupid ) {
				cacti_log('Remove Backups of : '.$backupid['description'].' date: '.$backupid['date'], false, 'CISCOTOOLS' );
				db_execute( "DELETE FROM plugin_ciscotools_backup WHERE id='".$backupid."'");
			}
		}
	}
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