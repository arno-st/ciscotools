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

 +------------------------------------------+
 |                 SUMMARY                  |
 +------------------------------------------+
 | A. QUEUE STATUS                          |
 |    A.0 PENDING                           |
 |    A.1 BEGIN PROCESS                     |
 |    A.2 UPLOAD PROCESS                    |
 |    A.3 FINAL PROCESS                     |
 |                                          |
 | 0. INITIALIZATION                        |
 |    0.1 QUEUE ADDING                      |
 |    0.2 QUEUE CHECKING                    |
 |                                          |
 | 1. STEP ONE                              |
 |    1.1 DEVICE INFOS GETTING AND CHECKING |
 |    1.2 MODELE VERIFICATION               |
 |    1.3 CREDENTIALS GETTING AND CHECKING  |
 |    1.4 VERSION GETTING                   |
 |    1.5 VERSION CHECKING                  |
 |    1.6 SIZE CHECKING                     |
 |    1.7 TFTP CHECKING                     |
 |    1.8 IMAGE UPLOADING                   |
 |                                          |
 | 2. STEP TWO                              |
 |    2.1 UPLOAD STATUS CHECKING            |
 |    2.2 IMAGES ERASING                    |
 |                                          |
 | 3. STEP THREE                            |
 |    3.1 IMAGE CHECKING                    |
 |                                          |
 | 4. MULTIPLE USAGES FUNCTIONS             |
 |    4.1 SSH COMMAND                       |
 |    4.2 SNMPSET UPGRADE                   |
 |    4.3 SNMPWALK UPGRADE                  |
 |    4.4 SNMP INFOS GETTING                |
 |    4.5 QUEUE DELETING                    |
 +------------------------------------------+

*/
/**
* +-----------------+
* | A. QUEUE STATUS |
* +-----------------+
* A.0 Pending...
* A.1 Begin process: checking infos and uploading new image
* A.2 Upload process: ping device, erasing old images and reboot if allowed
* A.3 Final process: ping device, check if new image is correctly installed
*/

/**
* +-------------------+
* | 0. INITIALIZATION |
* +-------------------+
* Functions dedicated or files for the initialization of upgrades
*/
include_once($config['base_path'] . '/plugins/extenddb/ssh2.php');    // ADD THE FILE FOR SSH CONNECTION



/**
* +-------------------+
* | 0.1. QUEUE ADDING |
* +-------------------+
* Add device in queue for an upgrade
*
* Add the selected device(s) to the queue table with the status n°0 to process the upgrade
*
* @param int $deviceID the ID of the device in the queue table references to the table host
* @return bool true if successful, false otherwise
*/
function ciscotools_addQueueUpgrade($deviceID)
{   // Query to check if device is already in queue
    $sqlQuery = "SELECT id, host_id FROM plugin_ciscotools_queueupgrade "
                ."WHERE host_id=" . $deviceID; 
    $queryQueue = db_fetch_assoc($sqlQuery);
    
    if ($queryQueue) 
    {   // If it's in queue
        ciscotools_log("Upgrade: device n°" . $deviceID . " is already in queue");

        // USER NOTIFICATION >>> function?
        return false;
    }
    else
    {   // If it is not => add in queue
        $sqlQuery = "INSERT INTO plugin_ciscotools_queueupgrade (id, host_id, status) "
                   ."VALUES (NULL, " . $deviceID . ", '0')";
        $sqlExec = db_execute($sqlQuery);
        ciscotools_log("Upgrade: device n°" . $deviceID . " has been added in queue");
        return true;
    }
}



/**
* +--------------------+
* | 0.2 QUEUE CHECKING |
* +--------------------+
* Function called by poller. It checks if device(s) in queue and the status
*
* Check the queue table to see if devices are in queue and the status number
* 0: The device is pending and wait the status n°1
* 1: Begin the upgrade until the image upload
* 2: Check the upload status until a potential rebooting
* 3: If a reboot started, ping the device and verify if the image has been correctly installed
*
* @return bool true if successful, false otherwise
*/
function checkQueue()
{   // Query to check if device is already in queue
    $sqlQuery = "SELECT id, host_id, status, session FROM plugin_ciscotools_queueupgrade";
    $queryQueue = db_fetch_assoc($sqlQuery);
    if(!$queryQueue)
    {
        ciscotools_log("Upgrade: no device to upgrade!");
        return false;
    }

    // If devices are in queue
    foreach($queryQueue as $device)
    {
        if($device['status'] == '0')
        {   // If device is pending > begin the first process
            $sqlQuery = "UPDATE plugin_ciscotools_queueupgrade "
                       ."SET status=1 "
                       ."WHERE host_id=" . $device['host_id'];
            $sqlExec = db_execute($sqlQuery);
            if(!$sqlExec)
            {
                ciscotools_log("[ERROR] Upgrade: cannot change status of device n°" . $device['host_id'] . "!");
                return false;
            }
            ciscotools_log("[STATUS 1] Upgrade: device n°" . $device['host_id'] . " begin its upgrade!");
            $upgradeStepOne = upgradeStepOne($device['host_id']);
            if($upgradeStepOne === false)
            {
                ciscotools_log("[ERROR] Upgrade: step one error.");
                return false;
            }
        }
        else if($device['status'] == '2')
        {   // If device is uploading the new image
            $upgradeStepTwo = upgradeStepTwo($device['host_id'], $device['session']);
            if($upgradeStepTwo === false)
            {
                return false;
            } 
        }
        else if($device['status'] == '3')
        {   // If device is rebooting 
            upgradeStepThree($device['host_id']);
        }
    }
    return;
}



/**
* +-------------+
* | 1. STEP ONE |
* +-------------+
* Begin process > checking infos and uploading new image
*
* Get all the informations about the device (general informations like the IP address,
* the modele informations, SNMP informations, version informations, SSH credentials, ...)
*
* @param int $deviceID the ID of the device in the queue table references to the table host
* @return bool true if successful, false otherwise
*/
function upgradeStepOne($deviceID)
{
    // DEVICE INFOS
    $infosDevice = getInfosDevice($deviceID);
    if($infosDevice === false)
    {
        return false;
    }
    $deviceID           = $infosDevice['id'];               //Set ID in var
    $deviceHostname     = $infosDevice['description'];      //Set Hostname in var
    $deviceIP           = $infosDevice['hostname'];         //Set IP in var
    $snmpSysObjectId    = $infosDevice['snmp_sysObjectID']; //Set SysObjectId in var
    ciscotools_log($snmpSysObjectId);
    // CHECK MODELE
    $modeleCheck = modeleCheck($snmpSysObjectId);
    if($modeleCheck === false)
    {
        ciscotools_log("[INFO] Upgrade: ". $deviceHostname . " is not supported for an upgrade! "
                      ."Add it only if you have the permission!");
        return false;
    }

    // SNMP INFOS
    $snmpInfos = getSnmpInfos($infosDevice, $deviceHostname);
    if($snmpInfos === false)
    {
        return false;
    }

    // CREDENTIALS INFOS
    $credentials = checkCredentials($infosDevice, $deviceID, $deviceHostname);
    if($credentials === false)
    {
        return false;
    }
    if($credentials['can_be_upgraded'] != "on")
    {
        ciscotools_log($deviceHostname . " cannot be upgraded!");
        queueDeleting($deviceID, $deviceHostname);
        return false;
    }

    // MODELE INFOS
    $modeleInfos = getVersion($deviceID, $deviceHostname, $snmpSysObjectId, $deviceIP, $snmpInfos);
    if($modeleInfos === false)
    {
        return false;
    }
    else if(($credentials['can_be_rebooted'] != "on") && ($modeleInfos['upgrade_method'] == "2"))
    {
        ciscotools_log("[INFO IOS-XE] Upgrade: " . $deviceHostname . " must accept upgrade and reboot!");
        queueDeleting($deviceID, $deviceHostname);
        return false;
    }
    $fileName = $modeleInfos['image'];  //Set file's name in var

    // CHECK VERSION
    $checkVersion = checkVersion($deviceID, $deviceHostname, $deviceIP, $credentials, $snmpInfos, $fileName, $modeleInfos['cmds']);
    if($checkVersion === false)
    {
        return false;
    }

    // CHECK SIZE
    /* Disabled until a TFTP server is active on localhost
    $totalSize  = cmdSSH($deviceIP, $credentials['login'], $credentials['password'], "show flash:");
    $checkSize = checkSize($deviceID, $totalSize, $fileSize);
    if($checkSize === false)
    {
        return false;
    }
    */

    // TFTP CHECK
    $tftpAddress = checkTFTP($deviceID, $deviceHostname, read_config_option('ciscotools_default_tftp'));
    if($tftpAddress === false)
    {
        return false;
    }

    // IMAGE UPLOAD
    imageUpload($deviceID, $deviceHostname, $deviceIP, $snmpInfos, $tftpAddress, $fileName);

    return true;
}



/**
* +---------------------------------------+
* | 1.1 DEVICE INFOS GETTING AND CHECKING |
* +---------------------------------------+
* Check all necessary informations from the device
*
* Get all general, SNMP and SSH informations about the device like the SNMPv3 passphrase
* or the login and password SSH
*
* @param int $deviceID the ID of the device in the queue table references to the table host
* @return array|bool $infosDevice if successful, false otherwise
*/
function getInfosDevice($deviceID)
{
    $sqlQuery ="SELECT `id`, `description`, `hostname`, "
              ."`snmp_community`, `snmp_version`, ".
              "`snmp_username`, `snmp_password`, `snmp_auth_protocol`, `snmp_priv_passphrase`, `snmp_priv_protocol`, `snmp_port`, `snmp_timeout`, "
              ."`snmp_sysObjectID`, "
              ."`can_be_upgraded`, `can_be_rebooted`, `login`, `password`, `console_type` "
              ."FROM `host` "
              ."WHERE host.id=".$deviceID;
    $infosDevice = db_fetch_row_prepared($sqlQuery);

    if($infosDevice === false) 
    {   // Check if device exists
        ciscotools_log("[ERROR] Upgrade: No device found!");

        // USER NOTIFICATION >> function?
        queueDeleting($deviceID, "the device");
        return false;
    }
    return $infosDevice;
}



/**
* +---------------------+
* | 1.2 MODELE CHECKING |
* +---------------------+
* Check if the devices modele
*
* Perform the modele verification with the sysObjectId to see if the modele exists and if it's supported
* Compare the host value in the table host with the value of the modele in the table ciscotools_plugin_modele
*
* @param string $snmpSysObjectId the sysObjectID of the device in the table host
* @return bool true if successful, false otherwise
*/
function modeleCheck($snmpSysObjectId)
{
    $sqlQuery = "SELECT modele FROM plugin_ciscotools_modele WHERE snmp_sysObjectID = '" . $snmpSysObjectId . "'";
    $sqlExec = db_fetch_row_prepared($sqlQuery);
    if(!$sqlExec)
    {
        return false;
    }
    return true;
}



/**
* +--------------------------------------+
* | 1.3 CREDENTIALS GETTING AND CHECKING |
* +--------------------------------------+
* Get and check all credentials informations for the SSH connection
*
* Perform a comparison between the credentials informations from the array $infosDevice
* to see if a row is empty and update the empty row with the default value
*
* @param array $infosDevice the array with all the informations about the device
* @param int $deviceID the ID of the device in the queue table references to the table host
* @param string $deviceHostname the name of the device useful for the logs
* @return array|bool $credentials if successful, false otherwise
*/
function checkCredentials($infosDevice, $deviceID, $deviceHostname)
{   // Conditions if a row is empty
    $checkCredentials = [];
    if(!array_key_exists('login', $infosDevice) || empty($infosDevice['login'])) 
    {
        $credentials['login'] = read_config_option('ciscotools_default_login');
        $checkCredentials['login'] = 1;
    }
    else {$credentials['login'] = $infosDevice['login'];}

    if(!array_key_exists('password', $infosDevice) || empty($infosDevice['password']))
    {
        $credentials['password'] = read_config_option('ciscotools_default_password');
        $checkCredentials['password'] = 1;
    }
    else {$credentials['password'] = $infosDevice['password'];}

    if(!array_key_exists('console_type', $infosDevice) || empty($infosDevice['console_type']))
    {
        $credentials['console_type'] = read_config_option('ciscotools_default_console_type');
        $checkCredentials['console_type'] = 1;
    }
    else {$credentials['console_type'] = $infosDevice['console_type'];}

    if(!array_key_exists('can_be_upgraded', $infosDevice) || empty($infosDevice['can_be_upgraded']))
    {
        //$credentials['can_be_upgraded'] = read_config_option('ciscotools_default_can_be_upgraded');
        $credentials['can_be_upgraded'] = 'on';
        $checkCredentials['can_be_upgraded'] = 1;
    }
    else {$credentials['can_be_upgraded'] = $infosDevice['can_be_upgraded'];}

    if(!array_key_exists('can_be_rebooted', $infosDevice) || empty($infosDevice['can_be_rebooted']))
    {
        //$credentials['canReboot'] = read_config_option('ciscotools_default_can_be_rebooted');
        $credentials['can_be_rebooted'] = 'off';
        $checkCredentials['can_be_rebooted'] = 1;
    }
    else {$credentials['can_be_rebooted'] = $infosDevice['can_be_rebooted'];}

    if(sizeof($checkCredentials) > 0)
    {   // If a row is empty > update DB
        $sqlQuery = "UPDATE host SET ";
        foreach($checkCredentials as $key => $value)
        {
            if($value == 1)
            {
                $sqlQuery .= $key . "='" . $credentials[$key] . "', ";
            }
        }
        $sqlQuery = substr($sqlQuery, 0, -2);
        $sqlQuery .= " WHERE host.id=".$deviceID;
        $sqlExec = db_execute($sqlQuery);
        if(!$sqlExec)
        {   // If problem
            ciscotools_log("[ERROR] Upgrade: Impossible to update table for credentials");

            // USER NOTIFICATION >> function?
            queueDeleting($deviceID, $deviceHostname);
            return false;
        }
    }
    return $credentials;
}



/**
* +---------------------+
* | 1.4 VERSION GETTING |
* +---------------------+
* Get all informations about the modele and the version (image)
*
* Get the name of the image, the SSH commands to check if the version is installed
* and the upgrade method depending of the OS (IOS and IOS-XE)
*
* @param int $deviceID the ID of the device in the queue table references to the table host
* @param string $deviceHostname the name of the device useful for the logs
* @param string $snmpSysObjectId the sysObjectID of the device in the table modele
* @param string $deviceIP the IP of the device
* @param array $infosDevice the array with all the informations about the device
* @return array|bool $modeleInfos if successful, false otherwise
*/
function getVersion($deviceID, $deviceHostname, $snmpSysObjectId, $deviceIP, $infosDevice)
{ 
    $sqlSelect = "SELECT id, snmp_SysObjectId, oid_modele, modele, image, SSHcmds_version, upgrade_method FROM plugin_ciscotools_modele "
                ."WHERE snmp_SysObjectId='" . $snmpSysObjectId ."'";
    $upgradeInfos = db_fetch_assoc($sqlSelect); // Get infos for upgrade

    if($upgradeInfos === false)
    {
        ciscotools_log("[ERROR] Upgrade: No info for upgrade!");

        // USER NOTIFICATION >> function?
        queueDeleting($deviceID, $deviceHostname);
        return false;
    }

    // Regex to check modele & verify if version already installed
    $modeleRegex = '/STRING: "(.*)"$/';
    foreach($upgradeInfos as $row)
    {
        $snmpModele = snmpUpgWalk("2c", "telvlsn", $snmpSysObjectId, $row['oid_modele'], $infosDevice);
        preg_match_all($modeleRegex, $snmpModele, $modele);
        if($modele)
        {
            $modeleInfos = ['image' => $row['image'], "cmds" => $row['SSHcmds_version'], "upgrade_method" => strval($row['upgrade_method'])];
            return $modeleInfos;
        }
        else
        {
            ciscotools_log("[ERROR] Upgrade: No modele found!");

            // USER NOTIFICATION >> function?
            queueDeleting($deviceID, $deviceHostname);
            return false;
        }
    }
}



/**
* +----------------------+
* | 1.5 VERSION CHECKING |
* +----------------------+
* Verify if the image was already installed
*
* Verification of the image and see if the device has already the version installed
* with two commands depending of the OS (IOS and IOS-XE). There is 2 methods :
* [IOS]     1: perform the 'dir' and 'show version commands
* [IOS-XE]  2: perform the 'dir' and 'more flash:/.installer/install_add_oper.log commands
*
* @param int $deviceID the ID of the device in the queue table references to the table host
* @param string $deviceHostname the name of the device useful for the logs
* @param string $snmpSysObjectId the sysObjectID of the device in the table modele
* @param string $deviceIP the IP of the device
* @param array $infosDevice the array with all the informations about the device
* @return bool true if successful, false otherwise
*/
function checkVersion($deviceID, $deviceHostname, $deviceIP, $credentials, $snmpInfos, $fileName, $modeleCmds)
{   // See if file is in flash: and set in bootvar
    $cmds = explode("|", $modeleCmds);
    $checkCounter = 0;
    $versionRegex = "/" . $fileName . "/";
    foreach($cmds as $index => $cmd)
    {
        $bootVersion = cmdSSH($deviceIP, $credentials['login'], $credentials['password'], $cmd);
        if($bootVersion === false) return false;
        //ciscotools_log($bootVersion);
        if(preg_match($versionRegex, $bootVersion, $result))
        {
            $checkCounter++;
        }
        else
        {
            ciscotools_log("Upgrade: no bootversion found, need to upload!");
            // None
        }
    }
    if($checkCounter == sizeOf($cmds))
    {
        ciscotools_log("[INFO] Upgrade: version already installed for " . $deviceHostname . "!");
    
        // USER NOTIFICATION
        queueDeleting($deviceID, $deviceHostname);
        return false;
    }
    return true;
}



/**
* +-------------------+
* | 1.6 SIZE CHECKING |
* +-------------------+
* Coming soon
*
* @param int $deviceID the ID of the device in the queue table references to the table host
* @param string $deviceHostname the name of the device useful for the logs
* @param int $totalSize the free space available
* @param int $fileSize the size of the new image
* @return bool true if successful, false otherwise
*/
function checkSize($deviceID, $deviceHostname, $totalSize, $fileSize) 
{
    // Regex Size
    $regexSize = "/\((.*) bytes free\)/";
    preg_match($regexSize, $totalSize, $sizeFree);
    $sizeFree = (int)$sizeFree[1];

    if($sizeFree > $fileSize) 
    {   // Compare sizeFree with fileSize
        ciscotools_log("Upgrade: free space is enough");
        return true;
    }
    else 
    {
        ciscotools_log("[ERROR] Upgrade: free space is not enough");
        queueDeleting($deviceID, $deviceHostname);
        return false;
    }
}



/**
* +-------------------+
* | 1.7 TFTP CHECKING |
* +-------------------+
* Check the TFTP server address and the availibility
*
* Perform a verification of the TFTP server address with a regex that accepts
* IPv4 and IPv6 and ping the server to see if it's alive.
*
* @param int $deviceID the ID of the device in the queue table references to the table host
* @param string $deviceHostname the name of the device useful for the logs
* @param string $tftpAddress the IP address of the TFTP server
* @return string|bool $tftpAddress if successful, false otherwise
*/
function checkTFTP($deviceID, $deviceHostname, $tftpAddress)
{
    // Regex allows IPv4 and IPv6 (must be exhaustive)
    $regexIP = "/(^|\s|(\[))(::)?([a-f\d]{1,4}::?){0,7}(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}"
              ."(?=(?(2)\]|($|\s|(?(3)($|\s)|(?(4)($|\s)|:\d)))))|((?(3)[a-f\d]{1,4})|(?(4)"
              ."[a-f\d]{1,4}))(?=(?(2)\]|($|\s))))(?(2)\])(:\d{1,5})?/";
    preg_match($regexIP, $tftpAddress, $result);
    ciscotools_log("Upgrade: TFTP server address is > " . $result[0]);
    if($result[0] != $tftpAddress)
    {
        ciscotools_log("[ERROR] Upgrade: TFTP server address seems wrong!");
        queueDeleting($deviceID, $deviceHostname);
        return false;
    }
    // Server ping to check if host down
    $ping = shell_exec("ping -c 1 -s 64 -t 64 " . $tftpAddress);
    if(empty($ping)) 
    {
        ciscotools_log("[ERROR] Upgrade: TFTP server is down!");
        queueDeleting($deviceID, $deviceHostname);
        return false;
    }
    return $tftpAddress;
}



/**
* +---------------------+
* | 1.8 IMAGE UPLOADING |
* +---------------------+
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
* @param int $deviceID the ID of the device in the queue table references to the table host
* @param string $deviceHostname the name of the device useful for the logs
* @param string $deviceIP the IP of the device
* @param array $snmpInfos the SNMP informations of the device (community, login...)
* @param string $tftpAddress the TFTP server address
* @param string $fileName the filename of the new image
* @return bool true if successful, false otherwise
*/
function imageUpload($deviceID, $deviceHostname, $deviceIP, $snmpInfos, $tftpAddress, $fileName)
{
    // OIDs
    $snmpFlashCopy  = "1.3.6.1.4.1.9.9.10.1.2.1.1"; // ciscoFlashCopyEntry
    $snmpStatus     = $snmpFlashCopy . ".8";        // ciscoFlashCopyStatus
    $snmpCmd        = $snmpFlashCopy . ".2";        // ciscoFlashCopyCommand
    $snmpProt       = $snmpFlashCopy . ".3";        // ciscoFlashCopyProtocol
    $snmpIP         = $snmpFlashCopy . ".4";        // ciscoFlashCopyServerAddress
    $snmpFileSrc    = $snmpFlashCopy . ".5";        // ciscoFlashCopySourceName
    $snmpFileDest   = $snmpFlashCopy . ".6";        // ciscoFlashCopyDestinationName
    $snmpEntryStatus= $snmpFlashCopy . ".11";       // ciscoFlashCopyEntryStatus

    snmpUpgSet($snmpInfos['version'], $deviceIP, $snmpEntryStatus . $snmpInfos['session'], "i", "6", $snmpInfos);     // Delete session
    snmpUpgSet($snmpInfos['version'], $deviceIP, $snmpEntryStatus . $snmpInfos['session'], "i", "5", $snmpInfos);     // Initialize
    snmpUpgSet($snmpInfos['version'], $deviceIP, $snmpCmd . $snmpInfos['session'], "i", "2", $snmpInfos);             // Cmd type - 2:Copy w/o erase
    snmpUpgSet($snmpInfos['version'], $deviceIP, $snmpProt . $snmpInfos['session'], "i", "1", $snmpInfos);            // Protocole - 1:TFTP
    snmpUpgSet($snmpInfos['version'], $deviceIP, $snmpIP . $snmpInfos['session'], "a", $tftpAddress, $snmpInfos);     // Server Address
    snmpUpgSet($snmpInfos['version'], $deviceIP, $snmpFileSrc . $snmpInfos['session'], "s", $fileName, $snmpInfos);   // Source File
    snmpUpgSet($snmpInfos['version'], $deviceIP, $snmpFileDest . $snmpInfos['session'], "s", $fileName, $snmpInfos);  // Dest File
    snmpUpgSet($snmpInfos['version'], $deviceIP, $snmpEntryStatus . $snmpInfos['session'], "i", "1", $snmpInfos);     // Begin Upload
    sleep(2); // Wait to be sure

    $uploadStatus = snmpUpgWalk($snmpInfos['version'], $snmpInfos['community'], $deviceIP, $snmpStatus . $snmpInfos['session'], $snmpInfos);
    $statusUploadRegex = "/INTEGER: ([0-9])$/";
    preg_match($statusUploadRegex, $uploadStatus, $uploadStatus);
    if(sizeof($uploadStatus) < 1)
    {
        ciscotools_log("[ERROR] Upgrade: an error occured with the upload on " . $deviceHostname . "!");
        return false;
    }
    else
    {
        if($uploadStatus[1] == '1')
        {
            $sqlQuery = "UPDATE plugin_ciscotools_queueupgrade "
                       ."SET status = '2' "
                       ."WHERE host_id = " . $deviceID;
            $sqlExec = db_execute($sqlQuery);
            return true;
        }
        else
        {
            ciscotools_log("[ERROR] Upgrade: an error occured with the upload on " . $deviceHostname . "!");
            return false;
        }
    }
}



/**
* +-------------+
* | 2. STEP TWO |
* +-------------+
* Upload verification, old images deletion and potential reboot
*
* Perform an upload verification to see the progress and, if successful, delete the
* old images. Can also perform a reboot if the device allows the action in the database
*
* @param int $deviceID the ID of the device in the queue table references to the table host
* @param string $deviceSession the session number of the upload
* @return bool true if successful, false otherwise
*/
function upgradeStepTwo($deviceID, $deviceSession)
{
    // OID
    $snmpStatus = "1.3.6.1.4.1.9.9.10.1.2.1.1.8";   // ciscoFlashCopyStatus
    $oidReload = "1.3.6.1.4.1.9.2.9.9.0";           // reload

    // DEVICE INFOS
    $infosDevice = getInfosDevice($deviceID);
    if($infosDevice === false)
    {
        return false;
    }
    $deviceID           = $infosDevice['id'];               //Set ID in var
    $deviceHostname     = $infosDevice['description'];      //Set Hostname in var
    $deviceIP           = $infosDevice['hostname'];         //Set IP in var
    $snmpSysObjectId    = $infosDevice['snmp_sysObjectID']; //Set SysObjectId in var

    // SNMP INFOS
    $snmpInfos = getSnmpInfos($infosDevice, $deviceHostname);
    if($snmpInfos === false)
    {
        return false;
    }

    // CREDENTIALS INFOS
    $credentials = checkCredentials($infosDevice, $deviceID, $deviceHostname);
    if($credentials === false)
    {
        return false;
    }

    // MODELE INFOS
    $modeleInfos = getVersion($deviceID, $deviceHostname, $snmpSysObjectId, $deviceIP, $snmpInfos);
    if($modeleInfos === false)
    {
        return false;
    }
    $fileName = $modeleInfos['image'];  //Set file's name in var

    // Check upload status
    $uploadStatus = checkUploadStatus($deviceID, $deviceIP, $deviceHostname, $snmpInfos);
    if($uploadStatus === false)
    {
        return false;
    }
    else if($uploadStatus == "progress")
    {
        ciscotools_log("[DEBUG] Upgrade: upload in progress for " . $deviceHostname . "!");
        return false;
    }

    // Erasing old images
    if($modeleInfos['upgrade_method'] == '1')
    {   // IOS & IR1101
        deleteImages($infosDevice, $credentials, $fileName);
    }
    else if($modeleInfos['upgrade_method'] == '2')
    {   // IOS-XE like Cisco 9x00
        ciscotools_log("[INFO] Upgrade: upgrade method n°2 is in development...");
        //queueDeleting($deviceID, $deviceHostname);

        $install = cmdSSH($deviceIP, $credentials['login'], $credentials['password'], 'install add file flash:' . $fileName . ' prompt-level none');
        ciscotools_log($install);
        $activate = cmdSSH($deviceIP, $credentials['login'], $credentials['password'], 'install activate file flash:' . $fileName . ' prompt-level none');
        ciscotools_log($activate);
        
        $sqlQuery = "UPDATE plugin_ciscotools_queueupgrade "
                   ."SET status = '3' "
                   ."WHERE host_id = " . $deviceID;
        $sqlExec = db_execute($sqlQuery);
        if(!$sqlExec)
        {
            ciscotools_log("[ERROR] Upgrade: error with DB!");
            return false;
        }
        return;
    }

    // If reboot='on' > status = '3'
    if(($credentials['can_be_rebooted'] == 'on') && ($snmpInfos['version'] == '3'))
    {
        $sqlQuery = "UPDATE plugin_ciscotools_queueupgrade "
        ."SET status = '3' "
        ."WHERE host_id = " . $deviceID;
        $sqlExec = db_execute($sqlQuery);
        if(!$sqlExec)
        {
            ciscotools_log("[ERROR] Upgrade: error with DB!");
            return false;
        }
        // Reboot device
        snmpUpgSet($snmpInfos['version'], $infosDevice['hostname'], $oidReload, "i", "2", $snmpInfos);
    }
    else
    {
        ciscotools_log("[INFO] Upgrade: " . $deviceHostname . " cannot be rebooted! SNMP version must be '3'!");
        queueDeleting($deviceID, $deviceHostname);
    }
    return true;
}



/**
* +-----------------------------+
* | 2.1. UPLOAD STATUS CHECKING |
* +-----------------------------+
* Perform the upload of the new image with a SNMP method with ciscoFlashCopy
*
* @param int $deviceID the ID of the device in the queue table references to the table host
* @param string $deviceIP the IP of the device
* @param string $deviceHostname the name of the device useful for the logs
* @param array $snmpInfos the SNMP informations of the device (community, login...)
* @return string|bool true if successful, 'progress' if in progress, false otherwise
*/
function checkUploadStatus($deviceID, $deviceIP, $deviceHostname, $snmpInfos)
{
    // Check upload status
    $uploadStatus = snmpUpgWalk($snmpInfos['version'], $snmpInfos['community'], $deviceIP, '1.3.6.1.4.1.9.9.10.1.2.1.1.8' . $snmpInfos['session'], $snmpInfos);
    $statusUploadRegex = "/INTEGER: ([0-9])$/";
    preg_match($statusUploadRegex, $uploadStatus, $uploadStatus);
    if(sizeof($uploadStatus) < 1)
    {
        ciscotools_log("[ERROR] Upgrade: an error occured with the upload on " . $deviceHostname . "!");
        return false;
    }
    else if($uploadStatus[1] == '2')
    {
        ciscotools_log("Upload status: Succeed");
        snmpUpgSet($snmpInfos['version'], $deviceIP, '1.3.6.1.4.1.9.9.10.1.2.1.1.11' . $snmpInfos['session'], "i", "6", $snmpInfos);
        return true;
    }
    else if($uploadStatus[1] == '1')
    {
        return 'progress';
    }
    else
    {
        switch($uploadStatus[1]) 
        {   // Catch errors
            default:    $errorUploadMsg = "Unknown Error";              break; // Unknown > check logs
            case "3":   $errorUploadMsg = "Invalid Operation";          break; // Unknown > check logs
            case "4":   $errorUploadMsg = "Invalid Protocol";           break; // Must be TFTP > check value of SNMP's query with $snmpProt
            case "5":   $errorUploadMsg = "Invalid Source Name";        break; // The file does not exist
            case "6":   $errorUploadMsg = "Invalid Destination Name";   break; // The file does not exist
            case "7":   $errorUploadMsg = "Invalid Server Address";     break; // The TFTP server is down
            case "8":   $errorUploadMsg = "Device Busy";                break; // The device is already doing a download
            case "9":   $errorUploadMsg = "Device Open Error";          break; // Problem with connection between device and TFTP server
            case "10":  $errorUploadMsg = "Device Error";               break; // Unknown > check device's logs
            case "11":  $errorUploadMsg = "Device Not Programmable";    break; // Device is not programmable > maybe wrong device selected
            case "12":  $errorUploadMsg = "Device Full";                break; // Device has no more left space
            case "13":  $errorUploadMsg = "File Open Error";            break; // Problem with file
            case "14":  $errorUploadMsg = "File Transfer Error";        break; // Error while transferring > need to upload again!
            case "15":  $errorUploadMsg = "Checksum Error";             break; // Corrupted file
            case "16":  $errorUploadMsg = "No Memory";                  break; // Check ressources > error with RAM
            case "17":  $errorUploadMsg = "Unknown Failure";            break; // Unknown > check logs
            case "18":  $errorUploadMsg = "Invalid Signature";          break; // Problem file
            case "19":  $errorUploadMsg = "Prohibited";                 break; // Check permission
        }
        queueDeleting($deviceID, $deviceHostname);
        ciscotools_log("[ERROR {" . $uploadStatus[1] . "}] Upgrade upload for " . $deviceHostname . ": " . $errorUploadMsg . "}");
        return false;
    }
}



/**
* +--------------------+
* | 2.2 IMAGES ERASING |
* +--------------------+
* Perform a deletion of the old installed images.
*
* @param array $infosDevice the array with all the informations about the device
* @param array $credentials the array with all the informations about credentials
* @param string $fileName the filename of the new image
* @return bool true if successful, false otherwise
*/
function deleteImages($infosDevice, $credentials, $fileName)
{
    $directory = cmdSSH($infosDevice['hostname'], $credentials['login'], $credentials['password'], "dir");
    if($directory === false) return false;
    $regexImages = '/[a-zA-Z0-9-.]{0,}.bin/';
    preg_match_all($regexImages, $directory, $result);

    $keys = array_keys($result);
    for($i=0;$i<count($result);$i++)
    {
        foreach($result[$keys[$i]] as $key => $value)
        {
            if($value != $fileName)
            {
                cmdSSH($infosDevice['hostname'], $credentials['login'], $credentials['password'], "delete /f /r " . $value);
                ciscotools_log($value . " deleted!");
            }
        }
    }
    cmdSSH($infosDevice['hostname'], $credentials['login'], $credentials['password'], "wr");
    return true;
}


/**
* +---------------+
* | 3. STEP THREE |
* +---------------+
* Check if device is rebooted and new image installation
*
* Ping the rebooted device, return false if down and check the new image installation
* if the device is up.
*
* @param int $deviceID the ID of the device in the queue table references to the table host
* @return bool true if successful, false otherwise
*/
function upgradeStepThree($deviceID)
{
    // OID
    $oidReload = "1.3.6.1.4.1.9.2.9.9.0";   // reload

    // DEVICE INFOS
    $infosDevice = getInfosDevice($deviceID);
    if($infosDevice === false)
    {
        return false;
    }
    $deviceID           = $infosDevice['id'];               //Set ID in var
    $deviceHostname     = $infosDevice['description'];      //Set Hostname in var
    $deviceIP           = $infosDevice['hostname'];         //Set IP in var
    $snmpSysObjectId    = $infosDevice['snmp_sysObjectID']; //Set SysObjectId in var

    // SNMP INFOS
    $snmpInfos = getSnmpInfos($infosDevice, $deviceHostname);
    if($snmpInfos === false)
    {
        return false;
    }

    // CREDENTIALS INFOS
    $credentials = checkCredentials($infosDevice, $deviceID, $deviceHostname);
    if($credentials === false)
    {
        return false;
    }

    // MODELE INFOS
    $modeleInfos = getVersion($deviceID, $deviceHostname, $snmpSysObjectId, $deviceIP, $snmpInfos);
    if($modeleInfos === false)
    {
        return false;
    }
    $fileName = $modeleInfos['image'];  //Set file's name in var

    // Check reboot
    $ping = exec("ping -c 1 -s 64 -t 64 " . $deviceIP);
    if(empty($ping))
    {
        ciscotools_log("[DEBUG] Upgrade: " . $deviceHostname . " is down...");
        return false;
    }
    ciscotools_log("[DEBUG] Upgrade: " . $deviceHostname . " is up!");

    if($modelesInfos['upgrade_method'] == '1')
    {
         // Check image boot
        $imageCheck = imagechecking($deviceID, $deviceHostname, $snmpInfos, $fileName);
        if($imageCheck === false)
        {
            return false;
        }
    }
    else if($modelesInfos['upgrade_method'] == '2')
    {
        $installSummary = cmdSSH($deviceIP, $credentials['login'], $credentials['password'], 'show install log');
        $regexInstall = '/^\[[0-9]{1}\|install_op_boot\]: (END SUCCESS) ([A-Za-z0-9: ]+)$/';
        if(preg_match($regexInstall, $installSummary, $result))
        {
            cmdSSH($deviceIP, $credentials['login'], $credentials['password'], 'install commit');
        }
        else
        {
            ciscotools_log("[ERROR] Upgrade: " . $deviceHostname . " wasn't correctly upgraded!");
            return false;
        }
    }
    ciscotools_log('[INFO] Upgrade: upgrade succeeded for ' . $deviceHostname . "!");
    queueDeleting($deviceID, $deviceHostname);    
}



/**
* +--------------------+
* | 3.1 IMAGE CHECKING |
* +--------------------+
* Check if the new image is correctly installed with a SNMP request
*
* @param int $deviceID the ID of the device in the queue table references to the table host
* @param string $deviceHostname the name of the device useful for the logs
* @param array $snmpInfos the SNMP informations of the device (community, login...)
* @param string $fileName the filename of the new image
* @return bool true if successful, false otherwise
*/
function imageChecking($deviceID, $deviceHostname, $snmpInfos, $fileName)
{
    $newImage = snmpUpgWalk($snmpInfos['version'], $snmpInfos['community'], $deviceHostname, "1.3.6.1.4.1.9.2.1.73", $snmpInfos);
    $regexNewImage = '/' . $fileName . '/';
    if(preg_match($regexNewImage, $newImage, $result))
    {
        if(!$result[0] || $result[0] != $fileName)
        {
            ciscotools_log("[ERROR] Upgrade: problem with new image of " . $deviceHostname . "!");
            queueDeleting($deviceID, $deviceHostname);
            return false;
        }
    }
    return true;
}



/** 
 * +------------------------------+
 * | 4. MULTIPLE USAGES FUNCTIONS |
 * +------------------------------+
*/

/**
* +-----------------+
* | 4.1 SSH COMMAND |
* +-----------------+
*/
function cmdSSH($deviceIP, $username, $password, $cmd) 
{
    $stream = open_ssh($deviceIP, $username, $password);
    if($stream === false) return false;

    $data = ssh_read_stream($stream);
    if($data === false) return false;

    if(ssh_write_stream($stream, 'term l 0') === false) return false;
    $data = ssh_read_stream($stream);
    if($data === false) return false;

    if(ssh_write_stream($stream, $cmd) === false) return false;
    $data = ssh_read_stream($stream);
    if($data === false) return false;

    return $data;
}



/**
* +---------------------+
* | 4.2 SNMPSET UPGRADE |
* +---------------------+
* Simplified function for snmpset through shell_exec command
* Choose between the SNMPv2c and SNMPv3
*
* @param string $snmpVersion version of SNMP (v2c or v3)
* @param string $deviceIP the IP of the device 
* @param string $oid chosen OID
* @param string $snmpDataType type of data like int, string...
* @param string $snmpData new value to set
* @param array $snmpInfos the SNMP informations of the device (community, login...)
* @return null
*/
function snmpUpgSet($snmpVersion, $deviceIP, $oid, $snmpDataType, $snmpData, $snmpInfos) 
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



/**
* +----------------------+
* | 4.3 SNMPWALK UPGRADE |
* +----------------------+
* Simplified function for snmpwalk through shell_exec command
* Choose between the SNMPv2c and SNMPv3
*
* @param string $snmpVersion version of SNMP (v2c or v3)
* @param string $snmpCommunity SNMP reading community
* @param string $deviceIP the IP of the device 
* @param string $oid chosen OID
* @param array $snmpInfos the SNMP informations of the device (community, login...)
* @return bool|string $snmpExec if successful, false otherwise
*/
function snmpUpgWalk($snmpVersion, $snmpCommunity, $deviceIP, $oid, $snmpInfos) 
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
    else
    {
        ciscotools_log("[ERROR] Upgrade: error with SNMP version!");
        return false;
    }
    return $snmpExec;
}



/**
* +------------------------+
* | 4.4 SNMP INFOS GETTING |
* +------------------------+
* Get and check all SNMP informations
*
* Tansform the SNMP version (int) into a string (2="2c" and 3="3")
* Create an array with all SNMP informations
* If no session number generated, create one
*
* @param array $infosDevice the array with all the informations about the device
* @param string $deviceHostname the name of the device useful for the logs
* @return array|bool $snmpInfos if successful, false otherwise
*/
function getSnmpInfos($infosDevice, $deviceHostname)
{
    if($infosDevice['snmp_version'] == 2)
    {   // Formatting version 2 to 2c
        $infosDevice['snmp_version'] = "2c";
    }
    else if($infosDevice['snmp_version'] == 3)
    {
        $infosDevice['snmp_version'] = "3";
    }
    $snmpInfos = 
    [
        'community'         => $infosDevice['snmp_community'],          // Get community string
        'version'           => $infosDevice['snmp_version'],            // Get SNMP's version
        'username'          => $infosDevice['snmp_username'],           // Get SNMP's username for v3
        'password'          => $infosDevice['snmp_password'],           // Get SNMP's password for v3
        'authProtocol'      => $infosDevice['snmp_auth_protocol'],      // GET SNMP's auth protocol for v3
        'privPassphrase'    => $infosDevice['snmp_priv_passphrase'],    // GET SNMP's private passphrase for v3
        'privProtocol'      => $infosDevice['snmp_priv_protocol'],      // GET SNMP's private protocol for v3
        'port'              => $infosDevice['snmp_port'],               // GET SNMP's port
        'timeout'           => $infosDevice['snmp_timeout']             // GET SNMP's timeout
    ];

    $sqlQuery = "SELECT session FROM plugin_ciscotools_queueupgrade "
               ."WHERE status = '2' "
               ."AND host_id = " . $infosDevice['id'];
    $session = db_fetch_row_prepared($sqlQuery);
    if(!$session)
    {
        $randomInt = rand(42,999);
        $sqlQuery = "UPDATE plugin_ciscotools_queueupgrade "
        ."SET session = '" . $randomInt . "' "
        ."WHERE host_id = " . $infosDevice['id'];
        $sqlExec = db_execute($sqlQuery);
        if($sqlExec === false)
        {
            ciscotools_log("[ERROR] Upgrade: " . $deviceHostname . " cannot update its session number for SNMP!");
            queueDeleting($infosDevice['id'], $deviceHostname);
            return false;
        }
        $snmpInfos['session'] = "." . strval($randomInt);
    }
    else
    {
        $snmpInfos['session'] = "." . $session['session'];
    }
    return $snmpInfos;
}



/**
* +--------------------+
* | 4.5 QUEUE DELETING |
* +--------------------+
* Delete the device of the upgrade queue
*
* @param int $deviceID the ID of the device in the queue table references to the table host
* @param string $deviceHostname the name of the device useful for the logs
* @return bool true if successful, false otherwise
*/
function queueDeleting($deviceID, $deviceHostname) 
{
    $sqlDelete = "DELETE FROM plugin_ciscotools_queueupgrade "
                ."WHERE plugin_ciscotools_queueupgrade.host_id = " . $deviceID;
    $sqlExec = db_execute($sqlDelete);
    if($sqlExec) 
    {
        ciscotools_log("Upgrade: succeed deleting " . $deviceHostname . " from queue!");
        return true;
    }
    else
    {
        ciscotools_log("[ERROR] Upgrade: impossible to delete " . $deviceHostname . " from queue!");
        return false;
    }
}

/*
BOOT VAR: 1.3.6.1.4.1.9.2.1.73
Device: .1.3.6.1.2.1.47.1.1.1.1.13.X >>> specify line
enterprises.9.5.1.3.1.1.17.1 > modele?
*/
?>