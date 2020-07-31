<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007-2017 The Cacti Group                                 |
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

/* ======================================== CHECKS ======================================== */

/** ================= CHECK DEVICE =================
 * Check if devices need to be upgraded or else
 * 
 * Select all devices in host table
 * Excluding devices still in the plugin_ciscotools_upgrade except with the status '21' (recheck)
 * 
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_device_check()
{
    $sqlQuery = "SELECT host.id as 'id', host.type as 'type', host.hostname as 'hostname', 
                plugin_ciscotools_image.image as 'image' 
                FROM host 
                LEFT JOIN plugin_ciscotools_image ON host.type = plugin_ciscotools_image.model 
                WHERE host.type <> '' 
                AND host.type IN (
                    SELECT plugin_extenddb_model.model FROM plugin_extenddb_model WHERE host.type = plugin_extenddb_model.model
                )
                AND NOT EXISTS (
                    SELECT plugin_ciscotools_upgrade.host_id FROM plugin_ciscotools_upgrade 
                    WHERE host.id = plugin_ciscotools_upgrade.host_id
                    AND plugin_ciscotools_upgrade.status <> '21'
                )
                GROUP BY host.id
                ORDER BY host.id";
    $result = db_fetch_assoc($sqlQuery);

    foreach($result as $device)
    {   // Perform a ping
        $ping = shell_exec("ping -c 1 -s 64 -t 64 -w 1 " . $device['hostname']);
        if(empty($ping)) continue;

        $stream = create_ssh($device['id']);
        if($stream === false)
        {
            ciscotools_upgrade_table($device['id'], 'add', 18);
            continue;
        }
        if(ssh_write_stream($stream, "dir") === false) continue;
        $sshResult = ssh_read_stream($stream);
        close_ssh($stream);

        $regexGetVersion = "/\.([0-9.EAMmz-]+)(|\.SPA)\.(bin|tar|pkg)/";
        if(!preg_match($regexGetVersion, $device['image'], $resultRegex))
        {   // Error in table 'upgrade'
            ciscotools_upgrade_table($device['id'], 'add', 8);
            continue;
        }
        if(!preg_match("/" . $resultRegex[1] . "/", $sshResult, $checkUpgrade))
        {   // Need to be upgraded
            ciscotools_upgrade_table($device['id'], 'add', 5);
            continue;
        }
        if($checkUpgrade[0] === $resultRegex[1])
        {   // Up to date
            ciscotools_upgrade_table($device['id'], 'add', 20);
            continue;
        }
        else 
        {   // Need to be upgraded
            ciscotools_upgrade_table($device['id'], 'add', 5);
        }
    }
}
/* ==================================================== */

/** ================= CHECK MODEL =================
 * Check if the devices model
 *
 * Perform a model verification
 * If the query is not successful, the model is not supported
 *
 * @param   string  $type: the device model
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_check_model($type)
{
    $sqlQuery = "SELECT plugin_ciscotools_image.model
        FROM plugin_ciscotools_image 
        WHERE plugin_ciscotools_image.model = '$type'";
    $sqlExec = db_fetch_row_prepared($sqlQuery);
    if(!$sqlExec) return false;
    return true;
}
/* ==================================================== */

/** ================= CHECK VERSION =================
 * Verify if the image was already installed
 *
 * See if the device has already the version installed
 * with two commands depending of the mode (bundle and install)
 * [Bundle]     perform the 'dir' and 'show boot' commands
 * [Install]    perform the 'dir' and 'more flash:/.installer/install_add_oper.log' commands
 *
 * @param   integer $deviceID:  the ID of the device (host.id)
 * @param   array   $infos:     array containing all device informations
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_check_version($deviceID, $infos)
{   // See if file is in flash: and set in bootvar
    $cmds = explode("|", $infos['model']['sshCmds_mode']);
    $checkCounter = 0;
    $versionRegex = "/" . $infos['model']['image'] . "/";

    $stream = create_ssh($deviceID);
    if($stream === false)
    {
        ciscotools_upgrade_table($deviceID, 'update', 18);
        return;
    }
    
    foreach($cmds as $index => $cmd)
    {
        if(ssh_write_stream($stream, $cmd) === false) return false;
        $bootVersion = ssh_read_stream($stream);
        if(preg_match($versionRegex, $bootVersion, $result)) $checkCounter++;
    }
    if($checkCounter == sizeOf($cmds)) return false;
    return true;
}
/* ==================================================== */

/** ================= CHECK TFTP =================
 * Check the TFTP server address and the availibility
 *
 * Perform a verification of the TFTP server address with a regex that accepts
 * IPv4 and IPv6 and ping the server to see if it's alive.
 * 
 * @param   integer $deviceID:      the ID of the device (host.id)
 * @param   string  $tftpAddress:   the IPv4 or IPv6 address of the TFTP server
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_check_tftp($deviceID, $tftpAddress)
{   // Regex allows IPv4 and IPv6 (must be exhaustive)
    $regexIP = "/(^|\s|(\[))(::)?([a-f\d]{1,4}::?){0,7}(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}"
              ."(?=(?(2)\]|($|\s|(?(3)($|\s)|(?(4)($|\s)|:\d)))))|((?(3)[a-f\d]{1,4})|(?(4)"
              ."[a-f\d]{1,4}))(?=(?(2)\]|($|\s))))(?(2)\])(:\d{1,5})?/";
    preg_match($regexIP, $tftpAddress, $result);
    if($result[0] != $tftpAddress) return false;

    // Ping server to check if up
    $ping = shell_exec("ping -c 1 -s 64 -t 64 -w 1 " . $tftpAddress);

    if(empty($ping)) return false; // Down
    return true; // Up
}
/* ==================================================== */

/** ================= CHECK UPLOAD =================
 * Perform the upload of the new image with a SNMP method with ciscoFlashCopy
 *
 * @param   array   $deviceInfos:   array containing general informations about the device
 * @param   array   $snmpInfos:     array containing SNMP informations for the device
 * @return  string  'finish' if successful, 'progress' if in progress, 'error' otherwise
 */
function ciscotools_upgrade_check_upload($deviceInfos, $snmpInfos)
{   // Check upload status
    $uploadStatus = ciscotools_upgrade_snmp_walk($snmpInfos['version'], $snmpInfos['community'], $deviceInfos['hostname'], '1.3.6.1.4.1.9.9.10.1.2.1.1.8' . $snmpInfos['session'], $snmpInfos);
    $statusUploadRegex = "/INTEGER: ([0-9])$/";
    preg_match_all($statusUploadRegex, $uploadStatus, $uploadStatusResult);

    if(preg_match($statusUploadRegex, $uploadStatus, $uploadStatusResult))
    {
        if($uploadStatusResult[1] === '1') return 'progress';
        else if($uploadStatusResult[1] === '2')
        {
            ciscotools_log("[DEBUG] Upgrade: upload succeed!");
            ciscotools_upgrade_snmp_set($snmpInfos['version'], $deviceInfos['hostname'], '1.3.6.1.4.1.9.9.10.1.2.1.1.11' . $snmpInfos['session'], "i", "6", $snmpInfos);
        }
        else
        {
            ciscotools_upgrade_table($deviceInfos['id'], 'update', 14);
            ciscotools_log("[ERROR] Upgrade: upload error!");
            return 'error';
        }
    }
    else
    {
        ciscotools_upgrade_table($deviceInfos['id'], 'update', 14);
        ciscotools_log("[ERROR] Upgrade: an error occured with the upload on " . $deviceInfos['description'] . "!");
        return 'error';
    }
}
/* ==================================================== */

/** ================= CHECK IMAGE =================
 * Check if the new image is correctly installed with a SNMP request
 *
 * @param   integer $deviceID:  the ID of the device (host.id)
 * @param   string  $deviceIP:  IP of the device
 * @param   array   $snmpInfos: array containing SNMP informations for the device
 * @param   string  $fileName:  name of the image
 * @param   boolean true if successful, false otherwise
 */
function ciscotools_upgrade_check_image($deviceID, $deviceIP, $snmpInfos, $fileName)
{
    $newImage = ciscotools_upgrade_snmp_walk($snmpInfos['version'], $snmpInfos['community'], $deviceIP, "1.3.6.1.4.1.9.2.1.73", $snmpInfos);
    $regexNewImage = '/' . $fileName . '/';
    if(preg_match($regexNewImage, $newImage, $result))
    {
        if(!$result[0] || $result[0] != $fileName)
        {   // Error in installation
            ciscotools_upgrade_table($deviceID, 'update', 15);
            return false;
        }
    }
    return true;
}
/* ==================================================== */

/* ======================================================================================== */



/* ========================================= SNMP ========================================= */

/** ================= SNMP SET =================
 * Simplified function for snmpset through shell_exec command
 * Choose between the SNMPv2c and SNMPv3
 *
 * @param   string  $snmpVersion:   version of SNMP (v2c or v3)
 * @param   string  $deviceIP:      the IP of the device 
 * @param   string  $oid:           chosen OID
 * @param   string  $snmpDataType:  type of data like int, string...
 * @param   string  $snmpData:      new value to set
 * @param   array   $snmpInfos:     array containing SNMP informations for the device
 * @return
 */
function ciscotools_upgrade_snmp_set($snmpVersion, $deviceIP, $oid, $snmpDataType, $snmpData, $snmpInfos) 
{
    if($snmpVersion == "2c")
    {   // Query for SNMPv2
        $snmpExec = shell_exec("snmpset "
        ."-v " . $snmpVersion . " "
        ."-c 'soivlsn'" 
        ." " . $deviceIP
        ." " . $oid 
        ." " . $snmpDataType 
        ." " . $snmpData);
    }
    else if($snmpVersion == "3")
    {   // Query for SNMPv3
        $snmpExec = shell_exec("snmpset "
        ."-v " . $snmpVersion . " "
        ."-l authPriv "
        ."-u '" . $snmpInfos['username'] . "' "
        ."-a " . $snmpInfos['authProtocol'] . " "
        ."-A '". $snmpInfos['password'] . "' "
        ."-x " . $snmpInfos['privProtocol'] . " "
        ."-X " . $snmpInfos['privPassphrase'] . " "
        . $deviceIP . " "
        . $oid . " "
        . $snmpDataType . " "
        . $snmpData);
    }

    return;
}
/* ==================================================== */

/** ================= SNMP WALK =================
 * Simplified function for snmpwalk through shell_exec command
 * Choose between the SNMPv2c and SNMPv3
 *
 * @param   string  $snmpVersion:   version of SNMP (2c or 3)
 * @param   string  $snmpCommunity: RO community
 * @param   string  $deviceIP:      the IP of the device 
 * @param   string  $oid:           chosen OID
 * @param   array   $snmpInfos:     array containing SNMP informations for the device
 * @param   string  $snmpExec:      SNMP Query result
 */
function ciscotools_upgrade_snmp_walk($snmpVersion, $snmpCommunity, $deviceIP, $oid, $snmpInfos) 
{
    //ciscotools_log($snmpVersion);
    if($snmpVersion == "2c")
    {   // Query for SNMPv2
        $snmpExec = shell_exec("snmpwalk "
        ."-v " . $snmpVersion . " "
        ."-c " . $snmpCommunity 
        ." " . $deviceIP
        ." " . $oid);
    }
    else if($snmpVersion == "3")
    {
        // Query for SNMPv3
        $snmpExec = shell_exec("snmpwalk "
        ."-v " . $snmpVersion . " "
        ."-l authPriv "
        ."-u '" . $snmpInfos['username'] . "' "
        ."-a " . $snmpInfos['authProtocol'] . " "
        ."-A '". $snmpInfos['password'] . "' "
        ."-x " . $snmpInfos['privProtocol'] . " "
        ."-X " . $snmpInfos['privPassphrase'] . " "
        . $deviceIP . " "
        . $oid);
    }
    else return false;

    return $snmpExec;
}
/* ==================================================== */

/* ======================================================================================== */



/* ========================================= MISC ========================================= */

/** ================= IMAGE UPLOAD =================
 * Upload of the new image on the device
 * 
 * Perform the upload of the new image with a SNMP method with ciscoFlashCopy
 * There is 9 steps:
 * 1: Delete a security delete of the ciscoFlashCopy session
 * 2: Initialize the upload
 * 3: Set the command type to 'Copy without erase'
 * 4: Set the upload protocol to 'TFTP'
 * 5: Set the TFTP server address
 * 6: Set the source filename (image)
 * 7: Set the destination filename (image)
 * 8: Begin the upload
 * 9: Check if upload has begun
 *
 * @param   integer $deviceID:      the ID of the device (host.id)
 * @param   array   $infos:         array containing all device informations
 * @param   string  $tftpAddress:   the IPv4 or IPv6 address of the TFTP server
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_upload_image($deviceID, $infos, $tftpAddress)
{   // OIDs
    $snmpFlashCopy  = "1.3.6.1.4.1.9.9.10.1.2.1.1"; // ciscoFlashCopyEntry
    $snmpStatus     = $snmpFlashCopy . ".8";        // ciscoFlashCopyStatus
    $snmpCmd        = $snmpFlashCopy . ".2";        // ciscoFlashCopyCommand
    $snmpProt       = $snmpFlashCopy . ".3";        // ciscoFlashCopyProtocol
    $snmpIP         = $snmpFlashCopy . ".4";        // ciscoFlashCopyServerAddress
    $snmpFileSrc    = $snmpFlashCopy . ".5";        // ciscoFlashCopySourceName
    $snmpFileDest   = $snmpFlashCopy . ".6";        // ciscoFlashCopyDestinationName
    $snmpVerify     = $snmpFlashCopy . ".12";       // ciscoFlashCopyVerify
    $snmpEntryStatus= $snmpFlashCopy . ".11";       // ciscoFlashCopyEntryStatus

    ciscotools_upgrade_snmp_set($infos['snmp']['version'], $infos['device']['hostname'], $snmpEntryStatus . $infos['snmp']['session'], "i", "6", $infos['snmp']);                        // Delete session
    ciscotools_upgrade_snmp_set($infos['snmp']['version'], $infos['device']['hostname'], $snmpEntryStatus . $infos['snmp']['session'], "i", "5", $infos['snmp']);                        // Initialize
    ciscotools_upgrade_snmp_set($infos['snmp']['version'], $infos['device']['hostname'], $snmpCmd . $infos['snmp']['session'], "i", "2", $infos['snmp']);                                // Cmd type - 2:Copy w/o erase
    ciscotools_upgrade_snmp_set($infos['snmp']['version'], $infos['device']['hostname'], $snmpProt . $infos['snmp']['session'], "i", "1", $infos['snmp']);                               // Protocole - 1:TFTP
    ciscotools_upgrade_snmp_set($infos['snmp']['version'], $infos['device']['hostname'], $snmpIP . $infos['snmp']['session'], "a", $tftpAddress, $infos['snmp']);                        // Server Address
    ciscotools_upgrade_snmp_set($infos['snmp']['version'], $infos['device']['hostname'], $snmpFileSrc . $infos['snmp']['session'], "s", $infos['model']['image'], $infos['snmp']);      // Source File
    ciscotools_upgrade_snmp_set($infos['snmp']['version'], $infos['device']['hostname'], $snmpFileDest . $infos['snmp']['session'], "s", $infos['model']['image'], $infos['snmp']);     // Dest File
    ciscotools_upgrade_snmp_set($infos['snmp']['version'], $infos['device']['hostname'], $snmpVerify . $infos['snmp']['session'], "i", "1", $infos['snmp']);                             // Verify integrity
    ciscotools_upgrade_snmp_set($infos['snmp']['version'], $infos['device']['hostname'], $snmpEntryStatus . $infos['snmp']['session'], "i", "1", $infos['snmp']);                        // Begin Upload
    sleep(2); // Wait - Do not change this line!

    $uploadStatus = ciscotools_upgrade_snmp_walk($infos['snmp']['version'], $infos['snmp']['community'], $infos['device']['hostname'], $snmpStatus . $infos['snmp']['session'], $infos['snmp']);
    $statusUploadRegex = "/INTEGER: ([0-9])$/";
    preg_match($statusUploadRegex, $uploadStatus, $uploadStatus);
    if(sizeof($uploadStatus) < 1) return false;
    else
    {
        if($uploadStatus[1] === '1') ciscotools_upgrade_table($infos['device']['id'], 'update', 2);
        else return false;
    }
    return true;
}
/* ==================================================== */

/** ================= IMAGES ERASE =================
 * Perform a deletion of the old installed images.
 *
 * @param   array   $deviceInfos:   array containing general informations about the device
 * @param   string  $fileName:      name of the image
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_delete_images($deviceInfos, $fileName)
{
    $stream = create_ssh($deviceInfos['id']);
    if($stream === false)
    {
        ciscotools_upgrade_table($deviceID, 'update', 18);
        return false;
    }
    if(ssh_write_stream($stream, 'dir') === false) return false;
    $directory = ssh_read_stream($stream);

    $regexImages = '/[a-zA-Z0-9-.]{0,}.bin/';
    preg_match_all($regexImages, $directory, $result);

    $keys = array_keys($result);
    for($i=0;$i<count($result);$i++)
    {
        foreach($result[$keys[$i]] as $key => $value)
        {
            if($value !== $fileName)
            {
                if(ssh_write_stream($stream, 'delete /f /r ' . $value) === false) return false;
                ssh_read_stream($stream);
            }
        }
    }

    if(ssh_write_stream($stream, 'wr') === false) return false;
    ssh_read_stream($stream);
    close_ssh($stream);
    return true;
}
/* ==================================================== */

/* ======================================================================================== */
?>