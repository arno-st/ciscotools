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
// do NOT run this script through a web browser
if(!isset($_SERVER['argv'][0]) || isset($_SERVER['REQUEST_METHOD'])  || isset($_SERVER['REMOTE_ADDR'])) {
    die('<br><strong>This script is only meant to run at the command line.</strong>');
}

// let PHP run just as long as it has to
ini_set('max_execution_time', '0');

error_reporting(E_ALL ^ E_DEPRECATED);

include(dirname(__FILE__) . '/../../include/global.php');
include_once($config['base_path'] . '/lib/utility.php');
include_once($config['base_path'] . '/plugins/ciscotools/setup.php');

/* Check upgrades for devices
   check all device that are not in an upgrade process, to see what status they are
   device in a upgrade process don't had to be changed
   it dosen't change or check device in upgrade process
	
1 UPGRADE_STATUS_PENDING
2 UPGRADE_STATUS_CHECKING
3 UPGRADE_STATUS_UPLOADING
4 UPGRADE_STATUS_ACTIVATING
5 UPGRADE_STATUS_REBOOTING
22 UPGRADE_STATUS_NEED_RECHECK
23 UPGRADE_STATUS_FORCE_REBOOT_COMMIT
*/

// get the argument to see if we can process the upgrade based on the upgradewindow
// check if we are inside the upgrade windows
$upg_start_time = strtotime(read_config_option('ciscotools_upg_start_time'));
$upg_end_time = strtotime(read_config_option('ciscotools_upg_end_time'));
$cur_time = time();

upgrade_log('UPG: end before adjustement: '.$upg_end_time.' ' . date('H:i:s', $upg_end_time) );
if($upg_start_time > $upg_end_time ) {
	$upg_end_time += 86400; // add 24H to have the correct start time
}
// then do the math
$proceedupgrade = false; // value to store the action to be taken for the upgrade process
upgrade_log('UPG: start: '.$upg_start_time .' ' . date('H:i:s', $upg_start_time ) );
upgrade_log('UPG: end: '.$upg_end_time.' ' . date('H:i:s', $upg_end_time) );
upgrade_log('UPG: cur: '.$cur_time .' ' . date('H:i:s', $cur_time) );

upgrade_log('UPG: test 1 '. (( (date('H:i:s', $cur_time) > date('H:i:s', $upg_start_time ) ) && (date('H:i:s', $cur_time) < date('H:i:s', $upg_end_time)) ) ?"true ":"false") );
upgrade_log('UPG: test 2 '.  ( ( (date('H:i:s', $cur_time) < date('H:i:s', $upg_start_time ) ) && (date('H:i:s', $cur_time) < date('H:i:s', $upg_end_time)) &&           (date('H:i:s', $upg_start_time ) > date('H:i:s', $upg_end_time)))?"true":"false") );
upgrade_log('UPG: test 3 '. (( (date('H:i:s', $cur_time) > date('H:i:s', $upg_start_time ) ) && (date('H:i:s', $cur_time) > date('H:i:s', $upg_end_time)) &&
	  (date('H:i:s', $upg_start_time ) > date('H:i:s', $upg_end_time)))?"true":"false") );
upgrade_log('UPG: test 4 '. (( (date('H:i:s', $cur_time) < date('H:i:s', $upg_start_time ) ) && (date('H:i:s', $cur_time) < date('H:i:s', $upg_end_time)) &&           (date('H:i:s', $upg_start_time ) > date('H:i:s', $upg_end_time)))?"true":"false") );


if( ( (date('H:i:s', $cur_time) > date('H:i:s', $upg_start_time ) ) && (date('H:i:s', $cur_time) < date('H:i:s', $upg_end_time)) ) || 
	( (date('H:i:s', $cur_time) < date('H:i:s', $upg_start_time ) ) && (date('H:i:s', $cur_time) < date('H:i:s', $upg_end_time)) &&
	  (date('H:i:s', $upg_start_time ) > date('H:i:s', $upg_end_time))) ||
 	( (date('H:i:s', $cur_time) > date('H:i:s', $upg_start_time ) ) && (date('H:i:s', $cur_time) > date('H:i:s', $upg_end_time)) &&
	  (date('H:i:s', $upg_start_time ) > date('H:i:s', $upg_end_time))) ||
	( (date('H:i:s', $cur_time) < date('H:i:s', $upg_start_time ) ) && (date('H:i:s', $cur_time) < date('H:i:s', $upg_end_time)) &&
	  (date('H:i:s', $upg_start_time ) > date('H:i:s', $upg_end_time)))	) {
	upgrade_log('UPG: Inside Upgrade windows' );
	$proceedupgrade = true;
} else upgrade_log('UPG: outside Upgrade windows' );

/*
Debut           fin                     current         current>Debut   Current<Debut   Current<fin     Current>fin     Debut >fin      Debut<fin       Donc attendu
12:00:00        14:00:00        15:00:00        VRAI                    FAUX                    FAUX            VRAI            FAUX            VRAI            FAUX
12:00:00        14:00:00        10:00:00        FAUX                    VRAI                    VRAI            FAUX            FAUX            VRAI            FAUX
06:00:00        18:00:00        10:00:00        VRAI                    FAUX                    VRAI            FAUX            FAUX            VRAI            VRAI
22:30:00        06:15:00        00:01:00        FAUX                    VRAI                    VRAI            FAUX            VRAI            FAUX            VRAI
22:30:00        06:15:00        23:00:00        VRAI                    FAUX                    FAUX            VRAI            VRAI            FAUX            VRAI
22:30:00        06:15:00        10:00:00        FAUX                    VRAI                    FAUX            VRAI            VRAI            FAUX            FAUX

12/10/2023 22:31:17 - CISCOTOOLS UPG: Inside Upgrade windows
12/10/2023 22:31:17 - CISCOTOOLS UPG: test 3 false
12/10/2023 22:31:17 - CISCOTOOLS UPG: test 2 false
12/10/2023 22:31:17 - CISCOTOOLS UPG: test 1 false
12/10/2023 22:31:17 - CISCOTOOLS UPG: test outside FALSE
12/10/2023 22:31:17 - CISCOTOOLS UPG: cur: 1697142677 22:31:17
12/10/2023 22:31:17 - CISCOTOOLS UPG: end: 1697170500 06:15:00
12/10/2023 22:31:17 - CISCOTOOLS UPG: start: 1697142600 22:30:00
12/10/2023 22:31:17 - CISCOTOOLS UPG: end before adjustement: 1697084100 06:15:00

3/10/2023 09:47:10 - CISCOTOOLS UPG: Inside Upgrade windows
13/10/2023 09:47:10 - CISCOTOOLS UPG: test 4 false
13/10/2023 09:47:10 - CISCOTOOLS UPG: test 3 false
13/10/2023 09:47:10 - CISCOTOOLS UPG: test 2 false
13/10/2023 09:47:10 - CISCOTOOLS UPG: test 1 true
13/10/2023 09:47:10 - CISCOTOOLS UPG: cur: 1697183230 09:47:10
13/10/2023 09:47:10 - CISCOTOOLS UPG: end: 1697184000 10:00:00
13/10/2023 09:47:10 - CISCOTOOLS UPG: start: 1697169600 06:00:00
13/10/2023 09:47:10 - CISCOTOOLS UPG: end before adjustement: 1697184000 10:00:00

*/

// Query to check if devices are in queue, and need upgrade processing
$sqlQuery = "SELECT id, host_id, status FROM plugin_ciscotools_upgrade WHERE status IN ('"
			."','".UPGRADE_STATUS_PENDING
			."','".UPGRADE_STATUS_CHECKING
			."','".UPGRADE_STATUS_UPLOADING
			."','".UPGRADE_STATUS_ACTIVATING
			."','".UPGRADE_STATUS_REBOOTING
			."','".UPGRADE_STATUS_NEED_RECHECK
			."','".UPGRADE_STATUS_FORCE_REBOOT_COMMIT
			."')";
$queryQueue = db_fetch_assoc($sqlQuery);
if(!$queryQueue){
    set_config_option('ciscotools_upgrade_running', 'off');
    upgrade_log('UPG: Upgrade ended, none to process');
    return;
}
upgrade_log("UPG: Start query queue: ".print_r($queryQueue, true));

// process device that are in a ugrade process
/*
Status 1 UPGRADE_STATUS_PENDING
Status 2 UPGRADE_STATUS_CHECKING
Status 3 UPGRADE_STATUS_UPLOADING
Status 4 UPGRADE_STATUS_ACTIVATING
Status 5 UPGRADE_STATUS_REBOOTING
Status 22 UPGRADE_STATUS_NEED_RECHECK
Status 23 UPGRADE_STATUS_FORCE_REBOOT_COMMIT
*/
foreach($queryQueue as $device) {   // Devices in queue
upgrade_log("UPG: upgrade_start id: ".$device['host_id']." status: ".CISCOTLS_UPG_STATUS[$device['status']]['name']);

    if($device['status'] == UPGRADE_STATUS_PENDING && $proceedupgrade) {
		// If device is pending > begin the first process
        ciscotools_upgrade_table($device['host_id'], 'update', UPGRADE_STATUS_CHECKING);

upgrade_log("UPG: Step One Start ".$device['host_id']);
        // Get infos + uploading new image
        $upgradeStepOne = ciscotools_upgrade_step_one($device['host_id']);
        if($upgradeStepOne === false) continue;
upgrade_log("UPG: Step One end ".$device['host_id']." status: ".CISCOTLS_UPG_STATUS[$device['status']]['name']);
    }
	
	if($device['status'] == UPGRADE_STATUS_UPLOADING && $proceedupgrade) {
		// Check upload image + delete old images
upgrade_log("UPG: Step Two Start ".$device['host_id']);
        $upgradeStepTwo = ciscotools_upgrade_step_two($device['host_id']);
        if($upgradeStepTwo === false) continue;
upgrade_log("UPG: Step Two end ".$device['host_id']." status: ".CISCOTLS_UPG_STATUS[$device['status']]['name']);	
    }

	if($device['status'] == UPGRADE_STATUS_ACTIVATING && $proceedupgrade) {
		// Install mode: activating image
upgrade_log("UPG: Step Three Start ".$device['host_id']);
        $upgradeStepThree = ciscotools_upgrade_step_three($device['host_id']);
        if($upgradeStepThree === false) continue;		
upgrade_log("UPG: Step Three end ".$device['host_id']." status: ".CISCOTLS_UPG_STATUS[$device['status']]['name']);

		continue; // when we reboot, don't go further, it cause error, so just continue on the nextone
    }

	if($device['status'] == UPGRADE_STATUS_REBOOTING && $proceedupgrade) {
		// Verify installation
upgrade_log("UPG: Step Four Start ".$device['host_id']);
        $upgradeStepFour = ciscotools_upgrade_step_four($device['host_id']);
        if($upgradeStepFour === false) continue;		
upgrade_log("UPG: Step Four end ".$device['host_id']." status: ".CISCOTLS_UPG_STATUS[$device['status']]['name']);

		continue; // when we reboot, don't go further, it cause error, so just continue on the nextone
    }

	if($device['status'] == UPGRADE_STATUS_FORCE_REBOOT_COMMIT && $proceedupgrade) {
		// Install mode: commit image
		// Bundle mode: reboot
		// copy mode: reboot
upgrade_log("UPG: Step Reboot/Commit Start ".$device['host_id']);
        $upgradeReboot = ciscotools_upgrade_step_force_reboot($device['host_id']);
        if($upgradeReboot === false) continue;		
upgrade_log("UPG: Step Reboot/Commit end ".$device['host_id']." status: ".CISCOTLS_UPG_STATUS[$device['status']]['name']);
    }

	if($device['status'] == UPGRADE_STATUS_NEED_RECHECK ){
upgrade_log("UPG: Step Recheck Start ".$device['host_id']);
		ciscotools_upgrade_device_check($device['host_id']);
	}
}
set_config_option('ciscotools_upgrade_running', 'off'); // set the end of the process
upgrade_log('UPG: Processing upgrade ended');
/* ==================================================== */

 ?>