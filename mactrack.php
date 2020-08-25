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

include_once($config['base_path'] . '/plugins/extenddb/ssh2.php');
$snmp_bridge = "1.3.6.1.2.1.17.4.4"; // where you can find info on mac table

$snmp_bridge_pot2int	= ".1.3.6.1.2.1.17.1.4.1.2"; // Attention mettre @vlan_ids apres la coummunity, snmpwalk

// interface OID
$snmp_interfaces_id		= ".1.3.6.1.2.1.2.2.1.1";
$snmp_interfaces_name	= ".1.3.6.1.2.1.31.1.1.1.1";
$snmp_interfaces_alias	= ".1.3.6.1.2.1.31.1.1.1.18";
$snmp_interfaces_hightSpeed	= ".1.3.6.1.2.1.31.1.1.1.15";
$snmp_interfaces_descr	= ".1.3.6.1.2.1.2.2.1.2";
$snmp_interfaces_adminstatus = ".1.3.6.1.2.1.2.2.1.7";
$snmp_interfaces_operstatus = ".1.3.6.1.2.1.2.2.1.8";

function purge_mac(){
	$datetopurge = date('YmdHis',strtotime(read_config_option('ciscotools_mac_data_retention')) );
	cacti_log( 'Mactrack Purge Before: '.$datetopurge, false, 'CISCOTOOLS' );

// return the list of record to purge
	$sqlquery = "SELECT *
			FROM plugin_ciscotools_mactrack 
			WHERE date < '".$datetopurge."'";

	$sqlret = db_fetch_assoc($sqlquery); // if empty no old record
	
	if($sqlret > 0 ) {
		db_execute( "DELETE FROM plugin_ciscotools_mactrack WHERE date<'".$datetopurge."'");
	}

}

function get_mac_table($hostrecord_array) {
	ciscotools_log('Pool mac for host id:'.$hostrecord_array['description']);
	// host_id is given by the call on this function, on the array $hostrecord_array
	// first pool vlan on device: here you got vlan_id and name
	// second pool mac on each vlan of device: here you add mac address
	// then merge mac, interface data, vlan, device
	// finaly do reverse lookup on ip to have description
	$vlan_array = get_vlan( $hostrecord_array );

	if ( !empty($vlan_array) ) {
		$mac_array = get_mac_vlan( $hostrecord_array, $vlan_array );

		if ( !empty($mac_array) )
			get_ip_4_mac( $hostrecord_array, $mac_array);
	}
}

// match the mac adress to IP and record it
function get_ip_4_mac( $hostrecord_array, $mac_array) {
	$snmp_get_ip = '.1.3.6.1.2.1.4.22.1.2';

	// get full arp table on device
	$arp_table_array = cacti_snmp_walk( $hostrecord_array['hostname'], $hostrecord_array['snmp_community'], $snmp_get_ip, 
	$hostrecord_array['snmp_version'] ); // return OID with IP and MAC as value
	
	//parse each line to extract IP, and match mac array
	$regex = '~\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$~';
	foreach( $arp_table_array as $key => $ipmac ) {
		$test = preg_match($regex, $ipmac['oid'], $ip_match );
		$arp_table['ip'] = $ip_match[0];
		
		// split the mac value a, dcomplete with 0 if necessary
		$arp_table['mac'] = '';
		foreach( explode(':', $ipmac['value']) as $mac ) {
			$arp_table['mac'] .= str_pad($mac, 2, '0', STR_PAD_LEFT);
		}
		unset($mac); // otherwise it is still set
		
		// do Reverse DNS, and update at once, if can't do reverse lookup, take the ip as name
		$ptr_record = gethostbyaddr( $arp_table['ip'] );
		if(filter_var($ptr_record, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === FALSE) {
			$arp_table['description'] = explode('.', $ptr_record )[0]; // take only the host part, remove the domain
		}
		else {
			$arp_table['description'] = $ptr_record;
		}
			
		$mysql = "UPDATE plugin_ciscotools_mactrack SET ip_address='".$arp_table['ip'].
		"', description='".$arp_table['description']."' WHERE mac_address='".$arp_table['mac']."'";
		$ret = db_execute($mysql);
	}
	
}

/* return an array of mac adress on interface index
 array based on index (same as vlan), array of mac, interface index for each mac and vlan
array
	mac_adress
	vlan_name
	vlan_id
	port_index
*/
function get_mac_vlan( $hostrecord_array, $vlan_array ) {
	// https://www.cisco.com/c/en/us/support/docs/ip/simple-network-management-protocol-snmp/44800-mactoport44800.html
	$snmp_mac_list = '.1.3.6.1.2.1.17.4.3.1.1';
	$snmp_bridge_port_number = '.1.3.6.1.2.1.17.4.3.1.2'; // bridge port number dans OID il y a MAC, value give bridgport
	$snmp_bridge_2_index = '.1.3.6.1.2.1.17.1.4.1.2'; // interface index to bridge port number, value donne index interface
	$snmp_interfaces_type	= ".1.3.6.1.2.1.2.2.1.3"; // take only type ethernetCsmacd(6)
	$snmp_is_trunk = '.1.3.6.1.4.1.9.9.46.1.6.1.1.14'; // 1 if in trunk mode
	
	// get mac adress
	$cnt=0;
	$mac_array=array();
	foreach( $vlan_array as $vlankey => $vlanid) {
		// define the community based on the version
		if( $hostrecord_array['snmp_version'] < 3 )
			$snmp_community = $hostrecord_array['snmp_community'].'@'.$vlan_array[$vlankey]['id'];
		else $snmp_community = 'vlan-'.$vlan_array[$vlankey]['id'];
		
//ciscotools_log('get mac vlan:'.$vlan_array[$vlankey]['id']);
		$mac_address_array = cacti_snmp_walk( $hostrecord_array['hostname'], $snmp_community, $snmp_mac_list, 
		$hostrecord_array['snmp_version'] ); // return an array OID and MAC as human readable

//ciscotools_log('get bridge port global');
		$bridge_port_array = cacti_snmp_walk( $hostrecord_array['hostname'], $snmp_community, 
		$snmp_bridge_port_number, $hostrecord_array['snmp_version'] ); // return a value used to get index of internet interface, and oid with the mac
//ciscotools_log('get bridge port global: '.print_r($bridge_port_array,true) );
		if( empty($bridge_port_array) ) continue; // if no bridge port, no mac, go further
		
//ciscotools_log('get itf index global');
		// get interface index from bridge port for all interface in that vlan
		$intf_index_array = cacti_snmp_walk( $hostrecord_array['hostname'], $snmp_community, 
		$snmp_bridge_2_index, $hostrecord_array['snmp_version'] ); 
//ciscotools_log('get itf index: '.print_r($intf_index_array, true) );

		$intf_type_array = cacti_snmp_walk( $hostrecord_array['hostname'], $hostrecord_array['snmp_community'], 
		$snmp_interfaces_type, $hostrecord_array['snmp_version'] );
//ciscotools_log('get itf type: '.print_r($intf_type_array, true) );

//ciscotools_log('get trunk array');
		// Check if it's a trunk, if so make vlan_name as trunk and id 0
		$intf_trunk_array = cacti_snmp_walk( $hostrecord_array['hostname'], $hostrecord_array['snmp_community'], 
		$snmp_is_trunk, $hostrecord_array['snmp_version'] );
//ciscotools_log('get itf trunk: '.print_r($intf_trunk_array, true) );

//ciscotools_log('format mac:'.print_r($mac_address_array, true));
		// save to the return array
		// take each mac address
		foreach($mac_address_array as $key => $mac_address){
			$mac_array[$cnt]['mac_adress'] = str_replace( ' ', '', $mac_address['value']); // and remove space inside
			if( !is_string($mac_array[$cnt]['mac_adress']) ) continue; // drop record if not correct
			$mac_array[$cnt]['vlan_id'] = $vlan_array[$vlankey]['id'];
			$mac_array[$cnt]['vlan_name'] = $vlan_array[$vlankey]['name'];

//ciscotools_log('get itf index from array:'.$key );
			// get interface index from bridge port
			$bridge_index = $snmp_bridge_2_index . '.' . $bridge_port_array[$key]['value'];
//ciscotools_log('oid:'.$bridge_index );
			foreach( $intf_index_array as $bridge2index ){
				$mac_array[$cnt]['port_index'] = '';
				if( $bridge2index['oid'] != $bridge_index) continue;
				$mac_array[$cnt]['port_index'] = $bridge2index['value'];
				unset($bridge2index); // clear the value to avoid problem
				break;
			}
			unset($bridge2index); // clear the value to avoid problem
			
//ciscotools_log('get typefor index: '.$mac_array[$cnt]['port_index']);
			// if interface csmacd, drop record, and continue

//ciscotools_log('get itf type from array');
			// get interface index from bridge port
			$type_index = $snmp_interfaces_type.'.'.$mac_array[$cnt]['port_index'];
//ciscotools_log('type oid:'.$type_index );
			foreach( $intf_type_array as $type4index ){
				if( $type4index['oid'] != $type_index) continue;
				$intf_type = $type4index['value'];
//ciscotools_log('type value:'.$intf_type );
				if( $intf_type != 'ethernetCsmacd' ){
					unset($type4index); // clear the value to avoid problem
					break 2;
				}
				unset($type4index); // clear the value to avoid problem
				break;
			}

			// Check if it's a trunk, if so make vlan_name as trunk and id 0
//ciscotools_log('get itf trunk from array');
			$trunk_index = $snmp_is_trunk.'.'.$mac_array[$cnt]['port_index'];
//ciscotools_log('type oid:'.$trunk_index );
			foreach( $intf_trunk_array as $trunk4index ){
//ciscotools_log('trunk4index oid:'.$trunk4index['oid'] );

				if( $trunk4index['oid'] != $trunk_index) continue;
				$intf_trunk = $trunk4index['value'];
//ciscotools_log('type value:'.$intf_trunk );
				if( $intf_trunk == '1' ) {
					$mac_array[$cnt]['vlan_id']= '0';
					$mac_array[$cnt]['vlan_name'] = 'trunk';
				}
				unset($trunk4index); // clear the value to avoid problem
				break;
			}

			$mysql = ("INSERT INTO plugin_ciscotools_mactrack (host_id,mac_address,port_index,vlan_id,vlan_name,date) 
				VALUES ('".
				$hostrecord_array['id']."','".
				$mac_array[$cnt]['mac_adress']."','".
				$mac_array[$cnt]['port_index']."','".
				$mac_array[$cnt]['vlan_id']."','".
				$mac_array[$cnt]['vlan_name']."','".
				date("YmdHis")."') ON DUPLICATE KEY UPDATE date='".
				date("YmdHis")."'"
				);
ciscotools_log('write to DB: '.$mysql);
			$ret = db_execute($mysql);
			$cnt++;
		}
	}
	
	return $mac_array;
}

// get all usefull vlan, exclude default unused one (1002-1005), and named it
function get_vlan( $hostrecord_array ) {
/* Variables to determine VLAN information */
	$snmp_vlan_ids         = ".1.3.6.1.4.1.9.9.46.1.3.1.1.2"; // SNMPv2-SMI::enterprises.9.9.46.1.3.1.1.2.1.110 = INTEGER: 1
	$snmp_vlan_names       = ".1.3.6.1.4.1.9.9.46.1.3.1.1.4"; //SNMPv2-SMI::enterprises.9.9.46.1.3.1.1.4.1.110 = STRING: "data"
	$snmp_vlan_trunkstatus = ".1.3.6.1.4.1.9.9.46.1.6.1.1.14"; // 1 pour actif
	$snmp_vlan_nbport_using = ".1.3.6.1.2.1.17.1.2.0"; // Attention mettre @vlan_ids après la coummunity, snmpget

	$vlan_ids = cacti_snmp_walk( $hostrecord_array['hostname'], $hostrecord_array['snmp_community'], $snmp_vlan_ids, 
	$hostrecord_array['snmp_version'] ); // return an array of array containing OID, vlan id

	$vlan_names = cacti_snmp_walk( $hostrecord_array['hostname'], $hostrecord_array['snmp_community'], $snmp_vlan_names, 
	$hostrecord_array['snmp_version'] ); // return an array of array containing OID, vlan name

	$cnt=0;
	$vlan=array();
	foreach($vlan_ids as $key => $values ) {
		$regex = '~.[0-9].*\.([0-9].*)~';
		preg_match( $regex, $vlan_ids[$key]['oid'], $result ); // extract the vlan id from the OID
		$id = $result[1];
		switch( $id ) {
			case 1002: // fddi-default
			case 1003: // token-ring-default
			case 1004: // fddinet-default
			case 1005: // trnet-default
			break;
			
			default:
				$vlan[$cnt]['id'] = $id;
				$vlan[$cnt]['name'] = $vlan_names[$key]['value'];
				$cnt++;
			break;
		}
	}
	
	return $vlan;
}

?>