<?php

use MyAdmin\App;

function get_map($args)
{
    require_once '/home/my/include/functions.inc.php';
    $db = App::db();
    $db->bindValue('id', (int)$args['id'], 'int');
    $db->query("select * from vps_masters left join vps_master_details using (vps_id) where vps_id=:id", __LINE__, __FILE__);
    $db->next_record(MYSQL_ASSOC);
    function_requirements('vps_queue_handler');
    return vps_queue_handler($db->Record, 'get_map');
}
