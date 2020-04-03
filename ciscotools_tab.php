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
	include_once($config["library_path"] . "/database.php");

	if (api_user_realm_auth('backup.php')) {
		if (!substr_count($_SERVER["REQUEST_URI"], "backup.php")) {
			print '<a href="' . $config['url_path'] . 'plugins/ciscotools/backup.php"><img src="' . $config['url_path'] . 'plugins/ciscotools/images/tab_discover.gif" alt="CiscoTools" align="absmiddle" border="0"></a>';
		}else{
			print '<a href="' . $config['url_path'] . 'plugins/ciscotools/backup.php"><img src="' . $config['url_path'] . 'plugins/ciscotools/images/tab_discover_down.gif" alt="CiscoTools" align="absmiddle" border="0"></a>';
		}
	}
	
}

function ciscotools_draw_navigation_text ($nav) {
	$nav['backup.php:'] = array(
		'title' => __('Backup', 'ciscotools'),
		'mapping' => 'index.php:',
		'url' => 'backup.php',
		'level' => '1'
	);

	return $nav;
}
