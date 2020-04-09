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

function open_ssh( $hostname, $username, $password ) {
/* ne semble pas necessaire
    $methods = array(
    'kex' => 'diffie-hellman-group1-sha1',
    'client_to_server' => array(
    'crypt'			=> 'aes256-cbc,aes192-cbc,aes128-cbc,aes128-ctr,aes192-ctr,aes256-ctr',
    'comp'			 => 'none'),
    'server_to_client' => array(
    'crypt'			=> 'aes256-cbc,aes192-cbc,aes128-cbc,aes128-ctr,aes192-ctr,aes256-ctr',
    'comp'			 => 'none'));

    $connection = ssh2_connect($hostname, 22, $methods);
*/

    $connection = ssh2_connect($hostname);
    if($connection === false ) {
        cacti_log( "can't open SSH session to ".$hostname."error: ".$connection, false, 'CISCOTOOLS');
        return false;
    }

    if( !ssh2_auth_password($connection, $username, $password) ) {
         cacti_log( "can't login to host ".$hostname." via SSH session, log: ".$username, false, 'CISCOTOOLS');
        return false;
   }

    return $connection;
}

function close_ssh($connection) {
    ssh2_disconnect ($connection);
}

function ssh_read_stream($connection, $cmd) {
    $execSSH = ssh2_exec($connection, $cmd);
    stream_set_blocking($execSSH, true);
    $stream_out = ssh2_fetch_stream($execSSH, SSH2_STREAM_STDIO);
    $output = stream_get_contents($execSSH);
    if($output) {
        return $output;
    }
    else {
        ciscotools_log("cmdSSH - Error - No output");
        return false;
    }
}

/* OLD FUNCTION
function ssh_read_stream( $connection, $cmd ) {
    $stream = ssh2_shell($connection);
    fwrite( $stream, $cmd);
    sleep(1);
    $stream = ssh2_exec($connection, $cmd);
    stream_set_blocking( $stream, true );
*/

?>