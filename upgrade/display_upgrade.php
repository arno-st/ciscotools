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

function ciscotools_displayUpgrade()
{
    global $upgradeActions, $config, $item_rows, $statusText, $statusColor;
	
    $upgradeActions = array(
		1	=> __("Recheck"),
		2	=> __("Upgrade"),
		3	=> __("Put in test"),
    );
	
    /* ================= input validation ================= */
    input_validate_input_number(get_request_var("page"));
    input_validate_input_number(get_request_var("rows"));
    /* ==================================================== */

	// Clean Description
    if(isset_request_var('description')) 
    {
        set_request_var('description', sanitize_search_string(get_request_var("description")));
	}
	// Clean Status
    if(isset_request_var('status')) 
    {
        set_request_var('status', sanitize_search_string(get_request_var("status")));
	}
	// Clean upgradeAction
    if(isset_request_var('upgradeAction'))
    {
        set_request_var('upgradeAction', sanitize_search_string(get_request_var("upgradeAction")));
	}
	// Clean upgradeError
	if(isset_request_var('upgradeError'))
	{
		set_request_var("upgradeError", sanitize_search_string(get_request_var("upgradeError")));
    }
    // Clean upgradeExport
    if(isset_request_var("upgradeExport"))
    {
        set_request_var("upgradeExport", sanitize_search_string(get_request_var("upgradeExport")));
    }

	// Remember search fields in session vars
    load_current_session_value("page", "sess_ciscotools_current_page", "1");			// Default:	1
    load_current_session_value("rows", "sess_ciscotools_rows", "-1");					// Default:	-1
	load_current_session_value("description", "sess_ciscotools_description", '');		// Default:	''
    load_current_session_value("status", "sess_ciscotools_status", "-1");				// Default:	-1
	load_current_session_value("upgradeAction", "sess_ciscotools_upgradeAction", '');	// Default:	''
    load_current_session_value("upgradeError", "sess_ciscotools_upgradeError", "0");	// Default:	0
    load_current_session_value("upgradeExport", "sess_ciscotools_upgradeExport", "0");  // Default: 0

	/* ===================== SQL Query ===================== */
	// SQLWhere - Where instructions in SQL
	$sqlWhere       = "";
	$description    = get_request_var_request("description");
    $status 		= get_request_var_request("status");
	$upgradeAction  = get_request_var_request("upgradeAction");
    $upgradeError	= get_request_var_request("upgradeError");
    $upgradeExport  = get_request_var_request("upgradeExport");
	$sortColumn		= get_request_var("sort_column");
	switch($sortColumn)
	{	// Precise table and field
		case "upg_id":
			$sortColumn = "host.id";
			break;
		case "upg_desc":
			$sortColumn = "host.description";
			break;
		case "upg_hostname":
			$sortColumn = "host.hostname";
			break;
		case "upg_type":
			$sortColumn = "host.type";
			break;
		case "upg_status":
			$sortColumn = "plugin_ciscotools_upgrade.status";
			break;
		default:
			$sortColumn = "plugin_ciscotools_upgrade.status";
			break;
	}
	$sortDirection	= get_request_var("sort_direction");
	
	if($upgradeError === "1")
	{	// Sort all error status
		$sqlWhere .= " AND " . "plugin_ciscotools_upgrade.status IN ('8', '9', '10', '11', '12', '13', '14', '15', '16', '17', '18')";
	}
	if($description != "")
    {	// Sort all descriptions like URL parameter
        $sqlWhere .= " AND " . "host.description like '%$description%'";
    }
    if($status != "" && $status != -1)
    {	// Sort all the status equal URL parameter
        $sqlWhere .= " AND " . "plugin_ciscotools_upgrade.status = $status";
	}
	if($sortColumn != "")
	{	// Order by URL parameter depends on the sort column
		$sort = " ORDER BY " . $sortColumn;
	}
	else $sort = " ORDER BY plugin_ciscotools_upgrade.status";
	if($sortDirection != "")
	{	// Precise sort direction
		$sort .= " " . $sortDirection;
	}
	else $sort .= " ASC";
	
	// Count how many devices are in the table
    $sqlTotalRow = "SELECT count(distinct(host.id))
                    FROM host, plugin_ciscotools_upgrade
                    WHERE host.id=plugin_ciscotools_upgrade.host_id".
                    $sqlWhere;
    $totalRows = db_fetch_cell($sqlTotalRow);

	// If nb rows is -1 > set it to the default
    if(get_request_var("rows") == "-1") $perRow = read_config_option("num_rows_table");
	else $perRow = get_request_var("rows");
	
    $page = ($perRow*(get_request_var("page")-1));
    $sqlLimit = $page . "," . $perRow;

	// SQL Query
     $sqlQuery = "SELECT host.id as 'id',
                host.description as 'description', host.hostname as 'hostname', plugin_ciscotools_upgrade.status as 'status', host.type as 'type'
                FROM host, plugin_ciscotools_upgrade
                WHERE host.id=plugin_ciscotools_upgrade.host_id
                $sqlWhere
                $sort
                LIMIT " . $sqlLimit;
    $result = db_fetch_assoc($sqlQuery); // Query result
	/* ===================================================== */

	// Sorting devices in array
    $devices = array();
    foreach($result as $entry)
    {
        $id = $entry['id'];$id = $entry['id'];
        $devices[$id]['id'] = $id;
        $devices[$id]['description'] = $entry['description'];
        $devices[$id]['hostname'] = $entry['hostname'];
        $devices[$id]['status'] = $entry['status'];
        $devices[$id]['type'] = $entry['type'];
	}

    if($upgradeExport == "1")
    {
        $filename = upgradeExport($result);
        if($filename === false) header("Location: upgrade.php?action=upgrade");
        else
        {
            $url = $filename;
            $files = array_diff(scandir(__DIR__), array('.', '..'));
            $regexTime = "/cacti-exportUpgrade-(\d{10}).csv/";
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
    }
	/* ====================== Actions ====================== */
	if(!empty($upgradeAction))
    {
		include_once($config['base_path'] . '/plugins/ciscotools/upgrade/upgrade.php');	// Include functions of upgrade.php
		$param = explode('?', $_SERVER['REQUEST_URI'], 2);	// Explode URL to get parameters
		if(!preg_match_all("/&chk_([0-9]+)/", $param[1], $devicesID)) header("Location: ciscotools_tab.php?action=upgrade");	// Check if devices were selected
			
		foreach($devicesID[1] as $key => $value)
		{
			switch($upgradeAction)
			{
				// Recheck
				case 1:
					ciscotools_upgrade_table($value, 'update', UPGRADE_STATUS_NEED_RECHECK);
					break;
				// Upgrade
				case 2:
					ciscotools_upgrade_table($value, 'update', UPGRADE_STATUS_PENDING);
					break;
				// Put in test
				case 3:
					ciscotools_upgrade_table($value, 'update', UPGRADE_STATUS_IN_TEST);
					break;
			}
		}
		header("Location: ciscotools_tab.php?action=upgrade");	// Redirect instantly
	}
	/* ===================================================== */

    general_header();
	
	// Columns displaying text
    $displayText = array(
        "upg_id"		=> array(
			"display" 	=> __("Host ID"),
			"align" 	=> "left",
			"sort" 		=> "ASC",
			"tip" 		=> __('The ID of the device in the database')
		),
        "upg_desc"   	=> array(
			"display"	=> __("Description"),
			"align"		=> "left",
			"sort"		=>"ASC",
			"tip"		=> __('The hostname of the device')
		),
		"upg_hostname"	=> array(
			"display"	=> __("IP"),
			"align"		=> "left",
			"sort"		=> "ASC",
			"tip"		=> __('The IP address of the device')
		),
		"upg_type"	=> array(
			"display"	=> __("Type"),
			"align"		=> "left",
			"sort"		=> "ASC",
			"tip"		=> __('The type of the device')
		),
		"upg_status"    => array(
			"display"	=> __("Status"),
			"align"		=> "left",
			"sort"		=> "ASC",
			"tip"		=> __('The status of the device')
		)
	);
	
    $refresh['seconds'] = '300';
    $refresh['page']    = 'ciscotools_tab.php?action=upgrade&header=false';
    $refresh['logout']  = 'false';

    set_page_refresh($refresh);
?>

    <script type="text/javascript">
    <!--
	// Dynamic function (jQuery)
	$(function() 
	{
		$("#upgradeDescription, #upgradeStatus, #upgradeRows, #upgradeError").change(function()
		{	// When fields change > apply filter
			loadPage(applyFilterChange());
		});

		$("#upgradeRefresh").click(function()
		{	// When 'Apply' button clicked > apply filter
			loadPage(applyFilterChange());
		});

		$("#upgradeClear").click(function()
		{	// When 'Clear' button clicked > clear filter
			clearFilter();
		});

		$("#upgradeButton").click(function()
		{	// When 'Go' button clicked > submit form
			submitForm();
		});

        $("#upgradeExport").click(function()
        {
            strURL = applyFilterChange();
            upgradeExport(strURL);
        });
	});

    function applyFilterChange()
    {	// Filters
        strURL = "?action=upgrade";	// URL base
        strURL += "&description=" + $("#upgradeDescription").val();	// Description param
        strURL += "&status=" + $("#upgradeStatus").val();	// Status param
        strURL += "&rows=" + $("#upgradeRows").val();	// Rows param
		// UpgradeError param > sort all errors or no
		if($("#upgradeError").is(":checked")) strURL += "&upgradeError=1";
		else strURL += "&upgradeError=0";
        return strURL;  // return URL
    }

    function clearFilter()
    {	// Clear filter (URL parameters)
        <?php
		// Kill all sessions
		kill_session_var("sess_ciscotools_description");
        kill_session_var("sess_ciscotools_status");
        kill_session_var("sess_ciscotools_upgradeAction");
        kill_session_var("sess_ciscotools_current_page");
		kill_session_var("sess_ciscotools_rows");
        kill_session_var("sess_ciscotools_upgradeError");
        kill_session_var("sess_ciscotools_upgradeExport");

		// Unset all parameters
        unset($_REQUEST['page']);
        unset($_REQUEST['rows']);
        unset($_REQUEST['description']);
        unset($_REQUEST['status']);
		unset($_REQUEST['upgradeAction']);
        unset($_REQUEST['upgradeError']);
        unset($_REQUEST['upgradeExport']);
        ?>
		// Reload URL
        strURL = "ciscotools_tab.php?action=upgrade";
        loadPage(strURL);
    }

    function upgradeActions(value)
    {	// jQuery Actions function
        var button = document.getElementById("upgradeButton");

        if(value == "0" && sizeof(boxes) != 0)
        {	// If none selected > disable button
            button.setAttribute("disabled", "disabled");
            button.classList.add("ui-button-disabled");
            button.classList.add("ui-state-disabled");
        }
        else 
		{	// If selected > enable button
            button.removeAttribute("disabled");
            button.classList.remove("ui-button-disabled");
            button.classList.remove("ui-state-disabled");
        }
    }

    function upgradeExport(strURL)
    {
        strURL += "&upgradeExport=1";   // Add parameter
        document.location = strURL;     // Load URL
    }

    function submitForm()
    {	// Submit form after checking
        var form = document.getElementById("upgradeForm");
        var boxes = [];
        var items;
		// Create array with selected devices
        $("input:checkbox[name^=chk_]:checked").each(function(){
            boxes.push($(this).val());
            items += "&" + $(this).attr('name') + "=on";
        });
        items = items.replace("undefined", "");
        if(boxes.length == 0)
        {	// None selected > alert
            alert("You must select at least one object from the list.");
        }
        //else form.submit(); // Submit
        strURL = applyFilterChange();
        strURL += items + "&upgradeAction=" + $("#upgradeAction").val();   // Add parameter
        console.log(strURL);
        document.location = strURL; // Load URL
    }
    -->
    </script>

<?php
    // Filters bar
    html_start_box(__("Filters"), "100%", "", "3", "center", "");
?>

    <meta charset="utf-8">
        <td class="noprint even">
            <form style="padding:0;margin:0;" name="form" id="upgradeForm" action="<?php echo $config['url_path']; ?>plugins/ciscotools/ciscotools_tab.php">
				<table class="filterTable">
					<tr>
						<td>
							<?php echo __("Description"); ?>
                        </td>
                        <td>
                            <input type="text" placeholder="Enter a description" name="description" id="upgradeDescription" size="25" value="<?php echo get_request_var_request('description'); ?>">
							<i class="fa fa-search filter"></i>
						</td>
						<td>
							<input type='button' class='ui-button ui-corner-all ui-widget ui-state-active' id='upgradeRefresh' value='<?php print __esc('Apply');?>' title='<?php print __esc('Set/Refresh Filters');?>'>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='upgradeClear' value='<?php print __esc('Clear');?>' title='<?php print __esc('Clear Filters');?>'>
                            <input type='button' class='ui-button ui-corner-all ui-widget' id='upgradeExport' value='<?php print __esc('Export');?>' title='<?php print __esc('Export Table');?>'>
						</td>
					</tr>
                </table>
				<table class="filterTable">
                    <tr>
                        <td>
                            <?php echo __("Status"); ?>
                        </td>
                        <td>
                            <select name="status" id="upgradeStatus" nowrap style="white-space:nowrap;" width="1">
                                <option value="-1"<?php if(get_request_var("status") == -1) {?> selected<?php }?>>None</option>
                                <?php
                                    foreach($statusText as $key => $value)
                                    {
                                        echo "<option value='" . $key . "'"; if(get_request_var("status") == $key) { echo " selected"; } echo ">" . $key . " – " . $value . "</option>\n";
                                    }
                                ?>
                            </select>
                        </td>
                        <td>
                            <?php echo __("Rows"); ?>
                        </td>
                        <td>
                            <select name="rows" id="upgradeRows">
                                <option value="-1"<?php if(get_request_var("rows") == "-1") {?> selected<?php }?>>Default</option>
                                <?php
                                    if(sizeof($item_rows) > 0)
                                    {
                                        foreach($item_rows as $key => $value)
                                        {
                                            echo "<option value='" . $key . "'"; if(get_request_var("rows") == $key) { echo " selected"; } echo ">" . $value . "</option>\n";
                                        }
                                    }
                                ?>
                            </select>
                        </td>
						<td>
							<?php echo __("Errors"); ?>
						</td>
						<td>
							<label class="checkboxSwitch" title="Display all errors">
								<input value="1" title="Display all errors" type="checkbox" id="upgradeError" name="upgradeError" <?php echo ($upgradeError === "1") ? "checked" : ""; ?>>
								<span class="checkboxSlider checkboxRound"></span>
							</label>
						</td>
                    </tr>
				</table>
                <input type="hidden" name="action" value="upgrade">
            

<?php
    html_end_box();
    html_start_box("", "100%", "", "3", "center", "");

    $displayDeviceText = ($totalRows>1) ? "Devices" : "Device";	// One or more > plural form
    $nav = html_nav_bar("ciscotools_tab.php?action=upgrade", MAX_DISPLAY_PAGES, get_request_var("page"), $perRow, $totalRows, 12, __($displayDeviceText), "page", "main");
    echo $nav;

	// Put checkboxes and redirect on upgrade tab
    html_header_sort_checkbox($displayText, get_request_var('sort_column'), get_request_var('sort_direction'), false, "ciscotools_tab.php?action=upgrade");

    if(!empty($devices))
    {
		foreach($devices as $row)
		{	// Put records in table
            $color = "; color:" . $statusColor[$row['status']] . ";";
			form_alternate_row('line' . $row['id'], true);	// Alternate color
			form_selectable_cell(filter_value($row['id'], get_request_var('filter'), "../../host.php?action=edit&id=" . $row['id']), $row['id']); // ID
			form_selectable_cell(filter_value($row['description'], get_request_var('filter')), $row['id'], $color); // Description
			form_selectable_cell(filter_value($row['hostname'], get_request_var('filter')), $row['id'], $color); // Hostname
			form_selectable_cell(filter_value($row['type'], get_request_var('filter')), $row['id'], $color); // type
			form_selectable_cell(filter_value($row['status'] . " – " . $statusText[$row['status']], get_request_var('filter')), $row['id'], $color); // Status
			form_checkbox_cell($row['description'], $row['id']);
			form_end_row();
		}
    }
    else
    {
        echo "<tr><td style='padding:4px;margin:4px;' colspan=11><center>There is no current upgrading information to display</center></td></tr>";
    }
    
    html_end_box(false);
    echo $nav;
?>
                <div class="actionsDropdown">
                    <div>
                        <span class="actionsDropdownArrow">
                            <img src="/cacti/images/arrow.gif" alt>
                        </span>
                        <select onChange="upgradeActions(this.value);" id="upgradeAction" name="upgradeAction" style="display:none;">
                            <option value="" selected>Choose an action</option>
                            <?php
                            foreach($upgradeActions as $key => $action)
                            {
                                echo "<option value='" . $key . "'>" . $action . "</option>";
                            }
                            ?>
                        </select>
                        <span class="actionsDropdownButton">
                            <input type="button" id="upgradeButton" class="ui-button ui-corner-all ui-widget ui-state-active ui-state-disabled ui-button-disabled" value="Go" title="Execute Action" role="button" disabled>
                        </span>
                    </div>
                </div>
            </form>
        </td>
	</tr>

<?php
	bottom_footer();
}

/**
* +-------------+
* | FORMAT DATA |
* +-------------+
* @param $data string data being formatted
*/
function formatData(&$data)
{
    $data = preg_replace("/\t/", "\\t", $data);
    $data = preg_replace("/\r?\n/", "\\n", $data);
}

/**
* +-----------------+
* | EXPORT FUNCTION |
* +-----------------+
* @param array $devices: contains all informations about devices
* @return string $filename if successful, false otherwise
*/
function upgradeExport($devices)
{
    global $config;
    if(cacti_sizeof($devices) > 0)
    {   // Create array with IDs
        $ids = array();
        foreach($devices as $d) array_push($ids, $d['id']);
        $ids = implode(",", $ids);
        
        // SQL Query to catch all useful infos
        $sqlQuery = "SELECT host.id as 'id', host.description as 'description', host.hostname as 'ip', host.type as 'type', "
                   ."plugin_ciscotools_image.image as 'default image', "
                   ."plugin_ciscotools_upgrade.status as 'status number' "
                   ."FROM host "
                   ."LEFT JOIN plugin_ciscotools_image ON host.type = plugin_ciscotools_image.model "
                   ."LEFT JOIN plugin_ciscotools_upgrade ON host.id = plugin_ciscotools_upgrade.host_id "
                   ."WHERE host.id IN (" . $ids . ") "
                   ."GROUP BY host.id ASC";
        $result = db_fetch_assoc($sqlQuery);
        if($result === false) return false;

        $filename = "cacti-exportUpgrade-" . time() . ".csv";   // Set filename with current time
        $fp = fopen("plugins/ciscotools/" . $filename, "w");    // File location in current directory
        $csv = "";

        // Put data in $csv variable
        $flag = false;
        foreach($result as $row)
        {
            if(!$flag)
            {
                $csv .= implode(",", array_keys($row)) . "\r\n";
                $flag = true;
            }
            array_walk($row, __NAMESPACE__ . '\formatData');
            $csv .= implode(",", array_values($row)) . "\r\n";
        }

        // Put data in file
        fwrite($fp, $csv);
        fclose($fp);
        return $filename;
    }
    else return false;
}
?>