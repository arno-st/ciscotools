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

// defined('NAME') or define('NAME', 'VALUE')
// STATUS
// Nomenclature : CISCOTLS_CONSTANT_NAME
// device status definition
if( !defined('UPGRADE_STATUS_PENDING') ) {
	define( 'UPGRADE_STATUS_PENDING', 0 );
	define( 'UPGRADE_STATUS_CHECKING', 1 );
	define( 'UPGRADE_STATUS_UPLOADING', 2 );
	define( 'UPGRADE_STATUS_ACTIVATING', 3 );
	define( 'UPGRADE_STATUS_REBOOTING', 4 );
	define( 'UPGRADE_STATUS_NEED_UPGRADE', 5 );
	define( 'UPGRADE_STATUS_NEED_REBOOT', 6 );
	define( 'UPGRADE_STATUS_NEED_COMMIT', 7 );
	define( 'UPGRADE_STATUS_TABLE_ERROR', 8 );
	define( 'UPGRADE_STATUS_UNSUPORTED', 9 );
	define( 'UPGRADE_STATUS_UPGRADE_DISABLED', 10 );
	define( 'UPGRADE_STATUS_CHECKING_ERROR', 11 );
	define( 'UPGRADE_STATUS_TFTP_DOWN', 12 );
	define( 'UPGRADE_STATUS_UPLOAD_STUCK', 13 );
	define( 'UPGRADE_STATUS_UPLOAD_ERROR', 14 );
	define( 'UPGRADE_STATUS_INFO_ERROR', 15 );
	define( 'UPGRADE_STATUS_SNMP_ERROR', 16 );
	define( 'UPGRADE_STATUS_IMAGE_INFO_ERROR', 17 );
	define( 'UPGRADE_STATUS_SSH_ERROR', 18 );
	define( 'UPGRADE_STATUS_IN_TEST', 19 );
	define( 'UPGRADE_STATUS_UPDATE_OK', 20 );
	define( 'UPGRADE_STATUS_NEED_RECHECK', 21 );
	define( 'UPGRADE_STATUS_UNKNOWN', 22 );
}
// Status text depending of status number
$statusText = array(
    '0'     => 'Pending',
    '1'     => 'Checking Device',
    '2'     => 'Uploading & Upgrading',
    '3'     => 'Activating (Install mode)',
    '4'     => 'Rebooting',
    '5'     => 'Need to be upgraded',
    '6'     => 'Need to be rebooted',
    '7'     => 'Need to be activated & committed manually',
    '8'     => 'Error in table "upgrade"',
    '9'     => 'Model unsupported',
    '10'    => 'Upgrade disabled',
    '11'    => 'Error checking version',
    '12'    => 'TFTP server seems to be down',
    '13'    => 'Stuck in upload',
    '14'    => 'Error in upload',
    '15'    => 'Error getting device infos',
    '16'    => 'Error getting SNMP infos',
    '17'    => 'Error getting image infos',
    '18'    => 'Error SSH',
    '19'    => 'In test',
    '20'    => 'Up to date',
    '21'    => 'Recheck',
	'22'	=> 'Unknown'
);

// Text color depending of status number
$statusColor = array(
    "0"     => "inherit",
    "1"     => "blue",
    "2"     => "blue",
    "3"     => "blue",
    "4"     => "blue",
    "5"     => "orange",
    "6"     => "orange",
    "7"     => "orange",
    "8"     => "red",
    "9"     => "red",
    "10"    => "red",
    "11"    => "red",
    "12"    => "red",
    "13"    => "red",
    "14"    => "red",
    "15"    => "red",
    "16"    => "red",
    "17"    => "red",
    "18"    => "red",
    "19"    => "violet",
    "20"    => "green",
    "21"    => "inherit",
	"22"	=> "red"
);

// =============================================================================
// STATUS
// =============================================================================
defined('CISCOTLS_UPG_STATUS')
    or define('CISCOTLS_UPG_STATUS', array(
        'PENDING'   => array(
            'code'  => 1,
            'name'  => 'Pending',
            'desc'  => 'The device is waiting the update call.',
            'color' => 'blue'
        ),
        'CHECKING_DEVICE'   => array(
            'code'  => 2,
            'name'  => 'Checking device',
            'desc'  => 'The device is being checked to see if it is supported.',
            'color' => 'blue'
        ),
        'UPLOADING_UPGRADING'   => array(
            'code'  => 3,
            'name'  => 'Uploading & Upgrading',
            'desc'  => 'The new image upload is in progress and the upgrading will start at the upload end.',
            'color' => 'blue'
        ),
        'ACTIVATING'    => array(
            'code'  => 4,
            'name'  => 'Activating (Install mode)',
            'desc'  => 'The activating is in progress - Only for the \'install mode\' devices.',
            'color' => 'blue'
        ),
        'REBOOTING' => array(
            'code'  => 5,
            'name'  => 'Rebooting',
            'desc'  => 'The device is rebooting.',
            'color' => 'blue'
        ),
        'NEED_UPGRADE'  => array(
            'code'  => 6,
            'name'  => 'Needs upgrade',
            'desc'  => 'The device needs to be upgraded, it is not up to date!',
            'color' => 'orange'
        ),
        'NEED_REBOOT'   => array(
            'code'  => 7,
            'name'  => 'Needs reboot',
            'desc'  => 'The device needs to be rebooted, the new image has been uploaded but the device was not upgraded!',
            'color' => 'orange'
        ),
        'NEED_ACTIVATE_COMMIT'  => array(
            'code'  => 8,
            'name'  => 'Needs activate & commit',
            'desc'  => 'The device needs to be activated and committed manually.',
            'color' => 'orange'
        ),
        'ERROR_TABLE_UPGRADE'   => array(
            'code'  => 9,
            'name'  => 'Error - Upgrade table',
            'desc'  => '<!>',
            'color' => 'red'
        ),
        'ERROR_MODEL_UNSUPPORTED' => array(
            'code'  => 10,
            'name'  => 'Error - Model unsupported',
            'desc'  => 'The device model is not supported by the upgrade tool',
            'color' => 'red'
        ),
        'UPGRADE_DISABLED'  => array(
            'code'  => 11,
            'name'  => 'Upgrade disabled',
            'desc'  => 'The device cannot be upgraded: the upgrade option is disabled.',
            'color' => 'red'
        ),
        'ERROR_CHECK_VERSION'   => array(
            'code'  => 12,
            'name'  => 'Error - Check version',
            'desc'  => '<!>',
            'color' => 'red'
        ),
        'ERROR_TFTP_DOWN'   => array(
            'code'  => 13,
            'name'  => 'Error - TFTP down',
            'desc'  => 'The TFTP service is down!',
            'color' => 'red'
        ),
        'ERROR_STUCK_UPLOAD'    => array(
            'code'  => 14,
            'name'  => 'Error - Stuck in upload',
            'desc'  => 'The device seems to be stuck in the upload progresss. Please erase the image on the device and do the \'recheck\' action for this device!',
            'color' => 'red'
        ),
        'ERROR_UPLOAD'  => array(
            'code'  => 15,
            'name'  => 'Error - Upload',
            'desc'  => 'A problem occured with the upload. Please connect to the device and check the image in the flash, delete the most recent and retry the upgrade.',
            'color' => 'red'
        ),
        'ERROR_DEVICE_INFOS'    => array(
            'code'  => 16,
            'name'  => 'Error - Device infos',
            'desc'  => '<!>',
            'color' => 'red'
        ),
        'EROR_SNMP_INFOS'   => array(
            'code'  => 17,
            'name'  => 'Error - SNMP infos',
            'desc'  => '<!>',
            'color' => 'red'
        ),
        'ERROR_IMAGE_INFOS' => array(
            'code'  => 18,
            'name'  => 'Error - Image infos',
            'desc'  => '<!>',
            'color' => 'red'
        ),
        'ERROR_SSH' => array(
            'code'  => 19,
            'name'  => 'Error - SSH',
            'desc'  => 'An error with SSH occured.',
            'color' => 'red'
        ),
        'IN_TEST'   => array(
            'code'  => 20,
            'name'  => 'In test',
            'desc'  => 'The device is in test. Do not modify this device!',
            'color' => 'violet'
        ),
        'UP_TO_DATE'    => array(
            'code'  => 21,
            'name'  => 'Up-to-date',
            'desc'  => 'The device is up-to-date.',
            'color' => 'green'
        ),
        'RECHECK'   => array(
            'code'  => 22,
            'name'  => 'Recheck',
            'desc'  => 'A recheck is made by the upgrade tool. Do not perform an action on this device!',
            'color' => 'black'
        )
    ));

// =============================================================================
// REGEX
// =============================================================================
// IP
defined('CISCOTOOLS_REGEX_IP')
    or define('CISCOTOOLS_REGEX_IP',
    "/(^|\s|(\[))(::)?([a-f\d]{1,4}::?){0,7}(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}" .
    "(?=(?(2)\]|($|\s|(?(3)($|\s)|(?(4)($|\s)|:\d)))))|((?(3)[a-f\d]{1,4})|(?(4)" .
    "[a-f\d]{1,4}))(?=(?(2)\]|($|\s))))(?(2)\])(:\d{1,5})?/"
);

// =============================================================================
// SNMP
// =============================================================================
// flashCopy
defined('CISCOTOOLS_SNMP_FLASHCOPY')
    or define('CISCOTOOLS_SNMP_FLASHCOPY', array(
        'cmd'           => '1.3.6.1.4.1.9.9.10.1.2.1.1.2',
        'protocol'      => '1.3.6.1.4.1.9.9.10.1.2.1.1.3',
        'ip'            => '1.3.6.1.4.1.9.9.10.1.2.1.1.4',
        'fileSrc'       => '1.3.6.1.4.1.9.9.10.1.2.1.1.5',
        'fileDst'       => '1.3.6.1.4.1.9.9.10.1.2.1.1.6',
        'status'        => '1.3.6.1.4.1.9.9.10.1.2.1.1.8',
        'entryStatus'   => '1.3.6.1.4.1.9.9.10.1.2.1.1.11',
        'verify'        => '1.3.6.1.4.1.9.9.10.1.2.1.1.12'
));

// copyEntry
defined('CISCOTOOLS_SNMP_COPYENTRY')
    or define('CISCOTOOLS_SNMP_COPYENTRY', array(
        'protocol'      => '1.3.6.1.4.1.9.9.96.1.1.1.1.2',
        'srcFileType'   => '1.3.6.1.4.1.9.9.96.1.1.1.1.3',
        'dstFileType'   => '1.3.6.1.4.1.9.9.96.1.1.1.1.4',
        'srvAddress'    => '1.3.6.1.4.1.9.9.96.1.1.1.1.5',
        'fileName'      => '1.3.6.1.4.1.9.9.96.1.1.1.1.6',
        'status'        => '1.3.6.1.4.1.9.9.96.1.1.1.1.14'
));

// Reboot
defined('CISCOTOOLS_SNMP_REBOOT')
    or define('CISCOTOOLS_SNMP_REBOOT', '1.3.6.1.4.1.0.2.9.9.0');
?>
