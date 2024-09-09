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
function ciscotools_upgrade_step_one($deviceID){
	// check to be sure we need to upgrade
	if( !ciscotools_upgrade_device_check($deviceID) ){
upgrade_log('UPG: ciscotools_upgrade_step_one: '.$deviceID." is up to date" );
		return false;
	}

	// GET INFOS from DB on the current device
    $infos = ciscotools_upgrade_get_infos($deviceID);
    if($infos === false) {
	    // Error in installation
		ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_CHECKING_ERROR);
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
upgrade_log('UPG: ciscotools_upgrade_step_one: '.$infos['device']['description']." info: ".print_r($infos, true) );
	if($infos['model']['mode'] == 'archive') {
        ciscotools_upgrade_delete_images($infos); // Delete old images

		ciscotools_upgrade_upload_archive($deviceID, $infos, $tftpAddress);
		ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_UPLOADING); // Change status so we can reboot it
		
	} else if($infos['model']['mode'] == 'copy') {
// SSH copy, check inside upload if enougth space on device
		if( ciscotools_upgrade_upload_copy($deviceID, $infos, $tftpAddress) ) {
			
			ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_UPLOADING); // Change status so we can reboot it
		}
		
	} else ciscotools_upgrade_upload_image($deviceID, $infos, $tftpAddress);
	
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
function ciscotools_upgrade_step_two($deviceID) {
    // GETTING INFOS
    $infos = ciscotools_upgrade_get_infos($deviceID);
    if($infos === false) {
	    // Error in installation
		ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_INFO_ERROR);
		return false;
	}

    // archive mode is different
	if($infos['model']['mode'] == 'archive') {
		// IE3000 serie
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
upgrade_log("UPG: ciscotools_upgrade_step_two: ".$infos['device']['description']." Reload result : ".print_r($reloadresult, true) );
        }
        else ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_NEED_REBOOT);
		return true;
	}

	    // SSH copy mode is different
	if($infos['model']['mode'] == 'copy') {
		// IE2000 serie
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
upgrade_log("UPG: ciscotools_upgrade_step_two: ".$infos['device']['description']." Reload result : ".print_r($reloadresult, true) );
        }
        else ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_NEED_REBOOT);
		return true;
	}

    // Check upload status
    $uploadStatus = ciscotools_upgrade_check_upload($infos);
    if($uploadStatus === 'error') {
		// Stuck in upload
        ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_UPLOAD_STUCK);
upgrade_log("UPG: ciscotools_upgrade_step_two " . $infos['device']['description'] . " seems to be stuck in upload!");
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
upgrade_log("UPG: ciscotools_upgrade_step_two device: ".$infos['device']['description']." Reload result : ".print_r($reloadresult, true) );
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
upgrade_log("UPG: ciscotools_upgrade_step_two device wr: ".$infos['device']['description'] );
		// add the new version
        if(ssh_write_stream($stream, "install add file flash:" . $infos['model']['image']." ") === false) return false;
		// to read the prompt
		$cmdok  = ssh_read_stream($stream);
upgrade_log("UPG: ciscotools_upgrade_step_two device install add: ".$infos['device']['description']." prompt : ".print_r($cmdok, true) );

        $installStatus = ssh_read_stream($stream, '#', 500);
		
upgrade_log("UPG: ciscotools_upgrade_step_two device install add: ".$infos['device']['description']." status : ".print_r($installStatus, true) );

		if( stripos($installStatus, 'INSTALL-3-OPERATION_ERROR_MESSAGE' ) !== false ) {
			if( stripos ($installStatus, 'Bundle-to-Install' ) !== false ){
				if(ssh_write_stream($stream, "install add file flash:".$infos['model']['image']."", 500 ) === false) return false;
				$installStatus = ssh_read_stream($stream, '#', 500 );
upgrade_log("UPG: ciscotools_upgrade_step_two device Bundle-to-Install: ".$infos['device']['description']." Conversion status : ".print_r($installStatus, true) );
			ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_BUNDLE_TO_INSTALL);
			} else {
				ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_ACTIVATING_ERROR);
			}
			return false;
		}

        if($infos['device']['can_be_rebooted'] === 'on') ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_ACTIVATING);
        else ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_NEED_COMMIT);
    }
    return true;
}

/** ================= STEP THREE =================
 * Install mode: activating the new image
 * install activate prompt-level none
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

    if( ($infos['device']['can_be_rebooted'] != 'on') ) {
		ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_NEED_COMMIT); // Must be activated and comitted!
	} else {
		if($infos['model']['mode'] === 'install') {   
			// IOS-XE like Cisco 9x00
			$stream = create_ssh($deviceID);
			if($stream === false)
			{
				// do nothing, mabye not ready after reboot
				return false;
			}
			
		// sometime config has change, and it has to be wr before install activate can be done.
        if(ssh_write_stream($stream, "wr") === false) return false;
        ssh_read_stream($stream);
upgrade_log("UPG: ciscotools_upgrade_step_three device wr: ".$infos['device']['description'] );
upgrade_log("UPG: ciscotools_upgrade_step_three install activate: ".$infos['device']['description'] );
			if(ssh_write_stream($stream, "install activate prompt-level none") === false) return false;
					
		// to read the prompt
		$cmdok  = ssh_read_stream($stream);
upgrade_log("UPG: ciscotools_upgrade_step_three install activate: ".$infos['device']['description']." prompt : ".print_r($cmdok, true) );

			$activate = ssh_read_stream($stream, '#', 500 );
upgrade_log("UPG: ciscotools_upgrade_step_three install activate prompt-level none: ".$infos['device']['description'].' status: ', print_r($activate, true) );
		}
	}

    ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_REBOOTING);
    return true;
}


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
    if(empty($ping)) {
upgrade_log("UPG: ciscotools_upgrade_step_four: " . $deviceID . ' '.$infos['device']['description'] . " device not yet up!");
		return false;
	}
	
    if($infos['model']['mode'] == 'bundle') {
		// Check image boot
        $imageCheck = ciscotools_upgrade_check_image( $infos );
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
        if(ssh_write_stream($stream, "term length 0") === false) return false;
        ssh_read_stream($stream);
        if(ssh_write_stream($stream, 'show install log') === false) return false;
		// to read the prompt
		$cmdok  = ssh_read_stream($stream);
upgrade_log("UPG: ciscotools_upgrade_step_two device show log: ".$infos['device']['description']." prompt : ".print_r($cmdok, true) );

        $installSummary = ssh_read_stream($stream);
        $regexInstall = '/\[[0-9]{1}\|install_op_boot\]:\s(END SUCCESS)/';
upgrade_log("UPG: ciscotools_upgrade_step_four install log: " . $infos['device']['description'] . " log: ".print_r($installSummary, true));
 
		if(!preg_match($regexInstall, $installSummary, $result)) {
            ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_INFO_ERROR);
upgrade_log("UPG: ciscotools_upgrade_step_four: " . $infos['device']['description'] . " was not correctly upgraded!");
            return false;
        }
upgrade_log("UPG: ciscotools_upgrade_step_four install commit: ".$infos['device']['description'] );
 
		if(ssh_write_stream($stream, "install commit") === false) return false;
		
		// to read the prompt
		$cmdok  = ssh_read_stream($stream); 
upgrade_log("UPG: ciscotools_upgrade_step_four install commit: ".$infos['device']['description']." prompt : ".print_r($cmdok, true) );

        $installcommit = ssh_read_stream($stream, '#', 500);
		
upgrade_log("UPG: ciscotools_upgrade_step_four install commit: ".$infos['device']['description'].' status:' .print_r($installcommit, true) );
		if( stripos($installcommit, 'INSTALL-3-OPERATION_ERROR_MESSAGE' ) !== false ) {
			ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_ACTIVATING_ERROR );
			close_ssh($stream);
			return false;
		}
		
        if(ssh_write_stream($stream, 'install remove inactive') === false) return false; // long timeout to clear old file
        $installremove = ssh_read_stream($stream, '?', 180);
		
upgrade_log("UPG: ciscotools_upgrade_step_four install remove: ".$infos['device']['description'].' status:' .print_r($installremove, true) );

        if(ssh_write_stream($stream, 'y') === false) return false;
		// to read the prompt
		$cmdok  = ssh_read_stream($stream); 
upgrade_log("UPG: ciscotools_upgrade_step_four install remove: ".$infos['device']['description']." prompt : ".print_r($cmdok, true) );

        $status = ssh_read_stream($stream, '#', 500);
upgrade_log("UPG: ciscotools_upgrade_step_four install remove Y: ".$infos['device']['description'].' status:' .print_r($status, true) );

        close_ssh($stream);
		
        $imageCheck = ciscotools_upgrade_check_image( $infos);
        if($imageCheck === false)
        {   // Error in check image, message allready display
            return false;
        }

	} else if($infos['model']['mode'] == 'copy') {
        $imageCheck = ciscotools_upgrade_check_image( $infos);
        if($imageCheck === false)
        {   // Error in check image, message allready display
            return false;
        }
	}
upgrade_log('UPG: ciscotools_upgrade_step_four: upgrade succeeded for ' . $infos['device']['description']);
    ciscotools_upgrade_device_check($deviceID);
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
upgrade_log("UPG: ciscotools_upgrade_step_force_reboot: ". $infos['device']['description']);

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
	} else if($infos['model']['mode'] === 'install') {   
		// IOS-XE like Cisco 9x00
        if(ssh_write_stream($stream, "install activate prompt-level none") === false) return false;
        $activate = ssh_read_stream($stream);
upgrade_log("UPG: ciscotools_upgrade_step_force_reboot: ".$infos['device']['description'].' install activate return: ', print_r($activate, true) );
	} else if($infos['model']['mode'] == 'archive') {   
		// archive mode
        if(ssh_write_stream($stream, "wr") === false) return false; // Write memory
        ssh_read_stream($stream);
        // SSH REBOOT
        if(ssh_write_stream($stream, "reload\r\n") === false) return false;
        ssh_read_stream($stream);
	}
	return true;
}
?>