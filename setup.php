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
// test Github
function plugin_ciscotools_install () {
	api_plugin_register_hook('ciscotools', 'config_arrays', 'ciscotools_config_arrays', 'setup.php'); // array used by this plugin
	api_plugin_register_hook('ciscotools', 'config_settings', 'ciscotools_config_settings', 'setup.php');
	api_plugin_register_hook('ciscotools', 'config_form', 'ciscotools_config_form', 'setup.php'); // host form
	api_plugin_register_hook('ciscotools', 'api_device_new', 'ciscotools_api_device_new', 'setup.php'); // device allready exist, just save value from the form

// Device action
    api_plugin_register_hook('ciscotools', 'device_action_array', 'ciscotools_device_action_array', 'setup.php');
    api_plugin_register_hook('ciscotools', 'device_action_execute', 'ciscotools_device_action_execute', 'setup.php');
    api_plugin_register_hook('ciscotools', 'device_action_prepare', 'ciscotools_device_action_prepare', 'setup.php');

	api_plugin_register_realm('ciscotools', 'ciscotools.php', 'Plugin -> Cisco Tools', 1);
	
	ciscotools_setup_tables();
}

function plugin_ciscotools_uninstall () {
	// Do any extra Uninstall stuff here

}

function plugin_ciscotools_check_config () {
	// Here we will check to ensure everything is configured
	ciscotools_check_upgrade ();

	return true;
}

function plugin_ciscotools_upgrade () {
	// Here we will upgrade to the newest version
	ciscotools_check_upgrade();
	return false;
}

function ciscotools_check_upgrade() {
	global $config;

	$version = plugin_ciscotools_version ();
	$current = $version['version'];
	$old     = read_config_option('plugin_ciscotools_version');
	if ($current != $old) {
		ciscotools_setup_tables();
		
		// Set the new version
		db_execute("UPDATE plugin_config SET version='$current' WHERE directory='ciscotools'");
		db_execute("UPDATE plugin_config SET 
			version='" . $version['version'] . "', 
			name='"    . $version['longname'] . "', 
			author='"  . $version['author'] . "', 
			webpage='" . $version['homepage'] . "' 
			WHERE directory='" . $version['name'] . "' ");
	}

}

function ciscotools_setup_tables() {

// Device login/password and console type
	api_plugin_db_add_column ('ciscotools', 'host', array('name' => 'login', 'type' => 'char(50)', 'NULL' => true, 'default' => ''));
	api_plugin_db_add_column ('ciscotools', 'host', array('name' => 'password', 'type' => 'char(50)', 'NULL' => true, 'default' => ''));
	api_plugin_db_add_column ('ciscotools', 'host', array('name' => 'console_type', 'type' => 'char(2)', 'NULL' => true, 'default' => ''));
	api_plugin_db_add_column ('ciscotools', 'host', array('name' => 'can_be_upgraded', 'type' => 'char(2)', 'NULL' => true, 'default' => ''));
	api_plugin_db_add_column ('ciscotools', 'host', array('name' => 'can_be_rebooted', 'type' => 'char(2)', 'NULL' => true, 'default' => ''));
}

function plugin_ciscotools_version () {
	global $config;
	$info = parse_ini_file($config['base_path'] . '/plugins/ciscotools/INFO', true);
	return $info['info'];
}

function ciscotools_check_dependencies() {
	global $plugins, $config;

	return true;
}

function ciscotools_config_arrays () {
	global $ciscotools_console_type;

	$ciscotools_console_type = array(
		"0" => "Disabled",
		"1" => "SSH",
		"2" => "Telnet"
		);
}

function ciscotools_config_form () {
	global $fields_host_edit, $ciscotools_console_type;
	
	$fields_host_edit2 = $fields_host_edit;
	$fields_host_edit3 = array();
	foreach ($fields_host_edit2 as $f => $a) {
		$fields_host_edit3[$f] = $a;
		if ($f == 'external_id') {
			$fields_host_edit3['cisco_tools_header'] = array(
				'friendly_name' => __('Cisco Tools'),
				'method' => 'spacer',
				'collapsible' => 'true'
			);
			$fields_host_edit3['login'] = array(
				'method' => 'textbox',
				'friendly_name' => 'Login name',
				'description' => 'The Login Name for the Console Access.',
				'max_length' => 50,
				'value' => '|arg1:login|',
				'default' => read_config_option('ciscotools_default_login'),
			);
			$fields_host_edit3['password'] = array(
				'friendly_name' => 'Password',
				'description' => 'Enter the Password for the Console Access.',
				'method' => 'textbox_password',
				'max_length' => 50,
				'value' => '|arg1:password|',
				'default' => read_config_option('ciscotools_default_password'),
			);
			$fields_host_edit3['console_type'] = array(
				'friendly_name' => 'Console Type',
				'description' => 'What Type of Console Access do we have SSH or Telnet',
				'method' => 'drop_array',
				'value' => '|arg1:console_type|',
 			     "array" => $ciscotools_console_type,
				'default' => read_config_option('ciscotools_default_console_type'),
			);
			$fields_host_edit3['can_be_upgraded'] = array(
				'friendly_name' => 'Can it be upgraded',
				'description' => 'Enable if the device can be upgraded without human intervention',
				'method' => 'checkbox',
				'value' => '|arg1:can_be_upgraded|',
				'default' => read_config_option('ciscotools_default_can_be_upgraded'),
			);
			$fields_host_edit3['can_be_rebooted'] = array(
				'friendly_name' => 'Can it be rebooted after upgrade of the OS',
				'description' => 'Enable if the device can be rebooted after the new OS is downloaded',
				'method' => 'checkbox',
				'value' => '|arg1:can_be_rebooted|',
				'default' => read_config_option('ciscotools_default_can_be_rebooted'),
			);
		}
	}
	$fields_host_edit = $fields_host_edit3;
}

function ciscotools_config_settings () {
	global $tabs, $settings, $ciscotools_console_type;

	if (isset($_SERVER['PHP_SELF']) && basename($_SERVER['PHP_SELF']) != 'settings.php')
		return;

	$tabs['Cisco Tools'] = 'Cisco Tools';
	$settings['Cisco Tools'] = array(
		"linkdiscovery_general_header" => array(
			"friendly_name" => "General",
			"method" => "spacer",
			),
		"ciscotools_default_console_type" => array(
			"friendly_name" => "Default Console Type",
			"description" => "This is default console type SSH or Telnet.",
			"method" => "drop_array",
			"array" => $ciscotools_console_type,
			'default' => '0',
			),
		"ciscotools_default_login" => array(
			"friendly_name" => "Default Login Name",
			"description" => "This is default Login name for the console access.",
			"method" => "textbox",
			"max_length" => 50,
			'default' => '',
			),
		'ciscotools_default_password' => array(
			"friendly_name" => "Default Password Name",
			"description" => "This is default Password for the console access.",
			"method" => "textbox_password",
			"max_length" => 50,
			'default' => '',
			),
		'ciscotools_default_can_be_upgraded' => array(
			'friendly_name' => 'Can it be upgraded',
			'description' => 'Enable if the device can be upgraded without human intervention.',
			'method' => 'checkbox',
			'default' => 'on',
			),
		'ciscotools_default_can_be_rebooted' => array(
			'friendly_name' => "Can it be rebooted after upgrade of the OS",
			'description' => "Enable if the device can be rebooted after the new OS is downloaded.",
			"method" => 'checkbox',
			"default" => 'off',
			),
		'ciscotools_default_upgrade_type' => array(
			'friendly_name' => "Enable Console access instead of SNMP",
			'description' => "We use SNMP command to upload the upgrade by default, enable to use console and tftp instead.",
			'method' => 'checkbox',
			'default' => 'off',
			),
	);
}

function ciscotools_api_device_new($hostrecord_array) {
	// don't do it for disabled
	if( $hostrecord_array['disabled'] == 'on'  ) {
		return $hostrecord_array;
	}

// We need to check if it's a cisco device
	$hostrecord_array['snmp_sysDescr'] = db_fetch_cell_prepared('SELECT snmp_sysDescr
			FROM host
			WHERE id ='.
			$hostrecord_array['id']);

	// don't do it for not Cisco type	
	if( mb_stripos($hostrecord_array['snmp_sysDescr'], "cisco") == false) {
		return $hostrecord_array;
	}

	if (isset($_POST['login'])) {
		$hostrecord_array['login'] = form_input_validate($_POST['login'], 'login', '', true, 3);
	} else {
		$hostrecord_array['login'] = form_input_validate('', 'login', '', true, 3);
	}
	
	if (isset($_POST['password'])) {
		$hostrecord_array['password'] = form_input_validate($_POST['password'], 'password', '', true, 3);
	} else {
		$hostrecord_array['password'] = form_input_validate('', 'password', '', true, 3);
	}
	
	if (isset($_POST['console_type'])) {
		$hostrecord_array['console_type'] = form_input_validate($_POST['console_type'], 'console_type', '', true, 3);
	} else {
		$hostrecord_array['console_type'] = form_input_validate('', 'console_type', '', true, 3);
	}

	if (isset($_POST['can_be_upgraded'])) {
		$hostrecord_array['can_be_upgraded'] = form_input_validate($_POST['can_be_upgraded'], 'can_be_upgraded', '', true, 3);
	} else {
		$hostrecord_array['can_be_upgraded'] = form_input_validate('', 'can_be_upgraded', '', true, 3);
	}
	
	if (isset($_POST['can_be_rebooted'])) {
		$hostrecord_array['can_be_rebooted'] = form_input_validate($_POST['can_be_rebooted'], 'can_be_rebooted', '', true, 3);
	} else {
		$hostrecord_array['can_be_rebooted'] = form_input_validate('', 'can_be_rebooted', '', true, 3);
	}

	sql_save($hostrecord_array, 'host');
	
	return $hostrecord_array;
}

function ciscotools_device_action_array($device_action_array) {
        $device_action_array['ciscotools_upgrade'] = __('Download new OS');

        return $device_action_array;
}

function ciscotools_device_action_execute($action) {
        global $config;

        if ($action != 'ciscotools_upgrade' ) {
                return $action;
        }

        $selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

        if ($selected_items != false) {
                if ($action == 'ciscotools_upgrade' ) {
                        for ($i = 0; ($i < count($selected_items)); $i++) {
				if ($action == 'ciscotools_upgrade') {
					$dbquery = db_fetch_assoc("SELECT * FROM host WHERE id=".$selected_items[$i]);
extdb_log("ciscotools_upgrade value: ".$selected_items[$i]." - ".print_r($dbquery[0])." - ".$dbquery[0]['description']."\n");
					extenddb_api_device_new( $dbquery[0] );
                                }
                        }
                 }
        }

        return $action;
}

function ciscotools_device_action_prepare($save) {
        global $host_list;

        $action = $save['drp_action'];

        if ($action != 'ciscotools_upgrade' ) {
                return $save;
        }

        if ($action == 'ciscotools_upgrade' ) {
			$action_description = 'Upgrade selected device';
				print "<tr>
                        <td colspan='2' class='even'>
                                <p>" . __('Click \'Continue\' to %s on these Device(s)', $action_description) . "</p>
                                <p><div class='itemlist'><ul>" . $save['host_list'] . "</ul></div></p>
                        </td>
                </tr>";
        }
}

function ciscotools_log( $text ){
    cacti_log( $text, false, "ciscotools" );
}

?>
