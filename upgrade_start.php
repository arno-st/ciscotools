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

/** ================= QUEUE CHECKING =================
 * Function called by poller. It checks if device(s) in queue and the status
 *
 * Check the queue table to see if devices are in queue and the status number
 * 0:   Pending and waiting step one
 * 1:   Upgrade beginning and uploading new image
 * 2:   Upload checking until a potential rebooting
 * 3:   Install mode: activating the new image
 * 4:   If rebooting, ping device and verify the installation
 *
 * @return  boolean true if successful, false otherwise
 */
// Check upgrades for devices
ciscotools_upgrade_device_check();
    
// Query to check if device is already in queue
$sqlQuery = "SELECT id, host_id, status FROM plugin_ciscotools_upgrade";
$queryQueue = db_fetch_assoc($sqlQuery);
if(!$queryQueue)
{
    set_config_option('ciscotools_upgrade_running', 'off');
    cacti_log('Check upgrade ended', false, 'CISCOTOOLS');
    return;
}

foreach($queryQueue as $device)
{   // Devices in queue
    if($device['status'] == UPGRADE_STATUS_PENDING)
    {   // If device is pending > begin the first process
        ciscotools_upgrade_table($device['host_id'], 'update', UPGRADE_STATUS_CHECKING);

        // Get infos + uploading new image
        $upgradeStepOne = ciscotools_upgrade_step_one($device['host_id']);
        if($upgradeStepOne === false) continue;
    }
    else if($device['status'] == UPGRADE_STATUS_UPLOADING)
    {   // Check upload image + delete old images
        $upgradeStepTwo = ciscotools_upgrade_step_two($device['host_id']);
        if($upgradeStepTwo === false) continue;
    }
    else if($device['status'] == UPGRADE_STATUS_ACTIVATING)
    {   // Install mode: activating image
        $upgradeStepThree = ciscotools_upgrade_step_three($device['host_id']);
        if($upgradeStepThree === false) continue;
    }
    else if($device['status'] == UPGRADE_STATUS_REBOOTING)
    {   // Verify installation
        $upgradeStepFour = ciscotools_upgrade_step_four($device['host_id']);
        if($upgradeStepFour === false) continue;
    }
}
set_config_option('ciscotools_upgrade_running', 'off'); // set the end of the process
cacti_log('Check upgrade ended', false, 'CISCOTOOLS');
/* ==================================================== */

 ?>