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
$snmp_bridge = "1.3.6.1.2.1.17.4.4";

function ciscotools_displaymac() {
    global $config, $item_rows;
	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("page"));
	input_validate_input_number(get_request_var("rows"));
	/* ==================================================== */
	// clean up descirption
	if (isset_request_var('description') ) {
		set_request_var('description', sanitize_search_string(get_request_var("description")) );
	}
	/* remember these search fields in session vars so we don't have to keep passing them around */
	load_current_session_value("page", "sess_ciscotools_current_page", "1");
	load_current_session_value("rows", "sess_ciscotools_rows", "-1");
	load_current_session_value("description", "sess_ciscotools_description", "");


// build the page
	?>
	
	<script type="text/javascript">
	<!--
	
	function applyFilterChange() {
		strURL  = '?header=false&action=display_mac'
        strUrl += '&description=' + $('#description').val();
        strUrl += '&macadr=' + $('#macadr').val();
		strURL += '&rows=' + $('#rows').val();
		document.location = strURL;
	}
	function clearFilter() {
		<?php
			kill_session_var("sess_ciscotools_description");
			kill_session_var("sess_ciscotools_macadr");
			kill_session_var("sess_ciscotools_current_page");
			kill_session_var("sess_ciscotools_rows");
	
			unset($_REQUEST["page"]);
			unset($_REQUEST["rows"]);
			unset($_REQUEST["description"]);
			unset($_REQUEST["macadr"]);
			
		?>
		strURL  = 'ciscotools_tab.php?action=display_mac&header=false';
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
			<form id='display_mac' action="<?php print $config['url_path'];?>plugins/ciscotools/ciscotools_tab.php">
			<table width="100%" cellpadding="0" cellspacing="0">
				<tr class="noprint">
					<td nowrap style='white-space: nowrap;' width="1">
						&nbsp;Description :&nbsp;
					</td>
					<td width="1">
						<input type="text" name="description" size="25" value="<?php print get_request_var_request("description");?>">
					</td>
					<td nowrap style='white-space: nowrap;' width="1">
						&nbsp;Mac address :&nbsp;
					</td>
					<td width="1">
						<input type="text" name="macadr" size="17" value="<?php print get_request_var_request("macadr");?>">
					</td>
				<td nowrap style='white-space: nowrap;' width="1">
					&nbsp;Rows:&nbsp;
				</td>
				<td width="1">
					<select name="rows" onChange="applyFilterChange()">
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
					<td>
						<span>
							<input type='submit' class='ui-button ui-corner-all ui-widget' id='refresh' value='<?php print __esc_x('Button: use filter settings', 'Go');?>' title='<?php print __esc('Set/Refresh Filters');?>'>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='clear' value='<?php print __esc_x('Button: reset filter settings', 'Clear');?>' title='<?php print __esc('Clear Filters');?>'>
						</span>
					</td>
				</tr>
			</table>
			<input type='hidden' name='action' value='display_mac'>
		</form>
		</td>
	</tr>
	
	<?php
	html_end_box();


}

function get_mac_table($hostrecord_array) {
	ciscotools_log('record mac for host:'.$hostrecord_array['description']);
}
?>