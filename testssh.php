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

$stream = open_ssh('se-dch9-60.recolte.lausanne.ch', 's_cactinetworkadm', 'UNAW2m3sFF+9uSzZf' );
//$stream = open_ssh('sre-core.recolte.lausanne.ch', 's_cactinetworkadm', 'UNAW2m3sFF+9uSzZf' );
//$stream = open_ssh('se-se46-8510.recolte.lausanne.ch', 's_cactinetworkadm', 'UNAW2m3sFF+9uSzZf' );
if($stream === false) exit;

	$data = ssh_read_stream($stream );
	if( $data === false ){
		echo 'Errreur just read'.PHP_EOL;
		return;
	}

	if(ssh_write_stream($stream, 'term length 0' ) === false) return;
	$data = ssh_read_stream($stream);
	if( $data === false ){
		echo 'Erreur term length 0'.PHP_EOL;
		return;
	}
echo '2nd: '.$data.PHP_EOL;

	if ( ssh_write_stream($stream, 'sh run') === false ) return;
	$data = ssh_read_stream($stream);
	if( $data === false ){
		echo 'Erreur sh run'.PHP_EOL;
		return;
	}
echo 'config:'.$data.PHP_EOL; // remove 38 from the start, +37 for size
   // remove all before version
    $data = substr($data, strpos($data,'version')); // remove the banner and version from config
	$data = substr($data, strpos($data,"\n")+1); // remove the line after config until the first 0d0a
	$data = addslashes(substr($data, 0, strrpos($data, "\n",0)-2 )); // remove the end of the config
	
echo 'data:'.$data.'('.bin2hex($data).PHP_EOL;

	if(ssh_write_stream($stream, 'sh run | inc change|!Time' ) === false) return;
	$data = ssh_read_stream($stream);
	if( $data === false ){
		echo 'Erreur can\'t read version';
		return false;
	}
	if($data !== false ) {
		$data = substr($data, strpos($data, "\n")+1); // clean up start of the string +1 for 0A
		$data = substr($data, 0, strpos($data, "\n")-1); // clean up end of the string, -2 for 0D0A
		echo 'data: '.$data.PHP_EOL;
		$date = format_date($data); // Apr272020
		echo 'date: '.$date.PHP_EOL;
	} else {
		$date = $data;
	}
echo $date.PHP_EOL;;

function open_ssh( $hostname, $username, $password ) {
    $connection = ssh2_connect($hostname, 22);
    if($connection === false ) {
        echo( "can't open SSH session to ".$hostname."error: ".$connection.PHP_EOL);
        return false;
    }

    if( !ssh2_auth_password($connection, $username, $password) ) {
        echo ( "can't login to host ".$hostname." via SSH session, log: ".$username.PHP_EOL);
        return false;
   }

    $stream = ssh2_shell($connection, 'vt100', null, 80, 24, SSH2_TERM_UNIT_CHARS );
	stream_set_timeout($stream, 10);
	stream_set_blocking($stream, true);

    return $stream;
}

function close_ssh($connection) {
    ssh2_disconnect ($connection);
}

function ssh_read_stream($stream) {
	$output = '';
	
    do {
		$stream_out = fread ($stream, 1);
        echo('stream read: >'.$stream_out.'<('.strlen($stream_out).')'.' hex:'.bin2hex($stream_out).PHP_EOL);
		$output .= $stream_out;
     } while ( !feof($stream) && $stream_out !== false && $stream_out != '#');
   
    if(strlen($output)!=0) {
        return $output;
    }
    else {
        echo("cmdSSH - Error - No output".PHP_EOL);
        return false;
    }
}

function ssh_write_stream( $stream, $cmd){
    do {
	$write = fwrite( $stream, $cmd.PHP_EOL );
    echo 'ecrit:'.$write.PHP_EOL;
	} while( $write < strlen($cmd) );

}

function format_date($string)
{
    $regex = "/(Mon|Tue|Wed|Thu|Fri|Sat|Sun) (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) ([0-9]{2})( [0-9]{2}:[0-9]{2}:[0-9]{2} | )([0-9]{4})/";
    preg_match($regex, $string, $result);

    $regexHour = "/[0-9]{2}:[0-9]{2}:[0-9]{2} /";
    $date = preg_replace($regexHour, "", $result[0]);

    return date("Ymd", strtotime($date));
}

?>