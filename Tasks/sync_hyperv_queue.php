<?php

use MyAdmin\App;
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

function sync_hyperv_queue($args)
{
    require_once '/home/my/include/functions.inc.php';
    // `select *` pulls the binary(16) uuid columns in raw. those bytes arent valid
    // utf8, so json_encode() returns false and send(false) makes Workerman
    // stopAll() the whole TaskWorker process. unpack them the same way
    // BIN_TO_UUID($col, 1) would, and hex anything else that isnt text.
    $utf8Safe = function (array $row) {
        foreach (['vps_uuid', 'qs_uuid'] as $uuidField) {
            if (isset($row[$uuidField]) && strlen($row[$uuidField]) == 16) {
                $row[$uuidField] = bin_to_uuid($row[$uuidField], true);
            }
        }
        foreach ($row as $key => $value) {
            if (is_string($value) && $value !== '' && !valid_utf8($value)) {
                $row[$key] = bin2hex($value);
            }
        }
        return $row;
    };
    $db = App::db();
    $db->query("select * from vps_masters left join vps_master_details using (vps_id) where vps_type=".get_service_define('HYPERV'));
    $rows = [];
    $sids = [];
    while ($db->next_record(MYSQL_ASSOC)) {
        $record = $utf8Safe($db->Record);
        $record['newvps'] = [];
        $record['queue'] = [];
        $rows[$record['vps_id']] = $record;
        $sids[] = $record['vps_id'];
    }
    if (empty($sids)) {
        // No HyperV masters found — nothing to sync
        return;
    }
    $db->query("select * from vps, accounts where vps_status='pending-setup' and vps_custid=account_id and account_status != 'locked' and vps_server in (".implode(',', $sids).")", __LINE__, __FILE__);
    if ($db->num_rows() > 0) {
        while ($db->next_record(MYSQL_ASSOC)) {
            $rows[$db->Record['vps_server']]['newvps'][] = $utf8Safe($db->Record);
        }
    }
    $db->query("select vps.*, hl1.* from vps, queue_log as hl1 left join queue_log as hl2 on hl2.history_type=hl1.history_id and hl2.history_section='vpsqueuedone' where hl1.history_section='vpsqueue' and hl1.history_type=vps_id and hl2.history_id is null and vps_server in (".implode(',', $sids).")", __LINE__, __FILE__);
    if ($db->num_rows() > 0) {
        while ($db->next_record(MYSQL_ASSOC)) {
            $rows[$db->Record['vps_server']]['queue'][] = $utf8Safe($db->Record);
        }
    }
    foreach ($rows as $service_id => $service_master) {
        // Seeding the old GlobalData lock var to 0 was required for cas(0, ...) to
        // have something to compare against. Redis SET NX needs no seed: the absence
        // of the key IS "unlocked". async_hyperv_queue_runner acquires the real
        // lock('vps_host_<id>', 900) downstream, so nothing to initialise here.
        if (sizeof($service_master['newvps']) > 0 || sizeof($service_master['queue']) > 0) {
            $payload = json_encode(['type' => 'async_hyperv_queue_runner', 'args' => ['id' => $service_id, 'data' => $service_master]]);
            if ($payload === false) {
                Worker::safeEcho("sync_hyperv_queue: skipping server {$service_id}, payload encode failed: ".json_last_error_msg()."\n");
                continue;
            }
            $task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
            $task_connection->send($payload);
            $task_connection->onMessage = function ($connection, $task_result) use ($task_connection) {
                //var_dump($task_result);
                $task_connection->close();
            };
            $task_connection->connect();
        }
    }
}
