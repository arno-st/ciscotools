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

/**
* +-----------+
* | ATTENTION |
* +-----------+
* You need a file named 'activate.txt' in your TFTP folder!
* This file must contain the following text:
!BEGIN
!save before activating
do wr
!activate without prompt
install activate prompt-level none
* +-----------+
* | ATTENTION |
* +-----------+
*/

/* ================= INITIALIZATION ================= */
/* Functions dedicated or files for the initialization of upgrades */
include_once($config['base_path'] . '/plugins/extenddb/ssh2.php');    // SSH2 connection
include_once($config['base_path'] . '/plugins/ciscotools/upgrade/upgrade_func.php');
include_once($config['base_path'] . '/plugins/ciscotools/upgrade/upgrade_infos.php');
/* ==================================================== */


/* ======================================== STEPS ======================================== */

/** ================= STEP ONE =================
 * Begin process > checking infos and uploading new image
 *
 * Get all the informations about the device (general informations like the IP address,
 * the model informations, SNMP informations, version informations...)
 *
 * @param   integer $deviceID:  the ID of the device (host.id)
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_step_one($deviceID)
{   // GET INFOS from DB on the current device
    $infos = ciscotools_upgrade_get_infos($deviceID);
    if($infos === false) {
	    // Error in installation
		ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_INFO_ERROR);
		return false;
	}

	// check if device can be upgrade automaticaly or not.
	// by default if device is not set to off, backup is dependig fo configuration setup
    if($infos['device']['can_be_upgraded'] == 'off')
    {   // Upgrade disabled, if device is not allowing upgrade, just bypass it
        ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_UPGRADE_DISABLED);
        return false;
    }

    // TFTP CHECK, if it's up and running
    $tftpAddress = read_config_option('ciscotools_default_tftp');
    if(ciscotools_upgrade_check_tftp($deviceID, $tftpAddress) === false)
    {   // Error checking TFTP address
        ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_TFTP_DOWN);
        return false;
    }
	
    // IMAGE UPLOAD
    ciscotools_upgrade_upload_image($deviceID, $infos, $tftpAddress);
    return true;
}

/** ================= STEP TWO =================
 * Upload verification, old images deletion and potential reboot
 *
 * Perform an upload verification to see the progress and, if successful, delete the
 * old images. Can also perform a reboot if the device allows the action in the database
 *
 * @param   integer $deviceID:  the ID of the device (host.id)
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_step_two($deviceID)
{
    // GETTING INFOS
    $infos = ciscotools_upgrade_get_infos($deviceID);
    if($infos === false) {
	    // Error in installation
		ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_INFO_ERROR);
		return false;
	}

    // Check upload status
    $uploadStatus = ciscotools_upgrade_check_upload($infos);
    if($uploadStatus === 'error') {
		// Stuck in upload
        ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_UPLOAD_STUCK);
ciscotools_log("[ERROR] Upgrade: " . $infos['device']['description'] . " seems to be stuck in upload!");
        return false;
    }
    else if($uploadStatus === 'progress') return false; 
	else if($uploadStatus === false ) return false;// other error
    
    // Erasing old images
    if($infos['model']['mode'] == 'bundle') {
		// Bundle and archive mode
        ciscotools_upgrade_delete_images($infos); // Delete old images
        $stream = create_ssh($deviceID);
        if($stream === false)
        {
            ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_SSH_ERROR);
            return false;
        }

        if($infos['device']['can_be_rebooted'] === 'on')
        {   // REBOOT
            ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_REBOOTING);

            // SSH REBOOT
            if(ssh_write_stream($stream, "reload\r\n") === false) return false; // \r\n is to confirm reload
            $reloadresult = ssh_read_stream($stream);
ciscotools_log("device: ".$infos['device']['description']." Reload result : ".print_r($reloadresult, true) );
        }
        else ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_NEED_REBOOT);
    } else if($infos['model']['mode'] === 'install') {
		// IOS-XE like Cisco 9x00
		// erase is done after reload
        $stream = create_ssh($deviceID);
        if($stream === false)
        {
            ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_SSH_ERROR);
            return false;
        }

        if(ssh_write_stream($stream, "wr") === false) return false;
        ssh_read_stream($stream);
		// activate the new version
        if(ssh_write_stream($stream, "install add file flash:" . $infos['model']['image']) === false) return false;
        $installStatus = ssh_read_stream($stream);
ciscotools_log("device: ".$infos['device']['description']." Install status : ".print_r($installStatus, true) );
		if( stripos($installStatus, 'INSTALL-3-OPERATION_ERROR_MESSAGE' ) !== false ) {
			ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_ACTIVATING_ERROR);
			return false;
		}

        if($infos['device']['can_be_rebooted'] === 'on') ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_ACTIVATING);
        else ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_NEED_COMMIT);
    } else if($infos['model']['mode'] == 'archive') {
		// IE2000 or IE3000 serie
        ciscotools_upgrade_delete_images($infos); // Delete old images
        $stream = create_ssh($deviceID);
        if($stream === false)
        {
            ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_SSH_ERROR);
            return false;
        }

        if(ssh_write_stream($stream, "wr") === false) return false;
        ssh_read_stream($stream);
		// activate the new version
        if(ssh_write_stream($stream, "archive extract flash:" . $infos['model']['image']) === false) return false;
        $installStatus = ssh_read_stream($stream);
ciscotools_log("device: ".$infos['device']['description']." Archive status : ".print_r($installStatus, true) );
		if( stripos($installStatus, 'INSTALL-3-OPERATION_ERROR_MESSAGE' ) !== false ) {
			ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_ACTIVATING_ERROR);
			return false;
		}

        if($infos['device']['can_be_rebooted'] === 'on') ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_ACTIVATING);
        else ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_NEED_COMMIT);
	}
    return true;
}

/** ================= STEP THREE =================
 * Install mode: activating the new image
 * Bundle mode: reboot (if $force is true)
 *
 * Perform an activation of the new image through SNMP
 *
 * @param   integer $deviceID:  the ID of the device (host.id)
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_step_three($deviceID) {
    // DEVICE INFOS
    $infos = ciscotools_upgrade_get_infos($deviceID);
    if($infos === false) {
	    // Error in installation
		ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_INFO_ERROR);
		return false;
	}

    $tftpAddress = read_config_option('ciscotools_default_tftp');
    if(ciscotools_upgrade_check_tftp($deviceID, $tftpAddress) === false)
    {   // Error checking TFTP address
        ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_TFTP_DOWN);
        return false;
    }

    if( ($infos['device']['can_be_rebooted'] != 'on') ) {
		ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_NEED_COMMIT); // Must be activated and comitted!
	}

    ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_REBOOTING);
    return true;
}
/* ==================================================== */


/** ================= STEP FOUR =================
 * Check if device is rebooted and new image installation
 *
 * Ping the rebooted device, return false if down and check the new image installation
 * if the device is up.
 *
 * @param   integer $deviceID:  the ID of the device (host.id)
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_step_four($deviceID) {
    // DEVICE INFOS
    $infos = ciscotools_upgrade_get_infos($deviceID);
    if($infos === false) {
	    // Error in installation
		ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_INFO_ERROR);
		return false;
	}

    // Check reboot
    $ping = exec("ping -c 1 -s 64 -t 64 -w 1 " . $infos['device']['hostname']);
    if(empty($ping)) return false;

    if($infos['model']['mode'] == 'bundle')
    {   // Check image boot
        $imageCheck = ciscotools_upgrade_check_image( $infos);
        if($imageCheck === false)
        {   // Error in check image, message allready display
            return false;
        }
    } else if($infos['model']['mode'] == 'install') {
        $regexNewImage = '/(\d{2}\.\d{2}\.\d{2})/'; // Catch new version number
        if(!preg_match($regexNewImage, $infos['model']['image'], $newVersion)) return false;

        $stream = create_ssh($deviceID);
        if($stream === false)
        {
			// do nothing, mabye not ready after reboot
            return false;
        }
        if(ssh_write_stream($stream, "term l 0") === false) return false;
        ssh_read_stream($stream);
        if(ssh_write_stream($stream, "dir") === false) return false;
        $dir = ssh_read_stream($stream);
        if($dir === false) return false;

        if(!preg_match_all($regexNewImage, $dir, $oldVersionResult)) return false;
        foreach($oldVersionResult as $array)
        {
            foreach($array as $value)
            {
                if($newVersion !== $value)
                {
                    $oldVersion = $value;
                    break 2;
                }
            }
        }
        
        if(ssh_write_stream($stream, 'show install log') === false) return false;
        $installSummary = ssh_read_stream($stream);
        $regexInstall = '/\[[0-9]{1}\|install_op_boot\]:\s(END SUCCESS)/';
        if(!preg_match($regexInstall, $installSummary, $result))
        {
            ciscotools_upgrade_table($device['host_id'], 'update', UPGRADE_STATUS_INFO_ERROR);
ciscotools_log("[ERROR] Upgrade: " . $infos['device']['description'] . " was not correctly upgraded!");
            return false;
        }
        if(ssh_write_stream($stream, "install commit") === false) return false;
        $installcommit = ssh_read_stream($stream);
ciscotools_log("device: ".$infos['device']['description'].' install commit:' .print_r($installcommit, true) );

        if(ssh_write_stream($stream, 'install remove inactive') === false) return false;
        $installremove = ssh_read_stream($stream);
ciscotools_log("device: ".$infos['device']['description'].' install remove:' .print_r($installremove, true) );
        if(ssh_write_stream($stream, 'y') === false) return false; // was wr
        ssh_read_stream($stream);

        close_ssh($stream);
    }
ciscotools_log('[DEBUG] Upgrade: upgrade succeeded for ' . $infos['device']['description'] . "!");
    ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_UPDATE_OK);
}

/** ================= Reboot/commit =================
 * Install mode: activating the new image
 * Bundle mode: reboot 
 *
 * Perform an activation of the new image through SNMP
 *
 * @param   integer $deviceID:  the ID of the device (host.id)
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_step_force_reboot($deviceID){
    // GETTING INFOS
    $infos = ciscotools_upgrade_get_infos($deviceID);
    if($infos === false) {
	    // Error in installation
		ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_INFO_ERROR);
		return false;
	}
ciscotools_log("reboot: ". $infos['device']['description']);

	// open thw ssh stream to the device
	$stream = create_ssh($deviceID);
    if($stream === false)
    {
        ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_SSH_ERROR);
        return false;
    }
    ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_REBOOTING);


    if($infos['model']['mode'] == 'bundle') {   
		// Bundle mode
        if(ssh_write_stream($stream, "wr") === false) return false; // Write memory
        ssh_read_stream($stream);
        // SSH REBOOT
        if(ssh_write_stream($stream, "reload\r\n") === false) return false;
        ssh_read_stream($stream);
	} else if($infos['model']['mode'] === 'install')
    {   
		// IOS-XE like Cisco 9x00
        if(ssh_write_stream($stream, "install activate prompt-level none") === false) return false;
        $activate = ssh_read_stream($stream);
ciscotools_log("device: ".$infos['device']['description'].' install activate return: ', print_r($activate, true) );
		}
	return true;
}
?>