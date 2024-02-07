<?php

function vps_get_list($args)
{
    require_once '/home/my/include/functions.inc.php';
    /**
    * @var \GlobalData\Client
    */
    global $global;
    $db = $GLOBALS['tf']->db;
    $db->query("select * from vps_masters left join vps_master_details using (vps_id) where vps_id=".$args['id']);
    $db->next_record(MYSQL_ASSOC);
    function_requirements('vps_queue_handler');
    return vps_queue_handler($db->Record, 'server_list', $args['content']);
}
