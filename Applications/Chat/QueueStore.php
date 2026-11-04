<?php

require_once __DIR__.'/SharedState.php';

/**
 * QueueStore — the provisioning-host queue DATA, Redis-first with a local
 * on-disk spill so the VPS/QS hosts keep working while Dragonfly/Redis is
 * down and nothing is lost or wedged when it comes back.
 *
 * Owns every raw (non dc:*) key the host queue path shares between
 * Web/queue.php (the hosts' HTTP endpoint), Tasks/map_queue_task.php and
 * Tasks/memcached_queue_task.php (the drain):
 *
 *   queuein:<ip>   inbound telemetry LIST (bandwidth / cpu_usage / server_info
 *                  posts). Redis down => appended to <spill>/queuein/<ip>.jsonl
 *                  (flock'd), keeping the newest SPILL_MAX_ITEMS. The drain
 *                  empties that spill FIRST (it holds the oldest items), then
 *                  Redis, so FIFO order survives an outage.
 *   maps:<ip>      per-host ip/vnc/slice map. Written to Redis AND mirrored to
 *                  <spill>/maps/<ip>.json on every build, so a host still gets
 *                  its map while Redis is down.
 *   queue:<ip>     outbound command LIST. Redis down => nothing handed out this
 *                  poll (an empty body, exactly like an empty queue); the
 *                  commands are still waiting when it comes back.
 *   queue:dedup:*  replay-dedup markers. Redis down => an exclusive-create
 *                  marker file, so replay protection keeps working instead of
 *                  failing open.
 *   drain lock     SharedState lock normally; Redis down => a non-blocking
 *                  flock() on <spill>/locks/<ip>.lock (released by the kernel
 *                  if the holder dies).
 *   <module>_masters:<ip> / _master_details:<id> / _vps:<id> and the
 *                  <module>:host:* telemetry values: plain cache. Redis down =>
 *                  a miss (the drain reads MySQL, the source of truth) and the
 *                  write is skipped.
 *
 * Why disk and not Memcached: the old USE_REDIS=false fallback assumed a
 * localhost memcached, but none runs on the hub hosts (installed-but-stopped
 * on mynew, absent elsewhere), so it would have dropped everything silently.
 * The spill lives on the hub's own disk — the web worker that receives a post
 * and the task worker that drains it run on the same box — and survives a
 * worker restart. With USE_REDIS off the disk store is simply the only store.
 *
 * Every Redis call goes through SharedState::rawCommand(), so this class
 * inherits the facade's whole failure contract instead of re-implementing it:
 * no transport exception ever escapes, a throw marks the transport dead for
 * SharedState::REPROBE_INTERVAL (one probe per window, not one timeout per
 * call), the outage is logged once, and recovery is automatic — the next call
 * after the window re-probes and resumes, or reconnects through the facade's
 * own fresh handle when the shared $redis global never comes back. Nothing
 * here needs a worker restart after an outage.
 */
final class QueueStore
{
    /** Newest items kept per host in the spill (oldest dropped first). */
    public const SPILL_MAX_ITEMS = 300;

    /** Default spill root; override with a QUEUE_SPILL_DIR constant. */
    public const DEFAULT_SPILL_DIR = '/home/my/logs/queue_spill';

    /** @var string|null test seam: spill root override */
    private static $spillDirOverride = null;

    /** @var bool|null test seam: when set, overrides USE_REDIS */
    private static $redisEnabledOverride = null;

    /** @var array<string,resource> lock token => open flock()ed handle */
    private static $fileLocks = [];

    /** @var array<string,int> message key => unix time last logged (rate limit) */
    private static $lastLogged = [];

    // -----------------------------------------------------------------------
    // Inbound telemetry: queuein:<ip>
    // -----------------------------------------------------------------------

    /**
     * Append one telemetry item for a host. Redis first; while Redis is
     * unavailable the item is parked in the disk spill instead of dropped.
     *
     * @param string $ip   reporting host
     * @param array  $item ['get'=>..., 'post'=>..., 'ip'=>...]
     * @return bool false only when BOTH stores refused the item
     */
    public static function pushInbound(string $ip, array $item): bool
    {
        $json = json_encode($item, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            self::log('encode', 'QueueStore: dropping an un-encodable queuein item for '.$ip.' ('.json_last_error_msg().')');

            return false;
        }
        if (self::redisEnabled()) {
            $fail = new \stdClass();
            $pushed = SharedState::rawCommand('queuein.rPush', 'queuein:'.$ip, static function ($client, $key) use ($json) {
                return $client->rPush($key, $json);
            }, $fail);
            if ($pushed !== $fail && $pushed !== false) {
                return true;
            }
            self::log('spilling', 'QueueStore: Redis unavailable — parking queuein items in '.self::spillDir().' (newest '.self::SPILL_MAX_ITEMS.' per host kept) until it recovers');
        }

        return self::spillWrite($ip, [$json], false);
    }

    /**
     * Take up to $max items for a host, oldest first: the disk spill (filled
     * while Redis was down) before the Redis list.
     *
     * @param string $ip
     * @param int    $max
     * @return array<int,array> decoded items
     */
    public static function popInbound(string $ip, int $max): array
    {
        $items = [];
        foreach (self::spillTake($ip, $max) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }
        if (count($items) >= $max || !self::redisEnabled()) {
            return $items;
        }
        $want = $max - count($items);
        $popped = SharedState::rawCommand('queuein.lPop', 'queuein:'.$ip, static function ($client, $key) use ($want) {
            $out = [];
            for ($i = 0; $i < $want; $i++) {
                $raw = $client->lPop($key);
                if ($raw === false || $raw === null) {
                    break;
                }
                $out[] = $raw;
            }

            return $out;
        }, []);
        foreach ($popped as $raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }

        return $items;
    }

    /**
     * Put an un-processed tail back at the HEAD of the host's queue, keeping
     * its order. Redis first; when Redis is unavailable (the usual reason a
     * drain is abandoned mid-batch) the tail goes to the FRONT of the spill.
     *
     * @param string $ip
     * @param array  $tail decoded items, oldest first
     * @return bool false only when both stores refused
     */
    public static function requeueInbound(string $ip, array $tail): bool
    {
        $remaining = [];
        foreach ($tail as $item) {
            $encoded = json_encode($item, JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded !== false) {
                $remaining[] = $encoded;
            }
        }
        if ($remaining === []) {
            return true;
        }
        if (self::redisEnabled()) {
            // lPush onto the head walking the tail backwards leaves $tail[0]
            // at the head, exactly where lPop found it. Track progress by
            // reference so a mid-loop throw spills only what never landed.
            SharedState::rawCommand('queuein.lPush', 'queuein:'.$ip, static function ($client, $key) use (&$remaining) {
                while ($remaining !== []) {
                    if ($client->lPush($key, $remaining[count($remaining) - 1]) === false) {
                        return false;
                    }
                    array_pop($remaining);
                }

                return true;
            }, false);
        }
        if ($remaining === []) {
            return true;
        }

        return self::spillWrite($ip, $remaining, true);
    }

    /**
     * Number of items parked in the disk spill for a host.
     *
     * @param string $ip
     * @return int
     */
    public static function spillCount(string $ip): int
    {
        $path = self::path('queuein', $ip, 'jsonl');
        if ($path === null || !is_file($path)) {
            return 0;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return is_array($lines) ? count($lines) : 0;
    }

    // -----------------------------------------------------------------------
    // Drain lock: Redis lock, local flock() while Redis is down
    // -----------------------------------------------------------------------

    /**
     * Acquire the per-host drain lock. A Redis (SharedState) lock normally;
     * when the Redis transport is dead — or Redis is not in use at all — a
     * non-blocking flock() on a per-host lock file, so the drain keeps running
     * through an outage instead of skipping every host.
     *
     * @param string $ip
     * @param int    $ttl seconds (Redis lock TTL; a flock lives until released)
     * @return array{backend:string,name:string,token:string}|null null = held elsewhere / no backend
     */
    public static function acquireDrainLock(string $ip, int $ttl): ?array
    {
        $name = 'queuein:'.$ip;
        if (self::redisEnabled()) {
            $token = SharedState::lock($name, $ttl);
            if ($token !== null) {
                return ['backend' => 'redis', 'name' => $name, 'token' => $token];
            }
            if (!SharedState::transportFailed()) {
                return null; // genuinely held by another worker
            }
        }
        $path = self::path('locks', $ip, 'lock');
        if ($path === null) {
            return null;
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            self::log('lockfile', 'QueueStore: cannot open drain lock file '.$path);

            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }
        $token = bin2hex(random_bytes(8));
        self::$fileLocks[$token] = $handle;

        return ['backend' => 'file', 'name' => $name, 'token' => $token];
    }

    /**
     * Extend a drain lock. false == ownership lost (expired/taken) or the
     * backend died: the caller must stop and requeue its tail.
     *
     * @param array $lock from acquireDrainLock()
     * @param int   $ttl
     * @return bool
     */
    public static function renewDrainLock(array $lock, int $ttl): bool
    {
        if ($lock['backend'] === 'redis') {
            return SharedState::renew($lock['name'], $lock['token'], $ttl);
        }

        // A flock() is held for as long as this process keeps the handle.
        return isset(self::$fileLocks[$lock['token']]);
    }

    /**
     * Release a drain lock only if this caller still owns it.
     *
     * @param array|null $lock from acquireDrainLock()
     * @return void
     */
    public static function releaseDrainLock(?array $lock): void
    {
        if ($lock === null) {
            return;
        }
        if ($lock['backend'] === 'redis') {
            SharedState::unlock($lock['name'], $lock['token']);

            return;
        }
        $handle = self::$fileLocks[$lock['token']] ?? null;
        unset(self::$fileLocks[$lock['token']]);
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    // -----------------------------------------------------------------------
    // Host maps: maps:<ip>
    // -----------------------------------------------------------------------

    /**
     * Store a host map in Redis and ALWAYS mirror it to disk, so the map
     * endpoint can still answer while Redis is down.
     *
     * @param string $ip
     * @param array  $map
     * @return void
     */
    public static function setMap(string $ip, array $map): void
    {
        $json = json_encode($map, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return;
        }
        if (self::redisEnabled()) {
            SharedState::rawCommand('maps.set', 'maps:'.$ip, static function ($client, $key) use ($json) {
                return $client->set($key, $json);
            }, false);
        }
        $path = self::path('maps', $ip, 'json');
        if ($path === null) {
            return;
        }
        // Write-then-rename so a concurrent reader never sees half a map.
        $tmp = $path.'.'.getmypid().'.tmp';
        if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            self::log('maps', 'QueueStore: could not write the map mirror '.$path);
        }
    }

    /**
     * Read a host map: Redis, else (unavailable or missing) the disk mirror.
     *
     * @param string $ip
     * @return array|null
     */
    public static function getMap(string $ip): ?array
    {
        if (self::redisEnabled()) {
            $raw = SharedState::rawCommand('maps.get', 'maps:'.$ip, static function ($client, $key) {
                return $client->get($key);
            }, false);
            if (is_string($raw)) {
                $map = json_decode($raw, true);
                if (is_array($map)) {
                    return $map;
                }
            }
        }
        $path = self::path('maps', $ip, 'json', false);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $map = json_decode((string) @file_get_contents($path), true);

        return is_array($map) ? $map : null;
    }

    // -----------------------------------------------------------------------
    // Outbound commands: queue:<ip>
    // -----------------------------------------------------------------------

    /**
     * Pop up to $max outbound command lines for a host. Redis unavailable =>
     * [] (an empty queue as far as the host can tell; it polls again).
     *
     * @param string $ip
     * @param int    $max
     * @return string[]
     */
    public static function popOutbound(string $ip, int $max): array
    {
        if (!self::redisEnabled()) {
            return [];
        }

        return SharedState::rawCommand('queue.lPop', 'queue:'.$ip, static function ($client, $key) use ($max) {
            $out = [];
            for ($i = 0; $i < $max; $i++) {
                $line = $client->lPop($key);
                if ($line === false || $line === null) {
                    break;
                }
                $out[] = (string) $line;
            }

            return $out;
        }, []);
    }

    // -----------------------------------------------------------------------
    // Replay dedup
    // -----------------------------------------------------------------------

    /**
     * Claim a replay-dedup marker: Redis SET NX EX, else an exclusive-create
     * marker file that counts as present for $ttl seconds.
     *
     * @param string $markerKey fully built marker key (never key material)
     * @param int    $ttl
     * @return bool|null true = first seen, false = duplicate, null = no store (fail-open)
     */
    public static function claimDedup(string $markerKey, int $ttl): ?bool
    {
        if (self::redisEnabled()) {
            $fail = new \stdClass();
            $claimed = SharedState::rawCommand('dedup.set', $markerKey, static function ($client, $key) use ($ttl) {
                return $client->set($key, 1, ['NX', 'EX' => $ttl]);
            }, $fail);
            if ($claimed !== $fail) {
                return (bool) $claimed;
            }
        }
        $dir = self::dir('dedup');
        if ($dir === null) {
            return null;
        }
        $path = $dir.'/'.hash('sha256', $markerKey);
        $now = time();
        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            fclose($handle);
            self::sweepDedup($dir, $ttl, $now);

            return true;
        }
        clearstatcache(true, $path);
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return null;
        }
        if ($now - $mtime < $ttl) {
            return false;
        }
        // Expired marker: reclaim it (touch is the re-claim; a racing claimer
        // that also saw it expired may win too — acceptable for a fail-open
        // defence-in-depth check).
        @touch($path, $now);

        return true;
    }

    // -----------------------------------------------------------------------
    // Drain lookup caches (cache-aside; MySQL is the source of truth)
    // -----------------------------------------------------------------------

    /**
     * Read a cached array from Redis (JSON). false = miss or Redis
     * unavailable — the caller falls through to MySQL either way.
     *
     * @param string $redisKey
     * @return array|false
     */
    public static function cacheGet(string $redisKey)
    {
        if (!self::redisEnabled()) {
            return false;
        }
        $raw = SharedState::rawCommand('cache.get', $redisKey, static function ($client, $key) {
            return $client->get($key);
        }, false);
        if (!is_string($raw)) {
            return false;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : false;
    }

    /**
     * Write a cached array with a TTL; skipped while Redis is unavailable.
     *
     * @param string $redisKey
     * @param array  $value
     * @param int    $ttl
     * @return void
     */
    public static function cacheSet(string $redisKey, array $value, int $ttl): void
    {
        if (!self::redisEnabled()) {
            return;
        }
        $json = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return;
        }
        SharedState::rawCommand('cache.setEx', $redisKey, static function ($client, $key) use ($ttl, $json) {
            return $client->setEx($key, $ttl, $json);
        }, false);
    }

    /**
     * Write a scalar telemetry value (no TTL); skipped while Redis is
     * unavailable.
     *
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    public static function putValue(string $key, $value): void
    {
        if (!self::redisEnabled()) {
            return;
        }
        SharedState::rawCommand('value.set', $key, static function ($client, $k) use ($value) {
            return $client->set($k, $value);
        }, false);
    }

    // -----------------------------------------------------------------------
    // Test seams
    // -----------------------------------------------------------------------

    /**
     * TEST SEAM ONLY. Point the spill at another directory (null restores it).
     *
     * @param string|null $dir
     * @return void
     */
    public static function setSpillDir(?string $dir): void
    {
        self::$spillDirOverride = $dir;
    }

    /**
     * TEST SEAM ONLY. Force USE_REDIS on/off (null restores the constant).
     *
     * @param bool|null $enabled
     * @return void
     */
    public static function setRedisEnabled(?bool $enabled): void
    {
        self::$redisEnabledOverride = $enabled;
    }

    /**
     * TEST SEAM ONLY. Forget every override, held file lock and log memo.
     *
     * @return void
     */
    public static function reset(): void
    {
        foreach (self::$fileLocks as $handle) {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
        self::$fileLocks = [];
        self::$spillDirOverride = null;
        self::$redisEnabledOverride = null;
        self::$lastLogged = [];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @return bool whether Redis is the configured primary store
     */
    public static function redisEnabled(): bool
    {
        if (self::$redisEnabledOverride !== null) {
            return self::$redisEnabledOverride;
        }

        return defined('USE_REDIS') && USE_REDIS === true;
    }

    /**
     * @return string the spill root
     */
    private static function spillDir(): string
    {
        if (self::$spillDirOverride !== null) {
            return self::$spillDirOverride;
        }

        return defined('QUEUE_SPILL_DIR') ? (string) QUEUE_SPILL_DIR : self::DEFAULT_SPILL_DIR;
    }

    /**
     * A spill sub-directory, created on first use.
     *
     * @param string $sub
     * @param bool   $create
     * @return string|null
     */
    private static function dir(string $sub, bool $create = true): ?string
    {
        $dir = self::spillDir().'/'.$sub;
        if (is_dir($dir)) {
            return $dir;
        }
        if (!$create) {
            return null;
        }
        if (!@mkdir($dir, 0o770, true) && !is_dir($dir)) {
            self::log('mkdir:'.$sub, 'QueueStore: cannot create spill directory '.$dir);

            return null;
        }

        return $dir;
    }

    /**
     * Per-host file path. The ip is validated, so it can never walk the tree.
     *
     * @param string $sub
     * @param string $ip
     * @param string $ext
     * @param bool   $create
     * @return string|null
     */
    private static function path(string $sub, string $ip, string $ext, bool $create = true): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $dir = self::dir($sub, $create);

        return $dir === null ? null : $dir.'/'.str_replace(':', '_', $ip).'.'.$ext;
    }

    /**
     * Add encoded lines to a host's spill — at the end (newest) or, for a
     * requeue, at the front — keeping the newest SPILL_MAX_ITEMS.
     *
     * @param string   $ip
     * @param string[] $lines JSON-encoded items, oldest first
     * @param bool     $front
     * @return bool
     */
    private static function spillWrite(string $ip, array $lines, bool $front): bool
    {
        $path = self::path('queuein', $ip, 'jsonl');
        $dropped = 0;
        $ok = $path !== null && self::withLockedFile($path, static function (array $current) use ($lines, $front, &$dropped) {
            $next = $front ? array_merge($lines, $current) : array_merge($current, $lines);
            $dropped = max(0, count($next) - self::SPILL_MAX_ITEMS);
            if ($dropped === 0) {
                return $next;
            }

            // Keep the newest; a requeued tail sits at the front, so it is
            // the oldest and goes first.
            return array_slice($next, $dropped);
        });
        if (!$ok) {
            self::log('spill:'.$ip, 'QueueStore: Redis unavailable AND the disk spill refused '.count($lines).' queuein item(s) for '.$ip.' — dropped');

            return false;
        }
        if ($dropped > 0) {
            self::log('spillcap:'.$ip, 'QueueStore: spill for '.$ip.' is full — dropped '.$dropped.' item(s)');
        }

        return true;
    }

    /**
     * Remove and return up to $max encoded lines from the front of a spill.
     *
     * @param string $ip
     * @param int    $max
     * @return string[]
     */
    private static function spillTake(string $ip, int $max): array
    {
        if ($max <= 0) {
            return [];
        }
        $path = self::path('queuein', $ip, 'jsonl', false);
        if ($path === null) {
            return [];
        }
        // Cheap emptiness check: the spill is empty on every healthy tick.
        clearstatcache(true, $path);
        if (!is_file($path) || @filesize($path) === 0) {
            return [];
        }
        $taken = [];
        self::withLockedFile($path, static function (array $current) use ($max, &$taken) {
            $taken = array_slice($current, 0, $max);

            return array_slice($current, $max);
        });

        return $taken;
    }

    /**
     * Exclusive read-modify-write of a line file under flock(). The file is
     * truncated, never unlinked: an appender already waiting on the lock holds
     * the same inode, and unlinking would make it write into a deleted file.
     *
     * @param string   $path
     * @param callable $mutate fn(string[] $lines): string[]
     * @return bool
     */
    private static function withLockedFile(string $path, callable $mutate): bool
    {
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            $raw = stream_get_contents($handle);
            $current = ($raw === false || $raw === '') ? [] : array_values(array_filter(explode("\n", $raw), 'strlen'));
            $next = $mutate($current);
            $body = $next === [] ? '' : implode("\n", $next)."\n";
            if (!ftruncate($handle, 0) || !rewind($handle)) {
                return false;
            }
            if ($body !== '' && fwrite($handle, $body) !== strlen($body)) {
                return false;
            }
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Occasionally delete expired dedup markers so the directory stays small.
     *
     * @param string $dir
     * @param int    $ttl
     * @param int    $now
     * @return void
     */
    private static function sweepDedup(string $dir, int $ttl, int $now): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }
        foreach ((array) @glob($dir.'/*') as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $now - $mtime >= $ttl) {
                @unlink($file);
            }
        }
    }

    /**
     * Rate-limited log: each message key at most once a minute, so a long
     * outage leaves a heartbeat in the log without flooding it.
     *
     * @param string $memo
     * @param string $message
     * @return void
     */
    private static function log(string $memo, string $message): void
    {
        $now = time();
        if (isset(self::$lastLogged[$memo]) && $now - self::$lastLogged[$memo] < 60) {
            return;
        }
        self::$lastLogged[$memo] = $now;
        if (class_exists('\Workerman\Worker', false) && \Workerman\Worker::getAllWorkers() !== []) {
            \Workerman\Worker::safeEcho($message.PHP_EOL);

            return;
        }
        error_log($message);
    }
}
