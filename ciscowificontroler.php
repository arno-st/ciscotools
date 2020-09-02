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

/* WiFi controler don't answer to vlan query
 it only answer to snmpquery :
Mobile MAC address list			1.3.6.1.4.1.14179.2.1.4.1.1
Client IP address list			1.3.6.1.4.1.14179.2.1.4.1.2
Client hostname address list	1.3.6.1.4.1.14179.2.1.4.1.3
Client SSID_name list			1.3.6.1.4.1.14179.2.1.4.1.7
Client vlan_name list		1.3.6.1.4.1.14179.2.1.4.1.27
Client vlan_id list			1.3.6.1.4.1.14179.2.1.4.1.29

Example:
SNMPv2-SMI::enterprises.14179.2.1.4.1.1.92.81.79.225.178.227 = Hex-STRING: 5C 51 4F E1 B2 E3
SNMPv2-SMI::enterprises.14179.2.1.4.1.2.92.81.79.225.178.227 = IpAddress: 10.95.70.52
SNMPv2-SMI::enterprises.14179.2.1.4.1.3.92.81.79.225.178.227 = STRING: "host/L20702.lausanne.ch"
SNMPv2-SMI::enterprises.14179.2.1.4.1.7.92.81.79.225.178.227 = STRING: "DATAS"
SNMPv2-SMI::enterprises.14179.2.1.4.1.27.92.81.79.225.178.227 = STRING: "pdp-12-0-205"
SNMPv2-SMI::enterprises.14179.2.1.4.1.29.92.81.79.225.178.227 = INTEGER: 205

*/

function get_wifi_mac_table($hostrecord_array) {
	$snmp_controler_ssid_name = '.1.3.6.1.4.1.14179.2.1.1.1.2';
	$snmp_client_mac =	'.1.3.6.1.4.1.14179.2.1.4.1.1';
	$snmp_client_ip =	'.1.3.6.1.4.1.14179.2.1.4.1.2';
	$snmp_client_name =	'.1.3.6.1.4.1.14179.2.1.4.1.3';
	$snmp_client_ssid =	'.1.3.6.1.4.1.14179.2.1.4.1.7';
	$snmp_client_vlan_name =	'.1.3.6.1.4.1.14179.2.1.4.1.27';
	$snmp_client_vlan_id =	'.1.3.6.1.4.1.14179.2.1.4.1.29';

	// get full MAC table on device
	$arp_table_array = cacti_snmp_walk( $hostrecord_array['hostname'], $hostrecord_array['snmp_community'], $snmp_client_mac, 
	$hostrecord_array['snmp_version'] ); // return OID with MAC and HEX MAC as value
//ciscotools_log('1: get MAC table array' . print_r( $arp_table_array ,true) );

	// get full ip table on device
	$ip_table_array = cacti_snmp_walk( $hostrecord_array['hostname'], $hostrecord_array['snmp_community'], $snmp_client_ip, 
	$hostrecord_array['snmp_version'] ); // return OID with MAC and IP as value
//ciscotools_log('2: get IP table array' . print_r( $ip_table_array, true) );

	// get full SSID table on device
	$ssid_table_array = cacti_snmp_walk( $hostrecord_array['hostname'], $hostrecord_array['snmp_community'], $snmp_client_ssid, 
	$hostrecord_array['snmp_version'] ); // return OID with MAC and SSID as value
//ciscotools_log('3: get SSID table array' . print_r( $ssid_table_array ,true) );

	// get full hostname table on device
	$hostname_table_array = cacti_snmp_walk( $hostrecord_array['hostname'], $hostrecord_array['snmp_community'], $snmp_client_name, 
	$hostrecord_array['snmp_version'] ); // return OID with MAC and SSID as value
//ciscotools_log('4: get hostname table array' . print_r( $hostname_table_array ,true) );

	// get full vlan id table on device
	$vlan_id_table_array = cacti_snmp_walk( $hostrecord_array['hostname'], $hostrecord_array['snmp_community'], $snmp_client_vlan_id, 
	$hostrecord_array['snmp_version'] ); // return OID with MAC and vlan id as value
//ciscotools_log('5: get vlan id table array' . print_r( $vlan_id_table_array ,true) );

	// get full vlan name table on device
	$vlan_name_table_array = cacti_snmp_walk( $hostrecord_array['hostname'], $hostrecord_array['snmp_community'], $snmp_client_vlan_name, 
	$hostrecord_array['snmp_version'] ); // return OID with MAC and lvan name as value
//ciscotools_log('6: get vlan name table array' . print_r( $vlan_name_table_array ,true) );

	// get full SSID name table on device
	$ssid_controler_table_array = cacti_snmp_walk( $hostrecord_array['hostname'], $hostrecord_array['snmp_community'], $snmp_controler_ssid_name, 
	$hostrecord_array['snmp_version'] ); // return OID with index and name
//ciscotools_log('7: get SSID controler name table array' . print_r( $ssid_controler_table_array ,true) );

	$cnt = 0; // init the table count to store all info later
	foreach( $arp_table_array as $mac_address) {
		$mac_array[$cnt]['mac_address'] = '';
		// take the MAC from the OID, in decimal format
		$regex = '~(.[0-9]{1,3}){6}$~';
		preg_match($regex, $mac_address['oid'], $matches, PREG_OFFSET_CAPTURE, 0);
		$mac_oid = $matches[0][0]; // include a first .
		
		// store mac to return array
		$mac_array[$cnt]['mac_address'] = str_replace( ' ', '', $mac_address['value']); // and remove space inside
		if( !is_string($mac_array[$cnt]['mac_address']) ) continue; // drop record if not correct

		// parse for ip
		$mac_array[$cnt]['ip_address'] = '';
		foreach( $ip_table_array as $ip_address) {
			$mac_ip_oid = $snmp_client_ip . $mac_oid;
			if( $ip_address['oid'] != $mac_ip_oid ) continue; // Mac dosen't match what we are looking for, so go to next one
			
			$mac_array[$cnt]['ip_address'] = $ip_address['value']; // store IP from value
			break; // find so exit
		}
		// if no IP  we think not online, so drop it
		if(filter_var($mac_array[$cnt]['ip_address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === FALSE || $mac_array[$cnt]['ip_address'] == '0.0.0.0') {
			ciscotools_log('IP error '.$mac_array[$cnt]['ip_address'].' for mac: '.$mac_ip_oid);
			continue;
		}
		
		// parse for name
		foreach( $hostname_table_array as $hostname) {
			$mac_hostname_oid = $snmp_client_name . $mac_oid;
			if( $hostname['oid'] != $mac_hostname_oid ) continue; // Mac dosen't match what we are looking for, so go to next one
			
			$mac_array[$cnt]['description'] = $hostname['value']; // store hostname from value
			break; // find so exit
		}
		// parse for SSID, as vlan name
		foreach( $ssid_table_array as $ssid) {
			$mac_ssid_oid = $snmp_client_ssid . $mac_oid;
			if( $ssid['oid'] != $mac_ssid_oid ) continue; // Mac dosen't match what we are looking for, so go to next one
			
			$mac_array[$cnt]['vlan_name'] = $ssid['value']; // store SSID from value
			break; // find so exit
		}
		// parse for vlan id
		foreach( $vlan_id_table_array as $vlan_id) {
			$mac_vlan_id_oid = $snmp_client_vlan_id . $mac_oid;
			if( $vlan_id['oid'] != $mac_vlan_id_oid ) continue; // Mac dosen't match what we are looking for, so go to next one
			
			$mac_array[$cnt]['vlan_id'] = $vlan_id['value']; // store VLAN ID from value
			break; // find so exit
		}
		// parse for port index, based on vlan(ssid) name and moatch by name
		foreach( $ssid_controler_table_array as $ssid_name) {
			$regex = '~([0-9]+$)~';
			preg_match($regex, $ssid_name['oid'], $matches, PREG_OFFSET_CAPTURE, 0);
			$ssid_index = $matches[0][0];
			if( $ssid_name['value'] != $mac_array[$cnt]['vlan_name'] ) continue; // Mac dosen't match what we are looking for, so go to next one
			
			$mac_array[$cnt]['port_index'] = $ssid_index;
			break; // find so exit
		}

		// finished and write to BD
		$mysql = ("INSERT INTO plugin_ciscotools_mactrack (host_id,mac_address,ip_address,description,port_index,vlan_id,vlan_name,date) 
			VALUES ('".
			$hostrecord_array['id']."','".
			$mac_array[$cnt]['mac_address']."','".
			$mac_array[$cnt]['ip_address']."','".
			$mac_array[$cnt]['description']."','".
			$mac_array[$cnt]['port_index']."','".
			$mac_array[$cnt]['vlan_id']."','".
			$mac_array[$cnt]['vlan_name']."','".
			date("YmdHis")."') ON DUPLICATE KEY UPDATE date='".
			date("YmdHis")."'"
		);
//ciscotools_log('write to DB: '.$mysql);
		$ret = db_execute($mysql);
		$cnt++;
	}

}

?>