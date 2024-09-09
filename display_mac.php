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
    global $config, $item_rows, $sql_where;
    $filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
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
			'default' => ''
			),
		'mac_address' => array(
			'filter' => FILTER_DEFAULT,
			'default' => ''
			),
		'ip_address' => array(
			'filter' => FILTER_DEFAULT,
			'default' => ''
			),
		'vlan' => array(
			'filter' => FILTER_DEFAULT,
			'default' => ''
			),
		'no_ip' => array(
			'filter' => FILTER_DEFAULT,
			'default' => 'false'
			),
		'description' => array(
			'filter' => FILTER_DEFAULT,
			'default' => ''
			)
	);

	validate_store_request_vars($filters, 'sess_ciscotools_mactrack');
	/* ================= input validation ================= */
	$sort_column = get_request_var('sort_column');
	$sort_direction = get_request_var('sort_direction');
    // Clean macExport
    if(isset_request_var("macExport"))
    {
        set_request_var("macExport", sanitize_search_string(get_request_var("macExport")));
    }
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
	dhcp 1=static, 2=Dynamic, 3=regular
 	*/
	
	$sql_where   = '';
	$switch		 = get_request_var_request("switch");
	$mac_address = str_replace(array(':','-','.'), '', get_request_var_request("mac_address"));
	$ip_address  = get_request_var_request("ip_address");
	$vlan  = get_request_var_request("vlan");
	$no_ip  = get_request_var_request("no_ip");
	$description = get_request_var_request("description");
	$macExport 	 = get_request_var("macExport");

	if ($switch != '') {
		$sql_where .= " AND " . "host.description LIKE '%$switch%'";
	}
	if ($mac_address != '') {
		$sql_where .= " AND " . "mac.mac_address LIKE '%$mac_address%'";
	}
	if ( $no_ip == 'true') {
		$sql_where .= " AND " . "mac.ip_address IN(NULL,'',0)";
	} else {
		if ($ip_address != '' ) {
			$sql_where .= " AND " . "mac.ip_address LIKE '%$ip_address%'";
		}
	}
	if ($vlan != '') {
		$sql_where .= " AND " . "(mac.vlan_name LIKE '%$vlan%' OR mac.vlan_id LIKE '%$vlan%')";
	}
	if ($description != '') {
		$sql_where .= " AND " . "mac.description LIKE '%$description%'";
	}
	if( $sort_column == 'mac_vendor'){
		$sort_column = 'oui.companyname';
	}
	
	general_header();
	// how many record we have
	$sql_total_row = "SELECT count(mac.id)
			FROM plugin_ciscotools_mactrack as mac 
			LEFT JOIN host ON host.id=mac.host_id
			LEFT JOIN host_snmp_cache as intf ON mac.host_id=intf.host_id
            LEFT JOIN plugin_ciscotools_oui as oui ON SUBSTRING(mac.mac_address, 1, 6)=oui.oui
			WHERE mac.port_index=intf.snmp_index
			AND intf.field_name='ifDescr'
			".$sql_where;
mactrack_log('ciscotools_displaymac Display total row');

	$total_rows = db_fetch_cell( $sql_total_row );
mactrack_log('ciscotools_displaymac Display total row end: '.$sql_total_row);

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
			mac.port_index as 'intf_index', mac.description as 'description', intf.field_value as 'intf_name', 
			mac.date as 'date', oui.companyname as 'oui',
			mac.dhcp as 'dhcp'
			FROM plugin_ciscotools_mactrack as mac 
			INNER JOIN host ON host.id=mac.host_id
			INNER JOIN host_snmp_cache as intf ON mac.host_id=intf.host_id
            LEFT JOIN plugin_ciscotools_oui as oui ON SUBSTRING(mac.mac_address, 1, 6)=oui.oui
			WHERE mac.port_index=intf.snmp_index
			AND intf.field_name='ifDescr'
			$sql_where
			ORDER BY ".$sort_column." ".$sort_direction." 
			LIMIT " . $sql_limit;
mactrack_log('ciscotools_displaymac Display query: '.$sql_query);
	$result = db_fetch_assoc($sql_query); // query result is one entry par backup
mactrack_log('ciscotools_displaymac Display query end ');

    if($macExport == "1") {
			// SQL Query
		$sqlQuery = "SELECT host.id as 'id', 
			host.description as 'switch', 
			mac.mac_address as 'mac', mac.ip_address as 'ip', mac.vlan_name as 'vlan_name', mac.vlan_id as 'vlan_id', 
			mac.port_index as 'intf_index', mac.description as 'description', intf.field_value as 'intf_name', 
			mac.date as 'date', oui.companyname as 'oui',
			mac.dhcp as 'dhcp'
			FROM plugin_ciscotools_mactrack as mac 
			INNER JOIN host ON host.id=mac.host_id
			INNER JOIN host_snmp_cache as intf ON mac.host_id=intf.host_id
            LEFT JOIN plugin_ciscotools_oui as oui ON SUBSTRING(mac.mac_address, 1, 6)=oui.oui
			WHERE mac.port_index=intf.snmp_index
			AND intf.field_name='ifDescr'
			$sql_where";
		$Macresult = db_fetch_assoc($sqlQuery); // Query Macresult
//mactrack_log('Export query1: '.$sql_query);

        $filename = macExport($Macresult);
        if($filename === false) 
			header("Location: ciscotools_tab.php?action=display_mac");
        else
        {
            $url = $filename;
            $files = array_diff(scandir(__DIR__), array('.', '..'));
            $regexTime = "/cacti-exportMac-(\d{10}).csv/";
            if(preg_match($regexTime, $filename, $defTime)) $defTime = $defTime[1];
            foreach($files as $fname)
            {
                if(preg_match($regexTime, $fname, $time))
                {
                    if($time[1] < $defTime)
                    {
                        unlink($config['base_path'] . "/plugins/ciscotools/" . $time[0]);
                    }
                }
                else continue;
            }
        }
        header("Location: " . $url);
    } else if($macExport == "2") {
			// SQL Query to select what to display
		$sqlQuery = "SELECT host.id as 'id', mac.mac_address as 'mac', mac.vlan_id as 'vlan_id', 
			mac.description as 'visitor_name'
			FROM plugin_ciscotools_mactrack as mac 
			INNER JOIN host ON host.id=mac.host_id
			INNER JOIN host_snmp_cache as intf ON mac.host_id=intf.host_id
            LEFT JOIN plugin_ciscotools_oui as oui ON SUBSTRING(mac.mac_address, 1, 6)=oui.oui
			WHERE mac.port_index=intf.snmp_index
			AND intf.field_name='ifDescr'
			AND mac.vlan_id IN('110','120','130','131','132','133','134','135','136','137','138','139','160','170')
			$sql_where";
		$Macresult = db_fetch_assoc($sqlQuery); // Query Macresult
//mactrack_log('Export query2: '.$sqlQuery);

        $filename = macExport4NAC($Macresult);
        if($filename === false) 
			header("Location: ciscotools_tab.php?action=display_mac");
        else {
            $url = $filename;
            $files = array_diff(scandir(__DIR__), array('.', '..'));
            $regexTime = "/cacti-exportMac4NAC-(\d{10}).csv/";
            if(preg_match($regexTime, $filename, $defTime)) $defTime = $defTime[1];
            foreach($files as $fname){
                if(preg_match($regexTime, $fname, $time)){
                    if($time[1] < $defTime){
                        unlink($config['base_path'] . "/plugins/ciscotools/" . $time[0]);
                    }
                }
                else continue;
            }
        }
        header("Location: " . $url);
    }
	?>
	
	<script type="text/javascript">
	<!--
	// Dynamic function (jQuery)
	$(function() 
	{
		$("#macRefresh").click(function()
		{	// When 'Apply' button clicked > apply filter
			loadPageNoHeader(applyFilter());
		});

		$("#macClear").click(function()
		{	// When 'Clear' button clicked > clear filter
			clearFilter();
		});

        $("#macExport1").click(function()
        {
            strURL = applyFilter();
            exportMac(strURL);
        });
        $("#macExport4NAC").click(function()
        {
            strURL = applyFilter();
            exportMac4NAC(strURL);
        });
	});

	
	function applyFilter() {
		strURL  = '?action=display_mac';
		strURL += '&switch=' + $('#switch').val();
		strURL += '&ip_address=' + $('#ip_address').val();
		strURL += '&vlan=' + $('#vlan').val();
		strURL += '&no_ip=' + $('#no_ip').is(":checked");
		strURL += '&mac_address=' + $('#mac_address').val();
		strURL += '&description=' + $('#description').val();
		strURL += '&rows=' + $('#rows').val();
		strURL += '&header=false&5';
		return strURL;  // return URL
	}

	function clearFilter() {
		strURL  = 'ciscotools_tab.php?action=display_mac&header=false&clear=1';
		loadPageNoHeader(strURL);
	}

    function exportMac(strURL) {
		strURL += "&macExport=1";   // Add parameter
		document.location = strURL;     // Load URL
    }
    function exportMac4NAC(strURL) {
		strURL += "&macExport=2";   // Add parameter
		document.location = strURL;     // Load URL
    }
	
	-->
	</script>
	
	<?php
mactrack_log('ciscotools_displaymac Display startbox');

	// TOP DEVICE SELECTION
	html_start_box('<strong>Filters</strong>', '100%', '', '3', 'center', '');
	
	?>
	<meta charset="utf-8"/>
		<td class="noprint">

		   <form style="padding:0px;margin:0px;" name="form" id="macForm" action="<?php print $config['url_path'];?>plugins/ciscotools/ciscotools_tab.php">
			<table width="100%" cellpadding="0" cellspacing="0">
				<tr class="noprint">
					<td nowrap style='white-space: nowrap;' width="1">
						&nbsp;Switch name :&nbsp;
					</td>
					<td width="1">
						<input type="text" name="switch" id="switch" size="15" value="<?php print get_request_var("switch");?>">
					</td>
					<td nowrap style='white-space: nowrap;' width="1">
						&nbsp;VLAN name or ID :&nbsp;
					</td>
					<td width="1">
						<input type="text" name="vlan" id="vlan" size="15" value="<?php print get_request_var("vlan");?>">
					</td>
					<td nowrap style='white-space: nowrap;' width="1">
						&nbsp;MAC address :&nbsp;
					</td>
					<td width="1">
						<input type="text" name="mac_address" id="mac_address" size="17" value="<?php print get_request_var("mac_address");?>">
					</td>
					<td nowrap style='white-space: nowrap;' width="1">
						&nbsp;IP address :&nbsp;
					</td>
					<td width="1">
						<input type="text" name="ip_address" id="ip_address" size="15" value="<?php print get_request_var("ip_address");?>">
					</td>
					<td nowrap style='white-space: nowrap;' width="1">
						&nbsp;Node Name :&nbsp;
					</td>
					<td width="1">
						<input type="text" name="description" id="description" size="20" value="<?php print get_request_var("description");?>">
					</td>
					<td nowrap style='white-space: nowrap;' width="1">
						&nbsp;No IP:&nbsp;
					</td>
					<td width="1">
						<input type="checkbox" name="no_ip" id="no_ip" value="<?php $no_ip;?>" <?php ($no_ip=='true')?print " checked":print "" ?> >
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
							<input type='button' class='ui-button ui-corner-all ui-widget ui-state-active' id='macRefresh' value='<?php print __esc('Apply');?>' title='<?php print __esc('Set/Refresh Filters');?>'>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='macClear' value='<?php print __esc('Clear');?>' title='<?php print __esc('Clear Filters');?>'>
                            <input type='button' class='ui-button ui-corner-all ui-widget' id='macExport1' value='<?php print __esc('Export');?>' title='<?php print __esc('Export Table');?>'>
                            <input type='button' class='ui-button ui-corner-all ui-widget' id='macExport4NAC' value='<?php print __esc('Export 4 NAC');?>' title='<?php print __esc('Export Table in NAC format');?>'>

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
		"dhcp" => array("DHCP flag", "ASC" ),
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
							<td>' . $row['dhcp'] . '</td>
							<td>' . date("d/m/Y H:i:s", strtotime($row['date']) ) . '</td>
							<td>' . $row['vlan_name'] . '</td>
							<td>' . $row['vlan_id'] . '</td>
							<td>' . $row['switch'] . '</td>
							<td>' . $row['intf_name'] . '</td>
							<td>' . $row['oui'] . '</td>';

					print "</tr>";
		}
	}else{
		print "<tr><td style='padding: 4px; margin: 4px;' colspan=11><center>There are no Mac to display!</center></td></tr>";
	}
	
	html_end_box(false);
	
	print $nav;
	
	bottom_footer();

}

/**
* +-----------------+
* | EXPORT FUNCTION |
* +-----------------+
* @param array $devices: contains all informations about devices
* @return string $filename if successful, false otherwise
*/
function macExport($devices) {
    global $config;
    if(cacti_sizeof($devices) > 0)
    {   // Create array with IDs
mactrack_log('Export result1: '.print_r($devices, true));
        $ids = array();
        foreach($devices as $d) array_push($ids, $d['id']);
        $ids = implode(",", array_unique($ids, SORT_REGULAR ) );
        
        // SQL Query to catch all useful infos
        $sqlQuery = "SELECT host.id as 'id', 
            host.description as 'switch', 
            mac.mac_address as 'mac', mac.ip_address as 'ip', mac.vlan_name as 'vlan_name', mac.vlan_id as 'vlan_id', 
            mac.port_index as 'intf_index', mac.description as 'description', intf.field_value as 'intf_name', 
            mac.date as 'date', oui.companyname as 'oui'
			FROM plugin_ciscotools_mactrack as mac 
			INNER JOIN host ON host.id=mac.host_id
			INNER JOIN host_snmp_cache as intf ON mac.host_id=intf.host_id
            LEFT JOIN plugin_ciscotools_oui as oui ON SUBSTRING(mac.mac_address, 1, 6)=oui.oui
			WHERE mac.port_index=intf.snmp_index
			AND intf.field_name='ifDescr' 
			AND host.id IN (" . $ids . ")";

        $result = db_fetch_assoc($sqlQuery);
        if($result === false) return false;

        $filename = "cacti-exportMac-" . time() . ".csv";   // Set filename with current time
        $fp = fopen("plugins/ciscotools/" . $filename, "w");    // File location in current directory
        $csv = "";

        // Put data in $csv variable
        $flag = false;
        foreach($result as $line)
        {
            if(!$flag)
            {
                $csv .= implode(",", array_keys($line)) . "\r\n";
                $flag = true;
            }
            array_walk($line, __NAMESPACE__ . '\formatData');
            $csv .= implode(",", array_values($line)) . "\r\n";
        }

        // Put data in file
        fwrite($fp, $csv);
        fclose($fp);
        return $filename;
    }
    else return false;
}

function macExport4NAC($devices) {
    global $config, $sql_where;
    if(cacti_sizeof($devices) > 0)
    {   // Create array with IDs
//mactrack_log('Export NAC result: '.print_r($devices, true));
        $ids = array();
        foreach($devices as $d) array_push($ids, $d['id']);
//mactrack_log('Export NAC ids: '.print_r($ids, true));
        $ids = implode(",", array_unique($ids, SORT_REGULAR ) );
        
        // SQL Query to catch all useful infos
   
        $sqlQuery = "SELECT mac.mac_address as 'MAC Address', mac.description as 'visitor_name',  
			CASE mac.vlan_id
				WHEN '120' THEN 'R-D-VoIP'
				WHEN '130' THEN 'R-D-Gaz'
				WHEN '131' THEN 'R-D-CAD'
				WHEN '132' THEN 'R-D-SEL'
				WHEN '133' THEN 'R-D-NRJ'
				WHEN '134' THEN 'R-D-DOMO'
				WHEN '135' THEN 'R-D-MOBI'
				WHEN '136' THEN 'R-D-EAUX'
				WHEN '137' THEN 'R-D-ASSAI'
				WHEN '138' THEN 'R-D-POLI'
				WHEN '139' THEN 'R-D-ONDU'
				WHEN '160' THEN 'R-D-Video'
				WHEN '170' THEN 'R-D-KIOSK'
			END AS role_name
		
			FROM plugin_ciscotools_mactrack as mac 
			INNER JOIN host ON host.id=mac.host_id
			INNER JOIN host_snmp_cache as intf ON mac.host_id=intf.host_id
            LEFT JOIN plugin_ciscotools_oui as oui ON SUBSTRING(mac.mac_address, 1, 6)=oui.oui
			WHERE mac.port_index=intf.snmp_index
			AND intf.field_name='ifDescr' 
			AND mac.vlan_id IN('120','130','131','132','133','134','135','136','137','138','139','160','170')
			AND host.id IN (" . $ids . ") 
			$sql_where";

			
        $result = db_fetch_assoc($sqlQuery);
//mactrack_log('Export query2NAC: '.$sqlQuery);

        if($result === false) return false;
//mactrack_log('Export NAC result2: '.print_r($result, true));

        $filename = "cacti-exportMac4NAC-" . time() . ".csv";   // Set filename with current time
        $fp = fopen("plugins/ciscotools/" . $filename, "w");    // File location in current directory
        $csv = "";

        // Put data in $csv variable
        $flag = false;
        foreach($result as $line)
        {
            if(!$flag)
            {
                $csv .= implode(",", array_keys($line)) . "\r\n";
                $flag = true;
            }
            array_walk($line, __NAMESPACE__ . '\formatData');
            $csv .= implode(",", array_values($line)) . "\r\n";
        }

        // Put data in file
        fwrite($fp, $csv);
        fclose($fp);
        return $filename;
    }
    else return false;
}
?>