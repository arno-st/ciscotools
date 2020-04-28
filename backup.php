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
        if($lastchange === false ) {
			ciscotools_log('Device: '.$host['description']. ' can not read version');
			return;
		}
		
        // check if it's time to backup, depending of the last change recorded
        $savedchange = db_fetch_cell("SELECT datechange FROM plugin_ciscotools_backup WHERE host_id=".$host['id']." ORDER BY version DESC LIMIT 1");

        if($lastchange > $savedchange ){
            ciscotools_log('Device: '.$host['description']. ' need backup '.$lastchange .' backup: '.$savedchange);
            ciscotools_backup($host['id']);
        } else ciscotools_log('Device: '.$host['description']. ' no diff since last backup');
    }        
}

function ciscotools_displaybackup() {
    global $config;
	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("page"));
	input_validate_input_number(get_request_var("rows"));
	/* ==================================================== */
	// clean up sort_column 
	if (isset_request_var('sort_column')) {
		set_request_var('sort_column', sanitize_search_string(get_request_var("sort_column")) );
	}
	if (isset_request_var('description') ) {
		set_request_var('description', sanitize_search_string(get_request_var("description")) );
	}
	/* remember these search fields in session vars so we don't have to keep passing them around */
	load_current_session_value("page", "sess_ciscotools_current_page", "1");
	load_current_session_value("rows", "sess_ciscotools_rows", "-1");
	load_current_session_value("description", "sess_ciscotools_host", "");
	load_current_session_value("sort_column", "sess_ciscotools_sort_column", "host.id");
	load_current_session_value("sort_direction", "sess_ciscotools_sort_direction", "ASC");
	
	$sql_where  = '';
	$description       = get_request_var_request("description");
	
	$sortby  = get_request_var("sort_column");
	if( strcmp($sortby, 'id')  == 0) {
		$sortby="host.id";
	} else if( strcmp($sortby, 'hostname')  == 0) {
		$sortby="host.hostname";
	} else if( strcmp($sortby, 'description')  == 0) {
		$sortby="host.description";
	} else if( strcmp($sortby, 'version')  == 0) {
		$sortby="pctb.version";
	} else if( strcmp($sortby, 'datechange')  == 0) {
		$sortby="pctb.datechange";
	};
	
	if ($description != '') {
		$sql_where .= " AND " . "host.description like '%$description%'";
	}
	/* if the number of rows is -1, set it to the default */
	if (get_request_var("rows") == "-1") {
		$per_row = read_config_option('num_rows_table'); //num_rows_device');
	}else{
		$per_row = get_request_var('rows');
	}
	$page = ($per_row*(get_request_var('page')-1));
	$sql_limit = $page . ',' . $per_row;
	
	general_header();
	
	$sql_query = "SELECT host.id as 'id', 
			host.description as 'description', host.hostname as 'hostname', pctb.version as version, pctb.diff as diff, pctb.datechange as date
			FROM host, plugin_ciscotools_backup pctb
			WHERE host.id=pctb.host_id
			$sql_where
			ORDER BY $sortby
			LIMIT " . $sql_limit;
	
	$result = db_fetch_assoc($sql_query);
	$total_rows = count($result);
	
	?>
	
	<script type="text/javascript">
	<!--
	
	function applyFilterChange(objForm) {
		strURL = '?header=false&description=' + objForm.description.value;
		document.location = strURL;
	}
	function clearFilter() {
		<?php
			kill_session_var("sess_ciscotools_host");
			kill_session_var("sess_ciscotools_current_page");
			kill_session_var("sess_ciscotools_rows");
			kill_session_var("sess_ciscotools_sort_column");
			kill_session_var("sess_ciscotools_sort_direction");
	
			unset($_REQUEST["sess_ciscotools_host"]);
			unset($_REQUEST["sess_ciscotools_rows"]);
			unset($_REQUEST["sess_ciscotools_current_page"]);
			unset($_REQUEST["sess_ciscotools_sort_column"]);
			unset($_REQUEST["sess_ciscotools_sort_direction"]);
			
		?>
		strURL  = 'ciscotools_tab.php?header=false';
		loadPageNoHeader(strURL);
	}
	
	-->
	</script>
	
	<?php
	// TOP DEVICE SELECTION
	html_start_box('<strong>Filters</strong>', '100%', '', '3', 'center', '');
	
	?>
	<meta charset="utf-8"/>
		<td class="noprint">
		<form style="padding:0px;margin:0px;" name="form" method="get" action="<?php print $config['url_path'];?>plugins/ciscotools/ciscotools_tab.php">
			<table width="100%" cellpadding="0" cellspacing="0">
				<tr class="noprint">
					<td nowrap style='white-space: nowrap;' width="1">
						&nbsp;Description :&nbsp;
					</td>
					<td width="1">
						<input type="text" name="description" size="25" value="<?php print get_request_var_request("description");?>">
					</td>
					<td nowrap style='white-space: nowrap;'>
						<input type="submit" value="Go" title="Set/Refresh Filters">
						<input type='button' value="Clear" id='clear' onClick='clearFilter()' title="Reset fields to defaults">
					</td>
				</tr>
			</table>
		</form>
		</td>
	</tr>
	
	<?php
	html_end_box();
	html_start_box('', '100%', '', '3', 'center', '');
	
	$nav = html_nav_bar('ciscotools_tab.php', MAX_DISPLAY_PAGES, get_request_var('page'), $per_row, $total_rows, 12, __('Devices'), 'page', 'main');
	
	print $nav;
	
	$display_text = array(
		"id" => array("Host ID", "ASC"),
		"hostname" => array("Hostname", "ASC"),
		"description" => array("Description", "ASC"),
		"version" => array("version", "ASC"),
		"date" => array("Backup Date", "ASC"),
		"diff" => array("Backup or Diff", ""),
		"nosort" => array("", ""));
	
	html_header_sort($display_text, get_request_var("sort_column"), get_request_var("sort_direction"), false);

	$i=0;
	if (!empty($result)) {
			$class   = 'odd';
			foreach($result as $row) {
					($class == 'odd' )?$class='even':$class='odd';
	
	
					print"<tr class='$class'>";
					print"<td style='padding: 4px; margin: 4px;'>"
							. $row['id'] . '</td>
							<td>' . $row['hostname'] . '</td>
							<td>' . $row['description'] . '</td>
							<td>' . $row['version'] . '</td>
							<td>' . $row['date'] . '</td>
							<td>' . '<a href=ciscotools_tab.php?action=diff&deviceid=' . $row['id'] . ">DIFF</a></td>
							<td align='right'>";
	
					print "</tr>";
		}
	}else{
		print "<tr><td style='padding: 4px; margin: 4px;' colspan=11><center>There are no Backup to display!</center></td></tr>";
	}
	
	html_end_box(false);
	
	print $nav;
	
	bottom_footer();
}

function ciscotools_diff() {
    global $config;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("deviceid"));
	/* ==================================================== */
	$deviceid = get_request_var('deviceid');
	/* display row  and page settings */
			
	$sql_query = "SELECT pctb.id as id, host.id as 'host', 
			host.description as 'description', host.hostname as 'hostname', pctb.version as version, pctb.diff as diff, pctb.datechange as date
			FROM host, plugin_ciscotools_backup pctb
			WHERE host.id=pctb.host_id
			AND host.id=".$deviceid.'
			ORDER BY pctb.version';
	$result = db_fetch_assoc($sql_query);

	/* display row  and page settings */
	$total_backup = (count($result)!==false)?count($result):1;
	$backupid = min(array_column($result, 'version'))-1; // array_column return a one based, and array is using 0 based count
	$diffids = array_slice($result, $backupid+1 ); // remove the lowest backup, and kepp all other to find the diff, null if only 1 backup

	// TOP DEVICE SELECTION
	html_start_box('', '100%', '', '3', 'center', '');
   
	$nav = html_nav_bar('ciscotools_tab.php', 1, 1, $total_backup, $total_backup, 30, 'of '.$result[$backupid]['description']
	. ' backup date: '.$result[$backupid]['date'] . ' version: '.$result[$backupid]['version']);

	print $nav;

	if (!empty($result)) {
			print "<div>";
			print 	"<table><tbody>";
			print 		'<tr>';
			print			"<td>".'Config base';
			print 				"<div style='border: 1px solid black;'>" . nl2br($result[$backupid]['diff']) . '</div>';
			print			'</td>';
			if($total_backup > 1){
				print 			'<td>';
				foreach( $diffids as $diffid ) {
					print			'Diff Date '.$diffid['date'];
					print	 			"<div style='border: 1px solid black;'>" . nl2br($diffid['diff']) . '</div>';
					print			'<br>';
				}
				print 			'</td>';
			}
			print 		'</tr>';
			print 	'</tbody></table>';
			print '</div>';
	}else{
		print "<tr><td style='padding: 4px; margin: 4px;' colspan=11><center>There are no Backup to display!</center></td></tr>";
	}
	
	html_end_box(false);
	$nav = html_nav_bar('ciscotools_tab.php', 1, 1, $total_backup, $total_backup, 30, 'of '. $result[$backupid]['description']  );
	
	print $nav;
	
	
	bottom_footer();

}

/* function called to do the backup of the device
At the call we receive the ID of the device.
*/
function ciscotools_backup($deviceid) {
     // retrieve previous version, if exist, and add 1 to it.
    $querybackuprow = db_fetch_row("SELECT version, datechange FROM plugin_ciscotools_backup WHERE host_id=".$deviceid." ORDER BY version DESC LIMIT 1");
    $dbquery = db_fetch_row_prepared("SELECT description, hostname FROM host WHERE id=?", array($deviceid));
    if( $dbquery === false ){
        return false; // no host to backup
    }
    $account = check_login_password($deviceid);

	$stream = open_ssh($dbquery['hostname'], $account['login'], $account['password']);
	if($stream === false) exit;

	$data = ssh_read_stream($stream );
	if( $data === false ){
		ciscotools_log( 'Erreur can\'t read login prompt');
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
	$data = substr($data, strpos($data,"\n")+1); // remove the line after config before the first 0d0a
	$data = addslashes(substr($data, 0, strrpos($data, "\n",0) )); // remove the end of the config
	
    $version = (empty($querybackuprow['version']))?1:$querybackuprow['version'] + 1; // just add one the the last receive.
    
    $ret = db_execute("INSERT INTO plugin_ciscotools_backup(host_id,version,diff,datechange) VALUES('".
    $deviceid. "', '".
    $version. "', '".
    $data. "', '".
    date("Ymd")."')");
    
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
    $account = check_login_password($deviceid);

	$stream = open_ssh($dbquery['hostname'], $account['login'], $account['password']);
	if($stream === false) exit;

	$data = ssh_read_stream($stream );
	if( $data === false ){
		ciscotools_log( 'Erreur can\'t read login prompt');
		return false;
	}

	if(ssh_write_stream($stream, 'sh run | inc configuration change' ) === false) return;
	$data = ssh_read_stream($stream);
	if( $data === false ){
		ciscotools_log( 'Erreur can\'t read version');
		return false;
	}
	
	if($data !== false ) {
		$data = substr($data, strpos($data, "\n")); // clean up start of the string
		$data = substr($data, 0, strrpos($data, "\n",0)); // clean up end of the string
		$data_array = explode(' ', $data);
		$data = $data_array[8].'-'.$data_array[9].'-'.$data_array[10];
		$date = date( "Ymd", strtotime($data) ); // Apr272020
	} else {
		$date = $data;
	}
	
    return $date;
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

?>