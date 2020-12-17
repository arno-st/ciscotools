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
* !BEGIN
* !save before activating
* do wr
* !activate without prompt
* install activate prompt-level none
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
{   // GET INFOS
    $infos = ciscotools_upgrade_get_infos($deviceID);
    if($infos === false) return false;

    // CHECK MODEL
    if(ciscotools_upgrade_check_model($infos['device']['type']) === false)
    {   // Model unsupported
        ciscotools_upgrade_table($deviceID, 'status', UPGRADE_STATUS_UNSUPORTED);
        return false;
    }

    if($infos['device']['can_be_upgraded'] != 'on')
    {   // Upgrade disabled
        ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_UPGRADE_DISABLED);
        return false;
    }

    // CHECK VERSION
    if(ciscotools_upgrade_check_version($deviceID, $infos) === false)
    {   // Error checking version
        ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_CHECKING_ERROR);
        return false;
    }

    // TFTP CHECK
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
/* ==================================================== */

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
{   // OID
    $snmpStatus = "1.3.6.1.4.1.9.9.10.1.2.1.1.8";   // ciscoFlashCopyStatus
    $oidReload = "1.3.6.1.4.1.9.2.9.9.0";           // reload

    // GETTING INFOS
    $infos = ciscotools_upgrade_get_infos($deviceID);
    if($infos === false) return false;

    // Check upload status
    $uploadStatus = ciscotools_upgrade_check_upload($infos['device'], $infos['snmp']);
    if($uploadStatus === 'error')
    {   // Stuck in upload
        ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_UPLOAD_STUCK);
        ciscotools_log("[ERROR] Upgrade: " . $infos['device']['description'] . " seems to be stuck in upload!");
        return false;
    }
    else if($uploadStatus === 'progress') return false; // Still uploading
    
    // Erasing old images
    if($infos['model']['mode'] == 'bundle')
    {   // Bundle mode
        ciscotools_upgrade_delete_images($infos['device'], $infos['model']['image']); // Delete old images
        $stream = create_ssh($deviceID);
        if($stream === false)
        {
            ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_SSH_ERROR);
            return false;
        }
        if(ssh_write_stream($stream, "conf t") === false) return false; // Enter in conf mode
        ssh_read_stream($stream);
        if(ssh_write_stream($stream, "boot system flash:" . $infos['model']['image']) === false) return false; // Set the boot path-list
        ssh_read_stream($stream);
        if(ssh_write_stream($stream, "do wr") === falsE) return false; // Write memory
        ssh_read_stream($stream);

        if($infos['device']['can_be_rebooted'] === 'on')
        {   // REBOOT
            ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_REBOOTING);

            // SSH REBOOT
            if(ssh_write_stream($stream, "reload\r\n") === false) return false;
            ssh_read_stream($stream);
        }
        else ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_NEED_REBOOT);
    }
    else if($infos['model']['mode'] === 'install')
    {   // IOS-XE like Cisco 9x00
        $stream = create_ssh($deviceID);
        if($stream === false)
        {
            ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_SSH_ERROR);
            return false;
        }

        if(ssh_write_stream($stream, "wr") === false) return false;
        ssh_read_stream($stream);
        if(ssh_write_stream($stream, "install add file flash:" . $infos['model']['image']) === false) return false;
        ssh_read_stream($stream);

        if($infos['device']['can_be_rebooted'] === 'on') ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_ACTIVATING);
        else ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_NEED_COMMIT);
    }
    return true;
}
/* ==================================================== */

/** ================= STEP THREE =================
 * Install mode: activating the new image
 *
 * Perform an activation of the new image through SNMP
 *
 * @param   integer $deviceID:  the ID of the device (host.id)
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_step_three($deviceID)
{
    // DEVICE INFOS
    $infos = ciscotools_upgrade_get_infos($deviceID);
    if($infos === false) return false;
    $tftpAddress = read_config_option('ciscotools_default_tftp');
    if(ciscotools_upgrade_check_tftp($deviceID, $tftpAddress) === false)
    {   // Error checking TFTP address
        ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_TFTP_DOWN);
        return false;
    }

    if($infos['device']['can_be_rebooted'] != 'on') ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_NEED_RECHECK); // Must be activated and comitted!

    // OIDs
    $snmpCopyEntry      = "1.3.6.1.4.1.9.9.96.1.1.1.1";    // ccCopyEntry
    $snmpCopyProtocol   = $snmpCopyEntry . ".2";            // ccCopyProtocol       
    $snmpCopySrcFileType= $snmpCopyEntry . ".3";            // ccCopySourceFileType
    $snmpCopyDstFileType= $snmpCopyEntry . ".4";            // ccCopyDestFileType
    $snmpCopySrvAddress = $snmpCopyEntry . ".5";            // ccCopyServerAddress
    $snmpCopyFileName   = $snmpCopyEntry . ".6";            // ccCopyFileName
    $snmpCopyStatus     = $snmpCopyEntry . ".14";           // ccCopyEntryRowStatus

    // Protocol TFTP
    ciscotools_upgrade_snmp_set($infos['snmp']['version'], $infos['device']['hostname'], $snmpCopyProtocol . $infos['snmp']['session'], "i", "1", $infos['snmp']);
    // Source file type - Network file
    ciscotools_upgrade_snmp_set($infos['snmp']['version'], $infos['device']['hostname'], $snmpCopySrcFileType . $infos['snmp']['session'], "i", "1", $infos['snmp']);
    // Destination file type - Running-config
    ciscotools_upgrade_snmp_set($infos['snmp']['version'], $infos['device']['hostname'], $snmpCopyDstFileType . $infos['snmp']['session'], "i", "4", $infos['snmp']);
    // Server Address - TFTP
    ciscotools_upgrade_snmp_set($infos['snmp']['version'], $infos['device']['hostname'], $snmpCopySrvAddress . $infos['snmp']['session'], "a", $tftpAddress, $infos['snmp']);
    // Filename - activate.txt
    ciscotools_upgrade_snmp_set($infos['snmp']['version'], $infos['device']['hostname'], $snmpCopyFileName . $infos['snmp']['session'], "s", "activate.txt", $infos['snmp']);
    // Status - 1 to begin
    ciscotools_upgrade_snmp_set($infos['snmp']['version'], $infos['device']['hostname'], $snmpCopyStatus . $infos['snmp']['session'], "i", "1", $infos['snmp']);

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
function ciscotools_upgrade_step_four($deviceID)
{
    // DEVICE INFOS
    $infos = ciscotools_upgrade_get_infos($deviceID);
    if($infos === false) return false;

    // Check reboot
    $ping = exec("ping -c 1 -s 64 -t 64 -w 1 " . $infos['device']['hostname']);
    if(empty($ping)) return false;

    if($infos['model']['mode'] == 'bundle')
    {   // Check image boot
        $imageCheck = ciscotools_upgrade_check_image($deviceID, $infos['device']['hostname'], $infos['snmp'], $infos['model']['image']);
        if($imageCheck === false)
        {   // Error in installation
            ciscotools_upgrade_table($device['host_id'], 'update', UPGRADE_STATUS_INFO_ERROR);
            return false;
        }
    }
    else if($infos['model']['mode'] == 'install')
    {
        $regexNewImage = '/(\d{2}\.\d{2}\.\d{2})/'; // Catch new version number
        if(!preg_match($regexNewImage, $infos['model']['image'], $newVersion)) return false;

        $stream = create_ssh($deviceID);
        if($stream === false)
        {
            ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_SSH_ERROR);
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
        
        if(ssh_write_stream($stream, 'delete /f /r flash:*' . $oldVersion . '.SPA.pkg') === false) return false;
        ssh_read_stream($stream);
        if(ssh_write_stream($stream, 'delete /f /r flash:*' . $newVersion[0] . '.SPA.conf') === false) return false;
        ssh_read_stream($stream);
        if(ssh_write_stream($stream, 'wr') === false) return false;
        ssh_read_stream($stream);
        
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
        ssh_read_stream($stream);
        close_ssh($stream);
    }
    ciscotools_log('[DEBUG] Upgrade: upgrade succeeded for ' . $infos['device']['description'] . "!");
    ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_UPDATE_OK);
}
/* ==================================================== */

/* ======================================================================================= */
?>