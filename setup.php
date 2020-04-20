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

include_once($config['base_path'] . '/plugins/ciscotools/backup.php');
include_once($config['base_path'] . '/plugins/ciscotools/upgrade.php');

function plugin_ciscotools_install () {
	api_plugin_register_hook('ciscotools', 'config_arrays', 'ciscotools_config_arrays', 'setup.php'); // array used by this plugin
	api_plugin_register_hook('ciscotools', 'config_settings', 'ciscotools_config_settings', 'setup.php');
	api_plugin_register_hook('ciscotools', 'config_form', 'ciscotools_config_form', 'setup.php'); // host form
	api_plugin_register_hook('ciscotools', 'api_device_new', 'ciscotools_api_device_new', 'setup.php'); // device allready exist, just save value from the form
	api_plugin_register_hook('ciscotools', 'poller_bottom', 'ciscotools_poller_bottom', 'setup.php'); // check the backup on all valid device, and do backup if necessary and rentetioin validation

// Device action
    api_plugin_register_hook('ciscotools', 'device_action_array', 'ciscotools_device_action_array', 'setup.php');
    api_plugin_register_hook('ciscotools', 'device_action_execute', 'ciscotools_device_action_execute', 'setup.php');
    api_plugin_register_hook('ciscotools', 'device_action_prepare', 'ciscotools_device_action_prepare', 'setup.php');

// Cisco Tools Tab ( backup,...)
	api_plugin_register_hook('ciscotools', 'top_header_tabs', 'ciscotools_show_tab', 'setup.php'); // display when into conosle tab
	api_plugin_register_hook('ciscotools', 'top_graph_header_tabs', 'ciscotools_show_tab', 'setup.php'); // display when clicked tabs
	api_plugin_register_hook('ciscotools', 'draw_navigation_text', 'ciscotools_draw_navigation_text', 'setup.php'); // nav bar under console and graph tab

	api_plugin_register_realm('ciscotools', 'upgrade.php', 'Plugin -> Upgrade', 1);
	api_plugin_register_realm('ciscotools', 'ciscotools_tab.php,backup.php', 'Plugin -> Backups', 1);
	
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
		
		// Set the new version
		db_execute("UPDATE plugin_config SET version='$current' WHERE directory='ciscotools'");
		db_execute("UPDATE plugin_config SET 
			version='" . $version['version'] . "', 
			name='"    . $version['longname'] . "', 
			author='"  . $version['author'] . "', 
			webpage='" . $version['homepage'] . "' 
			WHERE directory='" . $version['name'] . "' ");
	
			if( $old < '1.0' ) {
			}
	}

}

function ciscotools_setup_tables() {
	global $config;
	include_once($config["library_path"] . "/database.php");

// Device login/password and console type
	api_plugin_db_add_column ('ciscotools', 'host', array('name' => 'login', 'type' => 'varchar(20)', 'NULL' => true,  'default' => ''));
	api_plugin_db_add_column ('ciscotools', 'host', array('name' => 'password', 'type' => 'varchar(20)', 'NULL' => true, 'default' => ''));
	api_plugin_db_add_column ('ciscotools', 'host', array('name' => 'console_type', 'type' => 'varchar(3)', 'NULL' => true, 'default' => ''));
	api_plugin_db_add_column ('ciscotools', 'host', array('name' => 'can_be_upgraded', 'type' => 'varchar(3)', 'NULL' => true, 'default' => ''));
	api_plugin_db_add_column ('ciscotools', 'host', array('name' => 'can_be_rebooted', 'type' => 'varchar(3)', 'NULL' => true, 'default' => ''));
	api_plugin_db_add_column ('ciscotools', 'host', array('name' => 'do_backup', 'type' => 'varchar(3)', 'NULL' => true, 'default' => ''));

/* table to keep diff information */
	$data = array();
	$data['columns'][] = array('name' => 'id', 'type' => 'mediumint(8)', 'auto_increment'=>'');
	$data['columns'][] = array('name' => 'host_id', 'type' => 'mediumint(8)', 'NULL' => false, 'default' => '0');
	$data['columns'][] = array('name' => 'version', 'type' => 'mediumint(2)', 'NULL' => true, 'default' => '0');
	$data['columns'][] = array('name' => 'diff', 'type' => 'text', 'NULL' => false);
    $data['columns'][] = array('name' => 'datechange', 'type' => 'varchar(24)', 'NULL' => false);
	$data['primary'] = 'id';
	$data['keys'][] = array('name' => 'id', 'columns' => 'id');
	$data['keys'][] = array('name' => 'host_id', 'columns' => 'host_id');
	$data['keys'][] = array('name' => 'version', 'columns' => 'version');
	$data['keys'][] = array('name' => 'datechange', 'columns' => 'datechange');
	$data['type'] = 'InnoDB';
	$data['comment'] = 'Plugin ciscotoole - Table for diff in config change';
	api_plugin_db_table_create('ciscotools', 'plugin_ciscotools_backup', $data);

/* table to keep a queue of upgrade */
	unset($data);
	$data = array();
	$data['columns'][] = array('name' => 'id', 'type' => 'mediumint(8)', 'auto_increment'=>'');
	$data['columns'][] = array('name' => 'host_id', 'type' => 'mediumint(8)', 'NULL' => false, 'default' => '0');
	$data['primary'] = 'id';
	$data['keys'][] = array('name' => 'id', 'columns' => 'id');
	$data['keys'][] = array('name' => 'host_id', 'columns' => 'host_id');
	$data['type'] = 'InnoDB';
	api_plugin_db_table_create('ciscotools', 'plugin_ciscotools_queueupgrade', $data);

/* table to keep diff info for modele/version */
	unset($data);
	$data = array();
	$data['columns'][] = array('name' => 'id', 'type' => 'mediumint(8)', 'auto_increment'=>'');
	$data['columns'][] = array('name' => 'snmp_SysObjectId', 'type' => 'varchar(128)', 'NULL' => false);
	$data['columns'][] = array('name' => 'oid_modele', 'type' => 'varchar(32)', 'NULL' => false, 'default' => '1.3.6.1.2.1.47.1.1.1.1.13');
	$data['columns'][] = array('name' => 'modele', 'type' => 'varchar(128)', 'NULL' => true);
	$data['columns'][] = array('name' => 'image', 'type' => 'varchar(255)', 'NULL' => false);
	$data['primary'] = 'id';
	$data['keys'][] = array('name' => 'modele', 'columns' => 'modele');
	$data['type'] = 'InnoDB';
	api_plugin_db_table_create('ciscotools', 'plugin_ciscotools_modele', $data);

/* insert values in plugin_ciscotools_modele */
	db_execute("INSERT INTO `plugin_ciscotools_modele` "
		."(`id`, `snmp_SysObjectId`, `oid_modele`, `modele`, `image`, `sshCmds_version`) VALUES "
		."(NULL, 'iso.3.6.1.4.1.9.1.2560', '1.3.6.1.2.1.47.1.1.1.1.13.1', 'IR807G-LTE-GA-K9', 'ir800l-universalk9-mz.SPA.159-3.M.bin', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.1497', '1.3.6.1.2.1.47.1.1.1.1.13.1', 'C819G-4G-G-K9', 'c800-universalk9-mz.SPA.155-3.M8.bin', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.1378', '1.3.6.1.2.1.47.1.1.1.1.13.1', 'C819G-U-K9', 'c800-universalk9-mz.SPA.155-3.M8.bin', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.1384', '1.3.6.1.2.1.47.1.1.1.1.13.1', 'C819HG-U', 'c800-universalk9-mz.SPA.155-3.M8.bin', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.2059', '1.3.6.1.2.1.47.1.1.1.1.13.1', 'cisco819G-4G', 'c800-universalk9-mz.SPA.155-3.M8.bin', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.837', '1.3.6.1.2.1.47.1.1.1.1.13.1', 'CISCO881', 'c880data-universalk9-mz.155-3.M6.bin', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.857', '1.3.6.1.2.1.47.1.1.1.1.13.1', 'CISCO891-K9', 'c890-universalk9-mz.155-3.M8.bin', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.1858', '1.3.6.1.2.1.47.1.1.1.1.13.1', 'cisco891F', 'c800-universalk9-mz.SPA.155-3.M8.bin', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.5.18', '1.3.6.1.2.1.47.1.1.1.1.13', NULL, 'c1900-universalk9-mz.SPA.154-3.M7.bin', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.2661', '1.3.6.1.2.1.47.1.1.1.1.13.1', 'IR1101-K9', 'ir1101-universalk9.16.11.01.SPA.bin', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.1041', '1.3.6.1.2.1.47.1.1.1.1.13.1', 'CISCO3945-CHASSIS', 'c3900-universalk9-mz.SPA.155-3.M4.bin', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.1470', '1.3.6.1.2.1.47.1.1.1.1.13.1001', 'IE-2000-4TC-G-B', 'ie2000-universalk9-tar.152-4.EA7.tar', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.1471', '1.3.6.1.2.1.47.1.1.1.1.13.1001', 'IE-2000-4T-G-B', 'ie2000-universalk9-tar.152-4.EA7.tar', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.1473', '1.3.6.1.2.1.47.1.1.1.1.13.1001', 'IE-2000-8TC-G-B', 'ie2000-universalk9-tar.152-4.EA7.tar', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.1730', '1.3.6.1.2.1.47.1.1.1.1.13.1001', 'IE-2000-16PTC-G-E', 'ie2000-universalk9-tar.152-4.EA7.tar', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.95', '1.3.6.1.2.1.47.1.1.1.1.13.1001', 'IE-3000-8TC', 'ies-lanbasek9-tar.152-4.EA8.tar', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.1208', '1.3.6.1.2.1.47.1.1.1.1.13.1001', 'WS-C2960X-24PS-L', 'c2960x-universalk9-mz.152-6.E2.bin', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.1208', '1.3.6.1.2.1.47.1.1.1.1.13.1001', 'WS-C2960S-24PS-L', 'c2960s-universalk9-mz.152-2.E9.bin', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.1317', '1.3.6.1.2.1.47.1.1.1.1.13.1001', 'WS-C3560CG-8PC-S', 'c3560c405ex-universalk9-mz.152-2.E6.bin', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.2134', '1.3.6.1.2.1.47.1.1.1.1.13.1001', 'WS-C3560CX-12PC-S', 'c3560cx-universalk9-mz.152-6.E2.bin', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.1745', '1.3.6.1.2.1.47.1.1.1.1.13.1', 'WS-C3850-24XS-S', 'cat3k_caa-universalk9.16.06.06.SPA.bin', 'dir|show version'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.2694', '1.3.6.1.2.1.47.1.1.1.1.13.1', 'C9200L-24P-4G-E', 'cat9k_lite_iosxe.16.09.05.SPA.bin', 'dir|more flash:.installer/install_add_oper.log'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.2593', '1.3.6.1.2.1.47.1.1.1.1.13.1', 'C9500-16X', 'cat9k_iosxe.16.09.05.SPA.bin', 'dir|more flash:.installer/install_add_oper.log'),"
		."(NULL, 'iso.3.6.1.4.1.9.1.1732', '1.3.6.1.2.1.47.1.1.1.1.13.1000', 'WS-C4500X-32', 'cat4500e-universalk9.SPA.03.06.05.E.152-2.E5.bin', 'dir|show version') "
		."ON DUPLICATE KEY UPDATE id=id;");

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
	global $ciscotools_console_type,$ciscotools_backup_frequencies;

	$ciscotools_console_type = array(
		"0" => "Disabled",
		"1" => "SSH",
		"2" => "Telnet"
		);

	$ciscotools_backup_frequencies = array(
		"0" => "Disabled",
		"3600" => "Every hours",
		"86400" => "Every Day",
		"604800" => "Every Week",
		"1209600" => "Every 2 Weeks",
		"2419200" => "Every 4 Weeks"
		);

}

function ciscotools_config_form () {
	global $fields_host_edit, $ciscotools_console_type, $ciscotools_backup_frequencies;
	
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
				'max_length' => 20,
				'value' => '|arg1:login|',
				'default' => read_config_option('ciscotools_default_login'),
			);
			$fields_host_edit3['password'] = array(
				'friendly_name' => 'Password',
				'description' => 'Enter the Password for the Console Access.',
				'method' => 'textbox_password',
				'max_length' => 20,
				'value' => '|arg1:password|',
				'default' => read_config_option('ciscotools_default_password'),
			);
			$fields_host_edit3['console_type'] = array(
				'friendly_name' => 'Console Type',
				'description' => 'What Type of Console Access do we have SSH or Telnet.',
				'method' => 'drop_array',
				'value' => '|arg1:console_type|',
 			     "array" => $ciscotools_console_type,
				'default' => read_config_option('ciscotools_default_console_type'),
			);
			$fields_host_edit3['can_be_upgraded'] = array(
				'friendly_name' => 'Can it be upgraded',
				'description' => 'Enable if the device can be upgraded without human intervention.',
				'method' => 'checkbox',
				'value' => '|arg1:can_be_upgraded|',
				'default' => read_config_option('ciscotools_default_can_be_upgraded'),
			);
			$fields_host_edit3['can_be_rebooted'] = array(
				'friendly_name' => 'Can it be rebooted after upgrade of the OS',
				'description' => 'Enable if the device can be rebooted after the new OS is downloaded.',
				'method' => 'checkbox',
				'value' => '|arg1:can_be_rebooted|',
				'default' => read_config_option('ciscotools_default_can_be_rebooted'),
			);
			$fields_host_edit3['do_backup'] = array(
				'friendly_name' => 'Do we backup the config',
				'description' => 'Enable if the device need to be backuped on change.',
				'method' => 'checkbox',
				'value' => '|arg1:do_backup|',
				'default' => read_config_option('ciscotools_default_do_backup'),
			);
		}
	}
	$fields_host_edit = $fields_host_edit3;
}

function ciscotools_config_settings () {
	global $tabs, $settings, $ciscotools_console_type, $ciscotools_backup_frequencies;;

	if (isset($_SERVER['PHP_SELF']) && basename($_SERVER['PHP_SELF']) != 'settings.php')
		return;

	$tabs['Cisco Tools'] = 'Cisco Tools';
	$settings['Cisco Tools'] = array(
		"linkdiscovery_general_header" => array(
			"friendly_name" => "General",
			"method" => "spacer"
			),
		"ciscotools_default_console_type" => array(
			"friendly_name" => "Default Console Type",
			"description" => "This is default console type SSH or Telnet.",
			"method" => "drop_array",
			"array" => $ciscotools_console_type,
			'default' => '0'
			),
		"ciscotools_default_login" => array(
			"friendly_name" => "Default Login Name",
			"description" => "This is default Login name for the console access.",
			"method" => "textbox",
			"max_length" => 20,
			'default' => ''
			),
		'ciscotools_default_password' => array(
			"friendly_name" => "Default Password Name",
			"description" => "This is default Password for the console access.",
			"method" => "textbox_password",
			"max_length" => 20,
			'default' => ''
			),
		'ciscotools_default_can_be_upgraded' => array(
			'friendly_name' => 'Default Can it be upgraded',
			'description' => 'Enable if the device can be upgraded without human intervention.',
			'method' => 'checkbox',
			'default' => 'on'
			),
		'ciscotools_default_can_be_rebooted' => array(
			'friendly_name' => "Default Can it be rebooted after upgrade of the OS",
			'description' => "Enable if the device can be rebooted after the new OS is downloaded.",
			"method" => 'checkbox',
			"default" => 'off'
			),
		'ciscotools_default_do_backup' => array(
			'friendly_name' => "Default Do we backup the config",
			'description' => "Enable if the device need to be backuped on change.",
			'method' => 'checkbox',
			'default' => 'on'
			),
		'ciscotools_default_upgrade_type' => array(
			'friendly_name' => "Enable Console access instead of SNMP",
			'description' => "We use SNMP command to upload the upgrade by default, enable to use console and tftp instead.",
			'method' => 'checkbox',
			'default' => 'off'
			),
		'ciscotools_default_tftp' => array(
			'friendly_name' => 'TFTP server address',
			'description' => 'IP address of the TFTP server',
			'method' => 'textbox',
			'max_length' => 45, //Allow IPv4 & v6
			'default' => '127.0.0.1'
		),
		'ciscotools_check_backup' => array(
			'friendly_name' => 'Backup periode',
			'description' => "When did we check if we need to backup.",
			'method' => "drop_array",
			'default' => '0',
			'array' => $ciscotools_backup_frequencies,
		),
		'ciscotools_log_debug' => array(
			'friendly_name' => 'Debug Log',
			'description' => 'Enable logging of debug messages for ciscotools',
			'method' => 'checkbox',
			'default' => 'off'
		)
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
		$hostrecord_array['password'] = form_input_validate('off', 'password', '', true, 3);
	}
	
	if (isset($_POST['console_type'])) {
		$hostrecord_array['console_type'] = form_input_validate($_POST['console_type'], 'console_type', '', true, 3);
	} else {
		$hostrecord_array['console_type'] = form_input_validate('off', 'console_type', '', true, 3);
	}

	if (isset($_POST['can_be_upgraded'])) {
		$hostrecord_array['can_be_upgraded'] = form_input_validate($_POST['can_be_upgraded'], 'can_be_upgraded', '', true, 3);
	} else {
		$hostrecord_array['can_be_upgraded'] = form_input_validate('off', 'can_be_upgraded', '', true, 3);
	}
	
	if (isset($_POST['can_be_rebooted'])) {
		$hostrecord_array['can_be_rebooted'] = form_input_validate($_POST['can_be_rebooted'], 'can_be_rebooted', '', true, 3);
	} else {
		$hostrecord_array['can_be_rebooted'] = form_input_validate('off', 'can_be_rebooted', '', true, 3);
	}

	if (isset($_POST['do_backup'])) {
		$hostrecord_array['do_backup'] = form_input_validate($_POST['do_backup'], 'do_backup', '', true, 3);
	} else {
		$hostrecord_array['do_backup'] = form_input_validate('off', 'do_backup', '', true, 3);
	}

	sql_save($hostrecord_array, 'host');
	
	return $hostrecord_array;
}

function ciscotools_device_action_array($device_action_array) {
	$device_action_array['ciscotools_upgrade'] = __('Download new OS');
	$device_action_array['ciscotools_backup'] = __('Force Backup');

	return $device_action_array;
}

function ciscotools_device_action_execute($action) {
	global $config;

	if ($action != 'ciscotools_upgrade' && $action != 'ciscotools_backup') {
		return $action;
	}

	$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

	if ($selected_items != false) {
		for ($i = 0; ($i < count($selected_items)); $i++) {
			if ($action == 'ciscotools_upgrade') {
ciscotools_log("ciscotools_upgrade value: ".$selected_items[$i]);
				ciscotools_download_OS($selected_items[$i]);
			} else if($action == 'ciscotools_backup') {
				ciscotools_backup($selected_items[$i]);
			}
		}
	}
	return $action;
}

function ciscotools_device_action_prepare($save) {
        global $host_list;

        $action = $save['drp_action'];

        if ($action != 'ciscotools_upgrade' && $action != 'ciscotools_backup') {
                return $save;
        }

        if ($action == 'ciscotools_upgrade') {
			$action_description = 'Upgrade selected device';
		} else if ($action == 'ciscotools_backup') {
			$action_description = "Backup selected device.";
		}
		
		print "<tr>
			<td colspan='2' class='even'>
				<p>" . __('Click \'Continue\' to %s on these Device(s)', $action_description) . "</p>
				<p><div class='itemlist'><ul>" . $save['host_list'] . "</ul></div></p>
			</td>
		</tr>";  
}

function ciscotools_poller_bottom () {
	global $config;

	include_once($config['library_path'] . '/poller.php');
	include_once($config["library_path"] . "/database.php");

	$poller_interval = read_config_option('ciscotools_check_backup');

	if ($poller_interval == "0") {
		return;
	}

	$lp = read_config_option('ciscotools_last_poll');

	if ((time() - $lp) < $poller_interval || (time() - $lp) > $poller_interval+60){
		ciscotools_log('time: '.time().' lp: '. $lp .' poller: '. $poller_interval.' diff: '.(time() - $lp));
		return;
	}

	set_config_option('ciscotools_last_poll', time());
	
	ciscotools_log('Go time: '.time().' lp: '. $lp .' poller: '. $poller_interval.' diff: '.(time() - $lp));

	ciscotools_checkbackup();

/*
	// If its not set, just assume its in the path
	if (trim($command_string) == '')
		$command_string = 'php';
	$extra_args = ' -q ' . $config['base_path'] . '/plugins/ciscotools/backup.php';

	exec_background($command_string, $extra_args);
*/	
}

function ciscotools_show_tab () {
	global $config;

	if (api_user_realm_auth('ciscotools_tab.php') ) {
		$cp = false;
		if (get_current_page() == 'ciscotools_tab.php') {
			$cp = true;
		}
		print '<a href="' . $config['url_path'] . 'plugins/ciscotools/ciscotools_tab.php"><img src="' . $config['url_path'] . 'plugins/ciscotools/images/ciscotools' . ($cp ? '_down': '') . '.gif" alt="Cisco Tools" align="absmiddle" border="0"></a>';
	}
}

function ciscotools_draw_navigation_text ($nav) {
	global $config;

	$nav['ciscotools_tab.php:backup'] = array(
		'title' => __('Backup', 'ciscotools'),
		'mapping' => 'index.php:,ciscotools_tab.php:',
		'url' => 'ciscotools_tab.php',
		'level' => '1'
	);

	
	$nav['ciscotools_tab.php:diff'] = array(
		'title' => __('Diff', 'ciscotools'),
		'mapping' => 'index.php:,ciscotools_tab.php:',
		'url' => 'ciscotools_tab.php',
		'level' => '1'
	);

	return $nav;
}

function ciscotools_log( $text ){
    $dolog = read_config_option('ciscotools_log_debug');
    if( $dolog ){
		cacti_log( $text, false, 'CISCOTOOLS' );
	}
}

?>
