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
*/


/*
Mettre en place une table pour version et contrôle des versions
OU
Initialisation de variables en récupérant le modèle + version
*/
include_once($config['base_path'] . '/plugins/ciscotools/ssh2.php');

/****************
2. MAIN FUNCTION
****************/
function ciscotools_download_OS( $deviceUpgrade ) {
    // OID
    $snmpFlashCopy  = "1.3.6.1.4.1.9.9.10.1.2.1.1"; // ciscoFlashCopyEntry
    $snmpStatus     = $snmpFlashCopy . ".8";        // ciscoFlashCopyStatus
    $snmpCmd        = $snmpFlashCopy . ".2";        // ciscoFlashCopyCommand
    $snmpProt       = $snmpFlashCopy . ".3";        // ciscoFlashCopyProtocol
    $snmpIP         = $snmpFlashCopy . ".4";        // ciscoFlashCopyServerAddress
    $snmpFileSrc    = $snmpFlashCopy . ".5";        // ciscoFlashCopySourceName
    $snmpFileDest   = $snmpFlashCopy . ".6";        // ciscoFlashCopyDestinationName
    $snmpEntryStatus= $snmpFlashCopy . ".11";       // ciscoFlashCopyEntryStatus

    // Initialize variable to check device
    global $upgrade_check;
    $upgrade_check = 0;

    // Call function to check device
    ciscotools_check_device_upgrade($deviceUpgrade);

    // If check is alright, continue
    if ($upgrade_check === 0) {
        ciscotools_log("Upgrade : check is alright and continue");
        
        // SSH
        $dbquery = db_fetch_row_prepared("SELECT hostname, login, password FROM host WHERE id=" . $deviceUpgrade);
        $hostname = $dbquery['hostname'];
        $username = $dbquery['login'];
        $password = $dbquery['password'];
        if (($username != "") && ($password != "")) {
            // TEMPORARY
            $fileSize = 1212416;

            // Get total & free size of flash:
            $cmds = ["show flash:", "show run | include snmp"];
            $totalSize = cmdSSH($hostname, $username, $password, "show flash:");
            ciscotools_log($totalSize);
            $test = cmdSSH($hostname, $username, $password, "show run | include snmp");
            ciscotools_log($test);
            /*
            $cmds = ["show flash:", "show run | include snmp"];
            for ($i = 0; $i < sizeof($cmds); $i++) {
                if ($i == 0) {
                    $totalSize = ssh_read_stream($connection, $cmds[0]);
                    ciscotools_log($totalSize);
                }
                else {
                    $output = ssh_read_stream($connection, $cmds[$i]);
                    ciscotools_log($output);
                }
            }
            */

            // Check if size is enough
            $checkSize = checkSize($totalSize, $fileSize);
            if($checkSize == true) {
                // FILE INFOS
                //TEMPORARY
                $fileName = "test.txt";
                $initialFileMD5 = "cfae8b8de93317f96a7c069b6ed1efe8";
                $tftpAddress = "10.85.116.209";

                $snmpVersion = "2c";
                $snmpCommunity = "soivlsn";
                $snmpSession = ".209";

                // Begin upload via SNMP
                /*
                INITIALIZE : snmpset -v 2c -c soivlsn 10.85.0.177 1.3.6.1.4.1.9.9.10.1.2.1.1.11.50 i 5 ok
                DEL : snmpset -v 2c -c soivlsn 10.85.0.177 1.3.6.1.4.1.9.9.10.1.2.1.1.11.50 i 6 
                CMD : snmpset -v 2c -c soivlsn 10.85.0.177 1.3.6.1.4.1.9.9.10.1.2.1.1.2.50 i 2
                PROT : snmpset -v 2c -c soivlsn 10.85.0.177 1.3.6.1.4.1.9.9.10.1.2.1.1.3.50 i 1
                IP TFTP : snmpset -v 2c -c soivlsn 10.85.0.177 1.3.6.1.4.1.9.9.10.1.2.1.1.4.50 a 10.85.116.209
                SRC : snmpset -v 2c -c soivlsn 10.85.0.177 1.3.6.1.4.1.9.9.10.1.2.1.1.5.50 s test.txt
                DST : snmpset -v 2c -c soivlsn 10.85.0.177 1.3.6.1.4.1.9.9.10.1.2.1.1.6.50 s test.txt
                SRC : snmpset -v 2c -c soivlsn 10.85.0.177 1.3.6.1.4.1.9.9.10.1.2.1.1.11.50 i 1
                */
                
                // Upload SNMP
                snmpUpgSet($snmpVersion, $snmpCommunity, $hostname, $snmpEntryStatus, $snmpSession, "i", "6");     // Delete session
                snmpUpgSet($snmpVersion, $snmpCommunity, $hostname, $snmpEntryStatus, $snmpSession, "i", "5");     // Initialize
                snmpUpgSet($snmpVersion, $snmpCommunity, $hostname, $snmpCmd, $snmpSession, "i", "2");             // Cmd type - 2:Copy w/o erase
                snmpUpgSet($snmpVersion, $snmpCommunity, $hostname, $snmpProt, $snmpSession, "i", "1");            // Protocole - 1:TFTP
                snmpUpgSet($snmpVersion, $snmpCommunity, $hostname, $snmpIP, $snmpSession, "a", $tftpAddress);     // Server Address
                snmpUpgSet($snmpVersion, $snmpCommunity, $hostname, $snmpFileSrc, $snmpSession, "s", $fileName);   // Source File
                snmpUpgSet($snmpVersion, $snmpCommunity, $hostname, $snmpFileDest, $snmpSession, "s", $fileName);  // Dest File
                snmpUpgSet($snmpVersion, $snmpCommunity, $hostname, $snmpEntryStatus, $snmpSession, "i", "1");     // Begin Upload
                ciscotools_log(snmpUpgWalk($snmpVersion, "telvlsn", $hostname, $snmpStatus, $snmpSession));

                // Regex status & check end upload
                $statusUploadRegex = "/INTEGER: [0-9]$/";
                do {
                    $uploadStatus = snmpUpgWalk($snmpVersion, "telvlsn", $hostname, $snmpStatus, $snmpSession);
                    preg_match($statusUploadRegex, $uploadStatus, $uploadStatus);
                } while($uploadStatus[0] != "INTEGER: 2");
                ciscotools_log("Upload status: " . $uploadStatus[0]);

                // Delete session
                if($uploadStatus[0] == "INTEGER: 2") {

                }
                else {
                    switch($uploadStatus[0]) {
                        case "INTEGER: 3":
                            ciscotools_log("UPLOAD ERROR: ");
                            case "INTEGER: 3":
                                ciscotools_log("UPLOAD ERROR: ");
                        
                    }
                }
/*
copyInvalidOperation(3),
                        copyInvalidProtocol(4),
                        copyInvalidSourceName(5),
                        copyInvalidDestName(6),
                        copyInvalidServerAddress(7),
                        copyDeviceBusy(8),
                        copyDeviceOpenError(9),
                        copyDeviceError(10),
                        copyDeviceNotProgrammable(11),
                        copyDeviceFull(12),
                        copyFileOpenError(13),
                        copyFileTransferError(14),
                        copyFileChecksumError(15),
                        copyNoMemory(16),
                        copyUnknownFailure(17),
                        copyInvalidSignature(18),
                        copyProhibited(19)
*/

                //snmpset($hostname, "telvlsn", ".1.3.6.1.4.1.9.2.10.9.10.85.116.209", "s", "test.txt enterprises.9.2.10.9.10.85.116.209 = test.txt");

                // End upload >> check final file MD5
                /*
                $cmdVerify = "verify /md5 flash:" . $fileName;
                $checkIntegrity = ssh_read_stream($connection, $cmdVerify);
                */
            }
            elseif ($checkSize == false) {
                return false;
            }
            
            /*
            // Disconnect SSH
            close_ssh($connection);
            */
        }
        else {
            ciscotools_log("No login - No password");
            return false;
        }
        /* END OF FUNCTION SO REMOVE DEVICE FROM QUEUE
        //$queryRMQueue = db_execute("DELETE FROM `ciscotools_queueUpgrade` WHERE `ciscotools_queueUpgrade`.`id_ciscoTools_queueUpgrade` =" . $deviceUpgrade['id'] . ");
        //if ($queryRMQueue) {
            ciscotools_log("Upgrade : succeed - Entry removed from queue");
            return true;
        }
        else {
            ciscotools_log("Upgrade : error - Entry not removed from queue");
            return false;
        }
        */
        return true;
    }

    // If check is not alright, stop
    elseif ($upgrade_check === 1) {
        ciscotools_log("Upgrade : check is not alright and stop");

        // ADD USER NOTIFICATION
        return false;
    }
}

/************
3. FUNCTIONS
************/
// Check function to know if device is already in the queue
function ciscotools_check_device_upgrade( $deviceUpgrade ) {
    // Query if device is already in queue
    echo 'console.log('. json_encode( $deviceUpgrade ) .')';

    $queryQueue = db_fetch_assoc("SELECT id, host FROM plugin_ciscotools_queueupgrade WHERE host=".$deviceUpgrade);
    
    // If it's in queue
    if ($queryQueue) {
        $GLOBALS['upgrade_check'] = 1;
        ciscotools_log("Upgrade : device in queue");
        return false;
    }

    // If it is not
    else {
        $GLOBALS['upgrade_check'] = 0;
        // Create entry
        //$queryCDeviceQueue = db_execute("INSERT INTO `plugin_ciscotools_queueupgrade` (`id`, `host`) VALUES (NULL,". $deviceUpgrade['id'] .")");
        ciscotools_log("Upgrade : device not in queue - Added in DB");
        return true;
    }
}

function checkSize($totalSize, $fileSize) {
    // Regex Size
    $regexSize = "/\((.*) bytes free\)/";
    preg_match($regexSize, $totalSize, $sizeFree);
    $sizeFree = (int)$sizeFree[1];

    // Compare sizeFree with fileSize
    if($sizeFree > $fileSize) {
        ciscotools_log("Size OK");
        return true;
    }
    else {
        ciscotools_log("Size NOK");
        return false;
    }
}

function cmdSSH($hostname, $username, $password, $cmd) {
    $connection = open_ssh($hostname, $username, $password);
    $output = ssh_read_stream($connection, $cmd);
    close_ssh($connection);
    $output = addslashes(substr($output, strpos($output,'version')+25));
    return $output;
}

function snmpUpgSet($snmpVersion, $snmpCommunity, $hostname, $oid, $snmpSession, $snmpDataType, $snmpData) {
    $snmpExec = shell_exec("snmpset -v " . $snmpVersion . " -c " . $snmpCommunity . " " . $hostname . " " . $oid . $snmpSession . " " . $snmpDataType . " " . $snmpData);
    ciscotools_log($snmpExec);
    return $snmpExec;
}

function snmpUpgWalk($snmpVersion, $snmpCommunity, $hostname, $oid, $snmpSession) {
    $snmpExec = shell_exec("snmpwalk -v " . $snmpVersion . " -c " . $snmpCommunity . " " . $hostname . " " . $oid . $snmpSession);
    //ciscotools_log($snmpExec);
    return $snmpExec;
}

?>