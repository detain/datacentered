<?php

use Workerman\Worker;

/**
 * boardctl_task — spawns a detached runner for a queued /opt/boardctl.sh job.
 *
 * Receives the full queue_log row from boardctl_queue_timer in
 * Applications/Chat/Events.php. Rather than running boardctl_run_job() inline
 * (which blocked a port-2208 TaskWorker for the entire, up-to-6hr SSH run and
 * left 2208 bound across a stop/restart), it launches scripts/boardctl_runner.php
 * in a new session via `setsid ... &` and returns immediately. The detached job
 * survives a datacentered restart and never keeps the task port bound.
 *
 * The runner owns the per-asset GlobalData CAS lock for the job's lifetime and
 * writes a pidfile so boardctl_startup_reap() can distinguish a still-running job
 * from a dead one.
 *
 * @param array $args queue_log row (history_id, history_type, history_owner, ...)
 * @return string JSON: {ok, spawned, history_id} (consumed by boardctl_queue_timer)
 */
function boardctl_task($args)
{
    $historyId = isset($args['history_id']) ? intval($args['history_id']) : 0;
    if ($historyId <= 0) {
        Worker::safeEcho("boardctl_task: missing history_id\n");
        return json_encode(['ok' => false, 'error' => 'missing history_id']);
    }
    $ownerId = isset($args['history_owner']) ? intval($args['history_owner']) : 0;

    // Derive the per-asset lock var exactly as boardctl_queue_timer does so the
    // detached runner releases the same GlobalData CAS lock the timer acquired.
    $historyType = (string)($args['history_type'] ?? '');
    $parts = explode(':', $historyType, 2);
    $assetId = isset($parts[1]) ? intval($parts[1]) : intval($historyType);
    $lockVar = 'boardctl_asset_'.$assetId;

    $runner = __DIR__.'/../scripts/boardctl_runner.php';
    if (!is_file($runner)) {
        Worker::safeEcho("boardctl_task: runner not found at {$runner}\n");
        return json_encode(['ok' => false, 'error' => 'runner missing', 'history_id' => $historyId]);
    }

    $logDir = '/home/my/logs/boardctl';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir.'/'.$historyId.'.log';

    // Spawn the runner fully detached:
    //  - setsid => new session, so a datacentered stop/restart (SIGHUP/SIGTERM to
    //    the process group) does not kill the in-flight job.
    //  - The `for fd ... exec $n>&-` loop closes every inherited file descriptor
    //    >= 3 BEFORE exec'ing php. This is essential: PHP's listen sockets are not
    //    O_CLOEXEC, so without this the runner would inherit the TaskWorker's
    //    port-2208 socket and keep the port bound for the life of the job --
    //    exactly the bug we are fixing. stdio is redirected to the log / /dev/null.
    //  - trailing `&` backgrounds it so exec() returns immediately.
    $inner = 'for fd in /proc/self/fd/*; do n=${fd##*/}; [ "$n" -ge 3 ] && eval "exec $n>&-" 2>/dev/null; done; '
        .'exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($runner)
        .' --history-id='.$historyId
        .' --owner='.$ownerId
        .' --lock='.escapeshellarg($lockVar);
    $cmd = 'setsid bash -c '.escapeshellarg($inner)
        .' >> '.escapeshellarg($logFile).' 2>&1 < /dev/null &';
    $out = [];
    $rc = 0;
    @exec($cmd, $out, $rc);
    if ($rc !== 0) {
        Worker::safeEcho("boardctl_task: failed to spawn runner (rc={$rc}) for history_id={$historyId}\n");
        return json_encode(['ok' => false, 'error' => 'spawn failed', 'history_id' => $historyId]);
    }
    Worker::safeEcho("boardctl_task: spawned detached runner for history_id={$historyId} asset={$assetId}\n");
    return json_encode(['ok' => true, 'spawned' => true, 'history_id' => $historyId]);
}
