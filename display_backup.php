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
    /* ================= input validation and session storage ================= */
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
		'description' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			)
	);

	validate_store_request_vars($filters, 'sess_ciscotools_backup');
	/* ================= input validation ================= */
	$sort_column = get_request_var('sort_column');
	$sort_direction = get_request_var('sort_direction');

	$sql_where  = '';
	$description = get_request_var_request("description");
	
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
			ORDER BY ".$sort_column." ".$sort_direction." 
			LIMIT " . $sql_limit;
	
	$result = db_fetch_assoc($sql_query); // query result is one entry par backup

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
	}
// $result count the number of backups, devices give the number of backups per hosts, but resulting of the sql_limit (50 backup, but on 17 hosts)
// make a do while until count($devices) equal $per_row
	?>
	
	<script type="text/javascript">
	<!--
	
	function applyFilter() {
		strURL  = '?header=false&action=backup';
		strURL += '&description=' + $('#description').val();
		strURL += '&rows=' + $('#rows').val();
		loadPageNoHeader(strURL);
	}

	function clearFilter() {
		strURL  = 'ciscotools_tab.php?action=backup&header=false&clear=1';
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
		<form style="padding:0px;margin:0px;" name="form" method="get" action="<?php print $config['url_path'];?>plugins/ciscotools/ciscotools_tab.php?action=backup">
			<table width="100%" cellpadding="0" cellspacing="0">
				<tr class="noprint">
					<td nowrap style='white-space: nowrap;' width="1">
						&nbsp;Description :&nbsp;
					</td>
					<td width="1">
						<input type="text" name="description" size="25" value="<?php print get_request_var("description");?>">
					</td>
				<td nowrap style='white-space: nowrap;' width="1">
					&nbsp;Rows:&nbsp;
				</td>
				<td width="1">
						<select id='rows' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>><?php print __('Default', 'thold');?></option>
							<?php
							if (cacti_sizeof($item_rows)) {
								foreach ($item_rows as $key => $value) {
									print "<option value='" . $key . "'"; if (get_request_var('rows') == $key) { print ' selected'; } print '>' . $value . '</option>';
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
			<input type='hidden' name='action' value='backup'>
		</form>
		</td>
	</tr>
	
	<?php
	html_end_box();
	html_start_box('', '100%', '', '3', 'center', '');
	
	$nav = html_nav_bar('ciscotools_tab.php?action=backup', MAX_DISPLAY_PAGES, get_request_var('page'), $per_row, $total_rows, 12, __('Devices'), 'page', 'main');
	
	print $nav;
	
	$display_text = array(
		"id" => array("Host ID", "ASC"),
		"hostname" => array("Hostname", "ASC"),
		"description" => array("Description", "ASC"),
		"version" => array("Number of Version", "ASC"),
		"date" => array("Last Backup Date", "ASC"),
		"diff" => array("Backup or Diff", ""),
		"nosort" => array("", ""));
	
/* html_header_sort - draws a header row suitable for display inside of a box element.  When
        a user selects a column header, the collback function "filename" will be called to handle
        the sort the column and display the altered results.
   @arg $header_items - an array containing a list of column items to display.  The
        format is similar to the html_header, with the exception that it has three
        dimensions associated with each element (db_column => display_text, default_sort_order)
        alternatively (db_column => array('display' = 'blah', 'align' = 'blah', 'sort' = 'blah'))
   @arg $sort_column - the value of current sort column.
   @arg $sort_direction - the value the current sort direction.  The actual sort direction
        will be opposite this direction if the user selects the same named column.
   @arg $last_item_colspan - the TD 'colspan' to apply to the last cell in the row
   @arg $url - a base url to redirect sort actions to
   @arg $return_to - the id of the object to inject output into as a result of the sort action 

   function html_header_sort($header_items, $sort_column, $sort_direction, $last_item_colspan = 1, $url = '', $return_to = '') {
*/
	
	html_header_sort($display_text, $sort_column, $sort_direction, false, 'ciscotools_tab.php?action=backup');

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

function ciscotools_output( $versionid ) {
    global $config;

	$sql_query = 'SELECT host.id as id, 
			host.description as description, host.hostname as hostname, pctb.version as version, pctb.datechange as date, pctb.diff as config
			FROM plugin_ciscotools_backup pctb
			INNER JOIN host ON host.id=pctb.host_id
			WHERE pctb.id ='. $versionid;
	
	$result = db_fetch_row($sql_query); // query result is one entry par backup
	// export CSV device list
	header("Content-Type: text/plain");
	header("Content-Disposition: attachment; filename=".$result['description'].".txt");

	print( $result['config'] );
}
?>