<?php

use MyAdmin\App;
use Workerman\Worker;

function processing_queue_task($args)
{
    $return = false;
    try {
        require_once '/home/my/include/functions.inc.php';
        // Container is initialized once per worker process when functions.inc.php
        // first loads (in start_task.php's onWorkerStart). It binds the same
        // service instances $tf holds, so per-task re-init would just rebuild
        // a DI container around identical references.
        App::db()->haltOnError = 'report';
        $GLOBALS['default_dbh']->haltOnError = 'report';
        $GLOBALS['helpdesk_dbh']->haltOnError = 'report';
        $GLOBALS['pdns_dbh']->haltOnError = 'report';
        App::session()->sessionid = 'datacentered';
        App::session()->account_id = $args['history_owner'];
        App::accounts()->data = App::accounts()->read($args['history_owner']);
        $db = clone App::db();
        $db->query("update queue_log set history_new_value='processing' where history_id='{$args['history_id']}'", __LINE__, __FILE__);
        function_requirements('process_payment');
        $return = process_payment($args['history_type']);
        $db->query("update queue_log set history_new_value='completed' where history_id='{$args['history_id']}'", __LINE__, __FILE__);
        App::session()->account_id = 160307;
        App::accounts()->data = [];
    } catch (\Exception $e) {
        error_log("processing_queue_task Got Exception ".$e->getCode().': '.$e->getMessage());
        Worker::safeEcho("processing_queue_task Got Exception ".$e->getCode().': '.$e->getMessage()."\n");
    }
    return $return;
}
