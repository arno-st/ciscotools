<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2006-2019 The Cacti Group                                 |
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
chdir('../../');
include_once('./include/auth.php');
include_once($config['base_path'] . '/plugins/ciscotools/ciscotools_processcli.php');

set_default_action('display_mac');
set_time_limit(0);

switch(get_request_var('action')) {
	case 'diff': // Display diff after display of backup tab
		general_header();
		$deviceid = get_request_var('deviceid');
		if( $deviceid ) {
			ciscotools_tabs();
			ciscotools_diff();
		} else  {
			set_request_var('action', 'backup' );
			ciscotools_tabs();
			ciscotools_displaybackup();
		}
		bottom_footer();
		break;

	case 'output':
		$versionid = get_request_var('versionid');
		if( $versionid ) {
			ciscotools_output($versionid);
		} else  {
			general_header();
			set_request_var('action', 'backup' );
			ciscotools_tabs();
			ciscotools_displaybackup();
			bottom_footer();
		}
		break;

	case 'backup': // display the list of backup
		general_header();
		ciscotools_tabs();
		ciscotools_displaybackup();
		bottom_footer();
		break;
		
	case 'display_mac': // Display the mac adress table
		general_header();
		ciscotools_tabs();
		ciscotools_displaymac();
		bottom_footer();
		break;

	case 'upgrade': // display the upgrade page
		general_header();
		ciscotools_tabs();
		ciscotools_displayupgrade();
		bottom_footer();
		break;

	case 'cli': // display the CLI tools
		general_header();
		ciscotools_tabs();
		if (get_filter_request_var('cliAction') > 0) {
			process_cli();
		} else	ciscotools_displaycli();
		bottom_footer();
		break;
}

function ciscotools_tabs() {
	global $config;

	/* present a tabbed interface */
	$tabs = array(
		'backup'    	=> __('Backup', 'ciscotools'),
		'diff'      	=> __('Diff', 'ciscotools'),
		'display_mac'	=> __('Display Mac', 'ciscotools'),
		'upgrade'		=> __('Upgrade', 'ciscotools'),
		'cli'			=> __('CLI', 'ciscotools')
	);

	get_filter_request_var('tab', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^([a-zA-Z]+)$/')));

	load_current_session_value('tab', 'sess_ciscotools_tab', 'general');
	$current_tab = get_request_var('action');

	/* draw the tabs */
	print "<div class='tabs'><nav><ul>\n";

	if (sizeof($tabs)) {
		foreach (array_keys($tabs) as $tab_short_name) {
			print "<li><a class='tab" . (($tab_short_name == $current_tab) ? " selected'" : "'") .
				" href='" . htmlspecialchars($config['url_path'] .
				'plugins/ciscotools/ciscotools_tab.php?' .
				'action=' . $tab_short_name) .
				"'>" . $tabs[$tab_short_name] . "</a></li>\n";
		}
	}

	print "</ul></nav></div>\n";
}

?>