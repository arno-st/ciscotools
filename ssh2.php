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
    $connection = ssh2_connect($hostname, 22);
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

    $stream = ssh2_exec($connection, $cmd, 'ansi');
    stream_set_blocking($stream, true);
    /*
    $stream_out = ssh2_fetch_stream($stream, SSH2_STREAM_STDIO);
    $output = stream_get_contents($stream);
*/
    $output = '';
    while($stream_out = fgets($stream)){
        $output .= $stream_out;
    }
    
    fclose($stream);
    if($output) {
        return $output;
    }
    else {
        ciscotools_log("cmdSSH - Error - No output");
        return false;
    }
}
?>