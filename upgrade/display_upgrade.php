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

function ciscotools_displayUpgrade() {
    global $upgradeActions, $config, $item_rows;
	
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
			'default' => 'description'
			),
		'sort_direction' => array(
			'filter' => FILTER_DEFAULT,
			'default' => 'ASC',
			),
		'status' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => '-1'
			),
		'model' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			),
		'upgradeError' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			),
		'description' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			)
	);

	validate_store_request_vars($filters, 'sess_ciscotools_upgrade');

    $upgradeActions = array(
		1	=> __("Recheck"),
		2	=> __("Upgrade"),
		3	=> __("Put in test"),
		4	=> __("Reboot/Commit"),
		5	=> __("Delete"),
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
	// Clean model
    if(isset_request_var('model')) 
    {
        set_request_var('model', sanitize_search_string(get_request_var("model")));
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
	// clean up sort_column 
	if (isset_request_var('sort_column')) {
		set_request_var('sort_column', sanitize_search_string(get_request_var("sort_column")) );
	}
	
	// clean up sort direction 
	if (isset_request_var('sort_direction')) {
		set_request_var('sort_direction', sanitize_search_string(get_request_var("sort_direction")) );
	}

	/* ===================== SQL Query ===================== */
	// SQLWhere - Where instructions in SQL
	$sqlWhere       = "";
	$description    = get_request_var("description");
	$model    		= get_request_var("model");
    $status 		= get_request_var("status");
	$upgradeAction  = get_request_var("upgradeAction");
    $upgradeError	= get_request_var("upgradeError");
    $upgradeExport  = get_request_var("upgradeExport");
	$sortColumn		= get_request_var("sort_column");
	switch($sortColumn)
	{	// Precise table and field
		case "upg_id":
			$sortColumn = "host.id";
			break;
		case "description":
			$sortColumn = "host.description";
			break;
		default:
		case "hostname":
			$sortColumn = "host.hostname";
			break;
		case "model":
			$sortColumn = "plugin_extenddb_host_model.model";
			break;
		case "upg_image":
			$sortColumn = "plugin_ciscotools_upgrade.image";
			break;
		case "status":
			$sortColumn = "plugin_ciscotools_upgrade.status";
			break;
	}
	$sortDirection	= get_request_var("sort_direction");
	
	if($upgradeError === "1")
	{	// Sort all error status
		$sqlWhere .= " AND " . "plugin_ciscotools_upgrade.status IN ('"
			."','".UPGRADE_STATUS_TABLE_ERROR
			."','".UPGRADE_STATUS_UNSUPORTED
			."','".UPGRADE_STATUS_UPGRADE_DISABLED
			."','".UPGRADE_STATUS_CHECKING_ERROR
			."','".UPGRADE_STATUS_TFTP_DOWN
			."','".UPGRADE_STATUS_UPLOAD_STUCK
			."','".UPGRADE_STATUS_UPLOAD_ERROR
			."','".UPGRADE_STATUS_INFO_ERROR
			."','".UPGRADE_STATUS_SNMP_ERROR
			."','".UPGRADE_STATUS_IMAGE_INFO_ERROR
			."','".UPGRADE_STATUS_SSH_ERROR
			."','".UPGRADE_STATUS_ACTIVATING_ERROR
			."')";
	}
	if($description != '')
    {	// Sort all descriptions like URL parameter
        $sqlWhere .= " AND " . "host.description like '%$description%'";
    }
	if($model != '')
    {	// Sort all model like URL parameter
        $sqlWhere .= " AND " . "plugin_extenddb_host_model.model like '%$model%'";
    }
    if($status != '' && $status != -1)
    {	// Sort all the status equal URL parameter
        $sqlWhere .= " AND " . "plugin_ciscotools_upgrade.status = $status";
	}
	if($sortColumn != '')
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
                    FROM host
					INNER JOIN plugin_ciscotools_upgrade ON host.id=plugin_ciscotools_upgrade.host_id 
					INNER JOIN plugin_extenddb_host_model ON plugin_extenddb_host_model.host_id=host.id
					INNER JOIN plugin_extenddb_model ON plugin_extenddb_host_model.model=plugin_extenddb_model.model 
					INNER JOIN plugin_ciscotools_image ON plugin_extenddb_model.id=plugin_ciscotools_image.model_id 
                    WHERE host.id=plugin_ciscotools_upgrade.host_id".
                    $sqlWhere;
    $totalRows = db_fetch_cell($sqlTotalRow);

	// If nb rows is -1 > set it to the default
	/* if the number of rows is -1, set it to the default */
	if (get_request_var('rows') == -1) {
		$rows = read_config_option('num_rows_table');
	} else {
		$rows = get_request_var('rows');
	}
	
    $page = ($rows*(get_request_var("page")-1));
    $sqlLimit = $page . "," . $rows;

	if( $sqlWhere != '' ) {
		$sqlWhere = ' WHERE true' . $sqlWhere;
	}		
	
	// SQL Query
     $sqlQuery = "SELECT host.id as 'id', host.description as 'description', host.hostname as 'hostname', 
				plugin_ciscotools_upgrade.image as image, plugin_ciscotools_upgrade.status as 'status', 
				plugin_extenddb_host_model.model as 'model', plugin_ciscotools_image.image as 'requested' 
				FROM host 
				INNER JOIN plugin_ciscotools_upgrade ON host.id=plugin_ciscotools_upgrade.host_id 
				INNER JOIN plugin_extenddb_host_model ON plugin_extenddb_host_model.host_id=host.id
				INNER JOIN plugin_extenddb_model ON plugin_extenddb_host_model.model=plugin_extenddb_model.model 
				INNER JOIN plugin_ciscotools_image ON plugin_extenddb_model.id=plugin_ciscotools_image.model_id 
				$sqlWhere
                $sort
                LIMIT " . $sqlLimit;
    $result = db_fetch_assoc($sqlQuery); // Query result
//upgrade_log('UPG: Upgrade Pages: '.print_r($sqlQuery, true) );

	// Sorting devices in array
    $devices = array();
    foreach($result as $entry)
    {
        $id = $entry['id'];$id = $entry['id'];
        $devices[$id]['id'] = $id;
        $devices[$id]['description'] = $entry['description'];
        $devices[$id]['hostname'] = $entry['hostname'];
        $devices[$id]['image'] = $entry['image'];
        $devices[$id]['requested'] = $entry['requested'];
        $devices[$id]['status'] = $entry['status'];
        $devices[$id]['model'] = $entry['model'];
	}

    if($upgradeExport == "1")
    {
			// SQL Query
		$sqlQuery = "SELECT host.id as 'id', host.description as 'description', host.hostname as 'hostname', 
				plugin_ciscotools_upgrade.image as image, plugin_ciscotools_upgrade.status as 'status', 
				plugin_extenddb_host_model.model as 'model', plugin_ciscotools_image.image as 'requested' 
				FROM host 
				INNER JOIN plugin_ciscotools_upgrade ON host.id=plugin_ciscotools_upgrade.host_id 
				INNER JOIN plugin_extenddb_host_model ON plugin_extenddb_host_model.host_id=host.id
				INNER JOIN plugin_extenddb_model ON plugin_extenddb_host_model.model=plugin_extenddb_model.model 
				INNER JOIN plugin_ciscotools_image ON plugin_extenddb_model.id=plugin_ciscotools_image.model_id
                $sqlWhere";
		$result = db_fetch_assoc($sqlQuery); // Query result

        $filename = upgradeExport($result);
        if($filename === false) 
			header("Location: upgrade.php?action=upgrade");
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
	if(!empty($upgradeAction)) {
		include_once($config['base_path'] . '/plugins/ciscotools/upgrade/upgrade.php');	// Include functions of upgrade.php
		$param = explode('?', $_SERVER['REQUEST_URI'], 2);	// Explode URL to get parameters
		if(!preg_match_all("/&chk_([0-9]+)/", $param[1], $devicesID)) header("Location: ciscotools_tab.php?action=upgrade&5");	// Check if devices were selected
			
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
				// reboot or commit the device
				case 4:
					ciscotools_upgrade_table($value, 'update',UPGRADE_STATUS_FORCE_REBOOT_COMMIT);
					break;
				// Delete device from upgrade table
				case 5:
					ciscotools_upgrade_table($value, 'delete' );
					break;
			}
		}
		header("Location: ciscotools_tab.php?action=upgrade&0");	// Redirect instantly
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
        "description"   	=> array(
			"display"	=> __("Description"),
			"align"		=> "left",
			"sort"		=>"ASC",
			"tip"		=> __('The hostname of the device')
		),
		"hostname"	=> array(
			"display"	=> __("IP"),
			"align"		=> "left",
			"sort"		=> "ASC",
			"tip"		=> __('The IP address of the device')
		),
		"model"	=> array(
			"display"	=> __("Model"),
			"align"		=> "left",
			"sort"		=> "ASC",
			"tip"		=> __('The model of the device')
		),
		"upg_image"    => array(
			"display"	=> __("Current Version"),
			"align"		=> "left",
			"sort"		=> "ASC",
			"tip"		=> __('The current Version on the device')
		),
		"rqst_image"    => array(
			"display"	=> __("Requested Image"),
			"align"		=> "left",
			"sort"		=> "ASC",
			"tip"		=> __('The requested image on the device')
		),
		"status"    => array(
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
		$("#description, #status, #rows, #upgradeError, #model").change(function()
		{	// When fields change > apply filter
			loadPageNoHeader(applyFilterChange());
		});

		$("#upgradeRefresh").click(function()
		{	// When 'Apply' button clicked > apply filter
			loadPageNoHeader(applyFilterChange());
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
        strURL += "&description=" + $("#description").val();	// Description param
        strURL += "&model=" + $("#model").val();	// model param
        strURL += "&status=" + $("#status").val();	// Status param
        strURL += "&rows=" + $("#rows").val();	// Rows param
 		// UpgradeError param > sort all errors or no
		if($("#upgradeError").is(":checked")) strURL += "&upgradeError=1";
		else strURL += "&upgradeError=0";
		strURL += '&header=false';
        return strURL;  // return URL
    }

    function clearFilter()
    {	// Clear filter (URL parameters)
		// Reload URL
        strURL = "ciscotools_tab.php?action=upgrade&clear=1";
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
                            <input type="text" placeholder="Enter a description" name="description" id="description" size="25" value="<?php echo get_request_var('description'); ?>">
							<i class="fa fa-search filter"></i>
						</td>
						<td>
							<?php echo __("Model"); ?>
                        </td>
                        <td>
                            <input type="text" placeholder="Enter the model" name="model" id="model" size="25" value="<?php echo get_request_var('model'); ?>">
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
                            <select name="status" id="status" nowrap style="white-space:nowrap;" width="1">
                                <option value="-1"<?php if(get_request_var("status") == -1) {?> selected<?php }?>>None</option>
                                <?php
                                    foreach(CISCOTLS_UPG_STATUS as $key => $value)
                                    {
                                        echo "<option value='" . $key . "'"; if(get_request_var("status") == $key ) { echo " selected"; } echo ">" . $key . " – " . $value['name'] . "</option>\n";
                                    }
                                ?>
                            </select>
                        </td>
                        <td>
                            <?php echo __("Rows"); ?>
                        </td>
                        <td>
                            <select name="rows" id="rows">
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
	
	$URL = "ciscotools_tab.php?action=upgrade&description=".get_request_var('description')
	."&model=".get_request_var('model')
	."&status=".get_request_var('status')
	."&rows=".get_request_var('rows')
	."&upgradeError=".get_request_var('upgradeError');
	
    $nav = html_nav_bar($URL, MAX_DISPLAY_PAGES, get_request_var("page"), $rows, $totalRows, cacti_sizeof($displayDeviceText)+1, __($displayDeviceText), 'page', "main");

   print $nav;

	// Put checkboxes and redirect on upgrade tab
    html_header_sort_checkbox($displayText, get_request_var('sort_column'), get_request_var('sort_direction'), false, "ciscotools_tab.php?action=upgrade&description=".get_request_var('description')."&model=".get_request_var('model')."&7" );

    if(!empty($devices))
    {
		foreach($devices as $device)
		{	// Put records in table
            $color = "; color:" . CISCOTLS_UPG_STATUS[$device['status']]['color'] . ";";
			form_alternate_row('line' . $device['id'], true);	// Alternate color
			form_selectable_cell(filter_value($device['id'], get_request_var('description'), "../../host.php?action=edit&id=" . $device['id']), $device['id']); // ID
			form_selectable_cell(filter_value($device['description'], get_request_var('description')), $device['id'], $color); // Description
			form_selectable_cell($device['hostname'], $device['id'], $color); // Hostname
			form_selectable_cell(filter_value($device['model'], get_request_var('model')), $device['id'], $color); // model
			form_selectable_cell($device['image'], $device['id'], $color); // image
			form_selectable_cell($device['requested'], $device['id'], $color); // requested
			form_selectable_cell(filter_value($device['status'] . " – " . CISCOTLS_UPG_STATUS[$device['status']]['name'], get_request_var('filter')), $device['id'], $color); // Status
			form_checkbox_cell($device['description'], $device['id']);
			form_end_row();
		}
    }
    else
    {
        echo "<tr><td style='padding:4px;margin:4px;' colspan=11><center>There is no current upgrading information to display</center></td></tr>";
    }
    
    html_end_box(false);
    print $nav;
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
        $sqlQuery = "SELECT host.id as 'id', host.description as 'description', host.hostname as 'ip', plugin_extenddb_host_model.model as 'model', "
					."plugin_ciscotools_image.image as 'default image', "
					."plugin_ciscotools_upgrade.status as 'status', "
					."plugin_ciscotools_upgrade.image as 'current version' "
					."FROM host "
					."INNER JOIN plugin_extenddb_host_model ON plugin_extenddb_host_model.host_id=host.id "
					."LEFT JOIN plugin_extenddb_model ON plugin_extenddb_host_model.model = plugin_extenddb_model.model "
					."LEFT JOIN plugin_ciscotools_upgrade ON host.id = plugin_ciscotools_upgrade.host_id "
					."LEFT JOIN plugin_ciscotools_image ON plugin_ciscotools_image.model_id=plugin_extenddb_model.id "
					."WHERE host.id IN (" . $ids . ") "
					."GROUP BY host.id ASC";
        $result = db_fetch_assoc($sqlQuery);
        if($result === false) return false;

        $filename = "cacti-exportUpgrade-" . time() . ".csv";   // Set filename with current time
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