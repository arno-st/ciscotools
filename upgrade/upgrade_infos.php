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

/** ======================================== INFORMATIONS ========================================
 * Get informations of the device in the upgrade queue
 *
 * @param   integer $deviceID:  the ID of the device (host.id)
 * @return  array   $infos:     if successful|false otherwise
 */
function ciscotools_upgrade_get_infos($deviceID)
{
    $device = ciscotools_upgrade_get_device($deviceID);
    if($device === false)
    {   // Error getting device infos
        ciscotools_upgrade_table($deviceID, 'update', 15);
        return false;
    }

    $snmp = ciscotools_upgrade_get_snmp($device);
    if($snmp === false) 
    {   // Error getting SNMP infos
        ciscotools_upgrade_table($deviceID, 'update', 16);
        return false;
    }
    
    $model = ciscotools_upgrade_get_version($device);
    if($model === false)
    {   // Error getting image infos
        ciscotools_upgrade_table($deviceID, 'update', 17);
        return false;
    }

    $infos = array();
    foreach($device as $key => $value) $infos['device'][$key] = $value;
    foreach($snmp as $key => $value) $infos['snmp'][$key] = $value;
    foreach($model as $key => $value) $infos['model'][$key] = $value;

    return $infos;
}

/** ================= DEVICE INFOS =================
 * Check all necessary informations from the device
 *
 * Get all general, SNMP and SSH informations about the device like the SNMPv3 passphrase
 * or the login and password SSH
 *
 * @param    integer     $deviceID:  the ID of the device (host.id)
 * @return   array|bool  $infosDevice if successful, false otherwise
 */
function ciscotools_upgrade_get_device($deviceID)
{
    $sqlQuery ="SELECT host.id, host.description, host.hostname,
              host.snmp_community, host.snmp_version,
              host.snmp_username, host.snmp_password, host.snmp_auth_protocol, host.snmp_priv_passphrase, host.snmp_priv_protocol, host.snmp_port, host.snmp_timeout,
              host.snmp_sysObjectID,
              host.can_be_upgraded, host.can_be_rebooted, host.type
              FROM host
              WHERE host.id=$deviceID";
    $infosDevice = db_fetch_row_prepared($sqlQuery);

    if($infosDevice === false) 
    {   // Model unsupported
        ciscotools_upgrade_table($deviceID, 'update', 9);
        return false;
    }
    return $infosDevice;
}
/* ==================================================== */

/** ================= SNMP INFOS =================
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
function ciscotools_upgrade_get_snmp($infosDevice)
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

    $snmpInfos['session'] = "." . $infosDevice['id'];

    return $snmpInfos;
}
/* ==================================================== */

/** ================= VERSION INFOS =================
* Get all informations about the model and the version (image)
*
* Get the name of the image, the SSH commands to check if the version is installed
* and the upgrade method depending of the OS (IOS and IOS-XE)
*
* @param array $infosDevice the array with all the informations about the device
* @return array|bool $modelInfos if successful, false otherwise
*/
function ciscotools_upgrade_get_version($infosDevice)
{ 
    $sshCmds_mode = array(
        'bundle'    => "dir|show boot",
        'install'   => "dir|more flash:.installer/install_add_oper.log"
    );
    $sqlSelect = "SELECT plugin_ciscotools_image.id, " 
                ."plugin_ciscotools_image.model, plugin_ciscotools_image.image, plugin_ciscotools_image.mode, "
                ."plugin_extenddb_model.oid_model, plugin_extenddb_model.snmp_SysObjectId "
                ."FROM plugin_ciscotools_image "
                ."LEFT JOIN plugin_extenddb_model ON plugin_extenddb_model.model = plugin_ciscotools_image.model "
                ."WHERE plugin_ciscotools_image.model ='" . $infosDevice['type'] . "' "
                ."GROUP BY plugin_ciscotools_image.image";
    $upgradeInfos = db_fetch_assoc($sqlSelect); // Get infos for upgrade

    if($upgradeInfos === false)
    {
        ciscotools_log("[ERROR] Upgrade: No info for upgrade!");
        return false;
    }

    // Regex to check model & verify if version already installed
    $modelRegex = '/STRING: "(.*)"$/';
    foreach($upgradeInfos as $row)
    {
        $snmpModel = ciscotools_upgrade_snmp_walk("2c", "telvlsn", $row['snmp_SysObjectId'], $row['oid_model'], $infosDevice);
        preg_match_all($modelRegex, $snmpModel, $model);
        if($model)
        {
            $modelInfos = ['image' => $row['image'], "sshCmds_mode" => $sshCmds_mode[$row['mode']], "mode" => $row['mode']];
            return $modelInfos;
        }
        else
        {
            ciscotools_log("[ERROR] Upgrade: No model found!");
            return false;
        }
    }
}
/* ==================================================== */

/* ============================================================================================== */
?>