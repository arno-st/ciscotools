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

function ciscotools_show_tab () {
	global $config;

	if (api_user_realm_auth('ciscotools_tab.php') || api_user_realm_auth('backup.php')) {
		$cp = false;
		if (get_current_page() == 'ciscotools_tab.php' || get_current_page() == 'backup.php') {
			$cp = true;
		}
		print '<a href="' . $config['url_path'] . 'plugins/ciscotools/ciscotools_tab.php"><img src="' . $config['url_path'] . 'plugins/ciscotools/images/ciscotools' . ($cp ? '_down': '') . '.gif" alt="CiscoTools" align="absmiddle" border="0"></a>';
	}
	
}

function ciscotools_draw_navigation_text ($nav) {

	$nav['ciscotools_tab.php:'] = array(
		'title' => __('Cisco Tools', 'ciscotools'),
		'mapping' => 'index.php:',
		'url' => 'ciscotools_tab.php',
		'level' => '1'
	);
	$nav['backup.php:'] = array(
		'title' => __('Backup', 'ciscotools'),
		'mapping' => 'index.php:',
		'url' => 'backup.php',
		'level' => '1'
	);

	return $nav;
}
