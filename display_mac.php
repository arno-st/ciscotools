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

function ciscotools_displaymac() {
    global $config, $item_rows;
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
			'default' => 'mac.id'
			),
		'sort_direction' => array(
			'filter' => FILTER_DEFAULT,
			'default' => 'ASC',
			),
		'switch' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			),
		'mac_address' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			),
		'ip_address' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			),
		'trunk' => array(
			'filter' => FILTER_VALIDATE_BOOLEAN,
			'pageset' => true,
			'default' => '0'
			),
		'description' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			)
	);

	validate_store_request_vars($filters, 'sess_ciscotools_mactrack');
	/* ================= input validation ================= */
	$sort_column = get_request_var('sort_column');
	$sort_direction = get_request_var('sort_direction');
/*
table plugin_ciscotools_mactrack
	id
 	host_id
	mac_address
	ip_address
	ipv6_address
	port_index
	vlan_id
	vlan_name
	description
	date
 	*/
	
	$sql_where  = '';
	$switch       = get_request_var_request("switch");
	$mac_address       = str_replace(array(':','-','.'), '', get_request_var_request("mac_address"));
	$ip_address       = get_request_var_request("ip_address");
	$description       = get_request_var_request("description");
	$trunk       = get_request_var_request("trunk");
	
	if ($switch != '') {
		$sql_where .= " AND " . "host.description LIKE '%$switch%'";
	}
	if ($mac_address != '') {
		$sql_where .= " AND " . "mac.mac_address LIKE '%$mac_address%'";
	}
	if ($ip_address != '') {
		$sql_where .= " AND " . "mac.ip_address LIKE '%$ip_address%'";
	}
	if ($description != '') {
		$sql_where .= " AND " . "mac.description LIKE '%$description%'";
	}
	if ($trunk == '1') {
		$sql_where .= " AND " . "mac.vlan_id = '0'";
	} else {
		unset_request_var('trunk');
		$trunk = '0';
		$sql_where .= " AND " . "mac.vlan_id != '0'";
	}

	general_header();
	// how many record we have
	$sql_total_row = "SELECT count(mac.id)
			FROM plugin_ciscotools_mactrack as mac 
			INNER JOIN host ON host.id=mac.host_id
			INNER JOIN host_snmp_cache as intf ON mac.host_id=intf.host_id
			WHERE mac.port_index=intf.snmp_index
			AND intf.field_name='ifDescr'
			".$sql_where;
	
	$total_rows = db_fetch_cell( $sql_total_row );
			
	/* if the number of rows is -1, set it to the default */
	if (get_request_var("rows") == "-1") {
		$per_row = read_config_option('num_rows_table'); //num_rows_device');
	}else{
		$per_row = get_request_var('rows');
	}
	$page = ($per_row*(get_request_var('page')-1));
	$sql_limit = $page . ',' . $per_row;
	
	$sql_query = "SELECT host.id as 'id', 
			host.description as 'switch', 
			mac.mac_address as 'mac', mac.ip_address as 'ip', mac.vlan_name as 'vlan_name', mac.vlan_id as 'vlan_id', 
			mac.port_index as 'intf_index', mac.description as 'description', intf.field_value as 'intf_name', mac.date as 'date'
			FROM plugin_ciscotools_mactrack as mac 
			INNER JOIN host ON host.id=mac.host_id
			INNER JOIN host_snmp_cache as intf ON mac.host_id=intf.host_id
			WHERE mac.port_index=intf.snmp_index
			AND intf.field_name='ifDescr'
			$sql_where
			ORDER BY ".$sort_column." ".$sort_direction." 
			LIMIT " . $sql_limit;

	$result = db_fetch_assoc($sql_query); // query result is one entry par backup
	ciscotools_log('db query: '. $sql_query . ' ('.count($result).')' );
	?>
	
	<script type="text/javascript">
	<!--
	
	function applyFilter() {
		strURL  = '?header=false&action=display_mac';
		strURL += '&switch=' + $('#switch').val();
		strURL += '&ip_address=' + $('#ip_address').val();
		strURL += '&mac_address=' + $('#mac_address').val();
		strURL += '&description=' + $('#description').val();
		strURL += '&trunk=' + $('#trunk').val();
		strURL += '&rows=' + $('#rows').val();
		
		loadPageNoHeader(strURL);
	}

	function clearFilter() {
		strURL  = 'ciscotools_tab.php?action=display_mac&header=false&clear=1';
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
		<form style="padding:0px;margin:0px;" name="form" method="get" action="<?php print $config['url_path'];?>plugins/ciscotools/ciscotools_tab.php?action=display_mac">
			<table width="100%" cellpadding="0" cellspacing="0">
				<tr class="noprint">
					<td nowrap style='white-space: nowrap;' width="1">
						&nbsp;Switch name :&nbsp;
					</td>
					<td width="1">
						<input type="text" name="switch" size="20" value="<?php print get_request_var("switch");?>">
					</td>
					<td nowrap style='white-space: nowrap;' width="1">
						&nbsp;MAC address :&nbsp;
					</td>
					<td width="1">
						<input type="text" name="mac_address" size="20" value="<?php print get_request_var("mac_address");?>">
					</td>
					<td nowrap style='white-space: nowrap;' width="1">
						&nbsp;IP address :&nbsp;
					</td>
					<td width="1">
						<input type="text" name="ip_address" size="20" value="<?php print get_request_var("ip_address");?>">
					</td>
					<td nowrap style='white-space: nowrap;' width="1">
						&nbsp;Node Name :&nbsp;
					</td>
					<td width="1">
						<input type="text" name="description" size="20" value="<?php print get_request_var("description");?>">
					</td>
					<td nowrap style='white-space: nowrap;' width="1">
				<td nowrap style='white-space: nowrap;' width="1">
					&nbsp;Display Trunk:&nbsp;
				</td>
				<td width="1">
					<input type="checkbox" name="trunk" value="1" <?php ($trunk=='1')?print " checked":print "" ?>>
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
			<input type='hidden' name='action' value='display_mac'>
		</form>
		</td>
	</tr>
		
	<?php
	html_end_box();
	html_start_box('', '100%', '', '3', 'center', '');
	
	$nav = html_nav_bar('ciscotools_tab.php?action=display_mac', MAX_DISPLAY_PAGES, get_request_var('page'), $per_row, $total_rows, 12, __('Devices'), 'page', 'main');
	
	print $nav;
	//id	switch	mac	ip	vlan_name	vlan_id	intf_index	descripton	intf_name 	
		$display_text = array(
		"description" => array("Node name", "ASC"),
		"mac" => array("Mac address", "ASC"),
		"ip" => array("ip address", "ASC"),
		"date" => array("Date Last Seen", "ASC"),
		"vlan_name" => array("Vlan Name", "ASC"),
		"vlan_id" => array("Vlan ID", "ASC"),
		"switch" => array("Switch name", "ASC"),
		"intf_name" => array("Interface Name", "ASC"),
		"mac_vendor" => array("MAC Vendor", "ASC"),
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
	
	html_header_sort($display_text, $sort_column, $sort_direction, false, 'ciscotools_tab.php?action=display_mac');

		if (!empty($result)) {
		$class   = 'odd';
		// $page contain the start value, $row the number to display
		foreach($result as $row) {
					($class == 'odd' )?$class='even':$class='odd';
					print"<tr class='$class'>";
					print"<td style='padding: 4px; margin: 4px;'>"
							. $row['description'] . '</td>
							<td>' . $row['mac'] . '</td>
							<td>' . $row['ip'] . '</td>
							<td>' . date("d/m/Y H:i:s", strtotime($row['date']) ) . '</td>
							<td>' . $row['vlan_name'] . '</td>
							<td>' . $row['vlan_id'] . '</td>
							<td>' . $row['switch'] . '</td>
							<td>' . $row['intf_name'] . '</td>
							<td>' . substr($row['mac'], 0, 2).':'.substr($row['mac'],2,2).':'.substr($row['mac'],4, 2) . '</td>';
					print "</tr>";
		}
	}else{
		print "<tr><td style='padding: 4px; margin: 4px;' colspan=11><center>There are no Mac to display!</center></td></tr>";
	}
	
	html_end_box(false);
	
	print $nav;
	
	bottom_footer();

}

?>