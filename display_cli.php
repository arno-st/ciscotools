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
set_time_limit(0);
// let PHP run just as long as it has to
ini_set('max_execution_time', '0');

function ciscotools_displaycli() {
    global $cliActions, $config, $item_rows;
	
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
		'model' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			),
		'cliBackup' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => '1'
			),
		'cliStopOnError' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => '1'
			),
		'description' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			),
		'hostname' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			),
		'searchCfg' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			),
		'notSearchCfg' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			)
	);

	validate_store_request_vars($filters, 'sess_ciscotools_cli');

    $cliActions = array(
		1	=> __("Test"),
		2	=> __("Execute"),
    );

    /* ================= input validation ================= */
    input_validate_input_number(get_request_var("page"));
    input_validate_input_number(get_request_var("rows"));
    /* ==================================================== */

	// Clean searchCfg
    if(isset_request_var('searchCfg')) 
    {
        set_request_var('searchCfg', sanitize_search_string(get_request_var("searchCfg")));
	}
	// Clean notSearchCfg
    if(isset_request_var('notSearchCfg')) 
    {
        set_request_var('notSearchCfg', sanitize_search_string(get_request_var("notSearchCfg")));
	}
	// Clean Description
    if(isset_request_var('description')) 
    {
        set_request_var('description', sanitize_search_string(get_request_var("description")));
	}
	// Clean hostname
    if(isset_request_var('hostname')) 
    {
        set_request_var('hostname', sanitize_search_string(get_request_var("hostname")));
	}
	// Clean model
    if(isset_request_var('model')) 
    {
        set_request_var('model', sanitize_search_string(get_request_var("model")));
	}
	// Force backup of the config after execution
	if(isset_request_var('cliBackup'))
	{
		set_request_var("cliBackup", sanitize_search_string(get_request_var("cliBackup")));
    }
	// Force stop on first error
	if(isset_request_var('cliStopOnError'))
	{
		set_request_var("cliStopOnError", sanitize_search_string(get_request_var("cliStopOnError")));
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
	$searchCfg		= get_request_var("searchCfg");
	$notSearchCfg	= get_request_var("notSearchCfg");
	$description    = get_request_var("description");
	$hostname    	= get_request_var("hostname");
	$model    		= get_request_var("model");
    $cliBackup		= get_request_var("cliBackup");
    $cliStopOnError	= get_request_var("cliStopOnError");
	$sortColumn		= get_request_var("sort_column");
	switch($sortColumn)
	{	// Precise table and field
		case "upg_id":
			$sortColumn = "spctb.id";
			break;
		default:
		case "description":
			$sortColumn = "host.description";
			break;
		case "hostname":
			$sortColumn = "host.hostname";
			break;
		case "model":
			$sortColumn = "plugin_extenddb_host_model.model";
			break;
	}
	$sortDirection	= get_request_var("sort_direction");
	
	if($description != '')
    {	// Sort all descriptions like URL parameter
        $sqlWhere .= " AND " . "host.description like '%$description%'";
    }
	if($hostname != '')
    {	// Sort all hostname like URL parameter
        $sqlWhere .= " AND " . "host.hostname like '%$hostname%'";
    }
	if($model != '')
    {	// Sort all model like URL parameter
        $sqlWhere .= " AND " . "plugin_extenddb_host_model.model like '%$model%'";
    }
	if($sortColumn != '')
	{	// Order by URL parameter depends on the sort column
		$sort = " ORDER BY " . $sortColumn;
	}
	else $sort = " ORDER BY spctb.id";
	if($sortDirection != "")
	{	// Precise sort direction
		$sort .= " " . $sortDirection;
	}
	else $sort .= " ASC";

	// looking for something not in the config
	if($notSearchCfg) {
		$search = "AND pctb.diff NOT LIKE '%".$searchCfg."%' ";
	} else {
		$search = "AND pctb.diff LIKE '%".$searchCfg."%' ";
	}
	// Count how many devices are in the table
    $sqlTotalRow = "SELECT count(DISTINCT(pctb.host_id))
				FROM plugin_ciscotools_backup pctb 
				INNER JOIN (SELECT pctb.host_id as id, MAX(pctb.version) as version 
				FROM plugin_ciscotools_backup pctb GROUP BY pctb.host_id ) spctb 
				ON pctb.host_id = spctb.id AND pctb.version = spctb.version 
				INNER JOIN host ON host.id=pctb.host_id
				INNER JOIN plugin_extenddb_host_model ON plugin_extenddb_host_model.host_id=host.id
				$search 
				$sqlWhere ";

//ciscotools_log("SQL query row nb:" .$sqlTotalRow);
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
     $sqlQuery = "SELECT DISTINCT(pctb.host_id) as id, pctb.diff as config, pctb.version, host.description as description, host.hostname as hostname, plugin_extenddb_host_model.model as model, pctb.datechange as date, host.status as status
				FROM plugin_ciscotools_backup pctb 
				INNER JOIN (SELECT pctb.host_id as id, MAX(pctb.version) as version 
				FROM plugin_ciscotools_backup pctb GROUP BY pctb.host_id ) spctb 
				ON pctb.host_id = spctb.id AND pctb.version = spctb.version 
				INNER JOIN host ON host.id=pctb.host_id
				INNER JOIN plugin_extenddb_host_model ON plugin_extenddb_host_model.host_id=host.id
				$search
				$sqlWhere
                $sort
                LIMIT " . $sqlLimit;
//ciscotools_log("SQL query :" .$sqlQuery);
 
 $devices = db_fetch_assoc($sqlQuery); // Query result

    general_header();
	
	// Columns displaying text
    $displayText = array(
        "id"		=> array(
			"display" 	=> __("Host ID"),
			"align" 	=> "left",
			"sort" 		=> "ASC",
			"tip" 		=> __('The ID of the device in the database')
		),
        "description"   	=> array(
			"display"	=> __("Description"),
			"align"		=> "left",
			"sort"		=>"ASC",
			"tip"		=> __('The description of the device')
		),
        "hostname"   	=> array(
			"display"	=> __("hostname"),
			"align"		=> "left",
			"sort"		=>"ASC",
			"tip"		=> __('The hostname of the device')
		),
		"config"	=> array(
			"display"	=> __("Config"),
			"align"		=> "left",
			"sort"		=> "ASC",
			"tip"		=> __('Section of the config searched')
		),
		"date"	=> array(
			"display"	=> __("Date"),
			"align"		=> "left",
			"sort"		=> "ASC",
			"tip"		=> __('The date of latest config of the device')
		),
		"version"	=> array(
			"display"	=> __("Version"),
			"align"		=> "left",
			"sort"		=> "ASC",
			"tip"		=> __('The version ID latest config of the device')
		),
		"model"	=> array(
			"display"	=> __("Model"),
			"align"		=> "left",
			"sort"		=> "ASC",
			"tip"		=> __('The model of the device')
		)
	);
	
?>

    <script type="text/javascript">
    <!--
	// Dynamic function (jQuery)
	$(function() 
	{
		$("#description, #hostname, #rows, #cliBackup, #model, #searchCfg, #notSearchCfg").change(function()
		{	// When fields change > apply filter
			loadPageNoHeader(applyFilterChange());
		});

		$("#cliRefresh").click(function()
		{	// When 'Apply' button clicked > apply filter
			loadPageNoHeader(applyFilterChange());
		});

		$("#cliClear").click(function()
		{	// When 'Clear' button clicked > clear filter
			clearFilter();
		});

		$("#cliButton").click(function()
		{	// When 'Go' button clicked > submit form
			submitForm();
		});

	});

    function applyFilterChange()
    {	// Filters
        strURL = "?action=cli";	// URL base
        strURL += "&searchCfg=" + $("#searchCfg").val();	// searchCfg param
		if($("#notSearchCfg").is(":checked")) strURL += "&notSearchCfg=1";
		else strURL += "&notSearchCfg=0";
        strURL += "&description=" + $("#description").val();	// Description param
        strURL += "&hostname=" + $("#hostname").val();	// Hostname param
        strURL += "&model=" + $("#model").val();	// model param
        strURL += "&rows=" + $("#rows").val();	// Rows param
 		// UpgradeError param > sort all errors or no
		if($("#cliBackup").is(":checked")) strURL += "&cliBackup=1";
		else strURL += "&cliBackup=0";
		if($("#cliStopOnError").is(":checked")) strURL += "&cliStopOnError=1";
		else strURL += "&cliStopOnError=0";
		strURL += '&header=false';
        return strURL;  // return URL
    }

    function clearFilter()
    {	// Clear filter (URL parameters)
		// Reload URL
        strURL = "ciscotools_tab.php?action=cli&clear=1";
        loadPage(strURL);
    }

    function cliActions(value)
    {	// jQuery Actions function, called when change in dropDown Menu
        var button = document.getElementById("cliButton");
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

    function submitForm()
    {	// Submit form after checking, call when an action is requested
        var form = document.getElementById("cliForm");
        var boxes = [];
//console.log($("#cliCommand").val()); // command string
//console.log($("#cliAction :selected").text()); // return the name
//console.log($("#cliAction").val()); // 1 is test, 2 is execute

		// Create array with selected devices
        $("input:checkbox[name^=chk_]:checked").each(function(){
            boxes.push($(this).attr('name'));
        });
        if(boxes.length == 0)
        {	// None selected > alert
            alert("You must select at least one object from the list.");
			strURL = "ciscotools_tab.php?action=cli&clear=1";
			loadPage(strURL);
        } else {
			$("<input />").attr("type", "hidden")
				.attr("name", "cliAction")
				.attr("value", $("#cliAction").val())
			.appendTo("#cliForm");
			$("<input />").attr("type", "hidden")
				.attr("name", "cliCommand")
				.attr("value", $("#cliCommand").val())
			.appendTo("#cliForm");
			$("<input />").attr("type", "hidden")
				.attr("name", "selected_items")
				.attr("value", boxes )
			.appendTo("#cliForm");

			form.submit();
		}
	}
    -->
    </script>

<?php
    // Filters bar
    html_start_box(__("Filters"), "100%", "", "3", "center", "");
?>

    <meta charset="utf-8">
        <td class="noprint even">
            <form style="padding:0;margin:0;" name="form" id="cliForm" action="<?php echo $config['url_path']; ?>plugins/ciscotools/ciscotools_tab.php?action=cli" method="post">
				<table class="filterTable">
					<tr>
						<td>
							<?php echo __("Search in Config"); ?>
                        </td>
                        <td>
                            <input type="text" placeholder="Search in Config" name="searchCfg" id="searchCfg" size="50" value="<?php echo get_request_var('searchCfg'); ?>">
							<i class="fa fa-search filter"></i>
						</td>
						<td>
							<?php echo __("Not in the Config"); ?>
                        </td>
						<td>
							<label class="checkboxSwitch" title="Not in the Config">
								<input value="1" title="Not in the Config" type="checkbox" id="notSearchCfg" name="notSearchCfg" <?php echo ($notSearchCfg === "1") ? "checked" : ""; ?>>
								<span class="checkboxSlider checkboxRound"></span>
							</label>
						</td>
						<td>
							<?php echo __("Description"); ?>
                        </td>
                        <td>
                            <input type="text" placeholder="Enter a description" name="description" id="description" size="25" value="<?php echo get_request_var('description'); ?>">
							<i class="fa fa-search filter"></i>
						</td>
						<td>
							<?php echo __("Hostname"); ?>
                        </td>
                        <td>
                            <input type="text" placeholder="Enter the hostname" name="hostname" id="hostname" size="25" value="<?php echo get_request_var('hostname'); ?>">
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
							<input type='button' class='ui-button ui-corner-all ui-widget ui-state-active' id='cliRefresh' value='<?php print __esc('Apply');?>' title='<?php print __esc('Set/Refresh Filters');?>'>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='cliClear' value='<?php print __esc('Clear');?>' title='<?php print __esc('Clear Filters');?>'>
						</td>
					</tr>
                </table>
				<table class="filterTable">
                    <tr>
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
							<?php echo __("Force Backup"); ?>
						</td>
						<td>
							<label class="checkboxSwitch" title="Force Backup">
								<input value="1" title="Force backup after config" type="checkbox" id="cliBackup" name="cliBackup" <?php echo ($cliBackup === "1") ? "checked" : ""; ?>>
								<span class="checkboxSlider checkboxRound"></span>
							</label>
						</td>
						<td>
							<?php echo __("Stop On Error"); ?>
						</td>
						<td>
							<label class="checkboxSwitch" title="Stop On Error">
								<input value="1" title="Stop config on First Error" type="checkbox" id="cliStopOnError" name="cliStopOnError" <?php echo ($cliStopOnError === "1") ? "checked" : ""; ?>>
								<span class="checkboxSlider checkboxRound"></span>
							</label>
						</td>
                    </tr>
				</table>
                <input type="hidden" name="action" value="cli">
            

<?php
				html_end_box();
			
				html_start_box("", "100%", "", "3", "center", "");
			
				$displayDeviceText = ($totalRows>1) ? "Devices" : "Device";	// One or more > plural form
				
				$URL = "ciscotools_tab.php?action=cli&description=".get_request_var('description')
				."&hostname=".get_request_var('hostname')
				."&model=".get_request_var('model')
				."&rows=".get_request_var('rows')
				."&cliBackup=".get_request_var('cliBackup')
				."&cliStopOnError=".get_request_var('cliStopOnError');
				
				$nav = html_nav_bar($URL, MAX_DISPLAY_PAGES, get_request_var("page"), $rows, $totalRows, cacti_sizeof($displayDeviceText)+1, __($displayDeviceText), 'page', "main");
			
				print $nav;
			
				// Put checkboxes and redirect on cli tab
				html_header_sort_checkbox($displayText, get_request_var('sort_column'), get_request_var('sort_direction'), false, "ciscotools_tab.php?action=cli&description=".get_request_var('description')."&hostname=".get_request_var('hostname')."&model=".get_request_var('model') );
			
				if(!empty($devices))
				{
					foreach($devices as $device)
					{	// Put records in table
						form_alternate_row('line' . $device['id'], true);	// Alternate color
						form_selectable_cell(filter_value($device['id'], get_request_var('description'), "../../host.php?action=edit&id=" . $device['id']), $device['id']); // ID
						form_selectable_cell(filter_value($device['description'], get_request_var('description')), $device['id']); // Description
						form_selectable_cell(filter_value($device['hostname'], get_request_var('hostname')), $device['id']); // Hostname

						// extract the config from $device, to display what we are looking for something special, otherwise just display part of it
						if ( !empty($searchCfg && !$notSearchCfg ) ) {
							$expConfig = explode("\n",$device['config']); // convert to array
							$deviceConfig = array();
							foreach( $expConfig as $line) {
								if( preg_match( "/$searchCfg/", $line ) ) {
									$deviceConfig[] = $line;
								}
							}
							form_selectable_cell(substr(implode( ", ", $deviceConfig ), 0, 150), $device['id']); // config that match the search string
						} else {
							form_selectable_cell(substr($device['config'], 0, 150), $device['id']); // config general
						}

						form_selectable_cell($device['date'], $device['id']); // datechange
						form_selectable_cell($device['version'], $device['id']); // version
						form_selectable_cell(filter_value($device['model'], get_request_var('model')), $device['id']); // model
						form_checkbox_cell($device['description'], $device['id']);
						form_end_row();
					}
				}

				html_end_box(false);
				print $nav;
?>
				<br>
				<div class="formData">
						<td>
							<?php echo __("Command in Cisco format to execute remotely (do not include the conf t or end, it's implide)"); ?>
                        </td>				
					<br>
					<textarea rows = "10" cols = "120" name = "cliCommand" id="cliCommand" style="width:1000px;height=200px;"></textarea>
				</div>
			
               <div class="actionsDropdown">
                    <div>
                        <span class="actionsDropdownArrow">
                            <img src="/cacti/images/arrow.gif" alt>
                        </span>
                        <select onChange="cliActions(this.value);" id="cliAction" name="cliAction" style="display:none;">
                            <option value="" selected>Choose an action</option>
                            <?php
                            foreach($cliActions as $key => $action)
                            {
                                echo "<option value='" . $key . "'>" . $action . "</option>";
                            }
                            ?>
                        </select>
                        <span class="actionsDropdownButton">
                            <input type="button" id="cliButton" class="ui-button ui-corner-all ui-widget ui-state-active ui-state-disabled ui-button-disabled" value="Go" title="Execute Action" role="button" disabled>
                        </span>
                    </div>
                </div>
            </form>
        </td>
	</tr>

<?php
	bottom_footer();
}
?>