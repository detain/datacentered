<?php

use MyAdmin\App;
use Workerman\Worker;

/**
 * boardctl_task — runs a queued /opt/boardctl.sh recover-bmc-creds job.
 *
 * Receives {history_id, history_type} (the full queue_log row from the timer in
 * Applications/Chat/Events.php). Loads the boardctl helper from mystage and lets
 * boardctl_run_job() do the SSH + streaming. We just bridge the worker context.
 *
 * @param array $args queue_log row
 * @return string JSON of result (consumed by Events::dispatchTask onResult)
 */
function boardctl_task($args)
{
    $historyId = isset($args['history_id']) ? intval($args['history_id']) : 0;
    if ($historyId <= 0) {
        Worker::safeEcho("boardctl_task: missing history_id\n");
        return json_encode(['ok' => false, 'error' => 'missing history_id']);
    }
    try {
        require_once '/home/my/include/functions.inc.php';
        App::db()->haltOnError = 'report';
        if (isset($GLOBALS['default_dbh'])) {
            $GLOBALS['default_dbh']->haltOnError = 'report';
        }
        App::session()->sessionid = 'datacentered';
        $ownerId = isset($args['history_owner']) ? intval($args['history_owner']) : 0;
        if ($ownerId > 0) {
            App::session()->account_id = $ownerId;
            App::accounts()->data = App::accounts()->read($ownerId);
        }
        function_requirements('boardctl_run_job');
        $ok = boardctl_run_job($historyId);
        // restore session
        App::session()->account_id = 160307;
        App::accounts()->data = [];
        return json_encode(['ok' => (bool)$ok, 'history_id' => $historyId]);
    } catch (\Throwable $e) {
        Worker::safeEcho('boardctl_task exception '.$e->getCode().': '.$e->getMessage()."\n");
        try {
            function_requirements('boardctl_run_job');
            boardctl_append_output($historyId, PHP_EOL.'ERROR: task threw '.$e->getMessage().PHP_EOL);
            boardctl_set_status($historyId, 'failed');
        } catch (\Throwable $inner) {
            // ignore
        }
        return json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}
