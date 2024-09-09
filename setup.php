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

include_once($config['base_path'] . '/plugins/ciscotools/upgrade/upgrade_const.php');
include_once($config['base_path'] . '/plugins/ciscotools/upgrade/display_upgrade.php');
include_once($config['base_path'] . '/plugins/ciscotools/upgrade/upgrade.php');
include_once($config['base_path'] . '/plugins/ciscotools/upgrade/upgrade_table.php');
include_once($config['base_path'] . '/plugins/ciscotools/display_backup.php');
include_once($config['base_path'] . '/plugins/ciscotools/display_mac.php');
include_once($config['base_path'] . '/plugins/ciscotools/display_cli.php');
include_once($config['base_path'] . '/plugins/ciscotools/backup.php');
include_once($config['base_path'] . '/plugins/ciscotools/mactrack.php');

function plugin_ciscotools_install () {
	api_plugin_register_hook('ciscotools', 'config_arrays', 'ciscotools_config_arrays', 'setup.php'); // array used by this plugin
	api_plugin_register_hook('ciscotools', 'config_settings', 'ciscotools_config_settings', 'setup.php');
	api_plugin_register_hook('ciscotools', 'config_form', 'ciscotools_config_form', 'setup.php'); // host form
	api_plugin_register_hook('ciscotools', 'api_device_new', 'ciscotools_api_device_new', 'setup.php'); // device already exist, just save value from the form
	api_plugin_register_hook('ciscotools', 'device_remove', 'ciscotools_device_remove', 'setup.php'); // Remove device, so clean the upgrade table
	api_plugin_register_hook('ciscotools', 'poller_bottom', 'ciscotools_poller_bottom', 'setup.php'); // check the backup on all valid device, and do backup if necessary and retention validation

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

// Authentication
	api_plugin_register_realm('ciscotools', 'ciscotools_tab.php,display_mac.php', 'Plugin -> CiscoTools: Display Mac' );
	api_plugin_register_realm('ciscotools', 'display_backup.php', 'Plugin -> Cisco Tools: Display Backup');
	api_plugin_register_realm('ciscotools', 'upgrade/display_upgade.php', 'Plugin -> CiscoTools: Upgrade');
	api_plugin_register_realm('ciscotools', 'display_image.php', 'Plugin -> CiscoTools: Images');
	api_plugin_register_realm('ciscotools', 'process_cli.php,display_cli.php', 'Plugin -> CiscoTools: CLI');
	
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
	$old     = db_fetch_cell('SELECT version
		FROM plugin_config
		WHERE directory="ciscotools"');


	if ($current != $old) {
		
		// Set the new version
		db_execute("UPDATE plugin_config SET version='$current' WHERE directory='ciscotools'");
		db_execute("UPDATE plugin_config SET 
			version='" . $version['version'] . "', 
			name='"    . $version['longname'] . "', 
			author='"  . $version['author'] . "', 
			webpage='" . $version['homepage'] . "' 
			WHERE directory='" . $version['name'] . "' ");
	
		if( version_compare($old, '1.2.2', '<') ) {
// add MAC vendor information from :
/*
https://macaddress.io/database/macaddress.io-db.json

{"oui":"98:74:DA","isPrivate":false,"companyName":"Infinix mobility Ltd","companyAddress":"RMS 05-15, 13A/F SOUTH TOWER WORLD FINANCE CTR HARBOUR CITY 17 CANTON RD TST KLN HONG KONG HongKong HongKong 999077 HK","countryCode":"HK","assignmentBlockSize":"MA-L","dateCreated":"2017-02-21","dateUpdated":"2017-02-21"}
{"macPrefix":"00:00:0C","vendorName":"Cisco Systems, Inc","private":false,"blockType":"MA-L","lastUpdate":"2015/11/17"}
*/
//			$fp = fopen($config['base_path'] . '/plugins/ciscotools/macaddress.io-db.json', "r");
//			$fp = fopen('https://macaddress.io/database/macaddress.io-db.json', "r");
            $fp = fopen($config['base_path'] . '/plugins/ciscotools/mac-vendors-export.json', "r");

			if( $fp !== false ) {
				do {
					$json_data = fgets($fp);
					if( strlen($json_data) <= 1 ) continue;
					$mac_vendor = json_decode($json_data, true );
					$mac_vendor['vendorName'] = str_replace ("'", " ", $mac_vendor['vendorName']);
					$mac_vendor['macPrefix'] = str_replace (":", "", $mac_vendor['macPrefix'] ); // remove the :
					$sqlexec = "INSERT INTO plugin_ciscotools_oui (`oui`, `companyname`, `countrycode`) VALUE 
					('".$mac_vendor['macPrefix']."', '".$mac_vendor['vendorName']."', '".$mac_vendor['blockType']."')
					ON DUPLICATE KEY UPDATE 
					oui='".$mac_vendor['macPrefix']."',
					companyname='".$mac_vendor['vendorName']."',
					countrycode='".$mac_vendor['blockType']."'";

					db_execute($sqlexec);
				} while( !feof($fp) );
			}
		}
		if( version_compare($old, '1.2.3', '<') ) {
			api_plugin_remove_realms('ciscotools');
			api_plugin_register_realm('ciscotools', 'ciscotools_tab.php,display_mac.php', 'Plugin -> CiscoTools: Display Mac', 1);
			api_plugin_register_realm('ciscotools', 'display_backup.php', 'Plugin -> CiscoTools: Display Backup', 1);
			api_plugin_register_realm('ciscotools', 'upgrade/display_upgade.php', 'Plugin -> CiscoTools: Upgrade', 1);
			api_plugin_register_realm('ciscotools', 'display_image.php', 'Plugin -> CiscoTools: Images', 1);
		}

		if( version_compare($old, '1.2.7', '<') ) {
			// empty table of upgrade status, strut change to mutch to keep it
			db_execute( "TRUNCATE TABLE plugin_ciscotools_upgrade");
			// empty table of image, strut change to mutch to keep it
			db_execute( "TRUNCATE TABLE plugin_ciscotools_image");
			// remove row of model, taken from extenddb
			db_execute( "ALTER TABLE plugin_ciscotools_image DROP COLUMN IF EXISTS model;");
			// add the model_id that match the id of the model on the extenddb table
			api_plugin_db_add_column ('ciscotools', 'plugin_ciscotools_image', array('name' => 'model_id', 'type' => 'mediumint(8)', 'NULL' => false,  'default' => 0));
			// add command to check the current version running on the device
			api_plugin_db_add_column ('ciscotools', 'plugin_ciscotools_image', array('name' => 'command', 'type' => 'varchar(255)', 'NULL' => false,  'default' => ''));
			// add the regex to extract the exact version from the current running command
			api_plugin_db_add_column ('ciscotools', 'plugin_ciscotools_image', array('name' => 'regex', 'type' => 'varchar(255)', 'NULL' => false,  'default' => ''));

			// add new row on upgrade table, to keep the name of the actual image
			api_plugin_db_add_column ('ciscotools', 'plugin_ciscotools_upgrade', array('name' => 'image', 'type' => 'varchar(255)', 'NULL' => true,  'default' => ''));			
		}
		if( version_compare($old, '1.2.10', '<') ) {
			// add new row on upgrade table, to keep the size of the new image
			api_plugin_db_add_column ('ciscotools', 'plugin_ciscotools_image', array('name' => 'size', 'type' => 'varchar(255)', 'NULL' => false,  'default' => ''));			
		}
		if( version_compare($old, '1.2.11', '<') ) {
			db_execute("DELETE FROM settings WHERE name = 'ciscotools_default_upgrade_type'");
		}
		if( version_compare($old, '1.3.0', '<') ) {
			api_plugin_register_realm('ciscotools', 'display_cli.php', 'Plugin -> CiscoTools: CLI');
		}
		if( version_compare($old, '1.4.1', '<') ) {
			db_execute( "ALTER TABLE `plugin_ciscotools_oui` CHANGE `oui` `oui` VARCHAR(12)"); 
		}
		if( version_compare( $old, '1.4.2', '<') )  {
			api_plugin_db_add_column ('ciscotools', 'plugin_ciscotools_mactrack', array('name' => 'dhcp', 'type' => 'varchar(255)', 'NULL' => true,  'default' => ''));
		}
		if( version_compare($old, '1.4.6', '<') ) {
			db_execute( "ALTER TABLE `plugin_ciscotools_backup` CHANGE `diff` `diff` MEDIUMTEXT "); 
		}
	} else {
		// load the table
		ciscotools_setup_tables();
	}
	
	// if mac running is on change for a number to know how many process are running
	if( read_config_option('ciscotools_mac_running') == 'off' ) set_config_option('ciscotools_mac_running', '0');
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

/* table to keep diff information for backup*/
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
	$data['columns'][] = array('name' => 'id', 'type' => 'bigint(20)', 'auto_increment'=>'');
	$data['columns'][] = array('name' => 'host_id', 'type' => 'mediumint(8)', 'NULL' => false, 'default' => '0');
	$data['columns'][] = array('name' => 'mac_address', 'type' => 'varchar(12)', 'NULL' => false, 'default' => '0');
	$data['columns'][] = array('name' => 'ip_address', 'type' => 'varchar(20)', 'NULL' => true, 'default' => '0');
	$data['columns'][] = array('name' => 'ipv6_address', 'type' => 'varchar(32)', 'NULL' => true, 'default' => '0');
	$data['columns'][] = array('name' => 'port_index', 'type' => 'varchar(255)', 'NULL' => false);
    $data['columns'][] = array('name' => 'vlan_id', 'type' => 'varchar(4)', 'NULL' => false);
    $data['columns'][] = array('name' => 'vlan_name', 'type' => 'varchar(50)', 'NULL' => false);
    $data['columns'][] = array('name' => 'description', 'type' => 'varchar(200)', 'NULL' => false);
    $data['columns'][] = array('name' => 'date', 'type' => 'varchar(24)', 'NULL' => false);
    $data['columns'][] = array('name' => 'dhcp', 'type' => 'varchar(255)', 'NULL' => false);
	$data['primary'] = 'id';
	$data['keys'][] = array('name' => 'id', 'columns' => 'id');
	$data['keys'][] = array('name' => 'host_id', 'columns' => 'host_id');
	$data['keys'][] = array('name' => 'ip_address', 'columns' => 'ip_address');
	$data['keys'][] = array('name' => 'mac_address', 'columns' => 'mac_address');
	$data['keys'][] = array('name' => 'vlan_id', 'columns' => 'vlan_id');
	$data['keys'][] = array('name' => 'vlan_name', 'columns' => 'vlan_name');
	$data['keys'][] = array('name' => 'description', 'columns' => 'description');
	$data['type'] = 'InnoDB';
	$data['comment'] = 'Plugin ciscotools - Table for MacTrack information';
	api_plugin_db_table_create('ciscotools', 'plugin_ciscotools_mactrack', $data);

	// create unique record for host/mac on port
	$mysql = "ALTER TABLE `plugin_ciscotools_mactrack` DROP INDEX `record`;";
	$ret = db_execute($mysql);
	$mysql = "ALTER TABLE `plugin_ciscotools_mactrack` ADD UNIQUE `record` ( `host_id`, `mac_address`, `port_index`)";
	$ret = db_execute($mysql);

	// add mac info into the host table
	api_plugin_db_add_column ('ciscotools', 'host', array('name' => 'keep_mac_track', 'type' => 'varchar(2)', 'NULL' => true, 'default' => ''));
	
/* table to keep OID information */
/*
{"oui":"98:74:DA","isPrivate":false,"companyName":"Infinix mobility Ltd","companyAddress":"RMS 05-15, 13A/F SOUTH TOWER WORLD FINANCE CTR HARBOUR CITY 17 CANTON RD TST KLN HONG KONG HongKong HongKong 999077 HK","countryCode":"HK","assignmentBlockSize":"MA-L","dateCreated":"2017-02-21","dateUpdated":"2017-02-21"}
*/
	$data = array();
	$data['columns'][] = array('name' => 'id', 'type' => 'mediumint(8)', 'auto_increment'=>'');
	$data['columns'][] = array('name' => 'oui', 'type' => 'varchar(12)', 'NULL' => false, 'unique_keys' =>'' );
	$data['columns'][] = array('name' => 'companyname', 'type' => 'varchar(255)', 'NULL' => false );
	$data['columns'][] = array('name' => 'countryCode', 'type' => 'varchar(4)', 'NULL' => false);
	$data['primary'] = 'id';
	$data['keys'][] = array('name' => 'id', 'columns' => 'id');
	$data['unique_keys'][] = array('name' => 'oui', 'columns' => 'oui' );
	$data['type'] = 'InnoDB';
	$data['comment'] = 'Plugin ciscotoole - Table for OUI MAC information';
	api_plugin_db_table_create('ciscotools', 'plugin_ciscotools_oui', $data);

	/* table to keep a queue of upgrade */
	unset($data);
	$data = array();
	$data['columns'][] = array('name' => 'id', 'type' => 'mediumint(8)', 'auto_increment' => '');
	$data['columns'][] = array('name' => 'host_id', 'type' => 'mediumint(8)', 'NULL' => false, 'default' => '0');
	$data['columns'][] = array('name' => 'status', 'type' => 'tinyint(1)', 'NULL' => false, 'default' => '0');
	$data['columns'][] = array('name' => 'image', 'type' => 'varchar(255)', 'NULL' => false, 'default' => '');
	$data['primary'] = 'id';
	$data['keys'][] = array('name' => 'id', 'columns' => 'id');
	$data['keys'][] = array('name' => 'host_id', 'columns' => 'host_id');
	$data['type'] = 'InnoDB';
	api_plugin_db_table_create('ciscotools', 'plugin_ciscotools_upgrade', $data);

	/* table to keep info for image */
    unset($data);
    $data = array();
    $data['columns'][] = array('name' => 'id', 'type' => 'mediumint(8)', 'auto_increment' => '');
    $data['columns'][] = array('name' => 'model_id', 'type' => 'mediumint(8)', 'NULL' => false);
    $data['columns'][] = array('name' => 'image', 'type' => 'varchar(255)', 'NULL' => false);
    $data['columns'][] = array('name' => 'mode', 'type' => 'varchar(7)', 'NULL' => false, 'default' => 'bundle');
    $data['columns'][] = array('name' => 'command', 'type' => 'varchar(255)', 'NULL' => false, 'default' => '');
    $data['columns'][] = array('name' => 'regex', 'type' => 'varchar(255)', 'NULL' => false, 'default' => '');
    $data['columns'][] = array('name' => 'size', 'type' => 'varchar(255)', 'NULL' => false, 'default' => '');
    $data['primary'] = 'id';
    $data['type'] = 'InnoDB';
    api_plugin_db_table_create('ciscotools', 'plugin_ciscotools_image', $data);

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
	global $ciscotools_console_type,$ciscotools_backup_frequencies, $ciscotools_retention_duration, $mactrack_poller_frequencies, $ciscotools_upgrade_frequencies, $ciscotools_backup_transfert_type,
	$mactrack_data_retention, $statusText, $statusColor;

	$ciscotools_console_type = array(
		"0" => "Disabled",
		"1" => "SSH",
		"2" => "Telnet"
		);

	$ciscotools_upgrade_frequencies = array(
		"0" => "Disabled",
		"600" => "Every 10 minutes",
		"1800" => "Every half hours",
		"3600" => "Every hours",
		"86400" => "Every Day",
		"604800" => "Every Week",
		"1209600" => "Every 2 Weeks",
		"2419200" => "Every 4 Weeks"
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
	
	$ciscotools_backup_transfert_type = array(
		"0" => "Disabled",
		"1" => "FTP",
		"2" => "SFTP",
		"3" => "SCP"
		);
}

function ciscotools_config_form () {
	global $config, $fields_host_edit, $ciscotools_console_type, $ciscotools_backup_frequencies, $ciscotools_upgrade_frequencies;
	
	$fields_host_edit2 = $fields_host_edit;
	$fields_host_edit3 = array();
	foreach ($fields_host_edit2 as $f => $a) {
		$fields_host_edit3[$f] = $a;
		if ($f == 'isWifi') {
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
	global $config, $tabs, $settings, $ciscotools_console_type, $ciscotools_backup_frequencies,  $ciscotools_upgrade_frequencies, 
	$ciscotools_retention_duration, $mactrack_poller_frequencies, $mactrack_data_retention, $ciscotools_backup_transfert_type;

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
		"ciscotools_upg_start_time" => array(
			"friendly_name" => "Upgrade Start Time",
			"description" => "When Cacti can start the upgrade process.",
			"method" => "textbox",
			"max_length" => 10,
			'default' => '06:00pm'
			),
		"ciscotools_upg_end_time" => array(
			"friendly_name" => "Upgrade End Time",
			"description" => "When Cacti should end the upgrade process.",
			"method" => "textbox",
			"max_length" => 10,
			'default' => '06:00am'
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
		'ciscotools_check_backup' => array(
			'friendly_name' => 'Backup periode',
			'description' => "When did we check if we need to backup.",
			'method' => "drop_array",
			'default' => '0',
			'array' => $ciscotools_backup_frequencies,
		),
		'ciscotools_upgrade_check_periode' => array(
			'friendly_name' => "Upgrade check periode",
			'description' => "How often do we check for the upgrade process.",
			'method' => "drop_array",
			'default' => '0',
			'array' => $ciscotools_upgrade_frequencies,
			),
		'ciscotools_default_tftp' => array(
			'friendly_name' => 'TFTP server address',
			'description' => 'IP address of the TFTP server',
			'method' => 'textbox',
			'max_length' => 45, //Allow IPv4 & v6
			'default' => '127.0.0.1'
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
		'ciscotools_backup_log_debug' => array(
			'friendly_name' => 'Debug Backup Log',
			'description' => 'Enable logging of debug messages for Backup call',
			'method' => 'checkbox',
			'default' => 'off'
		),
		'ciscotools_upgrade_log_debug' => array(
			'friendly_name' => 'Debug Upgrade Log',
			'description' => 'Enable logging of debug messages for Upgrade process',
			'method' => 'checkbox',
			'default' => 'off'
		),
		'ciscotools_backup_hdr' => array(
			'friendly_name' => __('Backup Export Settings', 'ciscotools'),
			'method' => 'spacer',
			),
		"ciscotools_backup_transfert_type" => array(
			"friendly_name" => "Backup Transfert Type",
			"description" => "This is transfert mode to backup the config of the devices.",
			"method" => "drop_array",
			"array" => $ciscotools_backup_transfert_type,
			'default' => '0'
			),
		"ciscotools_backup_server" => array(
			"friendly_name" => "Backup Server Name or IP",
			"description" => "This is Server Name or IP for the remote Backup.",
			"method" => "textbox",
			"max_length" => 50,
			'default' => ''
			),
		"ciscotools_backup_login" => array(
			"friendly_name" => "Backup Login Name",
			"description" => "This is Login Name for the Backup.",
			"method" => "textbox",
			"max_length" => 50,
			'default' => ''
			),
		'ciscotools_backup_password' => array(
			"friendly_name" => "Backup Password Name",
			"description" => "This is Password for the Backup.",
			"method" => "textbox_password",
			"max_length" => 50,
			'default' => ''
			),
		'ciscotools_remote_path_backup' => array(
			'friendly_name' => 'Remote Backup Path',
			'description' => "Remote Directory of the Backup, if present local backup is done.",
			'method' => 'textbox',
			'max_length' => 100,
			'default' => ''
		),
		'ciscotools_path_export_backup' => array(
			'friendly_name' => 'Local Export Path',
			'description' => "Where can we export Localy the backup.",
			'method' => 'textbox',
			'max_length' => 100,
			'default' => $config['base_path'] . '/plugins/ciscotools/export_backup' // path on ciscotools plugin
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
			'default' => 'on',
			),
		'ciscotools_nb_mactrack_process' => array(
			'friendly_name' => "Number of mactrack process",
			'description' => "The number of processs we can start to do the mactracking function.",
			'method' => 'textbox',
			'max_length' => 5,
			'default' => '2'
			),
		'ciscotools_mactrack_log_debug' => array(
			'friendly_name' => 'Debug Log',
			'description' => 'Enable logging of debug messages for mactrack',
			'method' => 'checkbox',
			'default' => 'off'
		),
	);
}

function ciscotools_device_remove($host_id) {
	global $config;
ciscotools_log('ciscotools_device_remove Start remove: '.print_r($host_id, true) );
	foreach( $host_id as $hostid ) {
		$sqlQuery = "DELETE FROM plugin_ciscotools_upgrade WHERE plugin_ciscotools_upgrade.host_id =$hostid";
		$sqlExec = db_execute($sqlQuery);
		$sqlQuery = "DELETE FROM plugin_ciscotools_backup WHERE plugin_ciscotools_backup.host_id =$hostid";
		$sqlExec = db_execute($sqlQuery);
		$sqlQuery = "DELETE FROM plugin_ciscotools_mactrack WHERE plugin_ciscotools_mactrack.host_id =$hostid";
		$sqlExec = db_execute($sqlQuery);
	}

	return $host_id;
}

function ciscotools_api_device_new($hostrecord_array) {
	global $config;

	// don't do it for disabled
	if( !array_key_exists('disabled', $hostrecord_array ) || !array_key_exists('id', $hostrecord_array) ) {
ciscotools_log('ciscotools_api_device_new Not valid call: '. print_r($hostrecord_array, true) );
		return $hostrecord_array;
	}

	if( $hostrecord_array['disabled'] == 'on'  ) {
		return $hostrecord_array;
	}

ciscotools_log('ciscotools_api_device_new Enter Ciscotools: '.$hostrecord_array['description'].'('.$hostrecord_array['id'].')');

// We need to check if it's a cisco device
// that mean no backup when just discovered
	$hostrecord_array['snmp_sysDescr'] = db_fetch_cell_prepared("SELECT snmp_sysDescr
			FROM host
			WHERE id =".
			$hostrecord_array['id']);

	// don't do it for not Cisco type	
	if( mb_stripos($hostrecord_array['snmp_sysDescr'], "cisco") === false) {
ciscotools_log('ciscotools_api_device_newDevice Type:'.$hostrecord_array['snmp_sysDescr']);
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

ciscotools_log('ciscotools_api_device_new sql save: '.print_r($hostrecord_array, true));
 	sql_save($hostrecord_array, 'host');
// check the device to see it's status
	ciscotools_upgrade_device_check($hostrecord_array['id']);
// Do a backup, we supose something change
	ciscotools_backup($hostrecord_array['id']);
	
ciscotools_log('ciscotools_api_device_new End Ciscotools' );
	
	return $hostrecord_array;
}

function ciscotools_device_action_array($device_action_array) {
	$device_action_array['ciscotools_upgrade'] = __('Force upgrade');
	$device_action_array['ciscotools_backup'] = __('Force Backup');
	$device_action_array['ciscotools_export_backup'] = __('Export Backup');
	$device_action_array['ciscotools_mactrack'] = __('Force Mac Pooling');
	$device_action_array['ciscotools_check_upg'] = __('Check upgrade');

	return $device_action_array;
}

function ciscotools_device_action_execute($action) {
	global $config;

	if ($action != 'ciscotools_upgrade' && $action != 'ciscotools_backup' && $action != 'ciscotools_export_backup' && $action != 'ciscotools_mactrack' && $action != 'ciscotools_check_upg') {
		return $action;
	}

	$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

	if ($selected_items != false) {
backup_log( 'ciscotools_device_action_execute list: '.print_r($selected_items, true));
		foreach($selected_items as $device ) {
backup_log( 'ciscotools_device_action_execute on: '.$device);
			if ($action == 'ciscotools_upgrade') {
				ciscotools_upgrade_table($device, 'force');
			} else if($action == 'ciscotools_backup') {
backup_log( 'ciscotools_device_action_execute start ciscotools_backup:'.print_r($action,true));
				ciscotools_backup($device);
backup_log( 'ciscotools_device_action_execute end ciscotools_backup:'.print_r($action,true));
			} else if($action == 'ciscotools_export_backup') {
				ciscotools_export_backup($device);
			} else if($action == 'ciscotools_check_upg') {
				ciscotools_upgrade_device_check($device);
			} else if($action == 'ciscotools_mactrack') {
				$hostrecord_array = db_fetch_row( 'SELECT * FROM host where id='.$device );
				get_mac_table($hostrecord_array);
			}
		}
backup_log( 'ciscotools_device_action_execute end');
	}
	return $action;
}

function ciscotools_device_action_prepare($save) {
        global $host_list;

        $action = $save['drp_action'];

        if ($action == 'ciscotools_upgrade') {
			$action_description = 'Upgrade selected devices';
		} else if ($action == 'ciscotools_backup') {
			$action_description = "Backup on selected devices.";
		} else if ($action == 'ciscotools_export_backup') {
			$action_description = "Export Backup on selected devices.";
		} else if ($action == 'ciscotools_check_upg') {
			$action_description = "Check upgrade on selected devices.";
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

//*************** Upgrade Poller
	$pollerIntervalUpgrade =  read_config_option('ciscotools_upgrade_check_periode');
	$lastPoller = read_config_option('ciscotools_upgrade_lastPoll'); // See when was the last poll for an upgrade

	// Query to check if devices are in queue, and need upgrade processing
	$sqlQuery = "SELECT count(id) FROM plugin_ciscotools_upgrade WHERE status IN ('"
				."','".UPGRADE_STATUS_PENDING
				."','".UPGRADE_STATUS_CHECKING
				."','".UPGRADE_STATUS_UPLOADING
				."','".UPGRADE_STATUS_ACTIVATING
				."','".UPGRADE_STATUS_REBOOTING
				."','".UPGRADE_STATUS_FORCE_REBOOT_COMMIT
				."','".UPGRADE_STATUS_NEED_RECHECK
				."')";
	$queryQueue = db_fetch_cell_prepared($sqlQuery);
ciscotools_log('UPG: ciscotools_poller_bottom Nb device to upgrade: '.$queryQueue);

	if( $queryQueue > 0 ) {
		$upgradeCmdString = trim(read_config_option('path_php_binary'));
		// If its not set, just assume its in the path
		if (trim($upgradeCmdString) == '')
			$upgradeCmdString = 'php';
			$upgradeExtrArgs = ' -q ' . $config['base_path'] . '/plugins/ciscotools/upgrade_start.php';
		if(read_config_option('ciscotools_upgrade_running') != 'on' ) {
			set_config_option('ciscotools_upgrade_lastPoll', time()); // Set the last poll for an upgrade check
			ciscotools_log('UPG: ciscotools_poller_bottom Start Upgrade' );
			set_config_option('ciscotools_upgrade_running', 'on');
			exec_background($upgradeCmdString, $upgradeExtrArgs);
		} else {
			ciscotools_log('UPG: ciscotools_poller_bottom Upgrade is running');
		}
	}

//*************** Backup Poller
	$poller_interval = read_config_option('ciscotools_check_backup');

	if ($poller_interval != "0") {
		$lp = read_config_option('ciscotools_last_poll');
	
		if ((time() - $lp) <= $poller_interval ){
backup_log('ciscotools_poller_bottom Backup time: '.time().' lp: '. $lp .' poller: '. $poller_interval.' diff: '.(time() - $lp));
		} else {
	
			set_config_option('ciscotools_last_poll', time());
		
backup_log('ciscotools_poller_bottom Backup Go time: '.time().' lp: '. $lp .' poller: '. $poller_interval.' diff: '.(time() - $lp));
	
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
				cacti_log('Backup is running ', false, 'CISCOTOOLS');
			}
		}
	}
	
//*************** mactrack poller
	$pollerIntervalMac = read_config_option('ciscotools_mac_collection_timing');
	$lastMacPoller = read_config_option('ciscotools_mac_lastPoll'); // See when was the last poll for an mac update
	$mactrack_nb_process = read_config_option('ciscotools_nb_mactrack_process'); // how many process we spawn

	if($pollerIntervalMac == 'disabled') {
		cacti_log('ciscotools_poller_bottom interval: '.$pollerIntervalMac, TRUE, 'MACTRACK');
		return;
	}		

	if((time() - $lastMacPoller) <= $pollerIntervalMac) {
mactrack_log("ciscotools_poller_bottom Mac Poller: time: " . time() . " | lp: " . $lastMacPoller . " | poller: " . $pollerIntervalMac 
		. " | diff: " . (time() - $lastMacPoller));
	}
	else {
		$running_process = read_config_option('ciscotools_mac_running'); // check the number of proccess currently running
		if( $running_process == 0 ) {
			mactrack_log('ciscotools_poller_bottom Start Mac Check polling');
			$macCmdString = trim(read_config_option('path_php_binary'));
			// If its not set, just assume its in the path
			if (trim($macCmdString) == '')
				$macCmdString = 'php';
			$process = 1;
			do {
				$macExtrArgs = ' -q ' . $config['base_path'] . '/plugins/ciscotools/pool_mac.php '.$process .' '.$mactrack_nb_process;
				exec_background($macCmdString, $macExtrArgs);
				set_config_option('ciscotools_mac_running', $process );
				$process++;
			} while ( $process <= $mactrack_nb_process );
			purge_mac();

		} else {
			mactrack_log('ciscotools_poller_bottom Mac Check is running '.date( 'Y-m-d H:m:s', $lastMacPoller).' process: '.$running_process);
		}
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
			<a href='utilities.php?action=ciscotools_force_backup'>Force backup</a>
		</td>
		<td class="textArea">
			Force a backup on all devices.
		</td>
	<?php
	form_end_row();
	form_alternate_row();
	?>
		<td class="textArea">
			<a href='utilities.php?action=ciscotools_export_backup'>Export backup</a>
		</td>
		<td class="textArea">
			Export the Last Backup of All Device.
		</td>
	<?php
	form_end_row();
	form_alternate_row();
	?>
		<td class="textArea">
			<a href='utilities.php?action=ciscotools_add_oui'>Ciscotools import OUI file</a>
		</td>
		<td class="textArea">
			Import OUI file into DB, OUI has to be: macaddress.io-db.json on ciscotools directory
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
	form_end_row();
		form_alternate_row();
	?>
		<td class="textArea">
			<a href='utilities.php?action=ciscotools_check_upg'>Ciscotools check upgrade</a>
		</td>
		<td class="textArea">
			Check the devices to know if they have to be upgraded.
		</td>
	<?php
	form_end_row();
	form_alternate_row();
	?>
		<td class="textArea">
			<a href='utilities.php?action=ciscotools_force_mac'>Force MAC polling</a>
		</td>
		<td class="textArea">
			Force the MAC pooling on all device
		</td>
	<?php
	form_end_row();
	form_alternate_row();
	?>
		<td class="textArea">
			<a href='utilities.php?action=ciscotools_clear'>Clear all CiscoTools process</a>
		</td>
		<td class="textArea">
			Clear mactrack, backup and upgrad process if not running.
		</td>
	<?php
	form_end_row();

}

function ciscotools_utilities_action ($action) {
	global $config;
	
	if ($action == 'ciscotools_retention') {
		purge_backup();
		include_once('./include/top_header.php');
		utilities();
		include_once('./include/bottom_footer.php');
	}  else if ($action == 'ciscotools_force_backup') {
		cacti_log( 'force all backup', false, 'CISCOTOOLS' );
		force_backup();
		include_once('./include/top_header.php');
		utilities();
		include_once('./include/bottom_footer.php');
	}  else if ($action == 'ciscotools_export_backup') {
		cacti_log( 'Export all backup', false, 'CISCOTOOLS' );
		force_export_backup();
		include_once('./include/top_header.php');
		utilities();
		include_once('./include/bottom_footer.php');
	} else if ($action == 'ciscotools_purge') {
		cacti_log( 'Remove all backup', false, 'CISCOTOOLS' );
		db_execute( 'TRUNCATE TABLE plugin_ciscotools_backup' );
		include_once('./include/top_header.php');
		utilities();
		include_once('./include/bottom_footer.php');
	} else if ( $action == 'ciscotools_add_oui' ) {
		download_Mac_OUI();
		process_MAC_OUI();
		include_once('./include/top_header.php');
		utilities();
		include_once('./include/bottom_footer.php');
	} else if ($action == 'ciscotools_check_upg') {
		cacti_log( 'Check devices for upgrade', false, 'CISCOTOOLS' );
		ciscotools_upgrade_device_check();
		include_once('./include/top_header.php');
		utilities();
		include_once('./include/bottom_footer.php');
	} else if ($action == 'ciscotools_force_mac') {
		cacti_log( 'Force MAC polling', false, 'CISCOTOOLS' );

		$mactrack_nb_process = read_config_option('ciscotools_nb_mactrack_process'); // how many process we spawn

		$macCmdString = trim(read_config_option('path_php_binary'));
		// If its not set, just assume its in the path
		if (trim($macCmdString) == '')
			$macCmdString = 'php';
		set_config_option('ciscotools_mac_running', $mactrack_nb_process );
		$process = 1;
		do {
			$macExtrArgs = ' -q ' . $config['base_path'] . '/plugins/ciscotools/pool_mac.php '.$process .' '.$mactrack_nb_process;
			exec_background($macCmdString, $macExtrArgs);
			$process++;
		} while ( $process <= $mactrack_nb_process );
		purge_mac();
		include_once('./include/top_header.php');
		utilities();
		include_once('./include/bottom_footer.php');
	}  else if ($action == 'ciscotools_clear') {
		cacti_log( 'force clear all non running process', false, 'CISCOTOOLS' );
		force_clear();
		include_once('./include/top_header.php');
		utilities();
		include_once('./include/bottom_footer.php');
	}
	return $action;
}

function force_backup() {
	global $config;
	set_config_option('ciscotools_last_poll', time());
	
	// this function take too long to call it directly, we have to call it in background
	// a check has to be made to be sure not to run it twice
	$command_string = trim(read_config_option('path_php_binary'));
	
	// If its not set, just assume its in the path
	if (trim($command_string) == '') {
		$command_string = 'php';
	}
	$extra_args = ' -q ' . $config['base_path'] . '/plugins/ciscotools/check_backup.php';
backup_log('force_backup running: '.read_config_option('ciscotools_backup_running'));
//		set_config_option('ciscotools_backup_running', 'off');
backup_log('force_backup running: '.read_config_option('ciscotools_backup_running'));
	if( read_config_option('ciscotools_backup_running') != 'on' ) {
		cacti_log('Start Backup', false, 'CISCOTOOLS');
		set_config_option('ciscotools_backup_running', 'on');
		exec_background($command_string, $extra_args);
	}
}

function force_export_backup() {
	global $config;
	
	// this function take too long to call it directly, we have to call it in background
	// a check has to be made to be sure not to run it twice
	$command_string = trim(read_config_option('path_php_binary'));
	
	// If its not set, just assume its in the path
	if (trim($command_string) == '') {
		$command_string = 'php';
	}
	$extra_args = ' -q ' . $config['base_path'] . '/plugins/ciscotools/export_backup.php';
ciscotools_log('BCK: force_export_backup running: '.read_config_option('ciscotools_export_backup_running'));
	if( read_config_option('ciscotools_backup_running') != 'on' ) {
		cacti_log('Start Export Backup', false, 'CISCOTOOLS');
		set_config_option('ciscotools_export_backup_running', 'on');
		exec_background($command_string, $extra_args);
	}
}

// download a inline OUI mac database for processing
function download_Mac_OUI() {
	global $config;
	
	// https://devtools360.com/en/macaddress/vendorMacs.xml?download=true
	// https://standards-oui.ieee.org/oui/oui.csv
	// https://gitlab.com/wireshark/wireshark/-/raw/master/manuf
	$url = 'https://gitlab.com/wireshark/wireshark/-/raw/master/manuf';
	$handle = curl_init();
	
	curl_setopt( $handle, CURLOPT_URL, $url );
	curl_setopt( $handle, CURLOPT_POST, false );
	curl_setopt( $handle, CURLOPT_HEADER, true );
	curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, true );
	curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, false );
	curl_setopt ($handle, CURLOPT_SSLVERSION, 6);
	curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, true );
	curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
//	curl_setopt( $handle, CURLOPT_HTTPHEADER, array( 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:100.0) Gecko/20100101 Firefox/100.0','Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8') );

	$response = curl_exec($handle);
	$error = curl_error($handle);
	$result = array( 'header' => '',
                     'body' => '',
                     'curl_error' => '',
                     'http_code' => '',
                     'last_url' => '');

    $header_size = curl_getinfo($handle,CURLINFO_HEADER_SIZE);
    $result['header'] = substr($response, 0, $header_size);
    $result['body'] = substr( $response, $header_size );
    $result['http_code'] = curl_getinfo($handle,CURLINFO_HTTP_CODE);
    $result['last_url'] = curl_getinfo($handle,CURLINFO_EFFECTIVE_URL);
	
mactrack_log( "download_Mac_OUI: result URL: ". print_r( $result, true ) );
mactrack_log( "download_Mac_OUI: response URL: ". print_r( $response, true ) );
mactrack_log( "download_Mac_OUI: error URL: ". print_r( $error, true ) );

    if ( $result['http_code'] > "299" ) {
		mactrack_log( "download_Mac_OUI: Mac OUI download URL: ". $url );
        $result['curl_error'] = $error;
		mactrack_log( "download_Mac_OUI error:  error: ". print_r($result, true)  );
    }  elseif ( $result['http_code'] == 0 ) {
		mactrack_log( "download_Mac_OUI: Mac OUI download URL: ". $url );
        $result['curl_error'] = $error;
		mactrack_log( "download_Mac_OUI error:  error: ". print_r($result, true)  );
    } else {
		mactrack_log( "download_Mac_OUI ok: ". $result['http_code'] );
		file_put_contents($config['base_path'] . '/plugins/ciscotools/MAC_OUI.txt', $result['body'] );
	}
   
	curl_close($handle);
}

function process_MAC_OUI() {
	global $config;
//		$fp = fopen($config['base_path'] . '/plugins/ciscotools/macaddress.io-db.json', "r");
//        $fp = fopen($config['base_path'] . '/plugins/ciscotools/mac-vendors-export.json', "r");
     $fp = fopen($config['base_path'] . '/plugins/ciscotools/MAC_OUI.txt', "r");
	if( $fp !== false ) {
		$regex = '/([0-9A-Z:]{8,})(?:[\/0-9]){0,3}\s+([0-9a-zA-Z]{1,})/m';

		do {
			$json_data = fgets($fp);
//mactrack_log("import MAC json_data: " . print_r($json_data, true));
			if( $json_data === false ) continue;
			if( $json_data[0] == '#' || $json_data[0] == ' ' ) continue;
			$ret = preg_match_all($regex, $json_data, $match );
			if( !$ret ) continue;

//mactrack_log("import MAC match: " . print_r($match, true));
			$mac_vendor['VendorName'] = str_replace ("'", " ", $match[2][0]);
			$mac_vendor['oui'] = str_replace (":", "", $match[1][0] ); // remove the :
//mactrack_log("import MAC mac_vendor: " . print_r($mac_vendor, true));
			$sqlexec = "INSERT INTO plugin_ciscotools_oui (`oui`, `companyname`) VALUE 
			('".$mac_vendor['oui']."', '".$mac_vendor['VendorName']."')
			ON DUPLICATE KEY UPDATE 
			oui='".$mac_vendor['oui']."',
			companyname='".$mac_vendor['VendorName']."'";

			db_execute($sqlexec);
		} while ( !feof($fp) );
	}
}

function ciscotools_log( $text ){
    $dolog = read_config_option('ciscotools_log_debug');
    if( $dolog ){
		cacti_log( $text, false, 'CISCOTOOLS' );
	}
}

function upgrade_log( $text ){
    $dolog = read_config_option('ciscotools_upgrade_log_debug');
    if( $dolog ){
		cacti_log( $text, false, 'CISCOTOOLS_UPGRADE' );
	}
}

function force_clear() {
/* clear:
	ciscotools_upgrade_running upgrade_start.php
	ciscotools_backup_running check_backup.php
	ciscotools_mac_running pool_mac.php
	ciscotools_export_backup_running export_backup.php

08/08/2022 15:23:18 - CISCOTOOLS EXPORT process are really running: off
08/08/2022 15:23:18 - CISCOTOOLS BACKUP process are really running: off
08/08/2022 15:23:18 - CISCOTOOLS UPGRADE process are really running: off
08/08/2022 15:23:18 - CISCOTOOLS MACTRACK process are really running: 0
	*/
	
	// check mac_pool
cacti_log( 'MACTRACK process are really running: '.read_config_option('ciscotools_mac_running'), false, 'CISCOTOOLS' );
	if( read_config_option('ciscotools_mac_running') > 0 ) {
		// some process are suppose to run
		exec("ps -ef | grep pool_mac.php | grep -v grep", $pids);
		if(empty($pids)) {
			// No process are running  clean the field on DB
			set_config_option('ciscotools_mac_running', '0');
			cacti_log( 'MACTRACK no process are really running', false, 'CISCOTOOLS' );
		}
	}

	// check upgrade
cacti_log( 'UPGRADE process are really running: '.read_config_option('ciscotools_upgrade_running'), false, 'CISCOTOOLS' );
	if( read_config_option('ciscotools_upgrade_running') != 'off'  ) {
		// some process are suppose to run
		exec("ps -ef | grep upgrade_start.php | grep -v grep", $pids);
		if(empty($pids)) {
			// No process are running  clean the field on DB
			set_config_option('ciscotools_upgrade_running', 'off');
			cacti_log( 'UPGRADE no process are really running', false, 'CISCOTOOLS' );
		}
	}

	// check backup
cacti_log( 'BACKUP process are really running: '.read_config_option('ciscotools_backup_running'), false, 'CISCOTOOLS' );
	if( read_config_option('ciscotools_backup_running') != 'off' ) {
		// some process are suppose to run
		exec("ps -ef | grep check_backup.php | grep -v grep", $pids);
		if(empty($pids)) {
			// No process are running  clean the field on DB
			set_config_option('ciscotools_backup_running', 'off');
			cacti_log( 'BACKUP no process are really running', false, 'CISCOTOOLS' );
		}
	}

	// check export
cacti_log( 'EXPORT process are really running: '.read_config_option('ciscotools_export_backup_running'), false, 'CISCOTOOLS' );
	if( read_config_option('ciscotools_export_backup_running') != 'off' ) {
		// some process are suppose to run
		exec("ps -ef | grep export_backup.php | grep -v grep", $pids);
		if(empty($pids)) {
			// No process are running  clean the field on DB
			set_config_option('ciscotools_export_backup_running', 'off');
			cacti_log( 'EXPORT no process are really running', false, 'CISCOTOOLS' );
		}
	}

}
?>