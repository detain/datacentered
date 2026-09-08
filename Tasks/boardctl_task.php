<?php

use Workerman\Worker;

/**
 * boardctl_task — spawns a detached runner for a queued /opt/boardctl.sh job.
 *
 * Receives the full queue_log row from boardctl_queue_timer in
 * Applications/Chat/Events.php. Rather than running boardctl_run_job() inline
 * (which blocked a port-2208 TaskWorker for the entire, up-to-6hr SSH run and
 * left 2208 bound across a stop/restart), it launches scripts/boardctl_runner.php
 * with `setsid bash -c '<fd cleanup>; exec ...' &`: sh forks the job and exits at
 * once, so proc_close() returns immediately and neither the TaskWorker process nor
 * the dispatchTask connection is held for the run. setsid gives the job its own
 * session (immune to stop/restart signals) and the fd loop drops the inherited,
 * non-CLOEXEC listen sockets. The detached job survives a datacentered restart and
 * never keeps the task port bound.
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

    // stdio for the detached child. proc_open maps fds 0/1/2 here, so the fd
    // cleanup loop in the command below only has to deal with descriptors >= 3.
    $descriptorspec = [
        0 => ['file', '/dev/null', 'r'],  // stdin from /dev/null
        1 => ['file', $logFile, 'a'],     // stdout append to log
        2 => ['file', $logFile, 'a'],     // stderr append to log
    ];

    // Spawn fully detached (restores pre-ae6688e mechanics; see 3738ad9):
    //  - trailing '&' => sh forks the job and exits at once, so proc_close()
    //    returns IMMEDIATELY (without it, sh exec-chains into setsid and the
    //    waitable child IS the 6h job: the TaskWorker process and the
    //    dispatchTask connection are held for the whole run -> serialized runs
    //    and pool starvation).
    //  - setsid => new session/process group, immune to stop/restart signals.
    //  - the `for fd in /proc/self/fd/*` loop closes every inherited descriptor
    //    >= 3 BEFORE exec'ing php. PHP listen sockets are NOT O_CLOEXEC, so
    //    without this the detached runner keeps 2208 (and every other listen
    //    fd) bound for the life of the job -> stop/restart cannot rebind.
    //    stdio 0/1/2 are already redirected by proc_open's descriptor spec.
    //  - the lock ownership token travels ONLY via BOARDCTL_LOCK_TOKEN env
    //    (never argv — visible in ps); env passes through sh -> bash -> exec.
    //
    // Each arg is individually escaped to avoid shell injection. --lock is legacy
    // rollout compat: an old runner derives the key from it when --asset is
    // missing; the current runner uses --asset plus the BOARDCTL_LOCK_TOKEN env.
    $inner = 'for fd in /proc/self/fd/*; do n=${fd##*/}; [ "$n" -ge 3 ] && eval "exec $n>&-" 2>/dev/null; done; '
        .'exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($runner)
        .' --history-id='.$historyId
        .' --owner='.$ownerId
        .' --lock='.escapeshellarg($lockVar)
        .' --asset='.escapeshellarg((string)$assetId);
    $cmd = 'setsid bash -c '.escapeshellarg($inner).' &';

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
    if ($proc === false) {
        Worker::safeEcho("boardctl_task: failed to spawn runner for history_id={$historyId}\n");
        return json_encode(['ok' => false, 'error' => 'spawn failed', 'history_id' => $historyId]);
    }
    // The trailing '&' means the waitable child is the short-lived sh, not the
    // runner, so $rc only reflects sh's exit (~always 0) and cannot report a
    // runner start failure — same as the pre-regression behaviour. A runner that
    // never came up surfaces downstream via a missing pidfile/heartbeat (and the
    // boardctl_startup_reap path), not here. proc_close() is still called to reap
    // sh and avoid a zombie.
    $rc = proc_close($proc);
    Worker::safeEcho("boardctl_task: spawned detached runner for history_id={$historyId} asset={$assetId}\n");
    return json_encode(['ok' => true, 'spawned' => true, 'history_id' => $historyId]);
}
