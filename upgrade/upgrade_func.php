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
 *
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_device_check( $device_id=null) {
	$ret = true;
ciscotools_log('Devices upgrade check' );

	$sqlField = '';
	if( $device_id != null ) {
		// if we provide ID, check just this device, but still only if image upgrade exist
		$sqlField = 'AND host.id = '. $device_id;
	} else {
		// check all device where an image exist
		$sqlField = "AND host.type <> '' AND host.disabled !='on' AND host.status ='3'
                AND NOT EXISTS (
                    SELECT plugin_ciscotools_upgrade.host_id FROM plugin_ciscotools_upgrade 
                    WHERE host.id = plugin_ciscotools_upgrade.host_id
                    AND plugin_ciscotools_upgrade.status IN (".UPGRADE_STATUS_PENDING.",".UPGRADE_STATUS_CHECKING.",".
					UPGRADE_STATUS_UPLOADING.",".UPGRADE_STATUS_ACTIVATING.",".UPGRADE_STATUS_REBOOTING.",".
					UPGRADE_STATUS_UPGRADE_DISABLED.",".UPGRADE_STATUS_IN_TEST.",".UPGRADE_STATUS_NEED_REBOOT.",".
					UPGRADE_STATUS_NEED_COMMIT.",".UPGRADE_STATUS_FORCE_REBOOT_COMMIT.",".UPGRADE_STATUS_ACTIVATING_ERROR.") 
					 ORDER BY host.id )";
	}
	// remove device Down, disabled and non Cisco type
	// validaded only device that are in the upgrade table
	// except staus: 1,2,3,4,5,11,20,7,8,23,24
	// or on a specific device
    $sqlQuery = "SELECT host.id as 'id', host.type as 'type', host.hostname as 'hostname', host.description as 'description', 
                plugin_ciscotools_image.image as 'image', host.status as 'status',
				plugin_ciscotools_image.command as 'command',
				plugin_ciscotools_image.regex as 'regex'
                FROM host 
				LEFT JOIN plugin_extenddb_model ON host.type = plugin_extenddb_model.model
                LEFT JOIN plugin_ciscotools_image ON plugin_extenddb_model.id = plugin_ciscotools_image.model_id 
                WHERE host.type IN (
                    SELECT plugin_extenddb_model.model FROM plugin_extenddb_model WHERE host.type = plugin_extenddb_model.model
					AND plugin_extenddb_model.id = plugin_ciscotools_image.model_id
                ) "
				. $sqlField;
				
    $result = db_fetch_assoc($sqlQuery);
ciscotools_log('upgrade check sql query:'.$sqlQuery );
ciscotools_log('upgrade check sql answer:'. print_r( $result, true ) );

	if( sizeof($result) ) {
		$ret = false;
	}
	
    foreach($result as $device)
    {   
		if( $device['status'] != '3' && $device_id == null ) // when adding new device, it's on 0
		continue;

        $stream = create_ssh($device['id']);
        if($stream === false)
        {
            ciscotools_upgrade_table($device['id'], 'add', UPGRADE_STATUS_SSH_ERROR);
            continue;
        }
	    $cmds = explode("&", $device['command'] );
		$version = $cmds[1];
        if(ssh_write_stream($stream, $version ) === false) continue;
        $sshResult = ssh_read_stream($stream);
        close_ssh($stream);

ciscotools_log("device: ".$device['description'].' ssh result: '. $sshResult );
		$rules = $device['regex'].'i';
		$regex = preg_match( $rules, $sshResult, $res );
		$actualImage = ltrim(trim($res[2], " \""), " \"");

ciscotools_log("device: ".$device['description'].' Actual Image: '. $actualImage );
ciscotools_log("device: ".$device['description'].' Desired Image: '. $device['image'] );

        if( (strcasecmp( $actualImage, 'unknown') == 0 ) || (strcasecmp( $actualImage, 'packages.conf') == 0 ) )
        {   // Error in table 'upgrade'
            ciscotools_upgrade_table($device['id'], 'add', UPGRADE_STATUS_IMAGE_INFO_ERROR, $actualImage);
            continue;
        }
        if(strcasecmp($device['image'], $actualImage) == 0)
        {   // Up to date
            ciscotools_upgrade_table($device['id'], 'add', UPGRADE_STATUS_UPDATE_OK, $actualImage);
            continue;
        }
        else 
        {   // Need to be upgraded
            ciscotools_upgrade_table($device['id'], 'add', UPGRADE_STATUS_NEED_UPGRADE, $actualImage);
        }
    }
	
	return $ret;
}

/** ================= CHECK MODEL =================
 * Check if the devices model
 *
 * Perform a model verification
 * If the query is not successful, the model is not supported
 *
 * @param   string  $type: the device model
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_check_model($type) {
    $sqlQuery = "SELECT plugin_ciscotools_image.image 
	FROM plugin_ciscotools_image 
	LEFT JOIN plugin_extenddb_model ON plugin_extenddb_model.id = plugin_ciscotools_image.model_id 
	WHERE plugin_extenddb_model.model = '$type'";
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
 * @param   array   $infos:     array containing all device informations from host DB
 * @return  boolean true if successful, false otherwise
 */
 
 // NOT USED ANYMORE
function ciscotools_upgrade_check_version($deviceID, $infos) {
	// See if file is in flash: and set in bootvar
    $cmds = explode("&", $infos['model']['sshCmds_mode']); // replace | by &
    $checkCounter = 0;
    $versionRegex = "/" . $infos['model']['image'] . "/";

    $stream = create_ssh($deviceID);
    if($stream === false)
    {
        ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_SSH_ERROR);
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

/** ================= CHECK UPLOAD =================
 * Perform the upload of the new image with a SNMP method with ciscoFlashCopy
 *
 * @param   array   $Infos:   array containing informations about the device
 * @return  string  'finish' if successful, 'progress' if in progress, 'error' otherwise
 */
function ciscotools_upgrade_check_upload($infos) {
ciscotools_log("snmp upload query: " .print_r($infos, true ) );
	// Check upload status
	$uploadStatus = cacti_snmp_walk( $infos['device']['hostname'], $infos['snmp']['snmp_community'], 
	CISCOTOOLS_SNMP_FLASHCOPY['status'] . $infos['snmp']['session'], 
	$infos['snmp']['snmp_version'], $infos['snmp']['snmp_username'], $infos['snmp']['snmp_password'], 
	$infos['snmp']['snmp_auth_protocol'], $infos['snmp']['snmp_priv_passphrase'], 
	$infos['snmp']['snmp_priv_protocol'], $infos['snmp']['snmp_context'] ); 

ciscotools_log("device: ".$infos['device']['description']." snmp upload status: " .print_r($uploadStatus, true ) );

	if( empty($uploadStatus) ) {
		$stream = create_ssh($infos['device']['id']);
		if($stream === false)
		{
			ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_SSH_ERROR);
			return false;
		}
		if(ssh_write_stream($stream, 'dir') === false) {
			ciscotools_log("snmp upload write error ".$infos['device']['description'] );
			return false;
		}
		$directory = ssh_read_stream($stream);
ciscotools_log('device: '.$infos['device']['description']." snmp upload status directory : ". print_r( $directory, true ) );
		close_ssh($stream);
		if( stripos( $directory, $infos['model']['image'] ) !== false ) {
			return;
		} 
		return false;
		
	}

/*
 Status:
0 : copyOperationPending
1 : copyInProgress
2 : copyOperationSuccess
3 : copyInvalidOperation
4 : copyInvalidProtocol
5 : copyInvalidSourceName
6 : copyInvalidDestName
7 : copyInvalidServerAddress
8 : copyDeviceBusy
9 : copyDeviceOpenError
10 : copyDeviceError
11 : copyDeviceNotProgrammable
12 : copyDeviceFull
13 : copyFileOpenError
14 : copyFileTransferError
15 : copyFileChecksumError
16 : copyNoMemory
17 : copyUnknownFailure
18 : copyInvalidSignature
19 : copyProhibited
*/
	if($uploadStatus[0]['value'] === '1') return 'progress';
    else if($uploadStatus[0]['value'] === '2')
    {
        ciscotools_log("[DEBUG] Upgrade: upload succeed on ". $infos['device']['description'] . "!");
        ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], CISCOTOOLS_SNMP_FLASHCOPY['entryStatus'] . $infos['snmp']['session'], "i", "6", $infos['snmp']);
    }
    else
    {
        ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_UPLOAD_ERROR);
		ciscotools_log("[ERROR] Upgrade: an error occured with the upload on " . $infos['device']['description'] . "!");
        return 'error';
    }
}

/** ================= CHECK IMAGE =================
 * Check if the new image is correctly installed with a SNMP request
 *
 * @param   integer $deviceID:  the ID of the device (host.id)
 * @param   string  $deviceIP:  IP of the device
 * @param   array   $snmpInfos: array containing SNMP informations for the device
 * @param   string  $fileName:  name of the image
 * @param   boolean true if successful, false otherwise
 */
function ciscotools_upgrade_check_image($infos )
{
	$newImage = cacti_snmp_walk( $infos['device']['hostname'], $infos['snmp']['snmp_community'], 
	CISCOTOOLS_SNMP_IMAGE, 
	$infos['snmp']['snmp_version'], $infos['snmp']['snmp_username'], $infos['snmp']['snmp_password'], 
	$infos['snmp']['snmp_auth_protocol'], $infos['snmp']['snmp_priv_passphrase'], 
	$infos['snmp']['snmp_priv_protocol'], $infos['snmp']['snmp_context'] ); 
	// SNMPv2-SMI::enterprises.9.2.1.73.0 = STRING: "flash:/c3560cx-universalk9-mz.152-6.E2.bin"

ciscotools_log('device: '.$infos['device']['description']." snmp upgrade check image: " .print_r($newImage, true ) );
ciscotools_log('device: '.$infos['device']['description']." snmp upgrade check imageold : " .$infos['model']['image'] );
    if(stripos( $newImage[0]['value'], $infos['model']['image']) === false )
    {
		// Error in installation
        ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_CHECKING_ERROR);
        return false;
    }
	ciscotools_upgrade_table($infos['device']['id'], 'add', UPGRADE_STATUS_UPDATE_OK, $newImage[0]['value']);

    return true;
}

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
	ciscotools_log("snmp set V2: ". $oid.' data: '. $snmpData);
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
	ciscotools_log("snmp set V3: ". $oid.' data: '. $snmpData);
        $snmpExec = shell_exec("snmpset "
        ."-v3 "
        ."-l authPriv "
        ."-u '" . $snmpInfos['snmp_username'] . "' "
        ."-a " . $snmpInfos['snmp_auth_protocol'] . " "
        ."-A '". $snmpInfos['snmp_password'] . "' "
        ."-x " . $snmpInfos['snmp_priv_protocol'] . " "
        ."-X " . $snmpInfos['snmp_priv_passphrase'] . " "
        . $deviceIP . " "
        . $oid . " "
        . $snmpDataType . " "
        . $snmpData);
    }

    return;
}

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
function ciscotools_upgrade_upload_image($deviceID, $infos, $tftpAddress) {

// Need to check if images is on the disk

	// OIDs
    $snmpFlashCopy  = "1.3.6.1.4.1.9.9.10.1.2.1.1"; // ciscoFlashCopyEntry
    $snmpStatus     = $snmpFlashCopy . ".8";        // ciscoFlashCopyStatus
    $snmpCmd        = $snmpFlashCopy . ".2";        // ciscoFlashCopyCommand
    $snmpProt       = $snmpFlashCopy . ".3";        // ciscoFlashCopyProtocol
    $snmpIP         = $snmpFlashCopy . ".4";        // ciscoFlashCopyServerAddress
    $snmpFileSrc    = $snmpFlashCopy . ".5";        // ciscoFlashCopySourceName
    $snmpFileDest   = $snmpFlashCopy . ".6";        // ciscoFlashCopyDestinationName
    $snmpVerify     = $snmpFlashCopy . ".12";       // ciscoFlashCopyVerify
    $snmpEntryStatus= $snmpFlashCopy . ".11";       // ciscoFlashCopyEntryStatus

    // Delete session .11
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], $snmpEntryStatus . $infos['snmp']['session'], "i", "6", $infos['snmp']);                        
    // Initialize .11
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], $snmpEntryStatus . $infos['snmp']['session'], "i", "5", $infos['snmp']);                        
    // Cmd type - 2:Copy w/o erase
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], $snmpCmd . $infos['snmp']['session'], "i", "2", $infos['snmp']);                                
    // Protocole - 1:TFTP
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], $snmpProt . $infos['snmp']['session'], "i", "1", $infos['snmp']);                               
    // Server Address
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], $snmpIP . $infos['snmp']['session'], "a", $tftpAddress, $infos['snmp']);                        

    // Source File
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], $snmpFileSrc . $infos['snmp']['session'], "s", $infos['model']['image'], $infos['snmp']);      
	
    // Dest File
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], $snmpFileDest . $infos['snmp']['session'], "s", $infos['model']['image'], $infos['snmp']);     
    // Verify integrity
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], $snmpVerify . $infos['snmp']['session'], "i", "1", $infos['snmp']);                             
    // Begin Upload
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], $snmpEntryStatus . $infos['snmp']['session'], "i", "1", $infos['snmp']); 
	
    sleep(2); // Wait - Do not change this line!

	// query the status of the transfert order
	$uploadStatus = cacti_snmp_walk( $infos['device']['hostname'], $infos['snmp']['snmp_community'], $snmpStatus . $infos['snmp']['session'], 
	$infos['snmp']['snmp_version'], $infos['snmp']['snmp_username'], $infos['snmp']['snmp_password'], 
	$infos['snmp']['snmp_auth_protocol'], $infos['snmp']['snmp_priv_passphrase'], 
	$infos['snmp']['snmp_priv_protocol'], $infos['snmp']['snmp_context'] ); 
ciscotools_log('device: '.$infos['device']['description']." upload snmp walk: ".print_r($uploadStatus, true) );
 /*
 Status:
0 : copyOperationPending
1 : copyInProgress
2 : copyOperationSuccess
3 : copyInvalidOperation
4 : copyInvalidProtocol
5 : copyInvalidSourceName
6 : copyInvalidDestName
7 : copyInvalidServerAddress
8 : copyDeviceBusy
9 : copyDeviceOpenError
10 : copyDeviceError
11 : copyDeviceNotProgrammable
12 : copyDeviceFull
13 : copyFileOpenError
14 : copyFileTransferError
15 : copyFileChecksumError
16 : copyNoMemory
17 : copyUnknownFailure
18 : copyInvalidSignature
19 : copyProhibited
*/

	if(empty($uploadStatus) ){
		ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_CHECKING_ERROR);
		return false;
	}
	
    if($uploadStatus[0]['value'] > 2) {
		ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_UPLOAD_ERROR);
		return false;
	}
    else
    {
        if($uploadStatus[0]['value'] === '1') ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_UPLOADING);
        else return false;
    }
    return true;
}

/** ================= IMAGES ERASE =================
 * Perform a deletion of the old installed images.
 *
 * @param   array   $infos:   array containing general informations about the device
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_delete_images($infos)
{
    $stream = create_ssh($infos['device']['id']);
    if($stream === false)
    {
        ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_SSH_ERROR);
        return false;
    }

 	// delete the image from DB, who is the old one
	$sqlSelect ="SELECT plugin_ciscotools_upgrade.image as image
				FROM plugin_ciscotools_upgrade
				LEFT JOIN host ON host.id = plugin_ciscotools_upgrade.host_id
				WHERE host_id = ".$infos['device']['id'];
    $oldImage = db_fetch_cell($sqlSelect); // Get infos for old running image

	$result = explode('/', $oldImage );
	if( count($result) ) {
		ssh_write_stream($stream, 'delete /f /r ' . $result[0]);
		ssh_read_stream($stream);
	}

    if(ssh_write_stream($stream, 'dir') === false) return false;
    $directory = ssh_read_stream($stream);

	// then delete other file
    $regexImages = '/[a-zA-Z0-9-.]{0,}.bin/';
    preg_match_all($regexImages, $directory, $result);
    $keys = array_keys($result);
    for($i=0;$i<count($result);$i++)
    {
        foreach($result[$keys[$i]] as $key => $value)
        {
			if($value !== $infos['model']['image'])
            {
ciscotools_log('device: '.$infos['device']['id'].' delete: '.$value);
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

/* ======================================================================================== */
?>