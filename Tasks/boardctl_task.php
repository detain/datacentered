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
 * The runner owns the per-asset SharedState (Redis) lock for the job's lifetime,
 * released by the ownership token handed to it here, and writes a pidfile so
 * boardctl_startup_reap() can distinguish a still-running job from a dead one.
 *
 * @param array $args queue_log row (history_id, history_type, history_owner, ...)
 *                    plus lock_token: the raw SharedState::lock() ownership token
 *                    for 'boardctl_asset_<id>' set by boardctl_queue_timer.
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

    // Derive the per-asset lock name exactly as boardctl_queue_timer does so the
    // detached runner releases the same SharedState (Redis) lock the timer holds.
    $historyType = (string)($args['history_type'] ?? '');
    $parts = explode(':', $historyType, 2);
    $assetId = isset($parts[1]) ? intval($parts[1]) : intval($historyType);
    $lockVar = 'boardctl_asset_'.$assetId;
    // Raw ownership token from the producer (Events::boardctl_queue_timer via
    // SharedState::lock). Empty until the producer is wired -> the runner falls
    // back to a force-release, matching the pre-token blind set=0 behaviour.
    $lockToken = (string)($args['lock_token'] ?? '');

    $runner = __DIR__.'/../scripts/boardctl_runner.php';
    if (!is_file($runner)) {
        Worker::safeEcho("boardctl_task: runner not found at {$runner}\n");
        return json_encode(['ok' => false, 'error' => 'runner missing', 'history_id' => $historyId]);
    }

    $logDir = '/home/my/logs/boardctl';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0o775, true);
    }
    $logFile = $logDir.'/'.$historyId.'.log';

    // Spawn the runner fully detached using proc_open:
    //  - setsid => new session, so a datacentered stop/restart (SIGHUP/SIGTERM to
    //    the process group) does not kill the in-flight job.
    //  - File descriptors 0/1/2 are explicitly redirected; the child should
    //    close any inherited descriptors >= 3 before exec (PHP listen sockets
    //    are not O_CLOEXEC, so without cleanup the runner would inherit the
    //    TaskWorker's port-2208 socket).
    //  - proc_close() returns immediately while the detached child continues.
    $descriptorspec = [
        0 => ['file', '/dev/null', 'r'],  // stdin from /dev/null
        1 => ['file', $logFile, 'a'],     // stdout append to log
        2 => ['file', $logFile, 'a'],     // stderr append to log
    ];

    // Build command as a single string for setsid; each arg is individually
    // escaped to avoid shell injection while keeping the command readable.
    $cmd = 'setsid '
        . PHP_BINARY . ' ' . escapeshellarg($runner)
        . ' --history-id=' . $historyId
        . ' --owner=' . $ownerId
        // Legacy compat during rollout: --lock is still carried for an old runner;
        // the new runner prefers --asset/--token via SharedState and uses --lock
        // only as a key-derivation fallback when --asset is missing or <= 0.
        . ' --lock=' . escapeshellarg($lockVar)
        . ' --asset=' . escapeshellarg((string)$assetId);

    /*
     * REVIEW-FIX: the lock ownership token used to be passed as --token=<token>
     * on the command line, where it is world-readable via /proc/<pid>/cmdline and
     * `ps auxww` for the entire life of a job that can run SIX HOURS. Any local
     * account could read a live token and release another asset's in-flight lock,
     * which is exactly the duplicate-concurrent-job the token exists to prevent.
     * (The pre-token code passed only --lock=<name>, a non-secret, so this was a
     * regression introduced with the handoff.)
     *
     * The environment is not world-readable (/proc/<pid>/environ is 0400, owner
     * only), so hand it over that way instead.
     */
    $childEnv = $_ENV ?: [];
    foreach (['PATH', 'HOME', 'USER', 'LANG', 'TMPDIR'] as $passthru) {
        if (!isset($childEnv[$passthru]) && ($fromServer = getenv($passthru)) !== false) {
            $childEnv[$passthru] = $fromServer;
        }
    }
    $childEnv['BOARDCTL_LOCK_TOKEN'] = $lockToken;

    $proc = proc_open($cmd, $descriptorspec, $pipes, null, $childEnv);
    if ($proc === false || $proc === 0) {
        Worker::safeEcho("boardctl_task: failed to spawn runner for history_id={$historyId}\n");
        return json_encode(['ok' => false, 'error' => 'spawn failed', 'history_id' => $historyId]);
    }
    $rc = proc_close($proc);
    Worker::safeEcho("boardctl_task: spawned detached runner for history_id={$historyId} asset={$assetId}\n");
    return json_encode(['ok' => true, 'spawned' => true, 'history_id' => $historyId]);
}
