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
 * Lock ownership: boardctl_queue_timer acquires the per-asset Redis lock via
 * SharedState::lock('boardctl_asset_<id>') and forwards the raw ownership token to
 * this runner (--token) alongside the asset id (--asset). The runner releases that
 * lock by token when the job finishes, so a wrong/stale token frees nothing and a
 * slow holder can never delete a lock a re-acquirer took after expiry. A legacy
 * invocation with no token force-releases, matching the old blind set=0. If the
 * runner dies without releasing, the producer's lock TTL (22200s) lets the
 * timer's stale-lock reap free it later.
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

$opts = getopt('', ['history-id:', 'owner:', 'lock:', 'asset:', 'token:']);
$historyId = isset($opts['history-id']) ? intval($opts['history-id']) : 0;
$ownerId   = isset($opts['owner']) ? intval($opts['owner']) : 0;
$lockVar   = isset($opts['lock']) ? (string)$opts['lock'] : '';
$assetId   = isset($opts['asset']) ? intval($opts['asset']) : 0;
/*
 * REVIEW-FIX: prefer the token from the environment. It used to arrive as
 * --token= on the command line, readable by any local account via
 * /proc/<pid>/cmdline for the whole (up to 6 hour) life of the job;
 * /proc/<pid>/environ is owner-only. --token is still accepted so an in-flight
 * job spawned by the previous code still releases its lock correctly.
 */
$lockToken = (string) (getenv('BOARDCTL_LOCK_TOKEN') ?: '');
if ($lockToken === '' && isset($opts['token'])) {
    $lockToken = (string)$opts['token'];
}
// Do not leave it readable to anything this process later spawns.
putenv('BOARDCTL_LOCK_TOKEN');
unset($_ENV['BOARDCTL_LOCK_TOKEN'], $_SERVER['BOARDCTL_LOCK_TOKEN']);
if ($historyId <= 0) {
    fwrite(STDERR, "boardctl_runner: missing/invalid --history-id\n");
    exit(2);
}

require_once '/home/my/include/functions.inc.php';

// SharedState (phpredis facade) replaces the old hand-rolled GlobalData wire
// protocol used to free the per-asset lock. functions.inc.php already bootstraps
// $GLOBALS['redis'] and the USE_REDIS/REDIS_HOST/REDIS_PORT constants via
// config.settings.php; re-include the settings only if a constant is still
// undefined so SharedState's lazy-connect fallback can resolve a host when the
// shared handle is absent. SharedState REUSES $GLOBALS['redis'] and never
// replaces it.
if (!defined('USE_REDIS') || !defined('REDIS_HOST') || !defined('REDIS_PORT')) {
    $redisSettings = '/home/my/include/config/config.settings.php';
    if (is_readable($redisSettings)) {
        require_once $redisSettings;
    }
}
require_once __DIR__.'/../Applications/Chat/SharedState.php';

$logDir = '/home/my/logs/boardctl';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0o775, true);
}
$pidFile = $logDir.'/'.$historyId.'.pid';
@file_put_contents($pidFile, (string)getmypid());

// Resolve the SharedState lock name (the producer's bare name, before the
// dc:lock: prefix it applies). Prefer the explicit --asset; fall back to the
// legacy --lock var, which already carries the 'boardctl_asset_' prefix, so an
// old invocation still frees the right key.
$lockName = '';
if ($assetId > 0) {
    $lockName = 'boardctl_asset_'.$assetId;
} elseif ($lockVar !== '') {
    $lockName = $lockVar;
}
/*
 * SharedState::unlock() treats a null token as an unconditional delete and a
 * string token as owner-checked.
 *
 * REVIEW-FIX: this used to map an EMPTY token to null, putting an unconditional
 * DEL on the NORMAL completion path — the one thing the project rule says never
 * to do. The failure it enables is concrete: if runner A's own 22200s lock
 * lapses, the timer legitimately re-acquires and spawns runner B; when A then
 * finishes it blind-DELs B's live lock and a third job can start on the same
 * asset — exactly the duplicate-concurrent-job the token was introduced to
 * prevent. Tasks/boardctl_task.php already defaults the token to '', so any
 * dispatcher that omitted it got the blind delete.
 *
 * A missing token now means "release nothing" and say so: the producer's TTL is
 * the correct fallback, and it is bounded. Only a genuine legacy invocation
 * (--lock without --asset, i.e. a pre-token runner still in flight) keeps the
 * force-release, because there is no token to check and its lock would otherwise
 * be held for six hours.
 */
$legacyInvocation = $assetId <= 0 && $lockVar !== '';
$unlockToken = $lockToken !== '' ? $lockToken : null;
if ($unlockToken === null && !$legacyInvocation) {
    fwrite(STDERR, "boardctl_runner: no ownership token supplied; the asset lock will be left to its TTL rather than force-deleted\n");
    $lockName = '';
}

/**
 * Is the process-wide $GLOBALS['redis'] handle present and answering PING? A
 * 6h+ idle run can leave the object alive while the server has already closed
 * its socket, in which case any command against it (including SharedState's
 * release) would fail.
 *
 * @return bool
 */
function boardctl_runner_redis_alive()
{
    $redis = $GLOBALS['redis'] ?? null;
    if (!$redis instanceof \Redis) {
        return false;
    }
    try {
        return (bool) $redis->ping();
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Open a throwaway phpredis connection to the configured host, used ONLY to free
 * the lock when the shared global is no longer usable. Deliberately a local
 * handle: $GLOBALS['redis'] is never reassigned and functions.inc.php is not
 * modified.
 *
 * @return \Redis|null null when Redis is unconfigured or unreachable.
 */
function boardctl_runner_fresh_redis()
{
    if (
        !defined('USE_REDIS') || USE_REDIS !== true
        || !defined('REDIS_HOST') || !defined('REDIS_PORT')
        || !class_exists('\Redis')
    ) {
        return null;
    }
    try {
        $redis = new \Redis();
        if (!$redis->connect(REDIS_HOST, REDIS_PORT, 2)) {
            return null;
        }
        return $redis;
    } catch (\Throwable $e) {
        return null;
    }
}

$releaseLock = function () use ($lockName, $unlockToken) {
    if ($lockName === '') {
        return;
    }
    // Primary path: the shared connection answered PING, so release through
    // SharedState (it REUSES $GLOBALS['redis']; token-checked when owned, a
    // force-delete for a legacy no-token invocation). SharedState wraps every
    // command, so a socket dying in the window between the probe and the
    // release does NOT throw any more — it returns false AND reports
    // transportFailed(). "false + healthy transport" is a genuine not-released
    // (absent or stolen lock) and we are done; "false + dead transport" is the
    // same class of failure the old throw signalled, so fall through to the
    // fresh-connection path below instead of abandoning the release.
    if (boardctl_runner_redis_alive()) {
        try {
            $released = SharedState::unlock($lockName, $unlockToken);
            if ($released || !SharedState::transportFailed()) {
                echo 'boardctl_runner: lock '.$lockName.' '.($released ? 'released' : 'not released (absent or held elsewhere)')."\n";
                return;
            }
            fwrite(STDERR, 'boardctl_runner: shared lock release hit a dead transport, retrying on a fresh connection'."\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, 'boardctl_runner: shared lock release failed: '.$e->getMessage().', retrying on a fresh connection'."\n");
        }
    }
    // Degraded path: the global object is absent, its socket died across the
    // long run, or it died mid-release on the primary path. SharedState::client()
    // would hand that dead handle straight back (it only defers to an injected
    // client when the global is absent), so the release runs on a fresh
    // connection opened for this call alone. The token-checked Lua mirrors
    // SharedState::unlock() exactly; the shared global stays untouched.
    // Non-fatal: the producer's lock TTL reaps it otherwise.
    $redis = boardctl_runner_fresh_redis();
    if ($redis === null) {
        fwrite(STDERR, "boardctl_runner: lock release skipped, redis unreachable ({$lockName} freed by TTL/stale-lock reap)\n");
        return;
    }
    try {
        $key = SharedState::PREFIX_LOCK.$lockName;
        if ($unlockToken === null) {
            $released = (bool) $redis->del($key);
        } else {
            $script = "if redis.call('GET',KEYS[1])==ARGV[1] then return redis.call('DEL',KEYS[1]) end return 0";
            $released = (bool) $redis->eval($script, [$key, $unlockToken], 1);
        }
        echo 'boardctl_runner: lock '.$lockName.' '.($released ? 'released' : 'not released (absent or held elsewhere)')." [fresh connection]\n";
    } catch (\Throwable $e) {
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
