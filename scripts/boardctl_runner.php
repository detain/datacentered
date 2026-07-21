#!/usr/bin/env php
<?php

/**
 * boardctl_runner — detached CLI runner for a single boardctl job.
 *
 * Spawned by Tasks/boardctl_task.php via `setsid php ... &` so the (up to 6hr)
 * SSH job runs fully independent of the Workerman TaskWorker. Previously
 * boardctl_run_job() ran synchronously inside a TaskWorker process, which kept
 * one of the port-2208 workers blocked for the life of the SSH run and prevented
 * a clean `php start.php stop|restart` from rebinding 2208 (you had to kill the
 * process, which also killed the job). Running detached fixes both: the worker
 * returns immediately and the job survives a datacentered restart.
 *
 * boardctl_run_job() streams only to the DB (queue_log), so no Workerman/Gateway
 * context is needed here.
 *
 * Lock ownership: boardctl_queue_timer acquires the per-asset GlobalData CAS lock
 * before dispatch; this runner releases it when the job finishes. If the runner
 * dies without releasing, the timer's stale-lock reset frees it later.
 *
 * A pidfile at /home/my/logs/boardctl/<historyId>.pid lets
 * Events::boardctl_startup_reap() tell a still-running detached job from a dead
 * one so it no longer fails jobs that outlived a restart.
 *
 * @author Joe Huss <detain@interserver.net>
 * @package MyAdmin
 * @category Servers
 */

use MyAdmin\App;

$opts = getopt('', ['history-id:', 'owner:', 'lock:']);
$historyId = isset($opts['history-id']) ? intval($opts['history-id']) : 0;
$ownerId   = isset($opts['owner']) ? intval($opts['owner']) : 0;
$lockVar   = isset($opts['lock']) ? (string)$opts['lock'] : '';
if ($historyId <= 0) {
    fwrite(STDERR, "boardctl_runner: missing/invalid --history-id\n");
    exit(2);
}

require_once '/home/my/include/functions.inc.php';

$logDir = '/home/my/logs/boardctl';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$pidFile = $logDir.'/'.$historyId.'.pid';
@file_put_contents($pidFile, (string)getmypid());

$releaseLock = function () use ($lockVar) {
    if ($lockVar === '') {
        return;
    }
    // NB: \GlobalData\Client cannot be used from a plain CLI -- its lazy
    // connection setup registers a Workerman ping Timer, which throws without a
    // running event loop. The GlobalData wire protocol is trivial though, so we
    // speak it directly: one `set <lockVar> = 0` request/response over a socket
    // to GLOBALDATA_IP:2207. Framing is pack('N', 4 + strlen($body)) . $body
    // where $body is serialize(['cmd'=>'set','key'=>...,'value'=>...]).
    try {
        $conn = @stream_socket_client('tcp://'.GLOBALDATA_IP.':2207', $errno, $errstr, 5);
        if (!$conn) {
            throw new \Exception($errstr !== '' ? $errstr : 'connect failed');
        }
        stream_set_timeout($conn, 5);
        $body = serialize(['cmd' => 'set', 'key' => $lockVar, 'value' => 0]);
        $buffer = pack('N', 4 + strlen($body)).$body;
        if (fwrite($conn, $buffer) !== strlen($buffer)) {
            throw new \Exception('write failed');
        }
        // Read and discard the ack so the server commits the set before we close.
        $head = '';
        while (strlen($head) < 4) {
            $chunk = fread($conn, 4 - strlen($head));
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $head .= $chunk;
        }
        if (strlen($head) === 4) {
            $need = unpack('Ntotal_length', $head)['total_length'] - 4;
            $read = 0;
            while ($read < $need) {
                $chunk = fread($conn, $need - $read);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                $read += strlen($chunk);
            }
        }
        fclose($conn);
    } catch (\Throwable $e) {
        // Non-fatal: boardctl_queue_timer's stale-lock reset frees it eventually.
        fwrite(STDERR, 'boardctl_runner: lock release failed: '.$e->getMessage()."\n");
    }
};

// CLI has no execution time limit; keep sockets alive across the long SSH run.
@set_time_limit(0);
@ini_set('default_socket_timeout', '7200');

try {
    App::db()->haltOnError = 'report';
    if (isset($GLOBALS['default_dbh'])) {
        $GLOBALS['default_dbh']->haltOnError = 'report';
    }
    App::session()->sessionid = 'datacentered';
    if ($ownerId > 0) {
        App::session()->account_id = $ownerId;
        App::accounts()->data = App::accounts()->read($ownerId);
    }
    function_requirements('boardctl_run_job');
    $ok = boardctl_run_job($historyId);
    // restore session
    App::session()->account_id = 160307;
    App::accounts()->data = [];
    echo 'boardctl_runner: history_id='.$historyId.' finished ok='.($ok ? '1' : '0')."\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'boardctl_runner: exception '.$e->getCode().': '.$e->getMessage()."\n");
    try {
        function_requirements('boardctl_run_job');
        boardctl_append_output($historyId, PHP_EOL.'ERROR: runner threw '.$e->getMessage().PHP_EOL);
        boardctl_set_status($historyId, 'failed');
    } catch (\Throwable $inner) {
        // ignore
    }
} finally {
    $releaseLock();
    @unlink($pidFile);
}
exit(0);
