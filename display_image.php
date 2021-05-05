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
include(dirname(__FILE__).'/../../include/global.php');

$ciscotoolsImageActions = array(
    1	=> __("Delete"),
    2	=> __("Duplicate")
);
	
set_default_action('display_image');

/* ================= input validation ================= */
input_validate_input_number(get_request_var("page"));
input_validate_input_number(get_request_var("rows"));
/* ==================================================== */
// Clean upgradeExport
if(!isempty_request_var("model")) {
    set_request_var("model", sanitize_search_string(get_request_var("model")));
} else unset_request_var("model");

load_current_session_value("page", "sess_ciscotools_current_page", "1");    // Default:	1
load_current_session_value("rows", "sess_ciscotools_rows", "-1");			// Default:	-1
load_current_session_value("model", "sess_ciscotools_model", "");			// Default:	''

switch(get_request_var('action'))
{
    case 'display_image':
        top_header();
        display_image();
        bottom_footer();
        break;
        
    case 'edit_image':
        top_header();
		
        edit_image();
        bottom_footer();
        break;

    case 'actions':
        image_form_actions();
        break;
        
    case 'save':
        image_form_save();
        break;
}

/**** Display image liste ***/
function display_image() {
    global $config, $item_rows, $ciscotoolsImageActions;

    $filters = array(
        'rows'  => array(
            'filter'    => FILTER_VALIDATE_INT,
            'pageset'   => true,
            'default'   => '-1'
        ),
        'page'  => array(
            'filter'    => FILTER_VALIDATE_INT,
            'default'   => '1'
        ),
        'model' => array(
            'filter'    => FILTER_DEFAULT,
            'pageset'   => true,
            'default'   => ''
        )
    );

	validate_store_request_vars($filters, 'sess_ciscotools_model');
	/* ================= input validation ================= */

    if (get_request_var('rows') == '-1')
    {
		$rows = read_config_option('num_rows_table');
    } 
    else
    {
		$rows = get_request_var('rows');
	}
		$refresh['seconds'] = '300';
		$refresh['page']    = 'display_image.php?action=display_image&header=false';
		$refresh['logout']  = 'false';

		set_page_refresh($refresh);

	?>
    <script type="text/javascript">
        function applyFilter()
        {
            strURL = "display_image.php?action=display_image";
            strURL += '&rows' + $('#rows').val();
            strURL += '&header=false';
            if($('#model'))
            {
                strURL += '&model=' + $('#model').val();
            }
            loadPageNoHeader(strURL);
        }

        function clearFilter()
        {
            <?php
            kill_session_var("sess_ciscotools_rows");
            kill_session_var("sess_ciscotools_page");
            kill_session_var("sess_ciscotools_model");

            // Unset all parameters
            unset($_REQUEST['page']);
            unset($_REQUEST['rows']);
            unset($_REQUEST['model']);
            ?>
            strURL = "display_image.php?action=display_image&clear=1&header=false";
            loadPageNoHeader(strURL);
        }

        $(function()
        {
            $('#refresh').click(function()
            {
                applyFilter();
            });

            $('#clear').click(function()
            {
                clearFilter();
            });

            $('#form_display_image').submit(function(event)
            {
                event.preventDefault();
                applyFilter();
            });
        });
    </script>

    <?php

    html_start_box(__('Device Image'), '100%', '', '3', 'center', 'display_image.php?action=edit_image');
    ?>
    <tr class="even noprint">
        <td>
            <form id="form_display_image" action="display_image.php">
                <table class="filterTable">
                    <tr>
                        <td>
                            <?php print __('Model'); ?>
                        </td>
                        <td>
                            <input type="text" class="ui-state-default ui-corner-all" id="model" size="25" value='<?php print html_escape_request_var('model'); ?>'>
                        </td>
                        <td>
                            <?php print __('Rows'); ?>
                        </td>
                        <td>
                            <select id='rows' onChange='applyFilter()'>
                                <option value='-1'<?php print (get_request_var('rows') == '-1' ? ' selected>':'>') . __('Default');?></option>
								<?php
								if (cacti_sizeof($item_rows)) {
									foreach ($item_rows as $key => $value) {
										print "<option value='" . $key . "'"; if (get_request_var('rows') == $key) { print ' selected'; } print '>' . html_escape($value) . "</option>\n";
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
                <input type="hidden" name="action" value="form_display_db">
            </form>
        </td>
    </tr>
    <?php
    html_end_box();

    /* SQL */
    $sqlWhere = '';

    if(get_request_var('model') != '')
    {
        $sqlWhere = " WHERE plugin_ciscotools_image.model_id=plugin_extenddb_model.id AND plugin_extenddb_model.model LIKE " . db_qstr('%' . get_request_var('model') . '%');
    }
    $sqlTotalRow = "SELECT count(distinct(model_id))
					FROM plugin_ciscotools_image, plugin_extenddb_model".
					$sqlWhere;
    $totalRows = db_fetch_cell($sqlTotalRow);

    if(get_request_var('rows') == '-1') $perRow = read_config_option('num_rows_table');
    else $perRow = get_request_var('rows');

    $page = ($perRow*(get_request_var("page")-1));
    $sqlLimit = $page . "," . $perRow;

    $sqlQuery = "SELECT plugin_ciscotools_image.id as id, plugin_ciscotools_image.image as image, plugin_ciscotools_image.mode as mode, 
		plugin_extenddb_model.model as model, plugin_ciscotools_image.model_id as model_id
        FROM plugin_ciscotools_image
		INNER JOIN plugin_extenddb_model on plugin_ciscotools_image.model_id=plugin_extenddb_model.id
        $sqlWhere
        ORDER BY plugin_extenddb_model.model
        LIMIT " . $sqlLimit;
    $result = db_fetch_assoc($sqlQuery);

    $displayDeviceText = ($totalRows>1) ? "Models" : "Model";	// One or more > plural form
    $nav = html_nav_bar("display_image.php?action=display_image&model=" . get_request_var('model'), MAX_DISPLAY_PAGES, get_request_var('page'), $perRow, $totalRows, '', __($displayDeviceText));
    form_start('display_image.php');
    print $nav;
    html_start_box('', '100%', '', '3', 'center', '');

    html_header_checkbox(array(__('Model'), __('Image'), __('Mode')) );

    if(!empty($result))
    {
		// ref id is from plugin_ciscotools_image
        foreach($result as $item)
        {
			$image = filter_value($item['model'], get_request_var('model'));
			form_alternate_row('line' . $item['id'], false);

				print '<td><a href="' . html_escape('display_image.php?action=edit_image&id=' . 
				$item['id']) . '">' . $item['model'] . '</a></td>';

				form_selectable_cell($item['image'], $item['image']);
				form_selectable_cell($item['mode'], $item['mode']);
				
				form_checkbox_cell($item['image'], $item['id']);
			form_end_row();
        }
    }

    html_end_box(false);
    if(!empty($result)) print $nav;

    form_hidden_box('action_receivers', '1', '');
    draw_actions_dropdown($ciscotoolsImageActions);
    form_end();
}

/**** Action on image display ****/
function image_form_actions() {
    global $ciscotoolsImageActions;
	
    if(isset_request_var('selected_items'))
    {
      if(isset_request_var('action_receivers'))
        {
            $selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

            if($selected_items != false)
            {
                if(get_nfilter_request_var('drp_action') == '1') // delete
                {
					$sql_del = 'DELETE plugin_ciscotools_upgrade FROM plugin_ciscotools_upgrade 
								LEFT JOIN host ON plugin_ciscotools_upgrade.host_id=host.id
								LEFT JOIN plugin_extenddb_model ON plugin_extenddb_model.model=host.model 
								LEFT JOIN plugin_ciscotools_image ON plugin_extenddb_model.id=plugin_ciscotools_image.model_id
								WHERE plugin_ciscotools_image.id IN (' . implode(',', $selected_items) .')';
					db_execute($sql_del);

                    db_execute('DELETE FROM plugin_ciscotools_image WHERE id IN (' . implode(',', $selected_items) . ')');
                    header('Location: display_image.php?header=false');
                }
                else if(get_nfilter_request_var('drp_action') == '2') // duplicate
                {  
                    if(count($selected_items) > 1)
                    {
                        display_custom_error_message('Only one model can be duplicated at time');
                        header('Location: display_image.php?header=false');
                    }
                    else
                    {
                        $selected_item = implode(',', $selected_items);
						$sqlQuery = "SELECT plugin_ciscotools_image.id as id, plugin_extenddb_model.model as model, plugin_ciscotools_image.image as image, plugin_ciscotools_image.mode as mode, plugin_ciscotools_image.model_id,
						plugin_ciscotools_image.command as command,
						plugin_ciscotools_image.regex as regex
									FROM plugin_extenddb_model 
									LEFT JOIN plugin_ciscotools_image on plugin_ciscotools_image.model_id=plugin_extenddb_model.id 
									WHERE plugin_ciscotools_image.id='" . $selected_item . "'";
                        $item = db_fetch_row_prepared($sqlQuery);
						ciscotools_log("action: ".print_r( $item, true) );
                        $edit_image_db['model'] = $item['model'];
                        $edit_image_db['model_id'] = $item['model_id'];
                        $edit_image_db['image'] = $item['image'];
                        $edit_image_db['mode'] = $item['mode'];
                        $edit_image_db['command'] = $item['command'];
                        $edit_image_db['regex'] = $item['regex'];
                        
                        edit_image($edit_image_db);
                    }
                }
                else ciscotools_log("FAIL");
                exit;
            }
        }
    }
    else
    {
       if(isset_request_var('action_receivers'))
        {
            $selected_items = array();
            $list = '';
            foreach($_POST as $key => $value)
            {
                if(strstr($key, 'chk_'))
                {
                    $id = substr($key, 4);
                    /* ================= input validation ================= */
					input_validate_input_number($id);
                    /* ==================================================== */
                    $list .= '<li>' . html_escape(db_fetch_cell_prepared("SELECT plugin_extenddb_model.model as model
									FROM plugin_extenddb_model 
									LEFT JOIN plugin_ciscotools_image on plugin_ciscotools_image.model_id=plugin_extenddb_model.id 
									WHERE plugin_ciscotools_image.id = ?", array($id))) . '</li>';
                    $selected_items[] = $id;
                }
            }
            top_header();
            form_start("display_image.php");
            html_start_box($ciscotoolsImageActions[get_nfilter_request_var('drp_action')], '60%', '', '3', 'center', '');

            if(cacti_sizeof($selected_items))
            {
				if(get_nfilter_request_var('drp_action') == '1')
                {
                    $msg = __n('Click \'Continue\' to delete the following model', 'Click \'Continue\' to delete following model', cacti_sizeof($selected_items));
                }
                if(get_nfilter_request_var('drp_action') == '2')
                {
                    $msg = __n('Click \'Continue\' to duplicate the following model', 'Click \'Continue\' to duplicate following model', cacti_sizeof($selected_items));
                }

                print   "<tr>
                            <td class='textArea'>
                                <p>$msg</p>
                                <div class='itemlist'><ul>$list</ul></div>
                            </td>
                        </tr>";

                $save_html = "<input type='button' class='ui-button ui-corner-all ui-widget' value='" . __esc('Cancel') . "' onClick='cactiReturnTo()'><input type='submit' class='ui-button ui-corner-all ui-widget' value='" . __esc('Continue') . "' title='" . __esc('%s Image', $ciscotoolsImageActions[get_nfilter_request_var('drp_action')]) . "'>";
            }
            else
            {
                raise_message(40);
                header('Location: display_image.php?header=false');
                exit;
            }

            print "<tr>
                    <td class='saveRow'>
                        <input type='hidden' name='action' value='actions'>
                        <input type='hidden' name='action_receivers' value='1'>
                        <input type='hidden' name='selected_items' value='" . (isset($selected_items) ? serialize($selected_items) : '') . "'>
                        <input type='hidden' name='drp_action' value='" . html_escape(get_nfilter_request_var('drp_action')) . "'>
                        $save_html
                    </td>
                </tr>";
            
            html_end_box();
            form_end();
            bottom_footer();
        }
    }
}

/**** Edition part of the image field ****/
function edit_image($image=null)
{
    global $config;

	/* ================= input validation ================= */
	get_filter_request_var('id');
    /* ==================================================== */
    $id = (isset_request_var('id') ? get_request_var('id') : '0');
    if($id)
    {
        $image = db_fetch_row_prepared("SELECT plugin_extenddb_model.model as model, plugin_ciscotools_image.image as image, plugin_ciscotools_image.mode as mode, 
		plugin_ciscotools_image.id as id,  
		plugin_ciscotools_image.model_id as model_id,
		plugin_ciscotools_image.command as command,
		plugin_ciscotools_image.regex as regex
		FROM plugin_ciscotools_image INNER JOIN plugin_extenddb_model on plugin_extenddb_model.id=plugin_ciscotools_image.model_id WHERE plugin_ciscotools_image.id= ?", array($id));
		
        $header_label = __esc('Ciscotools Model [edit: %s ]', $image['model']);
		$sqlfilter = " OR plugin_extenddb_model.model='".$image['model']."'";
	}
    else
    {
        $header_label = __('Ciscotools Model [new]');
		$sqlfilter = '';
    }
  
	$sqlQuery = "SELECT plugin_extenddb_model.model as model, plugin_extenddb_model.id as model_id
				FROM plugin_extenddb_model 
				LEFT JOIN plugin_ciscotools_image on plugin_ciscotools_image.model_id=plugin_extenddb_model.id 
				WHERE plugin_ciscotools_image.model_id IS NULL" 
				.$sqlfilter;
    $result = db_fetch_assoc($sqlQuery);
	foreach( $result as $model) {
		$models_array[$model['model_id']] = $model['model'];
	}

    $fields_image_edit = array(
        'model_id' => array(
            'method' => 'drop_array',
			'array'  => $models_array,
            'friendly_name' => __('Model'),
            'description' => __('Exact model reference'),
            'value' => '|arg1:model_id|',
            'max_length' => '64',
            'size' => 64
        ),
        'image' => array(
            'method' => 'textbox',
            'friendly_name' => __('Image'),
            'description' => __('Image for upgrades'),
            'value' => '|arg1:image|',
            'max_length' => '255',
            'size' => 255
        ),
        'mode' => array(
            'method' => 'drop_array',
            'array'  => array("bundle"=>"bundle", "install"=>"install", "archive"=>"archive"),
            'friendly_name' => __('Mode'),
            'description' => __('Mode type (bundle, install or archive)'),
            'value' => '|arg1:mode|',
            'max_length' => '7',
            'size' => 7
        ),
        'command' => array(
            'method' => 'textbox',
            'friendly_name' => __('Command'),
            'description' => __('Command to check the device, 1st show content of flash, 2nd get current version'),
            'value' => '|arg1:command|',
            'max_length' => '255',
            'size' => 255
        ),
        'regex' => array(
            'method' => 'textbox',
            'friendly_name' => __('RegEx'),
            'description' => __('Regex to extract the version on second match'),
            'value' => '|arg1:regex|',
            'max_length' => '255',
            'size' => 255
        ),
        'id' => array(
            'method' => 'hidden_zero',
            'value' => '|arg1:id|'
        ),
        'model' => array(
            'method' => 'hidden',
            'value' => '|arg1:model|'
        )
    );
  
    form_start('display_image.php');
    html_start_box($header_label, '100%', true, '3', 'center', '');

    draw_edit_form(
		array(
			'config' => array('no_form_tag' => true),
			'fields' => inject_form_variables($fields_image_edit, (isset($image) ? $image : array()))
		)
	);

    html_end_box(true, true);
    form_save_button('display_image.php');
}

function image_form_save()
{
	$save['id']			= get_request_var('id');
	$save['model_id']	= form_input_validate(trim(get_nfilter_request_var('model_id')), 'model_id', '', false, 3);
	$save['image']		= form_input_validate(trim(get_nfilter_request_var('image')), 'image', '', false, 3);
    $save['mode']   	= form_input_validate(trim(get_nfilter_request_var('mode')), 'mode', '', false, 3);
	$save['command']	= form_input_validate(trim(get_nfilter_request_var('command')), 'command', '', false, 3);
	$save['regex']		= form_input_validate(trim(get_nfilter_request_var('regex')), 'regex', '', false, 3);

    $ciscotoolsImageId = 0;
    if(!is_error_message())
    {
        $ciscotoolsImageId = sql_save($save, 'plugin_ciscotools_image');

        $sqlQuery = "UPDATE plugin_ciscotools_upgrade
        LEFT JOIN host ON plugin_ciscotools_upgrade.host_id = host.id
		LEFT JOIN plugin_extenddb_model ON plugin_extenddb_model.model = host.model
        SET plugin_ciscotools_upgrade.status = ".UPGRADE_STATUS_NEED_RECHECK."
        WHERE plugin_extenddb_model.id= '" . $save['model_id'] . "'";
        $sqlExec = db_execute($sqlQuery);

        raise_message(($ciscotoolsImageId) ? 1 : 2);
   } 
	unset_request_var("model");
	kill_session_var("sess_ciscotools_model");
	header('Location: display_image.php?action=display_image&header=false');
}
?>