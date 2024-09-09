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
function ciscotools_upgrade_get_infos($deviceID){
	// get all infromation from the DB
    $device = ciscotools_upgrade_get_device($deviceID);
    if($device === false)
    {   // Error getting device infos
        ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_INFO_ERROR);
        return false;
    }

	// just format SNMP information from $device to another format!
    $snmp = ciscotools_upgrade_get_snmp($device);
    if($snmp === false) {
		// Error getting SNMP infos
        ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_SNMP_ERROR);
        return false;
    }
    
    $model = ciscotools_upgrade_get_version($device);
    if($model === false) {
		// Error getting image infos
        ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_IMAGE_INFO_ERROR);
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
function ciscotools_upgrade_get_device($deviceID) {
    $sqlQuery ="SELECT host.*, pehm.model, plugin_ciscotools_image.command as 'command' 
				FROM host 
				LEFT JOIN plugin_extenddb_host_model AS pehm ON pehm.host_id=host.id
				LEFT JOIN plugin_extenddb_model ON pehm.model = plugin_extenddb_model.model 
				LEFT JOIN plugin_ciscotools_image ON plugin_extenddb_model.id = plugin_ciscotools_image.model_id
				WHERE host.id=$deviceID";
    $infosDevice = db_fetch_row_prepared($sqlQuery);

    if($infosDevice === false) {
	// ID not find on host DB
        ciscotools_upgrade_table($deviceID, 'update', UPGRADE_STATUS_INFO_ERROR);
        return false;
    }
	$defaultupgrade = read_config_option('ciscotools_default_can_be_upgraded');
	if($infosDevice['can_be_upgraded'] != 'off' ){
		if( $defaultupgrade == 'on' )
			$infosDevice['can_be_upgraded'] = 'on';
	}
	$defaultreboot = read_config_option('ciscotools_default_can_be_rebooted');
	if($infosDevice['can_be_rebooted'] != 'off' ){
		if( $defaultreboot == 'on' )
			$infosDevice['can_be_rebooted'] = 'on';
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
function ciscotools_upgrade_get_snmp($infosDevice) {
    if($infosDevice['snmp_version'] == 2) {
	// Formatting version 2 to 2c
        $infosDevice['snmp_version'] = "2c";
    }
    else if($infosDevice['snmp_version'] == 3) {
        $infosDevice['snmp_version'] = "3";
    }
	
    $snmpInfos = 
    [
        'snmp_community'         => $infosDevice['snmp_community'],          // Get community string
        'snmp_version'           => $infosDevice['snmp_version'],            // Get SNMP's version
        'snmp_username'          => $infosDevice['snmp_username'],           // Get SNMP's username for v3
        'snmp_password'          => $infosDevice['snmp_password'],           // Get SNMP's password for v3
        'snmp_auth_protocol'     => $infosDevice['snmp_auth_protocol'],      // GET SNMP's auth protocol for v3
        'snmp_priv_passphrase'   => $infosDevice['snmp_priv_passphrase'],    // GET SNMP's private passphrase for v3
        'snmp_priv_protocol'     => $infosDevice['snmp_priv_protocol'],      // GET SNMP's private protocol for v3
        'snmp_context'      	 => $infosDevice['snmp_context'],      		 // GET SNMP's context for v3
        'snmp_port'              => $infosDevice['snmp_port'],               // GET SNMP's port
        'snmp_timeout'           => $infosDevice['snmp_timeout']             // GET SNMP's timeout
    ];

    $snmpInfos['session'] = "." . $infosDevice['id'];

    return $snmpInfos;
}
/* ==================================================== */

/** ================= VERSION INFOS =================
* Get all informations about the model and the desired version (image)
*
* Get the name of the image, the SSH commands to check if the version is installed
* and the upgrade method depending of the OS (IOS and IOS-XE)
*
* @param array $infosDevice the array with all the informations about the device
* @return array|bool $modelInfos if successful, false otherwise
*/
function ciscotools_upgrade_get_version($infosDevice){ 
	$sqlSelect ="SELECT plugin_ciscotools_image.image as image, 
				plugin_ciscotools_image.mode as mode,
				plugin_ciscotools_image.command as command,
				plugin_ciscotools_image.size as size
				FROM plugin_ciscotools_image
				LEFT JOIN plugin_extenddb_model ON plugin_extenddb_model.model ='".$infosDevice['model'] ."'
				LEFT JOIN plugin_ciscotools_upgrade ON plugin_extenddb_model.id = plugin_ciscotools_image.model_id
				LEFT JOIN host ON host.id = plugin_ciscotools_upgrade.host_id
				WHERE host_id = ".$infosDevice['id'];
    $upgradeInfos = db_fetch_row($sqlSelect); // Get infos for upgrade
upgrade_log("ciscotools_upgrade_get_version: ". print_r($sqlSelect, true) );
    if($upgradeInfos === false)
    {
        upgrade_log("[ERROR] Upgrade: No info for upgrade ".$infosDevice['description']."!");
        return false;
    }

	$modelInfos = ['image' => $upgradeInfos['image'], "mode" => $upgradeInfos['mode'], "command" => $upgradeInfos['command'], "size" => $upgradeInfos['size']];
    return $modelInfos;
}

?>