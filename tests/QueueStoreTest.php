<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../Applications/Chat/QueueStore.php';

/**
 * Minimal phpredis stand-in for the raw queue commands QueueStore issues.
 * Flip $down to make every command throw like a dead transport.
 */
final class QueueStoreFakeRedis
{
    /** @var array<string,mixed> */
    public $data = [];

    /** @var bool */
    public $down = false;

    private function guard(): void
    {
        if ($this->down) {
            throw new \RuntimeException('Connection refused');
        }
    }

    public function ping()
    {
        $this->guard();

        return true;
    }

    public function get($key)
    {
        $this->guard();

        return array_key_exists($key, $this->data) && !is_array($this->data[$key]) ? $this->data[$key] : false;
    }

    public function set($key, $value, $opts = null)
    {
        $this->guard();
        $nx = is_array($opts) && (in_array('NX', $opts, true) || in_array('nx', $opts, true));
        if ($nx && array_key_exists($key, $this->data)) {
            return false;
        }
        $this->data[$key] = (string) $value;

        return true;
    }

    public function setEx($key, $ttl, $value)
    {
        return $this->set($key, $value);
    }

    public function del($key)
    {
        $this->guard();
        $had = array_key_exists($key, $this->data);
        unset($this->data[$key]);

        return $had ? 1 : 0;
    }

    public function rPush($key, $value)
    {
        $this->guard();
        $this->data[$key][] = $value;

        return count($this->data[$key]);
    }

    public function lPush($key, $value)
    {
        $this->guard();
        $list = $this->data[$key] ?? [];
        array_unshift($list, $value);
        $this->data[$key] = $list;

        return count($list);
    }

    public function lPop($key)
    {
        $this->guard();
        if (empty($this->data[$key])) {
            return false;
        }

        return array_shift($this->data[$key]);
    }

    public function eval($script, $args = [], $numKeys = 0)
    {
        $this->guard();
        [$key, $token] = $args;
        if (($this->data[$key] ?? null) !== $token) {
            return 0;
        }
        if (strpos($script, "'DEL'") !== false) {
            unset($this->data[$key]);
        }

        return 1;
    }
}

/**
 * QueueStore keeps the provisioning-host queue working while Redis is down and
 * hands everything back to Redis once it recovers — pinned offline.
 */
final class QueueStoreTest extends TestCase
{
    /** @var QueueStoreFakeRedis */
    private $redis;

    /** @var string per-test spill directory */
    private $spillDir;

    /** @var string|false */
    private $previousErrorLog;

    protected function setUp(): void
    {
        unset($GLOBALS['redis']);
        SharedState::reset();
        QueueStore::reset();
        $this->redis = new QueueStoreFakeRedis();
        $this->spillDir = sys_get_temp_dir().'/queuestore-test-'.bin2hex(random_bytes(6));
        SharedState::setClient($this->redis);
        SharedState::setTestClock(1000);
        QueueStore::setSpillDir($this->spillDir);
        QueueStore::setRedisEnabled(true);
        // Outage lines are expected here; keep them out of the test output.
        $this->previousErrorLog = ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', (string) $this->previousErrorLog);
        }
        SharedState::reset();
        QueueStore::reset();
        unset($GLOBALS['redis']);
        $this->removeTree($this->spillDir);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }

    private function item(int $n): array
    {
        return ['get' => [], 'post' => ['action' => 'cpu_usage', 'n' => $n], 'ip' => '10.0.0.1'];
    }

    /** Let the SharedState dead window elapse so the next call re-probes. */
    private function recoverRedis(): void
    {
        $this->redis->down = false;
        SharedState::setTestClock(1000 + SharedState::REPROBE_INTERVAL + 1);
    }

    public function testHealthyRedisPathIsUnchanged(): void
    {
        $this->assertTrue(QueueStore::pushInbound('10.0.0.1', $this->item(1)));
        $this->assertCount(1, $this->redis->data['queuein:10.0.0.1']);
        $this->assertSame(0, QueueStore::spillCount('10.0.0.1'));
        $this->assertSame([$this->item(1)], QueueStore::popInbound('10.0.0.1', 10));
    }

    public function testOutageSpillsToDiskAndDrainsOldestFirstAfterRecovery(): void
    {
        QueueStore::pushInbound('10.0.0.1', $this->item(1)); // before the outage
        $this->redis->down = true;
        $this->assertTrue(QueueStore::pushInbound('10.0.0.1', $this->item(2)), 'an outage must not lose the item');
        $this->assertTrue(QueueStore::pushInbound('10.0.0.1', $this->item(3)));
        $this->assertSame(2, QueueStore::spillCount('10.0.0.1'));
        $this->assertTrue(SharedState::transportFailed());

        // Still down: the drain serves the spill without touching Redis.
        $this->assertSame([$this->item(2)], QueueStore::popInbound('10.0.0.1', 1));

        $this->recoverRedis();
        $this->assertTrue(QueueStore::pushInbound('10.0.0.1', $this->item(4)));
        $this->assertSame(1, QueueStore::spillCount('10.0.0.1'), 'post-recovery writes go back to Redis');

        // Spill (older) first, then the Redis list.
        $this->assertSame([$this->item(3), $this->item(1), $this->item(4)], QueueStore::popInbound('10.0.0.1', 10));
        $this->assertSame(0, QueueStore::spillCount('10.0.0.1'));
    }

    public function testSpillKeepsOnlyTheNewestItems(): void
    {
        $this->redis->down = true;
        for ($i = 1; $i <= QueueStore::SPILL_MAX_ITEMS + 5; $i++) {
            QueueStore::pushInbound('10.0.0.1', $this->item($i));
        }
        $this->assertSame(QueueStore::SPILL_MAX_ITEMS, QueueStore::spillCount('10.0.0.1'));
        $first = QueueStore::popInbound('10.0.0.1', 1);
        $this->assertSame(6, $first[0]['post']['n'], 'the 5 oldest were dropped');
    }

    public function testRequeueDuringOutageGoesToTheFrontOfTheSpillInOrder(): void
    {
        $this->redis->down = true;
        QueueStore::pushInbound('10.0.0.1', $this->item(9));
        $this->assertTrue(QueueStore::requeueInbound('10.0.0.1', [$this->item(1), $this->item(2)]));
        $this->assertSame([$this->item(1), $this->item(2), $this->item(9)], QueueStore::popInbound('10.0.0.1', 10));
    }

    public function testRequeueWithHealthyRedisRestoresHeadOrder(): void
    {
        QueueStore::pushInbound('10.0.0.1', $this->item(9));
        QueueStore::requeueInbound('10.0.0.1', [$this->item(1), $this->item(2)]);
        $this->assertSame([$this->item(1), $this->item(2), $this->item(9)], QueueStore::popInbound('10.0.0.1', 10));
    }

    public function testDrainLockFallsBackToFlockWhileRedisIsDown(): void
    {
        $this->redis->down = true;
        $lock = QueueStore::acquireDrainLock('10.0.0.1', 900);
        $this->assertNotNull($lock, 'a dead Redis must not stop the drain');
        $this->assertSame('file', $lock['backend']);
        // flock() is per open file description, so a second fopen() in this
        // same process contends exactly like another worker would.
        $this->assertNull(QueueStore::acquireDrainLock('10.0.0.1', 900), 'still exclusive');
        $this->assertTrue(QueueStore::renewDrainLock($lock, 900));
        QueueStore::releaseDrainLock($lock);
        $this->assertNotNull(QueueStore::acquireDrainLock('10.0.0.1', 900), 'released');
    }

    public function testDrainLockUsesRedisWhenHealthyAndContentionIsNotMistakenForAnOutage(): void
    {
        $lock = QueueStore::acquireDrainLock('10.0.0.1', 900);
        $this->assertSame('redis', $lock['backend']);
        $this->assertNull(QueueStore::acquireDrainLock('10.0.0.1', 900), 'held elsewhere, not a fallback trigger');
        $this->assertFileDoesNotExist($this->spillDir.'/locks/10.0.0.1.lock', 'no fallback lock taken on mere contention');
        QueueStore::releaseDrainLock($lock);
        $this->assertNotNull(QueueStore::acquireDrainLock('10.0.0.1', 900));
    }

    public function testMapIsMirroredAndServedFromDiskDuringAnOutage(): void
    {
        $map = ['ip' => "a:b\n", 'vnc' => "c:d\n"];
        QueueStore::setMap('10.0.0.1', $map);
        $this->assertSame($map, QueueStore::getMap('10.0.0.1'));
        $this->redis->down = true;
        $this->assertSame($map, QueueStore::getMap('10.0.0.1'), 'the mirror answers while Redis is down');
        $this->recoverRedis();
        $this->assertSame($map, QueueStore::getMap('10.0.0.1'));
    }

    public function testOutboundQueueIsEmptyNotFatalDuringAnOutage(): void
    {
        $this->redis->rPush('queue:10.0.0.1', 'echo hi');
        $this->redis->down = true;
        $this->assertSame([], QueueStore::popOutbound('10.0.0.1', 100));
        $this->recoverRedis();
        $this->assertSame(['echo hi'], QueueStore::popOutbound('10.0.0.1', 100), 'the command waited out the outage');
    }

    public function testDedupFallsBackToDiskInsteadOfFailingOpen(): void
    {
        $this->assertTrue(QueueStore::claimDedup('queue:dedup:a', 600));
        $this->assertFalse(QueueStore::claimDedup('queue:dedup:a', 600));
        $this->redis->down = true;
        $this->assertTrue(QueueStore::claimDedup('queue:dedup:b', 600));
        $this->assertFalse(QueueStore::claimDedup('queue:dedup:b', 600), 'replay still caught during the outage');
    }

    public function testDrainCachesMissWhileRedisIsDownSoMysqlIsRead(): void
    {
        QueueStore::cacheSet('vps_masters:1.2.3.4', ['vps_id' => 5], 3600);
        $this->assertSame(['vps_id' => 5], QueueStore::cacheGet('vps_masters:1.2.3.4'));
        $this->redis->down = true;
        $this->assertFalse(QueueStore::cacheGet('vps_masters:1.2.3.4'), 'a miss, so the caller reads MySQL');
        QueueStore::cacheSet('vps_masters:1.2.3.4', ['vps_id' => 6], 3600); // no throw
        QueueStore::putValue('vps:host:5:load', '1.5');                     // no throw
        $this->recoverRedis();
        $this->assertSame(['vps_id' => 5], QueueStore::cacheGet('vps_masters:1.2.3.4'));
    }

    public function testRedisDisabledUsesTheDiskStoreOnly(): void
    {
        QueueStore::setRedisEnabled(false);
        QueueStore::pushInbound('10.0.0.1', $this->item(1));
        $this->assertSame([], $this->redis->data);
        $this->assertSame([$this->item(1)], QueueStore::popInbound('10.0.0.1', 10));
        $this->assertSame('file', QueueStore::acquireDrainLock('10.0.0.1', 900)['backend']);
    }

    public function testNonIpHostNeverTouchesTheFilesystem(): void
    {
        $this->redis->down = true;
        $this->assertFalse(QueueStore::pushInbound('../../etc/passwd', $this->item(1)));
        $this->assertDirectoryDoesNotExist($this->spillDir.'/queuein');
    }

    public function testRawCommandRefusesTheFacadeNamespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SharedState::rawCommand('x', 'dc:lock:queuein:1', static fn ($c, $k) => $c->get($k), null);
    }
}
