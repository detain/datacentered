<?php

use MyAdmin\App;
use Workerman\Worker;

function processing_queue_task($args)
{
    $success = false;
    $historyId = $args['history_id'] ?? null;
    $historyOwner = $args['history_owner'] ?? null;
    $invoiceId = $args['history_type'] ?? null;
    $accountLid = null;
    $errorMessage = null;
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
        App::session()->account_id = $historyOwner;
        App::accounts()->data = App::accounts()->read($historyOwner);
        $accountLid = App::accounts()->data['account_lid'] ?? null;
        function_requirements('process_payment');
        $success = (bool) process_payment($invoiceId);
    } catch (\Throwable $e) {
        $success = false;
        $errorMessage = $e->getMessage();
        error_log('processing_queue_task Got Exception '.$e->getCode().': '.$e->getMessage());
        Worker::safeEcho('processing_queue_task Got Exception '.$e->getCode().': '.$e->getMessage()."\n");
    } finally {
        App::session()->account_id = 160307;
        App::accounts()->data = [];
    }
    if (!$success && function_exists('chatNotify')) {
        try {
            $customerLabel = $historyOwner !== null
                ? '['.($accountLid !== null && $accountLid !== '' ? $accountLid : 'customer').' ('.$historyOwner.')](https://my.interserver.net/admin/edit_customer?customer='.$historyOwner.')'
                : 'unknown customer';
            $msg = '⚠️ FAILED activation/payment for '.$customerLabel
                .' — invoice '.($invoiceId ?? 'unknown')
                .', queue history_id '.($historyId ?? 'unknown');
            if ($errorMessage !== null) {
                $msg .= ' — exception: '.$errorMessage;
            }
            chatNotify($msg);
        } catch (\Throwable $e) {
            Worker::safeEcho('processing_queue_task chatNotify failed: '.$e->getMessage()."\n");
        }
    }
    return $success;
}
