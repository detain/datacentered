<?php

use Workerman\Timer;

require_once __DIR__.'/SharedState.php';

/**
 * Per-worker version probe for MyAdmin's SecretBox rollout (plan_2way.md
 * §5.0.5, review 2 H-d). Every worker that can load MyAdmin code keeps one
 * Redis key alive saying which MyAdmin tree it started on and which SecretBox
 * flags it holds in memory:
 *
 *     dc:state:secretbox_probe:<host>:<pid>  =>  {host, worker, id, pid, started,
 *                                                  myadmin_root, myadmin_head, flags}
 *
 * refreshed every INTERVAL seconds with a TTL of TTL seconds, so a dead worker's
 * key disappears on its own. MyAdmin's `secretbox_tool.php check` reads them and
 * fails when a worker started before its tree's last deploy, reports another
 * SHA, or holds other flags: a WorkerMan worker keeps the code and the flag
 * file it loaded at start until it is restarted, and the master respawns a
 * crashed worker from whatever is on disk at that moment.
 *
 * `flags` is MyAdmin's SecretFlags::all() read inside this process (SecretFlags
 * caches the file per process, so after a flip without a restart it keeps
 * reporting the old flags, which is the point). It is null when the class is
 * not loadable. Publishing never throws: a probe must not stop a worker.
 */
final class SecretBoxProbe
{
    public const KEY_PREFIX = 'dc:state:secretbox_probe:';
    public const TTL = 180;
    public const INTERVAL = 60;
    public const MYADMIN_ROOT = '/home/my';

    /** @var array<string,mixed>|null */
    private static $snapshot = null;

    /**
     * Snapshot this worker once and keep it published.
     *
     * @param object $worker a Workerman worker (name, id)
     */
    public static function register($worker, string $root = self::MYADMIN_ROOT): void
    {
        try {
            self::$snapshot = self::snapshot($worker, $root);
            self::publish();
            Timer::add(self::INTERVAL, [self::class, 'publish']);
        } catch (\Throwable $e) {
            echo 'SecretBoxProbe register failed: '.get_class($e).': '.$e->getMessage().PHP_EOL;
        }
    }

    /**
     * @param object $worker
     * @return array<string,mixed>
     */
    public static function snapshot($worker, string $root = self::MYADMIN_ROOT, ?int $now = null): array
    {
        $flags = null;
        if (class_exists('MyAdmin\\Security\\SecretFlags')) {
            try {
                $flags = \MyAdmin\Security\SecretFlags::all();
            } catch (\Throwable $e) {
                $flags = null;
            }
        }
        return [
            'host' => (string) gethostname(),
            'worker' => (string) ($worker->name ?? ''),
            'id' => (int) ($worker->id ?? 0),
            'pid' => (int) getmypid(),
            'started' => $now ?? time(),
            'myadmin_root' => $root,
            'myadmin_head' => self::gitHead($root),
            'flags' => $flags,
        ];
    }

    /** Re-publish the snapshot with a fresh TTL. */
    public static function publish(): bool
    {
        if (self::$snapshot === null) {
            return false;
        }
        try {
            return SharedState::set(self::key(self::$snapshot['host'], self::$snapshot['pid']), self::$snapshot, self::TTL);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function key(string $host, int $pid): string
    {
        return self::KEY_PREFIX.$host.':'.$pid;
    }

    /**
     * The commit a git checkout's HEAD points at, read from the files (no git
     * binary, no shell): a detached HEAD, a loose ref, or packed-refs. A
     * worktree's `.git` file is followed. Null when it cannot be read.
     */
    public static function gitHead(string $root): ?string
    {
        $gitDir = $root.'/.git';
        if (is_file($gitDir) && preg_match('/^gitdir:\s*(.+)$/m', (string) file_get_contents($gitDir), $m)) {
            $gitDir = trim($m[1]);
            if ($gitDir !== '' && $gitDir[0] !== '/') {
                $gitDir = $root.'/'.$gitDir;
            }
        }
        $head = @file_get_contents($gitDir.'/HEAD');
        if ($head === false) {
            return null;
        }
        $head = trim($head);
        if (preg_match('/^[0-9a-f]{40}$/', $head)) {
            return $head;
        }
        if (!preg_match('/^ref:\s*(refs\/\S+)$/', $head, $m)) {
            return null;
        }
        $ref = $m[1];
        // a linked worktree keeps its refs in the common dir
        $common = is_file($gitDir.'/commondir') ? trim((string) file_get_contents($gitDir.'/commondir')) : '';
        $dirs = [$gitDir];
        if ($common !== '') {
            $dirs[] = $common[0] === '/' ? $common : $gitDir.'/'.$common;
        }
        foreach ($dirs as $dir) {
            $loose = @file_get_contents($dir.'/'.$ref);
            if ($loose !== false && preg_match('/^[0-9a-f]{40}/', trim($loose), $mm)) {
                return $mm[0];
            }
        }
        foreach ($dirs as $dir) {
            $packed = @file_get_contents($dir.'/packed-refs');
            if ($packed !== false && preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/m', $packed, $mm)) {
                return $mm[1];
            }
        }
        return null;
    }

    /** Test seam. */
    public static function reset(): void
    {
        self::$snapshot = null;
    }
}
