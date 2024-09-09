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
	define( 'UPGRADE_STATUS_UNKNOWN', 0 );
	define( 'UPGRADE_STATUS_PENDING', 1 ); //
	define( 'UPGRADE_STATUS_CHECKING', 2 ); //
	define( 'UPGRADE_STATUS_UPLOADING', 3 ); //
	define( 'UPGRADE_STATUS_ACTIVATING', 4 );
	define( 'UPGRADE_STATUS_REBOOTING', 5 ); //
	define( 'UPGRADE_STATUS_NEED_UPGRADE', 6 );
	define( 'UPGRADE_STATUS_NEED_REBOOT', 7 );
	define( 'UPGRADE_STATUS_NEED_COMMIT', 8 );
	define( 'UPGRADE_STATUS_TABLE_ERROR', 9 );
	define( 'UPGRADE_STATUS_UNSUPORTED', 10 );
	define( 'UPGRADE_STATUS_UPGRADE_DISABLED', 11 ); //
	define( 'UPGRADE_STATUS_CHECKING_ERROR', 12 );
	define( 'UPGRADE_STATUS_TFTP_DOWN', 13 );
	define( 'UPGRADE_STATUS_UPLOAD_STUCK', 14 );
	define( 'UPGRADE_STATUS_UPLOAD_ERROR', 15 );
	define( 'UPGRADE_STATUS_INFO_ERROR', 16 );
	define( 'UPGRADE_STATUS_SNMP_ERROR', 17 );
	define( 'UPGRADE_STATUS_IMAGE_INFO_ERROR', 18 );
	define( 'UPGRADE_STATUS_SSH_ERROR', 19 );
	define( 'UPGRADE_STATUS_IN_TEST', 20 ); //
	define( 'UPGRADE_STATUS_UPDATE_OK', 21 );
	define( 'UPGRADE_STATUS_NEED_RECHECK', 22 );
	define( 'UPGRADE_STATUS_FORCE_REBOOT_COMMIT', 23 );
	define( 'UPGRADE_STATUS_ACTIVATING_ERROR', 24 );
	define( 'UPGRADE_STATUS_ARCHIVE_EXTRACT', 25 );
	define( 'UPGRADE_STATUS_NO_SPACE_LEFT', 26 );
	define( 'UPGRADE_STATUS_BUNDLE_TO_INSTALL', 27 );
}
/*
// Status text depending of status number
$statusText = array(
	'0'		=> 'Unknown',
    '1'     => 'Pending',
    '2'     => 'Checking Device',
    '3'     => 'Uploading & Upgrading',
    '4'     => 'Activating (Install mode)',
    '5'     => 'Rebooting',
    '6'     => 'Need to be upgraded',
    '7'     => 'Need to be rebooted',
    '8'     => 'Need to be activated & committed manually',
    '9'     => 'Error in table "upgrade"',
    '10'    => 'Model unsupported',
    '11'    => 'Upgrade disabled',
    '12'    => 'Error checking version',
    '13'    => 'TFTP server seems to be down',
    '14'    => 'Stuck in upload',
    '15'    => 'Error in upload',
    '16'    => 'Error getting device infos',
    '17'    => 'Error getting SNMP infos',
    '18'    => 'Error getting image infos',
    '19'    => 'Error SSH',
    '20'    => 'In test',
    '21'    => 'Up to date',
    '22'    => 'Recheck',
	'23'	=> 'Force reboot & commit',
	'24'	=> 'Activating Error';
	'25'	=> 'Extracting archive file';
	'26'	=> 'No space left on device';
	'27'	=> 'Bundle to install mode, manual action';
);
*/

// =============================================================================
// STATUS
// =============================================================================
defined('CISCOTLS_UPG_STATUS')
    or define('CISCOTLS_UPG_STATUS', array(
        UPGRADE_STATUS_UNKNOWN   => array(
            'name'  => 'Unknown',
            'desc'  => 'The status is unknown.',
            'color' => 'red'
        ),
        UPGRADE_STATUS_PENDING   => array(
            'name'  => 'Pending',
            'desc'  => 'The device is waiting the update call.',
            'color' => 'blue'
        ),
        UPGRADE_STATUS_CHECKING   => array(
            'name'  => 'Checking device',
            'desc'  => 'The device is being checked to see if it is supported.',
            'color' => 'BlueViolet'
        ),
        UPGRADE_STATUS_UPLOADING   => array(
            'name'  => 'Uploading & Upgrading',
            'desc'  => 'The new image upload is in progress and the upgrading will start at the upload end.',
            'color' => 'BlueViolet'
        ),
        UPGRADE_STATUS_ACTIVATING    => array(
            'name'  => 'Activating (Install mode)',
            'desc'  => 'The activating is in progress - Only for the \'install mode\' devices.',
            'color' => 'GoldenRod'
        ),
        UPGRADE_STATUS_REBOOTING => array(
            'name'  => 'Rebooting',
            'desc'  => 'The device is rebooting.',
            'color' => 'GoldenRod'
        ),
        UPGRADE_STATUS_NEED_UPGRADE  => array(
            'name'  => 'Needs upgrade',
            'desc'  => 'The device needs to be upgraded, it is not up to date!',
            'color' => 'orange'
        ),
        UPGRADE_STATUS_NEED_REBOOT   => array(
            'name'  => 'Needs reboot',
            'desc'  => 'The device needs to be rebooted, the new image has been uploaded but the device was not upgraded!',
            'color' => 'orange'
        ),
        UPGRADE_STATUS_NEED_COMMIT  => array(
            'name'  => 'Needs activate & commit',
            'desc'  => 'The device needs to be activated and committed manually.',
            'color' => 'orange'
        ),
        UPGRADE_STATUS_TABLE_ERROR   => array(
            'name'  => 'Error - Upgrade table',
            'desc'  => '<!>',
            'color' => 'red'
        ),
        UPGRADE_STATUS_UNSUPORTED => array(
            'name'  => 'Error - Model unsupported',
            'desc'  => 'The device model is not supported by the upgrade tool',
            'color' => 'red'
        ),
        UPGRADE_STATUS_UPGRADE_DISABLED  => array(
            'name'  => 'Upgrade disabled',
            'desc'  => 'The device cannot be upgraded: the upgrade option is disabled.',
            'color' => 'red'
        ),
        UPGRADE_STATUS_CHECKING_ERROR  => array(
            'name'  => 'Error - Check version',
            'desc'  => '<!>',
            'color' => 'red'
        ),
        UPGRADE_STATUS_TFTP_DOWN   => array(
            'name'  => 'Error - TFTP down',
            'desc'  => 'The TFTP service is down!',
            'color' => 'red'
        ),
        UPGRADE_STATUS_UPLOAD_STUCK    => array(
            'name'  => 'Error - Stuck in upload',
            'desc'  => 'The device seems to be stuck in the upload progresss. Please erase the image on the device and do the \'recheck\' action for this device!',
            'color' => 'red'
        ),
        UPGRADE_STATUS_UPLOAD_ERROR  => array(
            'name'  => 'Error - Upload',
            'desc'  => 'A problem occured with the upload. Please connect to the device and check the image in the flash, delete the most recent and retry the upgrade.',
            'color' => 'red'
        ),
        UPGRADE_STATUS_INFO_ERROR    => array(
            'name'  => 'Error - Device infos',
            'desc'  => '<!>',
            'color' => 'red'
        ),
        UPGRADE_STATUS_SNMP_ERROR   => array(
            'name'  => 'Error - SNMP infos',
            'desc'  => '<!>',
            'color' => 'red'
        ),
        UPGRADE_STATUS_IMAGE_INFO_ERROR => array(
            'name'  => 'Error - Image infos',
            'desc'  => '<!>',
            'color' => 'red'
        ),
        UPGRADE_STATUS_SSH_ERROR => array(
            'name'  => 'Error - SSH',
            'desc'  => 'An error with SSH occured.',
            'color' => 'red'
        ),
        UPGRADE_STATUS_IN_TEST   => array(
            'name'  => 'In test',
            'desc'  => 'The device is in test. Do not modify this device!',
            'color' => 'LightSalmon'
        ),
        UPGRADE_STATUS_UPDATE_OK    => array(
            'name'  => 'Up-to-date',
            'desc'  => 'The device is up-to-date.',
            'color' => 'green'
        ),
        UPGRADE_STATUS_NEED_RECHECK   => array(
            'name'  => 'Recheck',
            'desc'  => 'A recheck is made by the upgrade tool. Do not perform an action on this device!',
            'color' => 'black'
        ),
		UPGRADE_STATUS_FORCE_REBOOT_COMMIT   => array(
            'name'  => 'Force Reboot/Commit',
            'desc'  => 'Force the device to reboot or Activate commit.',
            'color' => 'Indigo'
        ),
		UPGRADE_STATUS_ACTIVATING_ERROR   => array(
            'name'  => 'Activation Error',
            'desc'  => 'Install Mode Error when activatio of the new version.',
            'color' => 'red'
        ),
		UPGRADE_STATUS_ARCHIVE_EXTRACT   => array(
            'name'  => 'Archive Extract',
            'desc'  => 'Archive mode: extracting bin file.',
            'color' => 'blue'
        ),
		UPGRADE_STATUS_NO_SPACE_LEFT   => array(
            'name'  => 'No Space Left',
            'desc'  => 'No enougth space on device.',
            'color' => 'red'
        ),
		UPGRADE_STATUS_BUNDLE_TO_INSTALL   => array(
            'name'  => 'Bundle to install mode',
            'desc'  => 'Bundle to install mode, manual action request.',
            'color' => 'orange'
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

/*
Values of Status
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

// Reboot
defined('CISCOTOOLS_SNMP_REBOOT')
    or define('CISCOTOOLS_SNMP_REBOOT', '1.3.6.1.4.1.0.2.9.9.0');

// check System image file
defined('CISCOTOOLS_SNMP_IMAGE')
    or define('CISCOTOOLS_SNMP_IMAGE', '1.3.6.1.4.1.9.2.1.73');
?>
