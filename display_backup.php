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
include_once($config['base_path'] . '/plugins/ciscotools/class.Diff.php');

function ciscotools_displaybackup() {
    global $config, $item_rows;
	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("page"));
	input_validate_input_number(get_request_var("rows"));
	/* ==================================================== */
	// clean up descrption
	if (isset_request_var('description') ) {
		set_request_var('description', sanitize_search_string(get_request_var("description")) );
	}
	/* remember these search fields in session vars so we don't have to keep passing them around */
	load_current_session_value("page", "sess_ciscotools_current_page", "1");
	load_current_session_value("rows", "sess_ciscotools_rows", "-1");
	load_current_session_value("description", "sess_ciscotools_description", "");

	$sql_where  = '';
	$description       = get_request_var_request("description");
	
	if ($description != '') {
		$sql_where .= " AND " . "host.description like '%$description%'";
	}

	general_header();
	// how many backup we have
	$sql_total_row = "SELECT count(distinct(host.id))
			FROM host, plugin_ciscotools_backup pctb
			WHERE host.id=pctb.host_id".
			$sql_where;
	
	$total_rows = db_fetch_cell( $sql_total_row);
			
	/* if the number of rows is -1, set it to the default */
	if (get_request_var("rows") == "-1") {
		$per_row = read_config_option('num_rows_table'); //num_rows_device');
	}else{
		$per_row = get_request_var('rows');
	}
	$page = ($per_row*(get_request_var('page')-1));
	$sql_limit = $page . ',' . $per_row;
	
	$sql_query = "SELECT host.id as 'id', 
			host.description as 'description', host.hostname as 'hostname', pctb.version as version, pctb.datechange as date
			FROM host, plugin_ciscotools_backup pctb
			WHERE host.id=pctb.host_id
			$sql_where
			ORDER BY host.id 
			LIMIT " . $sql_limit;
	
	$result = db_fetch_assoc($sql_query); // query result is one entry par backup
	ciscotools_log('query host: '. $sql_query );
	// get last date, and count how many backup per 1 host, can't do in the sql query
	$devices = array();
	foreach( $result as $entry ) {
		$id = $entry['id'];
		if (isset($devices[$id]) ) { 
			$devices[$id]['count']++;
			if ($devices[$id]['date'] < $entry['date'] ) {
				$devices[$id]['date'] = $entry['date'];
			}
		} else {
			$devices[$id]['id'] = $id;
			$devices[$id]['description'] = $entry['description'];
			$devices[$id]['hostname'] = $entry['hostname'];
			$devices[$id]['date'] = $entry['date'];
			$devices[$id]['count'] = 1;
		}
	ciscotools_log('Devices: '. $devices[$id]['description'] .' count: '.$devices[$id]['count'] ); // display number of backup
	}
	ciscotools_log('Nb Devices: '. count($devices) ); // actual number of device into $devices to display
// $result count the number of backups, devices give the number of backups per hosts, but resulting of the sql_limit (50 backup, but on 17 hosts)
// make a do while until count($devices) equal $per_row
	?>
	
	<script type="text/javascript">
	<!--
	
	function applyFilterChange(objForm) {
		strURL = '?header=false&description=' + objForm.description.value;
		strURL += '&rows=' + objForm.rows.value;
		document.location = strURL;
	}
	function clearFilter() {
		<?php
			kill_session_var("sess_ciscotools_description");
			kill_session_var("sess_ciscotools_current_page");
			kill_session_var("sess_ciscotools_rows");
	
			unset($_REQUEST["page"]);
			unset($_REQUEST["rows"]);
			unset($_REQUEST["description"]);
			
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
				<td nowrap style='white-space: nowrap;' width="1">
					&nbsp;Rows:&nbsp;
				</td>
				<td width="1">
					<select name="rows" onChange="applyFilterChange(document.form)">
						<option value="-1"<?php if (get_request_var("rows") == "-1") {?> selected<?php }?>>Default</option>
						<?php
						if (sizeof($item_rows) > 0) {
							foreach ($item_rows as $key => $value) {
								print "<option value='" . $key . "'"; if (get_request_var("rows") == $key) { print " selected"; } print ">" . $value . "</option>\n";
							}
						}
						?>
					</select>
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
		"version" => array("Number of Version", "ASC"),
		"date" => array("Last Backup Date", "ASC"),
		"diff" => array("Backup or Diff", ""),
		"nosort" => array("", ""));
	
	html_header_sort($display_text, '', '', false);

	if (!empty($devices)) {
			$class   = 'odd';
			// $page contain the start value, $row the number to display
		foreach($devices as $row) {
					($class == 'odd' )?$class='even':$class='odd';
					$type_string = ($row['count']>1)?'>DIFF':'>Backup';
					print"<tr class='$class'>";
					print"<td style='padding: 4px; margin: 4px;'>"
							. $row['id'] . '</td>
							<td>' . $row['hostname'] . '</td>
							<td>' . $row['description'] . '</td>
							<td>' . $row['count'] . '</td>
							<td>' . $row['date'] . '</td>
							<td>';
					print   '<a href=ciscotools_tab.php?action=diff&deviceid=' . $row['id'] . $type_string . "</a></td><td align='right'>";
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

	$obj_diff = new Diff();
	
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
	$backupid = min(array_column($result, 'version'))-1; // array_column return a one based, and array is using 0 based count, look for the first backup version
	$diffids = array_slice($result, $backupid+1 ); // remove the lowest backup, and kepp all other to find the diff, null if only 1 backup
	
	// TOP DEVICE DISPLAY
	$start_text = 'Cisco Tools all ' .$total_backup . ' of '
	.$result[$backupid]['description']
	. ' backup date: '.$result[$backupid]['date'] 
	. ' version: '.$result[$backupid]['version'];
	
	html_start_box($start_text, '100%', '', '3', 'center', '');
 
	if (!empty($result)) {
			print	'<tr>';
				print	"<td style='width: 50%;vertical-align:top;background:#E5E5E5;'>"."<a href='ciscotools_tab.php?action=output&versionid=".$result[$backupid]['id']."'>Config base".'<a/>';
					print	"<div style='border: 1px solid black;background:#E5E5E5;'>" . nl2br($result[$backupid]['diff']) . '</div>';
				print	'</td>';
		// display all change, one under the previous one
			if($total_backup > 1){
				$previous = $result[$backupid]; // set the previous backup is first backup
				print	"<td style='width: 50%;background:#E5E5E5;vertical-align:top;position: relative;'>";
				foreach( $result as $diffid ) { // number of different version
					if( $diffid['version'] == $result[$backupid]['version'] ) {
						continue;
					}
					$diff_array = $obj_diff->compare($previous['diff'], $diffid['diff']);
					print	"<a href='ciscotools_tab.php?action=output&versionid=".$diffid['id']."'>Diff Date ".$diffid['date'].'<a/>';
						print	"<div style='background:#E5E5E5;vertical-align:top;'>";
						foreach( $diff_array as $linenbr => $line ){ // for each line look what is the diff
      						switch ($line[1]){ // line[1] contain the status of the line, line[0] is the string
        						case $obj_diff::UNMODIFIED;
								break;
								
       							case $obj_diff::DELETED:
									print "<span style='color:#FF0033;'>".$linenbr . ' -- ' . $line[0].'<span/><br/>';
									break;
									
        						case $obj_diff::INSERTED:
									print "<span style='color:#FF0066;'>".$linenbr . ' ++ ' . $line[0].'<span/><br/>';
									break;
      						}
						}
					
						print '</div>';
					print	'<br>';
					$previous = $diffid;
				}
				print	'</td>';
			}
			print	'</tr>';
	}else{
		print "<tr><td style='padding: 4px; margin: 4px;' colspan=11><center>There are no Backup to display!</center></td></tr>";
	}
	
	html_end_box(false);
	$nav = html_nav_bar('ciscotools_tab.php', 1, 1, $total_backup, $total_backup, 30, 'of '. $result[$backupid]['description']  );
	
	print $nav;
	
	
	bottom_footer();

}

function ciscotools_output() {
    global $config;
	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("versionid"));
	/* ==================================================== */
	$versionid = get_request_var('versionid');
	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("page"));
	input_validate_input_number(get_request_var("rows"));
	/* ==================================================== */

	general_header();
	
	$sql_query = 'SELECT host.id as id, 
			host.description as description, host.hostname as hostname, pctb.version as version, pctb.datechange as date, pctb.diff as config
			FROM plugin_ciscotools_backup pctb
			INNER JOIN host ON host.id=pctb.host_id
			WHERE pctb.id ='. $versionid;
	
	$result = db_fetch_row($sql_query); // query result is one entry par backup

	$start_text = 'Cisco Tools output of the version '
	. $result['version']
	. ' for device: ' .$result['description'].', backup date:' .$result['date'];
	
	html_start_box($start_text, '100%', '', '3', 'center', '');
 		
	if (!empty($result)) {
			print	"<td >".'Config requested';
			print	"<div style='border: 1px solid black;background:#E5E5E5;'>" . nl2br($result['config']) . '</div>';
			print	'</td>';
	} else{
		print "<tr><td style='padding: 4px; margin: 4px;' colspan=11><center>There are no Backup to display!</center></td></tr>";
	}
	
	html_end_box();
		
	bottom_footer();
	
}
?>