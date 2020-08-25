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

include_once($config['base_path'] . '/plugins/ciscotools/upgrade/display_upgrade.php');
include_once($config['base_path'] . '/plugins/ciscotools/display_backup.php');
include_once($config['base_path'] . '/plugins/ciscotools/display_mac.php');
include_once($config['base_path'] . '/plugins/ciscotools/backup.php');
include_once($config['base_path'] . '/plugins/ciscotools/upgrade/upgrade.php');
include_once($config['base_path'] . '/plugins/ciscotools/upgrade/upgrade_table.php');
include_once($config['base_path'] . '/plugins/ciscotools/mactrack.php');

function plugin_ciscotools_install () {
	api_plugin_register_hook('ciscotools', 'config_arrays', 'ciscotools_config_arrays', 'setup.php'); // array used by this plugin
	api_plugin_register_hook('ciscotools', 'config_settings', 'ciscotools_config_settings', 'setup.php');
	api_plugin_register_hook('ciscotools', 'config_form', 'ciscotools_config_form', 'setup.php'); // host form
	api_plugin_register_hook('ciscotools', 'api_device_new', 'ciscotools_api_device_new', 'setup.php'); // device already exist, just save value from the form
	api_plugin_register_hook('ciscotools', 'poller_bottom', 'ciscotools_poller_bottom', 'setup.php'); // check the backup on all valid device, and do backup if necessary and rentetioin validation

// Device action
    api_plugin_register_hook('ciscotools', 'device_action_array', 'ciscotools_device_action_array', 'setup.php');
    api_plugin_register_hook('ciscotools', 'device_action_execute', 'ciscotools_device_action_execute', 'setup.php');
    api_plugin_register_hook('ciscotools', 'device_action_prepare', 'ciscotools_device_action_prepare', 'setup.php');

// Cisco Tools Tab ( backup,...)
	api_plugin_register_hook('ciscotools', 'top_header_tabs', 'ciscotools_show_tab', 'setup.php'); // display when into console tab
	api_plugin_register_hook('ciscotools', 'top_graph_header_tabs', 'ciscotools_show_tab', 'setup.php'); // display when clicked tabs
	api_plugin_register_hook('ciscotools', 'draw_navigation_text', 'ciscotools_draw_navigation_text', 'setup.php'); // nav bar under console and graph tab

// Utilities
	api_plugin_register_hook('ciscotools', 'utilities_action', 'ciscotools_utilities_action', 'setup.php');
	api_plugin_register_hook('ciscotools', 'utilities_list', 'ciscotools_utilities_list', 'setup.php');

//	api_plugin_register_realm('ciscotools', 'upgrade.php', 'Plugin -> CiscoTools: Upgrade', 1);
	api_plugin_register_realm('ciscotools', 'ciscotools_tab.php,display_backup.php,mactrack.php', 'Plugin -> Cisco Tools', 1);
	
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
	
		if( $old < '1.1' ) {
/* table to keep a queue of upgrade */
			unset($data);
			$data = array();
			$data['columns'][] = array('name' => 'id', 'type' => 'mediumint(8)', 'auto_increment' => '');
			$data['columns'][] = array('name' => 'host_id', 'type' => 'mediumint(8)', 'NULL' => false, 'default' => '0');
			$data['columns'][] = array('name' => 'status', 'type' => 'tinyint(1)', 'NULL' => false, 'default' => '0');
			$data['primary'] = 'id';
			$data['keys'][] = array('name' => 'id', 'columns' => 'id');
			$data['keys'][] = array('name' => 'host_id', 'columns' => 'host_id');
			$data['type'] = 'InnoDB';
			api_plugin_db_table_create('ciscotools', 'plugin_ciscotools_upgrade', $data);

/* table to keep diff info for image */
			unset($data);
			$data = array();
			$data['columns'][] = array('name' => 'id', 'type' => 'mediumint(8)', 'auto_increment' => '');
			$data['columns'][] = array('name' => 'model', 'type' => 'varchar(64)', 'NULL' => false);
			$data['columns'][] = array('name' => 'image', 'type' => 'varchar(255)', 'NULL' => false);
			$data['columns'][] = array('name' => 'mode', 'type' => 'varchar(7)', 'NULL' => false, 'default' => 'bundle');
			$data['primary'] = 'id';
			$data['keys'][] = array('name' => 'model', 'columns' => 'model');
			$data['type'] = 'InnoDB';
			api_plugin_db_table_create('ciscotools', 'plugin_ciscotools_image', $data);
		
			/* insert values in plugin_ciscotools_image */
			db_execute("INSERT INTO plugin_ciscotools_image 
				(`model`, `image`, `mode`) VALUES 
				('IR807-LTE-GA-K9', 'ir800l-universalk9-mz.SPA.159-3.M.bin', 'bundle'),
				('C819HG-U-K9', 'c800-universalk9-mz.SPA.155-3.M8.bin', 'bundle'),
				('C819G-4G-GA-K9', 'c800-universalk9-mz.SPA.155-3.M8.bin', 'bundle'),
				('C819G-U-K9', 'c800-universalk9-mz.SPA.155-3.M8.bin', 'bundle'),
				('C819G-4G-G-K9', 'c800-universalk9-mz.SPA.155-3.M8.bin', 'bundle'),
				('CISCO881', 'c880data-universalk9-mz.155-3.M6.bin', 'bundle'),
				('CISCO891-K9', 'c890-universalk9-mz.155-3.M8.bin', 'bundle'),
				('cisco891F', 'c800-universalk9-mz.SPA.155-3.M8.bin', 'bundle'),
				('IR1101-K9', 'ir1101-universalk9.16.12.03.SPA.bin', 'bundle'),
				('CISCO3945-CHASSIS', 'c3900-universalk9-mz.SPA.155-3.M4.bin', 'bundle'),
				('IE-2000-4T-G-B', 'ie2000-universalk9-tar.152-4.EA7.tar', 'bundle'),
				('IE-2000-4TC-G-B', 'ie2000-universalk9-tar.152-4.EA7.tar', 'bundle'),
				('IE-2000-8TC-G-B', 'ie2000-universalk9-tar.152-4.EA7.tar', 'bundle'),
				('IE-2000-16PTC-G-E', 'ie2000-universalk9-tar.152-4.EA7.tar', 'bundle'),
				('IE-3000-8TC', 'ies-lanbasek9-tar.152-4.EA8.tar', 'bundle'),
				('WS-C2960X-24PS-L', 'c2960x-universalk9-mz.152-6.E2.bin', 'bundle'),
				('WS-C2960S-24PS-L', 'c2960s-universalk9-mz.152-2.E9.bin', 'bundle'),
				('WS-C3560CG-8PC-S', 'c3560c405ex-universalk9-mz.152-2.E6.bin', 'bundle'),
				('WS-C3560CX-12PC-S', 'c3560cx-universalk9-mz.152-7.E2.bin', 'bundle'),
				('WS-C3560CX-12PD-S', 'c3560cx-universalk9-mz.152-7.E2.bin', 'bundle'),
				('WS-C3850-24XS-S', 'cat3k_caa-universalk9.16.06.06.SPA.bin', 'bundle'),
				('WS-C-4500X-32', 'cat4500e-universalk9.SPA.03.06.05.E.152-2.E5.bin', 'bundle'),
				('C9200L-24P-4G-E', 'cat9k_lite_iosxe.16.09.05.SPA.bin', 'install'),
                ('C9500-16X', 'cat9k_iosxe.16.09.05.SPA.bin', 'install')"
            );
		}
		if( $old < '1.2' ) {
		}
	}
	ciscotools_setup_tables();
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

/* table to keep MAC information */
	$data = array();
	$data['columns'][] = array('name' => 'id', 'type' => 'mediumint(8)', 'auto_increment'=>'');
	$data['columns'][] = array('name' => 'host_id', 'type' => 'mediumint(8)', 'NULL' => false, 'default' => '0');
	$data['columns'][] = array('name' => 'mac_address', 'type' => 'varchar(12)', 'NULL' => false, 'default' => '0');
	$data['columns'][] = array('name' => 'ip_address', 'type' => 'varchar(20)', 'NULL' => true, 'default' => '0');
	$data['columns'][] = array('name' => 'ipv6_address', 'type' => 'varchar(32)', 'NULL' => true, 'default' => '0');
	$data['columns'][] = array('name' => 'port_index', 'type' => 'varchar(255)', 'NULL' => false);
    $data['columns'][] = array('name' => 'vlan_id', 'type' => 'varchar(4)', 'NULL' => false);
    $data['columns'][] = array('name' => 'vlan_name', 'type' => 'varchar(50)', 'NULL' => false);
    $data['columns'][] = array('name' => 'description', 'type' => 'varchar(200)', 'NULL' => false);
    $data['columns'][] = array('name' => 'date', 'type' => 'varchar(24)', 'NULL' => false);
	$data['primary'] = 'id';
	$data['keys'][] = array('name' => 'id', 'columns' => 'id');
	$data['keys'][] = array('name' => 'host_id', 'columns' => 'host_id');
	$data['keys'][] = array('name' => 'ip_address', 'columns' => 'ip_address');
	$data['keys'][] = array('name' => 'mac_address', 'columns' => 'mac_address');
	$data['keys'][] = array('name' => 'vlan_id', 'columns' => 'vlan_id');
	$data['keys'][] = array('name' => 'vlan_name', 'columns' => 'vlan_name');
	$data['keys'][] = array('name' => 'description', 'columns' => 'description');
	$data['type'] = 'InnoDB';
	$data['comment'] = 'Plugin ciscotoole - Table for MacTrack information';
	api_plugin_db_table_create('ciscotools', 'plugin_ciscotools_mactrack', $data);
	db_add_index('plugin_ciscotools_mactrack', 'UNIQUE', 'record', array('host_id','mac_address','port_index') );
	//ALTER TABLE `plugin_ciscotools_mactrack` ADD UNIQUE( `host_id`, `mac_address`, `port_index`); 
	// add mac info into the host table
	api_plugin_db_add_column ('ciscotools', 'host', array('name' => 'keep_mac_track', 'type' => 'varchar(2)', 'NULL' => true, 'default' => ''));
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
	global $ciscotools_console_type,$ciscotools_backup_frequencies, $ciscotools_retention_duration, $mactrack_poller_frequencies,
	$mactrack_data_retention;

	$ciscotools_console_type = array(
		"0" => "Disabled",
		"1" => "SSH",
		"2" => "Telnet"
		);

	$ciscotools_backup_frequencies = array(
		"0" => "Disabled",
		"1800" => "Every half hours",
		"3600" => "Every hours",
		"86400" => "Every Day",
		"604800" => "Every Week",
		"1209600" => "Every 2 Weeks",
		"2419200" => "Every 4 Weeks"
		);

	$ciscotools_retention_duration = array(
		"0" => "Disabled",
		"-1 days" => "1 day",
		"-1 months" => "1 month",
		"-6 months" => "6 months",
		"-1 years" => "1 year"
		);

	$mactrack_poller_frequencies = array(
		'disabled' => __('Disabled', 'mactrack'),
		'600'       => __('Every %d Minutes', 10, 'mactrack'),
		'900'       => __('Every %d Minutes', 15, 'mactrack'),
		'1200'       => __('Every %d Minutes', 20, 'mactrack'),
		'1800'       => __('Every %d Minutes', 30, 'mactrack'),
		'3600'       => __('Every %d Hour', 1, 'mactrack'),
		'7200'      => __('Every %d Hours', 2, 'mactrack'),
		'14400'      => __('Every %d Hours', 4, 'mactrack'),
		'28800'      => __('Every %d Hours', 8, 'mactrack'),
		'43200'      => __('Every %d Hours', 12, 'mactrack'),
		'86400'     => __('Every Day', 'mactrack')
	);

	$mactrack_data_retention = array(
		'-3 days'   => __('%d Days', 3, 'mactrack'),
		'-7 days'   => __('%d Days', 7, 'mactrack'),
		'-10 days'  => __('%d Days', 10, 'mactrack'),
		'-14 days'  => __('%d Days', 14, 'mactrack'),
		'-20 days'  => __('%d Days', 20, 'mactrack'),
		'-1 months'  => __('%d Month', 1, 'mactrack'),
		'-2 months'  => __('%d Months', 2, 'mactrack'),
		'-4 months' => __('%d Months', 4, 'mactrack'),
		'-8 months' => __('%d Months', 8, 'mactrack'),
		'-1 years' => __('%d Year', 1, 'mactrack')
	);

}

function ciscotools_config_form () {
	global $config, $fields_host_edit, $ciscotools_console_type, $ciscotools_backup_frequencies;
	
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
			$fields_host_edit3['keep_mac_track'] = array(
				'friendly_name' => 'Do we keep the mac list',
				'description' => 'Enable if we need to keep the mac information on mactrack table.',
				'method' => 'checkbox',
				'value' => '|arg1:keep_mac_track|',
				'default' => read_config_option('ciscotools_default_keep_mac_track'),
			);
		}
	}
	$fields_host_edit = $fields_host_edit3;
}

function ciscotools_config_settings () {
	global $config, $tabs, $settings, $ciscotools_console_type, $ciscotools_backup_frequencies, 
	$ciscotools_retention_duration, $mactrack_poller_frequencies, $mactrack_data_retention;

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
		'ciscotools_retention_duration' => array(
			'friendly_name' => 'Rentention Duration',
			'description' => "How long do we keep the backup.",
			'method' => "drop_array",
			'default' => '0',
			'array' => $ciscotools_retention_duration,
		),
		'ciscotools_log_debug' => array(
			'friendly_name' => 'Debug Log',
			'description' => 'Enable logging of debug messages for ciscotools',
			'method' => 'checkbox',
			'default' => 'off'
		),
		'ciscotools_mac_hdr_timing' => array(
			'friendly_name' => __('Mactrack Settings', 'mactrack'),
			'method' => 'spacer',
			),
		'ciscotools_mac_collection_timing' => array(
			'friendly_name' => __('Scanning Frequency', 'mactrack'),
			'description' => __('Choose when to collect MAC and IP Addresses and Interface statistics from your network devices.', 'mactrack'),
			'method' => 'drop_array',
			'default' => 'disabled',
			'array' => $mactrack_poller_frequencies,
			),
		'ciscotools_mac_data_retention' => array(
			'friendly_name' => __('Data Retention', 'mactrack'),
			'description' => __('How long should port MAC details be retained in the database.', 'mactrack'),
			'method' => 'drop_array',
			'default' => '2weeks',
			'array' => $mactrack_data_retention,
			),
		'ciscotools_default_keep_mac_track' => array(
			'friendly_name' => "Default Do we keep the mac list",
			'description' => "Enable if we need to keep the mac information on mactrack table.",
			'method' => 'checkbox',
			'default' => 'off',
			),
		'ciscotools_nb_mactrack_process' => array(
			'friendly_name' => "Number of mactrack process",
			'description' => "The number of processs we can start to do the mactracking function.",
			'method' => 'textbox',
			'max_length' => 5,
			'default' => '2'
		)
	);
}

function ciscotools_api_device_new($hostrecord_array) {
	global $config;

	// don't do it for disabled
	if( $hostrecord_array['disabled'] == 'on'  ) {
		return $hostrecord_array;
	}

// We need to check if it's a cisco device
	$hostrecord_array['snmp_sysDescr'] = db_fetch_cell_prepared("SELECT snmp_sysDescr
			FROM host
			WHERE id =".
			$hostrecord_array['id']);

	// don't do it for not Cisco type	
	if( mb_stripos($hostrecord_array['snmp_sysDescr'], "cisco") === false) {
		ciscotools_log('Device Type:'.$hostrecord_array['snmp_sysDescr']);
		return $hostrecord_array;
	}

	if (isset_request_var('login')) {
		$hostrecord_array['login'] = form_input_validate(get_nfilter_request_var('login'), 'login', '', true, 3);
	} else {
		$hostrecord_array['login'] = form_input_validate('', 'login', '', true, 3);
	}
	
	if (isset_request_var('password')) {
		$hostrecord_array['password'] = form_input_validate(get_nfilter_request_var('password'), 'password', '', true, 3);
	} else {
		$hostrecord_array['password'] = form_input_validate('', 'password', '', true, 3);
	}
	
	if (isset_request_var('console_type')) {
		$hostrecord_array['console_type'] = form_input_validate(get_nfilter_request_var('console_type'), 'console_type', '', true, 3);
	} else {
		$hostrecord_array['console_type'] = form_input_validate('', 'console_type', '', true, 3);
	}
// following change is present only if ON
	if (isset_request_var('can_be_upgraded')) {
		$hostrecord_array['can_be_upgraded'] = form_input_validate(get_nfilter_request_var('can_be_upgraded'), 'can_be_upgraded', '', true, 3);
	} else {
		$hostrecord_array['can_be_upgraded'] = form_input_validate('off', 'can_be_upgraded', '', true, 3);
	}
	
	if (isset_request_var('can_be_rebooted')) {
		$hostrecord_array['can_be_rebooted'] = form_input_validate(get_nfilter_request_var('can_be_rebooted'), 'can_be_rebooted', '', true, 3);
	} else {
		$hostrecord_array['can_be_rebooted'] = form_input_validate('off', 'can_be_rebooted', '', true, 3);
	}

	if (isset_request_var('do_backup')) {
		$hostrecord_array['do_backup'] = form_input_validate(get_nfilter_request_var('do_backup'), 'do_backup', '', true, 3);
	} else {
		$hostrecord_array['do_backup'] = form_input_validate('off', 'do_backup', '', true, 3);
	}
	
	if (isset_request_var('keep_mac_track')) {
		$hostrecord_array['keep_mac_track'] = form_input_validate(get_nfilter_request_var('keep_mac_track'), 'keep_mac_track', '', true, 3);
		get_mac_table($hostrecord_array);
	} else {
		$hostrecord_array['keep_mac_track'] = form_input_validate('off', 'keep_mac_track', '', true, 3);
	}

	sql_save($hostrecord_array, 'host');
	
	return $hostrecord_array;
}

function ciscotools_device_action_array($device_action_array) {
	$device_action_array['ciscotools_upgrade'] = __('Force upgrade');
	$device_action_array['ciscotools_backup'] = __('Force Backup');
	$device_action_array['ciscotools_mactrack'] = __('Force Mac Pooling');

	return $device_action_array;
}

function ciscotools_device_action_execute($action) {
	global $config;

	if ($action != 'ciscotools_upgrade' && $action != 'ciscotools_backup' && $action != 'ciscotools_mactrack') {
		return $action;
	}

	$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

	if ($selected_items != false) {
		for ($i = 0; ($i < count($selected_items)); $i++) {
			if ($action == 'ciscotools_upgrade') {
				ciscotools_upgrade_table($selected_items[$i], 'force');
			} else if($action == 'ciscotools_backup') {
				ciscotools_backup($selected_items[$i]);
			} else if($action == 'ciscotools_mactrack') {
				$hostrecord_array = db_fetch_row( 'SELECT *  FROM host where id='.$selected_items[$i] );
				get_mac_table($hostrecord_array);
			}
		}
	}
	return $action;
}

function ciscotools_device_action_prepare($save) {
        global $host_list;

        $action = $save['drp_action'];

        if ($action == 'ciscotools_upgrade') {
			$action_description = 'Upgrade selected devices';
		} else if ($action == 'ciscotools_backup') {
			$action_description = "Backup selected devices.";
		} else if ($action == 'ciscotools_mactrack') {
			$action_description = "Pool mac address on selected devices.";
		} else return $save;
		
		print "<tr>
			<td colspan='2' class='even'>
				<p>" . __('Click \'Continue\' to %s on these Device(s)', $action_description) . "</p>
				<p><div class='itemlist'><ul>" . $save['host_list'] . "</ul></div></p>
			</td>
		</tr>"; 
		
	return $save;
}

function ciscotools_poller_bottom () {
	global $config;

	include_once($config['library_path'] . '/poller.php');
	include_once($config["library_path"] . "/database.php");

	// Upgrade Poller
	$pollerIntervalUpgrade = "300"; // 60: 1 minute | 300: 5 minutes
	$lastPoller = read_config_option('ciscotools_upgrade_lastPoll'); // See when was the last poll for an upgrade
	
	if((time() - $lastPoller) <= $pollerIntervalUpgrade) {
		ciscotools_log("Upgrade: time: " . time() . " | lp: " . $lastPoller . " | poller: " . $pollerIntervalUpgrade 
		. " | diff: " . (time() - $lastPoller));
	}
	else {
		$upgradeCmdString = trim(read_config_option('path_php_binary'));
		// If its not set, just assume its in the path
		if (trim($upgradeCmdString) == '')
			$upgradeCmdString = 'php';
			$upgradeExtrArgs = ' -q ' . $config['base_path'] . '/plugins/ciscotools/upgrade_start.php';
		if(read_config_option('ciscotools_upgrade_running') != 'on' ) {
			cacti_log('Start Upgrade', false, 'CISCOTOOLS');
			set_config_option('ciscotools_upgrade_running', 'on');
			exec_background($upgradeCmdString, $upgradeExtrArgs);
		} else {
			cacti_log('Upgrade is running', false, 'CISCOTOOLS');
		}
		set_config_option('ciscotools_upgrade_lastPoll', time()); // Set the last poll for an upgrade check
	}

	// Backup Poller
	$poller_interval = read_config_option('ciscotools_check_backup');

	if ($poller_interval == "0") {
		return;
	}

	$lp = read_config_option('ciscotools_last_poll');

	if ((time() - $lp) <= $poller_interval ){
		ciscotools_log('Backup time: '.time().' lp: '. $lp .' poller: '. $poller_interval.' diff: '.(time() - $lp));
	} else {

		set_config_option('ciscotools_last_poll', time());
	
		ciscotools_log('Backup Go time: '.time().' lp: '. $lp .' poller: '. $poller_interval.' diff: '.(time() - $lp));

		// this function take too long to call it directly, we have to call it in background
		// a check has to be made to be sure not to run it twice
		$command_string = trim(read_config_option('path_php_binary'));
		
		// If its not set, just assume its in the path
		if (trim($command_string) == '')
			$command_string = 'php';
			$extra_args = ' -q ' . $config['base_path'] . '/plugins/ciscotools/check_backup.php';
		if( read_config_option('ciscotools_backup_running') != 'on' ) {
			cacti_log('Start Backup', false, 'CISCOTOOLS');
			set_config_option('ciscotools_backup_running', 'on');
			exec_background($command_string, $extra_args);
// purge poller, test is made only when we should do a backup, to avoid overload of the pooler bottom
			purge_backup();
		} else {
			cacti_log('Backup is running', false, 'CISCOTOOLS');
		}
	}
	// mactrack poller
	$pollerIntervalMac = read_config_option('ciscotools_mac_collection_timing');
	$lastMacPoller = read_config_option('ciscotools_mac_lastPoll'); // See when was the last poll for an mac update
	$mactrack_nb_process = read_config_option('ciscotools_nb_mactrack_process'); // how many process we spawn
	
	if((time() - $lastMacPoller) <= $pollerIntervalMac) {
		ciscotools_log("Mac Poller: time: " . time() . " | lp: " . $lastMacPoller . " | poller: " . $pollerIntervalMac 
		. " | diff: " . (time() - $lastMacPoller));
	}
	else {
		$macCmdString = trim(read_config_option('path_php_binary'));
		// If its not set, just assume its in the path
		if (trim($macCmdString) == '')
			$macCmdString = 'php';
		if(read_config_option('ciscotools_mac_running') != 'on' ) {
			cacti_log('Start Mac polling', false, 'CISCOTOOLS');
			set_config_option('ciscotools_mac_running', 'on');
			$process = 1;
			do {
				$macExtrArgs = ' -q ' . $config['base_path'] . '/plugins/ciscotools/pool_mac.php '.$process .' '.$mactrack_nb_process;
				exec_background($macCmdString, $macExtrArgs);
				$process++;
			} while ( $process <= $mactrack_nb_process );
			purge_mac();

		} else {
			cacti_log('Mac Check is running', false, 'CISCOTOOLS');
		}
		set_config_option('ciscotools_mac_lastPoll', time()); // Set the last poll for an mac check
	}

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

	$nav['ciscotools_tab.php'] = array(
		'title' => __('CiscoTools', 'ciscotools'),
		'mapping' => 'index.php:',
		'url' => 'ciscotools_tab.php',
		'level' => '1'
	);
	return $nav;
}
function ciscotools_utilities_list () {
	global $colors, $config;
	html_header(array("Ciscotools Plugin"), 2);
	form_alternate_row();
	?>
		<td class="textArea">
			<a href='utilities.php?action=ciscotools_retention'>Backup Retention Check</a>
		</td>
		<td class="textArea">
			Check the Retention Date of the Backup, and Purge if Necessary
		</td>
	<?php
	form_end_row();
	form_alternate_row();
	?>
		<td class="textArea">
			<a href='utilities.php?action=ciscotools_purge'>Remove all backup</a>
		</td>
		<td class="textArea">
			Remove ALL backup from DB.
		</td>
	<?php
	form_end_row();
	form_alternate_row();
	?>
		<td class="textArea">
			<a href="<?php print $config['url_path'] . 'plugins/ciscotools/'; ?>display_image.php?action=display_image">Edit the Ciscotools image table</a>
		</td>
		<td class="textArea">
			Change, add or remove an image entry on the Ciscotools Image table.
		</td>
	<?php
}

function ciscotools_utilities_action ($action) {
	if ($action == 'ciscotools_retention') {
		purge_backup();
		include_once('./include/top_header.php');
		utilities();
		include_once('./include/bottom_footer.php');
	} else if ($action == 'ciscotools_purge') {
		cacti_log( 'Remove all backup', false, 'CISCOTOOLS' );
		db_execute( 'TRUNCATE TABLE plugin_ciscotools_backup' );
		include_once('./include/top_header.php');
		utilities();
		include_once('./include/bottom_footer.php');
	}
	return $action;
}

function ciscotools_log( $text ){
    $dolog = read_config_option('ciscotools_log_debug');
    if( $dolog ){
		cacti_log( $text, false, 'CISCOTOOLS' );
	}
}

?>