<?php

use Workerman\Worker;

function vps_queue_task($args)
{
    require_once '/home/my/include/functions.inc.php';
    /**
    * @var \GlobalData\Client
    */
    global $global;
    $db = $GLOBALS['tf']->db;
    $db->query("select * from vps_masters left join vps_master_details using (vps_id) where vps_id=".$args['id']);
    $rows = [];
    $sids = [];
    $output = '';
    while ($db->next_record(MYSQL_ASSOC)) {
        $db->Record['newvps'] = [];
        $db->Record['queue'] = [];
        $rows[$db->Record['vps_id']] = $db->Record;
        $sids[] = $db->Record['vps_id'];
    }
    $db->query("select * from vps where vps_status='pending-setup' and vps_server in (".implode(',', $sids).")", __LINE__, __FILE__);
    if ($db->num_rows() > 0) {
        while ($db->next_record(MYSQL_ASSOC)) {
            $rows[$db->Record['vps_server']]['newvps'][] = $db->Record;
        }
    }
    $db->query("select vps.*, hl1.* from vps, queue_log as hl1 left join queue_log as hl2 on hl2.history_type=hl1.history_id and hl2.history_section='vpsqueuedone' where hl1.history_section='vpsqueue' and hl1.history_type=vps_id and hl2.history_id is null and vps_server in (".implode(',', $sids).")", __LINE__, __FILE__);
    if ($db->num_rows() > 0) {
        while ($db->next_record(MYSQL_ASSOC)) {
            $rows[$db->Record['vps_server']]['queue'][] = $db->Record;
        }
    }
    foreach ($rows as $service_id => $service_master) {
        $var = 'vps_host_'.$service_id;
        if (!isset($global->$var)) {
            $global->$var = 0;
        }
        if (sizeof($service_master['newvps']) > 0 || sizeof($service_master['queue']) > 0) {
            if ($global->cas($var, 0, 1)) {
                function_requirements('vps_queue_handler');
                if (sizeof($service_master['newvps']) > 0) {
                    myadmin_log('myadmin', 'info', 'Processing New VPS for '.$service_master['vps_name'], __LINE__, __FILE__, 'vps');
                    $output .= vps_queue_handler($service_master, 'get_new_vps', $service_master['newvps']);
                }
                if (sizeof($service_master['queue']) > 0) {
                    myadmin_log('myadmin', 'info', 'Processing VPS Queue for '.$service_master['vps_name'], __LINE__, __FILE__, 'vps');
                    $output .= vps_queue_handler($service_master, 'get_queue', $service_master['queue']);
                }
                $output .= vps_queue_handler($service_master, 'server_list');
                if (trim($output) != '') {
                    //echo "Got Output To Send: $output\n";
                }
                $global->$var = 0;
            }
        }
    }
    return $output;
}
