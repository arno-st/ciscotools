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
backup_log( 'ciscotools_backup enter');
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
backup_log( 'ciscotools_backup error on stream');
		return;
	}
backup_log( 'ciscotools_backup term length');

	if ( $console_type == 1 ) {
		if(ssh_write_stream($stream, 'term length 0' ) === false) return;
		$data = ssh_read_stream($stream);
	} else {
		if(telnet_write_stream($stream, 'term length 0' ) === false) return;
		$data = telnet_read_stream($stream);
	}
	if( $data === false ){
backup_log( 'ciscotools_backup Erreur can\'t read term length 0');
		return;
	}
	
backup_log( 'ciscotools_backup sh run');
	ssh_read_stream($stream ); // empty the buffer
	
	if ( $console_type == 1 ) {
		if ( ssh_write_stream($stream, 'sh run') === false ) return;
		$data = ssh_read_stream($stream );
	} else {
		if ( telnet_write_stream($stream, 'sh run') === false ) return;
		$data = telnet_read_stream($stream );
	}
	if( $data === false ){
backup_log( 'ciscotools_backup Erreur can\'t read sh run');
		return;
	}
backup_log( 'ciscotools_backup end sh run');
//backup_log( 'ciscotools_backup read config: '.print_r($data, true));

    // clean up config
    $data = substr($data, strpos($data,'version')); // remove the banner and version from config
	$data = substr($data, strpos($data,"\n")+1); // remove the line after config before the first 0d0a, +1 is for the 0a
	$data = addslashes(substr($data, 0, strrpos($data, "\n",0)-2 )); // remove the end of the config, -2 is for 0d0a
//backup_log( 'ciscotools_backup trim config: '.print_r($data, true));

backup_log( 'ciscotools_backup insert to DB');

// 0d0a is present, why not after the read !!
    $version = (empty($querybackupversion))?1:$querybackupversion + 1; // just add one the the last receive.
    $ret = db_execute_prepared('INSERT INTO plugin_ciscotools_backup(host_id,version,diff,datechange) VALUES(?,?,?,?)',
    array($deviceid,$version,$data,date("Ymd")) );
    
    cacti_log($ret?($host['description'].' config backup done '):($host['description'].' config backup error '), true, 'CISCOTOOLS');
	
	// export the config to file
	ciscotools_export_backup($deviceid);
backup_log( 'ciscotools_backup exit');

}

// function to export the last backup from the DB to a known path
function ciscotools_export_backup($deviceid) {
backup_log( 'ciscotools_export_backup start');
	// get the path
	$local_path = trim(read_config_option('ciscotools_path_export_backup'));

	// Get the list of backup
	$sqlquerybackup = "SELECT host.description as description, plugin_ciscotools_backup.diff as data 
		FROM host 
		INNER JOIN plugin_ciscotools_backup on host.id=host_id 
		WHERE host.id=$deviceid
		ORDER BY version DESC LIMIT 1";

	$export_backup = db_fetch_row($sqlquerybackup);
		
	// if the path is empty, no local backup is done
	$localbackup='';
	if( !empty( $local_path ) ) {
		// check if directory exist, if not create it
		if ( !is_dir( $local_path ) ) {
			mkdir( $local_path, 0755 );
			if ( !is_dir( $local_path ) ) {
				backup_log( "ciscotools_export_backups directory dosen't exist, can't create it: ".$local_path);
				return;
			}			
		}
		if( !array_key_exists('data', $export_backup ) ) {
			backup_log('Not valid call: '. $deviceid . ' data: '.print_r($export_backup, true) );
			return;
		}

		// save data
		$localbackup = $local_path."/".$export_backup['description'].".cfg";
		$export_status = file_put_contents( $localbackup,
			$export_backup['data']
		);
backup_log( 'ciscotools_export_backup saved: '.$export_status);
	}
	
	// get the path for local backup
	$ciscotools_backup_transfert_type = read_config_option('ciscotools_backup_transfert_type');
	if( $ciscotools_backup_transfert_type == 0 ){
		// no remote backup, exit
backup_log( 'ciscotools_export_backup end no remote backup ' );
		return;
	}
	
	//**** REMOTE backup
	// get data for backup procedure
	$ciscotools_backup_server = read_config_option('ciscotools_backup_server');
	$ciscotools_backup_login = read_config_option('ciscotools_backup_login');
	$ciscotools_backup_password = read_config_option('ciscotools_backup_password');
	$ciscotools_remote_path_backup = read_config_option('ciscotools_remote_path_backup');
	
	$remotebackup = $ciscotools_remote_path_backup."/".$export_backup['description'].".cfg";
	// if file was not stored localy, just store it in temp
	if( empty( $local_path ) ) {
		$localbackup = "php://temp/exportbackup";
		$export_status = file_put_contents( $localbackup,
			$export_backup['data'] );
backup_log( 'ciscotools_export_backup memory saved: '.$export_status);
		
	}
	
	switch( $ciscotools_backup_transfert_type ) {
		case 1: // FTP
			$ftp = ftp_connect($ciscotools_backup_server);

			// Identification avec un nom d'utilisateur et un mot de passe
			$login_result = ftp_login($ftp, $ciscotools_backup_login, $ciscotools_backup_password);

// Vérification de la connexion
			if ((!$ftp) || (!$login_result)) {
				backup_log( "La connexion FTP a échoué: " . $ciscotools_backup_server. " pour l'utilisateur". $ciscotools_backup_login );
				exit;
			}
			// Chargement d'un fichier
			ftp_pasv($ftp, true);
			$upload = ftp_put($ftp, $remotebackup, $localbackup, FTP_BINARY);

			// Vérification du status du chargement
			if (!$upload) {
				backup_log( "Le chargement FTP a échoué: ". $remotebackup );
			} else {
				backup_log( "Le chargement FTP est ok: ". $remotebackup);
			}
			
			// Fermeture de la connexion FTP
			ftp_close($ftp);
		 break;
		 
		case 2: // SFTP
			$connection = @ssh2_connect( $ciscotools_backup_server, 22);
			@ssh2_auth_password($connection, $ciscotools_backup_login, $ciscotools_backup_password);

			$sftp = @ssh2_sftp($connection);
			if (! $sftp) {
				backup_log( "Impossible d'ouvrir le S/FTP" );
				@ssh2_disconnect( $connection );
				return;
			}
			$stream = @fopen("ssh2.sftp://$remotebackup", 'w');

			if (! $stream) {
				backup_log( "Impossible d'ouvrir le fichier de destination: ". $remotebackup );
				@ssh2_disconnect( $connection );
				return;
			}

			$data_to_send = @file_get_contents($localbackup);
			if ($data_to_send === false) {
				backup_log( "Impossible d'ouvrir le fichier source: ". $localbackup );
				@ssh2_disconnect( $connection );
				return;
			}
 
			if (@fwrite($stream, $data_to_send) === false) {
				backup_log( "Impossible d'envoyer le fichier" );
				@ssh2_disconnect( $connection );
				return;
			}

			@fclose($stream);

			@ssh2_disconnect( $connection );
		break;
		
		case 3: // SCP
			$connection = @ssh2_connect( $ciscotools_backup_server, 22);
			@ssh2_auth_password($connection, $ciscotools_backup_login, $ciscotools_backup_password);
			
			$upload = @ssh2_scp_send($connection, $localbackup, $remotebackup );
			// Vérification du status du chargement
			if (!$upload) {
				backup_log( "Le chargement SCP a échoué: ". $remotebackup );
			} else {
				backup_log( "Le chargement SCP est ok: ". $remotebackup);
			}
			@ssh2_disconnect( $connection );
		break;
	} 
}

function ciscotools_lastchange($deviceid) {
    /* retrieve the last change date:
    sh start | inc configuration change            D   M   d y
    ! Last configuration change at 12:28:03 LSN Wed Apr 8 2020 by a_soi_0518
    */
    $host = db_fetch_row_prepared("SELECT description, hostname, console_type FROM host WHERE id=?", array($deviceid));
    if( $host === false ){
        return false; // no host to backup
    }

	if(empty($host['console_type'])) {
        $console_type = read_config_option('ciscotools_default_console_type');
    } else {
        $console_type = $host['console_type'];
    }
 
	switch( $console_type ) {
		case 1:
			$stream = create_ssh($deviceid);
		break;
		
		case 2:
			$stream = create_telnet($deviceid);
		break;
		
		default:
		case 0:
			$stream = false;
	}
	
	if($stream === false){
backup_log( 'ciscotools_lastchange Erreur can\'t connect to: '.$host['description']);
		return false;
	}
	
	if ( $console_type == 1 ) {
		if(ssh_write_stream($stream, 'sh start | inc change|!Startup' ) === false) return;
			$data = ssh_read_stream($stream);
	} else {
		if(telnet_write_stream($stream, 'sh start | inc change|!Startup' ) === false) return;
			$data = telnet_read_stream($stream);
	}

	if( $data === false ){
backup_log( 'ciscotools_lastchange Erreur can\'t read version');
		return false;
	}
	
	if($data !== false ) {
backup_log('ciscotools_lastchange 1st: '.print_r($data, true) );

		$data = substr($data, strpos($data, "\n")+1); // clean up start of the string +1 for 0A
backup_log('ciscotools_lastchange 2nd: '.print_r($data, true) ); 
		$data = substr($data, 0, strpos($data, "\n")-1); // clean up end of the string, -2 for 0D0A
backup_log('ciscotools_lastchange last: '.print_r($data, true) );
		$date = format_date($data); // 20012704
	} else {
		$date = $data;
	}
backup_log( 'ciscotools_lastchange version: '. $date .' Device: '.$host['description']);
	
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
backup_log('purge_backup Only one backup for '. $backup['description']);
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
				db_execute( "DELETE FROM plugin_ciscotools_backup WHERE id='".$backupid['backupid']."'");
			}
		}
	}
}

function format_date($string) {
	// if no backup, just fake the date
	if( strpos( $string, 'configuration change since last restart' ) || strpos( $string, '***' )) {
		return '19700101';
	}
backup_log('format_date call: '.print_r($string, true) );
	
    $regex = "/(Mon|Tue|Wed|Thu|Fri|Sat|Sun) (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)(.*?)((0?[1-9]|[1-9][0-9]|100))( [0-9]{2}:[0-9]{2}:[0-9]{2} | )([0-9]{4})/";
    $firstcheck = preg_match($regex, $string, $result);
backup_log('format_date: '.print_r($result, true).' first: '.$firstcheck );

    $regexHour = "/[0-9]{2}:[0-9]{2}:[0-9]{2} /";
    $date = preg_replace($regexHour, '', $result[0]);

backup_log('format_date format: '.date("Ymd", strtotime($date) ) );
    return date("Ymd", strtotime($date)); // 20010212
}

function backup_log( $text ){
    $dolog = read_config_option('ciscotools_backup_log_debug');
    if( $dolog ){
		cacti_log( $text, false, 'CISCOTOOLS_BACKUP' );
	}
}

?>