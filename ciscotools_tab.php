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

include_once($config['base_path'] . '/plugins/ciscotools/backup.php');

set_default_action('backup');

switch(get_request_var('action')) {
	case 'ajax_hosts':

		break;
	case 'ajax_hosts_noany':

		break;
	case 'backup':
		general_header();
		ciscotools_tabs();
		ciscotools_backup();
		bottom_footer();

		break;
	case 'diff':
		general_header();
		ciscotools_tabs();
		ciscotools_diff();
		bottom_footer();

		exit;
	default:
		general_header();
		ciscotools_tabs();
		bottom_footer();
		break;
}

function ciscotools_tabs() {
	global $config;

	/* present a tabbed interface */
	$tabs = array(
		'backup'    => __('Backup', 'ciscotools'),
		'diff'      => __('Diff', 'ciscotools')
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

html_start_box(__('Cisco Tools', 'ciscotools'), '100%', '', '3', 'center', 'ciscotools_tab.php');
?>

<meta charset="utf-8"/>
	<td class="noprint">
	<form style="padding:0px;margin:0px;" name="form" method="get" action="<?php print $config['url_path'];?>plugins/ciscotools/ciscotools_map.php">
		<table width="100%" cellpadding="0" cellspacing="0">
			<tr class="noprint">
				<td nowrap style='white-space: nowrap;' width="1">
					&nbsp;Description :&nbsp;
				</td>
				<td width="1">
					<input type="text" name="description" size="25" value="<?php print get_request_var_request("description");?>">
				</td>
				<td nowrap style='white-space: nowrap;'>
					<input type="submit" value="Go" title="Set/Refresh Filters">
					<input type='button' value="Clear" id='clear' onClick='clearFilter()' title="Reset fields to defaults">
				</td>
			</tr>
		</table>
	</form>
	</td>
</tr>

<?php

html_end_box(false);


?>
