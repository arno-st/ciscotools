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

/** ====================================================
 * SQL Queries on the table plugin_ciscotools_upgrade
 * 
 * @param   integer $deviceID:  the ID of the device (host.id and plugin_ciscotools_upgrade.host_id)
 * @param   string  $action:    the type of action ('add', 'update' and 'delete' only)
 * @param   integer $status:    the status code - View the meaning on the file display_upgrade.php
 * @return  boolean $flag:      true if successful, false otherwise
 */
function ciscotools_upgrade_table($deviceID, $action, $status=UPGRADE_STATUS_PENDING, $image='')
{
    $sqlFind = "SELECT plugin_ciscotools_upgrade.id, plugin_ciscotools_upgrade.host_id, 
                plugin_ciscotools_upgrade.status
                FROM plugin_ciscotools_upgrade
                WHERE plugin_ciscotools_upgrade.host_id=$deviceID";
    $result = db_fetch_assoc($sqlFind);
    $sqlWhere = "WHERE plugin_ciscotools_upgrade.host_id=$deviceID";
    $flag = false;
    $sqlExec = false;

    switch($action)
    {
        case 'add':
                if(!$result)
                {
                    $sqlQuery = "INSERT INTO plugin_ciscotools_upgrade (host_id, status, image) VALUES (".$deviceID.", ".$status.", '".$image."')";
                    $sqlExec = db_execute($sqlQuery);
                }
                else if((cacti_sizeof($result) == 1) )
                {
                    $sqlQuery = "UPDATE plugin_ciscotools_upgrade SET status=" . $status . ",image='".$image."' " . $sqlWhere;
                    $sqlExec = db_execute($sqlQuery);
                }
            break;
        
        case 'update': // start upgrade of the device
                if(cacti_sizeof($result) == 1)
                {
                    $sqlQuery = "UPDATE plugin_ciscotools_upgrade SET status=" . $status . " " . $sqlWhere;
                    $sqlExec = db_execute($sqlQuery);
                }
            break;

        case 'delete':
            if(cacti_sizeof($result) == 1)
            {
                $sqlQuery = "DELETE FROM plugin_ciscotools_upgrade WHERE plugin_ciscotools_upgrade.host_id =$deviceID";
                $sqlExec = db_execute($sqlQuery);
            }
            break;
			
        case 'force':
            if(cacti_sizeof($result) == 0)
            {
                $sqlQuery = "INSERT INTO plugin_ciscotools_upgrade (host_id, status) VALUES (".$deviceID.", ".$status.")";
                $sqlExec = db_execute($sqlQuery);
            }
            else if(cacti_sizeof($result) == 1)
            {
                $sqlQuery = "UPDATE plugin_ciscotools_upgrade SET status=" . $status ." " . $sqlWhere;
                $sqlExec = db_execute($sqlQuery);
            }
            break;
    }
    if($sqlExec !== false) $flag = true;

upgrade_log("UPG: ciscotools_upgrade_table: ".$deviceID." action: ".$action." status : ".CISCOTLS_UPG_STATUS[$status]['name'] );

    return $flag;
}

?>