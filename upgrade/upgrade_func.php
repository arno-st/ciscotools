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
upgrade_log('UPG: ciscotools_upgrade_device_check: '.$device_id );

	$sqlField = '';
	if( $device_id != null ) {
		// if we provide ID, check just this device, but still only if image upgrade exist
		$sqlField = 'AND host.id = '. $device_id;
	} else {
		// check all device where an image exist
		$sqlField = "AND plugin_extenddb_host_model.model <> '' AND host.disabled !='on' AND host.status ='3'
                AND NOT EXISTS (
                    SELECT plugin_ciscotools_upgrade.host_id FROM plugin_ciscotools_upgrade 
                    WHERE host.id = plugin_ciscotools_upgrade.host_id
                    AND plugin_ciscotools_upgrade.status IN (".UPGRADE_STATUS_PENDING.",".UPGRADE_STATUS_CHECKING.",".
					UPGRADE_STATUS_UPLOADING.",".UPGRADE_STATUS_ACTIVATING.",".UPGRADE_STATUS_REBOOTING.",".
					UPGRADE_STATUS_UPGRADE_DISABLED.",".UPGRADE_STATUS_IN_TEST.",".UPGRADE_STATUS_NEED_REBOOT.",".
					UPGRADE_STATUS_NEED_COMMIT.",".UPGRADE_STATUS_FORCE_REBOOT_COMMIT.",".UPGRADE_STATUS_ACTIVATING_ERROR.") 
					 ORDER BY host.id )";
	}
	// remove device Down, disabled and non Cisco model
	// validaded only device that are in the upgrade table
	// except status: 1,2,3,4,5,11,20,7,8,23,24
	// or on a specific device
    $sqlQuery = "SELECT host.id as 'id', plugin_extenddb_host_model.model as 'model', host.hostname as 'hostname', host.description as 'description', 
                plugin_ciscotools_image.image as 'image', host.status as 'status',
				plugin_ciscotools_image.command as 'command',
				plugin_ciscotools_image.regex as 'regex',
				plugin_ciscotools_image.size as 'size'
                FROM host 
				INNER JOIN plugin_extenddb_host_model ON plugin_extenddb_host_model.host_id=host.id
				LEFT JOIN plugin_extenddb_model ON plugin_extenddb_host_model.model = plugin_extenddb_model.model
                LEFT JOIN plugin_ciscotools_image ON plugin_extenddb_model.id = plugin_ciscotools_image.model_id 
                WHERE plugin_extenddb_host_model.model IN (
                    SELECT plugin_extenddb_model.model FROM plugin_extenddb_model WHERE plugin_extenddb_host_model.model = plugin_extenddb_model.model
					AND plugin_extenddb_model.id = plugin_ciscotools_image.model_id
                ) "
				. $sqlField;
    $result = db_fetch_assoc($sqlQuery);
upgrade_log('UPG: ciscotools_upgrade_device_check sql query:'.$sqlQuery );
	if( empty($result) ){
		$sqlQuery = "SELECT host.id as 'id', plugin_extenddb_host_model.model as 'model', host.hostname as 'hostname', host.description as 'description', host.status as 'status',
			plugin_ciscotools_image.image as 'image',  plugin_ciscotools_image.command as 'command', 
			plugin_ciscotools_image.regex as 'regex', plugin_ciscotools_image.size as 'size' 
			FROM host 
			LEFT JOIN plugin_extenddb_host_model ON plugin_extenddb_host_model.host_id=host.id 
			LEFT JOIN plugin_extenddb_model ON plugin_extenddb_host_model.model = plugin_extenddb_model.model 
			LEFT JOIN plugin_ciscotools_image ON plugin_extenddb_model.id = plugin_ciscotools_image.model_id 
			WHERE host.id = "
				. $device_id;
    $result = db_fetch_assoc($sqlQuery);
//upgrade_log('UPG: ciscotools_upgrade_device_check sql answer:'. print_r( $result, true ) );
	}
// si pas de résultat, pas de changement
	if( empty($result) ) {
		return false;
	}
	
    foreach($result as $device)
    {
		// if no command can't test it
		if( empty($device['command']) ) {
			return false;
		}
	// if device status is something that need manual interaction, 
		// don't check
		if( $device['status'] == UPGRADE_STATUS_NEED_COMMIT || $device['status'] == UPGRADE_STATUS_NEED_REBOOT )
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

upgrade_log("UPG: ciscotools_upgrade_device_check : ".$device['description'].' ssh result: '. $sshResult );
               
		$rules = $device['regex'].'i';
        $regex = preg_match( $rules, $sshResult, $res );
        $actualVersion = ltrim(trim($res[1], " \""), " \"");

upgrade_log("UPG: ciscotools_upgrade_device_check actualVersion: ".$device['description'].' Actual Version: '. $actualVersion );
upgrade_log("UPG: ciscotools_upgrade_device_check request image: ".$device['description'].' desired Image: '. $device['image'] );

$filter = array('.', '(', ')', '-' );
$actualCompVersion = str_replace( $filter, '', $actualVersion);

$reg_level = "/([a-z0-9A-Z-_]*)\.([0-9-.a-zA-Z]+\.).*/";
$regex = preg_match( $reg_level, $device['image'], $res );
$requestVersion = str_replace('.0', '.', $res[2]);

$filter = array( '.', '(', ')', 'SPA', '-' );
$requestCompVersion = str_replace( $filter, '', $requestVersion );

upgrade_log("UPG: ciscotools_upgrade_device_check : ".$device['description'].' requestVersion: '. $requestVersion );

upgrade_log("UPG: ciscotools_upgrade_device_check : ".$device['description'].' actualCompVersion: '. $actualCompVersion );
upgrade_log("UPG: ciscotools_upgrade_device_check : ".$device['description'].' requestCompVersion: '. $requestCompVersion );

        if( (strcasecmp( $actualCompVersion, 'unknown') == 0 ) || (strcasecmp( $actualCompVersion, 'packages.conf') == 0 ) )
        {   // Error in table 'upgrade'
            ciscotools_upgrade_table($device['id'], 'add', UPGRADE_STATUS_IMAGE_INFO_ERROR, $actualVersion);
            continue;
        }

		if( (strcasecmp( $requestCompVersion, $actualCompVersion ) == -1 ) || (strcasecmp( $requestCompVersion, $actualCompVersion ) == 0 ) ){
			// Up to date
upgrade_log("UPG: ciscotools_upgrade_device_check: ".$device['description'] ." Up to date");
			ciscotools_upgrade_table($device['id'], 'add', UPGRADE_STATUS_UPDATE_OK, $actualVersion);
			continue;
		}
		// Need to be upgraded
upgrade_log("UPG: ciscotools_upgrade_device_check: ".$device['description'] ." Need upgrade");
		ciscotools_upgrade_table($device['id'], 'add', UPGRADE_STATUS_NEED_UPGRADE, $actualVersion);
    }
	
	return $ret;
}

/** ================= CHECK MODEL =================
 * Check if the devices model
 *
 * Perform a model verification
 * If the query is not successful, the model is not supported
 *
 * @param   string  $model: the device model
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_check_model($model) {
    $sqlQuery = "SELECT plugin_ciscotools_image.image 
	FROM plugin_ciscotools_image 
	LEFT JOIN plugin_extenddb_model ON plugin_extenddb_model.id = plugin_ciscotools_image.model_id 
	WHERE plugin_extenddb_model.model = '$model'";
    $sqlExec = db_fetch_row_prepared($sqlQuery);
    if(!$sqlExec) return false;
    return true;
}

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
function ciscotools_upgrade_check_tftp($deviceID, $tftpAddress) {   // Regex allows IPv4 and IPv6 (must be exhaustive)
    $regexIP = "/(^|\s|(\[))(::)?([a-f\d]{1,4}::?){0,7}(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}"
              ."(?=(?(2)\]|($|\s|(?(3)($|\s)|(?(4)($|\s)|:\d)))))|((?(3)[a-f\d]{1,4})|(?(4)"
              ."[a-f\d]{1,4}))(?=(?(2)\]|($|\s))))(?(2)\])(:\d{1,5})?/";
    preg_match($regexIP, $tftpAddress, $result);
    if($result[0] != $tftpAddress) return false;

    // Ping server to check if up
    if($result[0] != $tftpAddress) return false;

    // Ping server to check if up
    $ping = exec("ping -c 1 -s 64 -t 64 -w 1 $tftpAddress", $outcome, $status);

upgrade_log('UPG: ciscotools_upgrade_check_tftp: '.$tftpAddress." info: ".print_r($status, true) );
    if($status == 1) return false; // Down
    return true; // Up
}

/** ================= CHECK UPLOAD =================
 * Perform the upload of the new image with a SNMP method with ciscoFlashCopy
 *
 * @param   array   $Infos:   array containing informations about the device
 * @return  string  'finish' if successful, 'progress' if in progress, 'error' otherwise
 */
function ciscotools_upgrade_check_upload($infos) {
	// Check upload status
	$uploadStatus = cacti_snmp_get( $infos['device']['hostname'], $infos['snmp']['snmp_community'], 
	CISCOTOOLS_SNMP_FLASHCOPY['status'] . $infos['snmp']['session'], 
	$infos['snmp']['snmp_version'], $infos['snmp']['snmp_username'], $infos['snmp']['snmp_password'], 
	$infos['snmp']['snmp_auth_protocol'], $infos['snmp']['snmp_priv_passphrase'], 
	$infos['snmp']['snmp_priv_protocol'], $infos['snmp']['snmp_context'], $infos['snmp']['snmp_port'], $infos['snmp']['snmp_timeout'], 5 ); 

upgrade_log("UPG: ciscotools_upgrade_check_upload device: ".$infos['device']['description']." snmp upload status: " .print_r($uploadStatus, true ) );

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
null : pas d'info que faire !!
ciscotools_upgrade_check_upload device: se-se46-8507 snmp upload status: 2 
*/

	if( $uploadStatus === '' || $uploadStatus === null ) {
		//$infos['model']['image']
upgrade_log("UPG: ciscotools_upgrade_check_upload size expected " . $infos['device']['description'] . " size: ".$infos['model']['size']);
		if( $infos['model']['size'] > 0 ){
			$stream = create_ssh($infos['device']['id']);
			if($stream === false)
			{
				ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_SSH_ERROR);
				return false;
			}

			if(ssh_write_stream($stream, "dir flash: | inc " . $infos['model']['image']) === false){
		        ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_UPLOAD_ERROR);
upgrade_log("UPG: ciscotools_upgrade_check_upload error occured with the dir flash: cmd " . $infos['device']['description'] . "!");
				close_ssh($stream);
				return 'error';
			}
			
			$uploadStatus = ssh_read_stream($stream);
			close_ssh($stream);
			$uploadStatus = trim(preg_replace('/\s+/', ' ', $uploadStatus));
			$regexSpaceUsed = '/('.$infos['model']['image'].' )([0-9]*) *([-rwx]+) *([0-9]+).+/im';

			$regexMatch = preg_match($regexSpaceUsed, $uploadStatus, $matchSpaceUsed );
upgrade_log("UPG: ciscotools_upgrade_check_upload read stream ".$infos['device']['description']." read :". print_r( $uploadStatus, true) );
upgrade_log("UPG: ciscotools_upgrade_check_upload ".$infos['device']['description']." pattern :". print_r( $regexSpaceUsed, true) );
upgrade_log("UPG: ciscotools_upgrade_check_upload preg_match ".$infos['device']['description']." status : ". print_r( $regexMatch, true) );
upgrade_log("UPG: ciscotools_upgrade_check_upload dir flash: | inc ".$infos['device']['description']." status : ". print_r( $matchSpaceUsed, true) );
			if( !$regexMatch || $matchSpaceUsed[4] != $infos['model']['size'] ){
				ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_UPLOAD_ERROR);
				return 'error';
			}
upgrade_log("UPG: ciscotools_upgrade_check_upload check size ".$infos['device']['description']." rest : ". ($matchSpaceUsed[4] != $infos['model']['size'])?'true':'false' );

			ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], CISCOTOOLS_SNMP_FLASHCOPY['entryStatus'] . $infos['snmp']['session'], "i", "6", $infos['snmp']);
			return;
		}
	}
	
	if($uploadStatus === '1' ){
		return 'progress';
	} else if($uploadStatus === '2') {
upgrade_log("UPG: ciscotools_upgrade_check_upload succeed on ". $infos['device']['description'] . "!");
        ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], CISCOTOOLS_SNMP_FLASHCOPY['entryStatus'] . $infos['snmp']['session'], "i", "6", $infos['snmp']);
    }
    else
    {
        ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_UPLOAD_ERROR);
upgrade_log("UPG: ciscotools_upgrade_check_upload error occured with the upload on " . $infos['device']['description'] . "!");
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
function ciscotools_upgrade_check_image($infos ) {
	$newImage = cacti_snmp_walk( $infos['device']['hostname'], $infos['snmp']['snmp_community'], 
	CISCOTOOLS_SNMP_IMAGE, 
	$infos['snmp']['snmp_version'], $infos['snmp']['snmp_username'], $infos['snmp']['snmp_password'], 
	$infos['snmp']['snmp_auth_protocol'], $infos['snmp']['snmp_priv_passphrase'], 
	$infos['snmp']['snmp_priv_protocol'], $infos['snmp']['snmp_context'] ); 
	// enterprises.9.2.1.73.0 = STRING: "flash:/c3560cx-universalk9-mz.152-6.E2.bin"

upgrade_log('UPG: ciscotools_upgrade_check_image device: '.$infos['device']['description']." snmp upgrade check image: " .print_r($newImage, true ) );
//02/10/2023 23:12:35 - CISCOTOOLS UPG: ciscotools_upgrade_check_image device: se-pama-2026 snmp upgrade check image: Array ( [0] => Array ( [oid] => .1.3.6.1.4.1.9.2.1.73.0 [value] => flash:packages.conf ) )

upgrade_log('UPG: ciscotools_upgrade_check_image device: '.$infos['device']['description']." snmp upgrade check image old : " .$infos['model']['image'] );
//02/10/2023 23:12:35 - CISCOTOOLS UPG: ciscotools_upgrade_check_image device: se-pama-2026 snmp upgrade check image old : cat9k_lite_iosxe.17.09.04.SPA.bin

    if(stripos( $newImage[0]['value'], $infos['model']['image']) === false )
    {
		// query version from mode table
		$queryversion = db_fetch_cell("SELECT plugin_ciscotools_image.image as 'image' FROM host 
		INNER JOIN plugin_extenddb_host_model ON plugin_extenddb_host_model.host_id=host.id
		LEFT JOIN plugin_extenddb_model ON plugin_extenddb_host_model.model = plugin_extenddb_model.model 
		LEFT JOIN plugin_ciscotools_image ON plugin_extenddb_model.id = plugin_ciscotools_image.model_id 
		WHERE plugin_extenddb_host_model.model 
		IN ( SELECT plugin_extenddb_model.model FROM plugin_extenddb_model WHERE plugin_extenddb_host_model.model = plugin_extenddb_model.model AND plugin_extenddb_model.id = plugin_ciscotools_image.model_id ) 
		AND host.id =".$infos['device']['id'] );

upgrade_log('UPG: ciscotools_upgrade_check_image device: '.$infos['device']['description']." check image from model : " .$queryversion );
//02/10/2023 23:12:35 - CISCOTOOLS UPG: ciscotools_upgrade_check_image device: se-pama-2026 check image from model : cat9k_lite_iosxe.17.09.04.SPA.bin

		if( stripos( $queryversion, $infos['model']['image']) === false ) {
			// Error in installation
			ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_IMAGE_INFO_ERROR);
			return false;
		}
		ciscotools_upgrade_table($infos['device']['id'], 'add', UPGRADE_STATUS_UPDATE_OK, $infos['model']['image']);

	} else {
		ciscotools_upgrade_table($infos['device']['id'], 'add', UPGRADE_STATUS_UPDATE_OK, $newImage[0]['value']);
	}

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
function ciscotools_upgrade_snmp_set($snmpVersion, $deviceIP, $oid, $snmpDataType, $snmpData, $snmpInfos) {
    if($snmpVersion == "2c")
    {   // Query for SNMPv2
upgrade_log("UPG: ciscotools_upgrade_snmp_set V2: ". $oid.' data: '. $snmpData);
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
upgrade_log("UPG: ciscotools_upgrade_snmp_set V3: ". $oid.' data: '. $snmpData);
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
 * Upload of the new image on the device via SNMP
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
/*
	// OIDs
    $snmpFlashCopy  = "1.3.6.1.4.1.9.9.10.1.2.1.1"; // ciscoFlashCopyEntry
    $snmpCmd        = $snmpFlashCopy . ".2";        // ciscoFlashCopyCommand
    $snmpProt       = $snmpFlashCopy . ".3";        // ciscoFlashCopyProtocol
    $snmpIP         = $snmpFlashCopy . ".4";        // ciscoFlashCopyServerAddress
    $snmpFileSrc    = $snmpFlashCopy . ".5";        // ciscoFlashCopySourceName
    $snmpFileDest   = $snmpFlashCopy . ".6";        // ciscoFlashCopyDestinationName
    $snmpEntryStatus= $snmpFlashCopy . ".11";       // ciscoFlashCopyEntryStatus
    $snmpVerify     = $snmpFlashCopy . ".12";       // ciscoFlashCopyVerify
*/
    // Delete session .11
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], CISCOTOOLS_SNMP_FLASHCOPY['entryStatus'] . $infos['snmp']['session'], "i", "6", $infos['snmp']);                        
    // Initialize .11
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], CISCOTOOLS_SNMP_FLASHCOPY['entryStatus'] . $infos['snmp']['session'], "i", "5", $infos['snmp']);                        
    // Cmd type - 2:Copy w/o erase
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], CISCOTOOLS_SNMP_FLASHCOPY['cmd'] . $infos['snmp']['session'], "i", "2", $infos['snmp']);                                
    // Protocole - 1:TFTP
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], CISCOTOOLS_SNMP_FLASHCOPY['protocol'] . $infos['snmp']['session'], "i", "1", $infos['snmp']);                               
    // Server Address
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], CISCOTOOLS_SNMP_FLASHCOPY['ip'] . $infos['snmp']['session'], "a", $tftpAddress, $infos['snmp']);                        

    // Source File
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], CISCOTOOLS_SNMP_FLASHCOPY['fileSrc'] . $infos['snmp']['session'], "s", $infos['model']['image'], $infos['snmp']);      
	
    // Dest File
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], CISCOTOOLS_SNMP_FLASHCOPY['fileDst'] . $infos['snmp']['session'], "s", $infos['model']['image'], $infos['snmp']); 
    
    // Verify integrity
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], CISCOTOOLS_SNMP_FLASHCOPY['verify'] . $infos['snmp']['session'], "i", "1", $infos['snmp']);                             
    // Begin Upload
	ciscotools_upgrade_snmp_set($infos['snmp']['snmp_version'], $infos['device']['hostname'], CISCOTOOLS_SNMP_FLASHCOPY['entryStatus'] . $infos['snmp']['session'], "i", "1", $infos['snmp']); 
	
	$count=0;
	do {
		sleep(5); // Wait - Do not change this line!
		// query the status of the transfert order
		$uploadStatus = cacti_snmp_get( $infos['device']['hostname'], $infos['snmp']['snmp_community'], CISCOTOOLS_SNMP_FLASHCOPY['status'] . $infos['snmp']['session'], 
		$infos['snmp']['snmp_version'], $infos['snmp']['snmp_username'], $infos['snmp']['snmp_password'], 
		$infos['snmp']['snmp_auth_protocol'], $infos['snmp']['snmp_priv_passphrase'], 
		$infos['snmp']['snmp_priv_protocol'], $infos['snmp']['snmp_context'] ); 
upgrade_log('UPG: ciscotools_upgrade_upload_image: '.$infos['device']['description']." upload snmp get: ".print_r($uploadStatus, true). ' count: ' .$count );
		// only try a few times, to avoid been stuck in the loop
		if( $count > 10 ) 
			break;
		$count++;
	}
	while( empty($uploadStatus) );
	
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
		ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_UPLOAD_ERROR);
		return false;
	}
	
    if($uploadStatus === '5') {
		ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_IMAGE_INFO_ERROR);
upgrade_log( 'UPG: ciscotools_upgrade_upload_image '.$infos['device']['description'].' Status: '.CISCOTLS_UPG_STATUS[$device['status']]['name'] );
		return false;
	} else if($uploadStatus > 2) {
		ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_UPLOAD_ERROR);
upgrade_log( 'UPG: ciscotools_upgrade_upload_image '.$infos['device']['description'].' Status: '.CISCOTLS_UPG_STATUS[$device['status']]['name'] );
		return false;
	} else
    {
        if($uploadStatus === '1') ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_UPLOADING);
        else return false;
    }

    return true;
}

/** ================= ARCHIVE UPLOAD =================
 * Upload of the new archive on the device and xtract it
 * 
 *
 * @param   integer $deviceID:      the ID of the device (host.id)
 * @param   array   $infos:         array containing all device informations
 * @param   string  $tftpAddress:   the IPv4 or IPv6 address of the TFTP server
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_upload_archive($deviceID, $infos, $tftpAddress) {
	ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_ARCHIVE_EXTRACT);
	
    $stream = create_ssh($infos['device']['id']);
    if($stream === false)
    {
        ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_SSH_ERROR);
        return false;
    }

	if(ssh_write_stream($stream, "archive tar /xtract tftp://". $tftpAddress ."/" . $infos['model']['image'] . " flash:") === false) return false;
	
	// pool until end of upload 'extracting info'
	do {
		$installStatus = ssh_read_stream($stream);
upgrade_log("UPG: ciscotools_upgrade_upload_archive: ".$infos['device']['description']." Archive status : ".print_r($installStatus, true) );
		if( $installStatus === false ) {
			ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_IMAGE_INFO_ERROR);
			break;
		} else if( strpos($installStatus, 'Not enough space')!== false ) {
			ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_NO_SPACE_LEFT );
			break;
		}
	}
	while( stripos($installStatus, 'extracting info' ) !== false && $installStatus !== false);

}


/** ================= SSH copy UPLOAD =================
 * Upload of the new bin on the device, via SSH copy
 * 
 *
 * @param   integer $deviceID:      the ID of the device (host.id)
 * @param   array   $infos:         array containing all device informations
 * @param   string  $tftpAddress:   the IPv4 or IPv6 address of the TFTP server
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_upload_copy($deviceID, $infos, $tftpAddress) {
	$ret = true;
	ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_UPLOADING);
	
    $stream = create_ssh($infos['device']['id']);
    if($stream === false)
    {
        ciscotools_upgrade_table($infos['device']['id'], 'update', UPGRADE_STATUS_SSH_ERROR);
        return false;
    }

	// check space left
    if(ssh_write_stream($stream, 'dir | inc free') === false) return false;
    $spaceleft = ssh_read_stream($stream);
upgrade_log("UPG: ciscotools_upgrade_upload_copy: ".$infos['device']['description']." space left SSH: ".print_r( $spaceleft, true) );		
	$regexSpaceLeft = '/\(([0-9]+)/';
	$regexMatch = preg_match($regexSpaceLeft, $spaceleft, $matchSpaceLeft);

upgrade_log("UPG: ciscotools_upgrade_upload_copy: ".$infos['device']['description']." space left: ".$matchSpaceLeft[1].' requested: '.$infos['model']['size'] );

    if( !$regexMatch || $matchSpaceLeft[1] < $infos['model']['size'] ){
		ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_NO_SPACE_LEFT );
		return false;
	}

	if(ssh_write_stream($stream, "copy tftp://". $tftpAddress ."/" . $infos['model']['image'] . " flash:\r\n") === false) return false;
	
	$regexDownloaded = '/OK - ([0-9]+)/m';
	// pool until end of upload '! info'
	// [OK - 89682320 bytes]

	do {
		$installStatus = ssh_read_stream($stream);
upgrade_log("UPG: ciscotools_upgrade_upload_copy: ".$infos['device']['description']." upload copy : ".print_r($installStatus, true) );
		if( !preg_match($regexDownloaded, $installStatus, $matchDownloaded) ) {
			ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_UPLOAD_ERROR);
			$ret = false;
			return $ret;
		}
			
	}
	while( !preg_match($regexDownloaded, $installStatus, $matchDownloaded) );
upgrade_log("UPG: ciscotools_upgrade_upload_copy: ".$infos['device']['description']." downloaded: ".$matchDownloaded[1] );
	ciscotools_upgrade_delete_images($infos); // Delete old images first, can be dangerous if error during transmit

	return $ret;
}

/** ================= IMAGES ERASE =================
 * Perform a deletion of the old installed images.
 *
 * @param   array   $infos:   array containing general informations about the device
 * @return  boolean true if successful, false otherwise
 */
function ciscotools_upgrade_delete_images($infos) {
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
		$readstream = ssh_read_stream($stream);
upgrade_log('UPG: ciscotools_upgrade_delete_images: '.$infos['device']['id'].' '. print_r($readstream, true));
	}

    if(ssh_write_stream($stream, 'dir') === false) return false;
    $directory = ssh_read_stream($stream);

upgrade_log('UPG: ciscotools_upgrade_delete_images dir: '.$infos['device']['id'].' '. print_r($directory, true));
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
upgrade_log('UPG: ciscotools_upgrade_delete_images: '.$infos['device']['id'].' delete: '.$value);
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
?>