<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/TestBootstrap.php';
require_once __DIR__.'/../Applications/Chat/SharedState.php';
require_once __DIR__.'/../Applications/Chat/SecretBoxProbe.php';
require_once __DIR__.'/../Applications/Chat/HyperVHostSecret.php';

/**
 * MyAdmin plan_2way §5.0.5 (per-worker version probe) and §5.11 (the Hyper-V
 * host password opened right before use).
 */
final class SecretBoxProbeTest extends TestCase
{
    /** @var InMemoryRedis */
    private $redis;
    /** @var string */
    private $tmp;

    protected function setUp(): void
    {
        unset($GLOBALS['redis']);
        SharedState::reset();
        $this->redis = new InMemoryRedis();
        SharedState::setClient($this->redis);
        SecretBoxProbe::reset();
        $this->tmp = sys_get_temp_dir().'/sbprobe-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/.git/refs/heads', 0o777, true);
    }

    protected function tearDown(): void
    {
        SharedState::reset();
        SecretBoxProbe::reset();
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    private static function worker(string $name = 'TaskWorker', int $id = 3): object
    {
        return (object) ['name' => $name, 'id' => $id];
    }

    public function testGitHeadReadsLooseRefsPackedRefsDetachedHeadsAndWorktrees(): void
    {
        $sha = str_repeat('a', 40);
        file_put_contents($this->tmp.'/.git/HEAD', "ref: refs/heads/master\n");
        file_put_contents($this->tmp.'/.git/refs/heads/master', $sha."\n");
        $this->assertSame($sha, SecretBoxProbe::gitHead($this->tmp));

        unlink($this->tmp.'/.git/refs/heads/master');
        file_put_contents($this->tmp.'/.git/packed-refs', "# pack-refs with: peeled\n".str_repeat('b', 40)." refs/heads/master\n");
        $this->assertSame(str_repeat('b', 40), SecretBoxProbe::gitHead($this->tmp));

        file_put_contents($this->tmp.'/.git/HEAD', str_repeat('c', 40)."\n");
        $this->assertSame(str_repeat('c', 40), SecretBoxProbe::gitHead($this->tmp));

        // a linked worktree: .git is a file, refs live in the common dir
        mkdir($this->tmp.'/wt');
        mkdir($this->tmp.'/.git/worktrees/wt', 0o777, true);
        file_put_contents($this->tmp.'/wt/.git', 'gitdir: '.$this->tmp."/.git/worktrees/wt\n");
        file_put_contents($this->tmp.'/.git/worktrees/wt/HEAD', "ref: refs/heads/secretbox\n");
        file_put_contents($this->tmp.'/.git/worktrees/wt/commondir', "../..\n");
        file_put_contents($this->tmp.'/.git/refs/heads/secretbox', str_repeat('d', 40)."\n");
        $this->assertSame(str_repeat('d', 40), SecretBoxProbe::gitHead($this->tmp.'/wt'));

        $this->assertNull(SecretBoxProbe::gitHead($this->tmp.'/nowhere'));
    }

    public function testRegisterPublishesTheSnapshotWithATtlAndKeepsItAlive(): void
    {
        file_put_contents($this->tmp.'/.git/HEAD', str_repeat('e', 40)."\n");
        $loop = TestTimer::install();
        try {
            SecretBoxProbe::register(self::worker(), $this->tmp);
            $key = SecretBoxProbe::key((string) gethostname(), getmypid());
            $stored = SharedState::get($key);
            $this->assertSame('TaskWorker', $stored['worker']);
            $this->assertSame(3, $stored['id']);
            $this->assertSame(getmypid(), $stored['pid']);
            $this->assertSame(str_repeat('e', 40), $stored['myadmin_head']);
            $this->assertSame($this->tmp, $stored['myadmin_root']);
            $this->assertArrayHasKey($key, $this->redis->expires, 'the key has a TTL, so a dead worker drops out');
            $timers = TestTimer::added();
            $this->assertCount(1, $timers);
            $this->assertSame((float) SecretBoxProbe::INTERVAL, (float) $timers[0]['interval']);
            SharedState::del($key);
            call_user_func($timers[0]['func']);
            $this->assertNotNull(SharedState::get($key), 'the timer re-publishes');
        } finally {
            TestTimer::uninstall();
        }
    }

    public function testFlagsAreReadInsideTheProcessOrNullWithoutTheClass(): void
    {
        if (!class_exists('MyAdmin\\Security\\SecretFlags')) {
            $this->assertNull(SecretBoxProbe::snapshot(self::worker(), $this->tmp, 100)['flags']);
            eval('namespace MyAdmin\\Security; final class SecretFlags { public static function all() { return ["write" => ["vps_rootpass" => false], "allow_plaintext" => true]; } }');
        }
        $snap = SecretBoxProbe::snapshot(self::worker('WebServer', 0), $this->tmp, 100);
        $this->assertIsArray($snap['flags']);
        $this->assertSame(100, $snap['started']);
        $this->assertNull($snap['myadmin_head'], 'no HEAD file in this fixture');
    }

    public function testPublishingNeverThrows(): void
    {
        $this->assertFalse(SecretBoxProbe::publish(), 'nothing registered yet');
        SharedState::setClient(new class() {
            public function __call($m, $a)
            {
                throw new \RedisException('down');
            }
        });
        file_put_contents($this->tmp.'/.git/HEAD', str_repeat('f', 40)."\n");
        TestTimer::install();
        try {
            SecretBoxProbe::register(self::worker(), $this->tmp);
            $this->assertFalse(SecretBoxProbe::publish());
        } finally {
            TestTimer::uninstall();
        }
    }

    public function testHostPasswordIsTheStoredValueWithoutCoreAndNeverAnUnopenableEnvelope(): void
    {
        if (!class_exists('MyAdmin\\Security\\ServiceSecrets')) {
            $this->assertSame("Host'Pw", HyperVHostSecret::password(['vps_id' => 440, 'vps_root' => "Host'Pw"], 'test'));
            eval('namespace MyAdmin\\Security; class SecretBoxException extends \\RuntimeException {} final class ServiceSecrets {
                public static function readColumn($t, $c, $ref, $stored) {
                    if (is_string($stored) && strncmp($stored, "S1.", 3) === 0) { throw new SecretBoxException("secretbox hyperv_host_root $t.$c.$ref: envelope failed authentication"); }
                    return $stored;
                }
            }');
        }
        $this->assertSame('Plain-Pw', HyperVHostSecret::password(['vps_id' => 440, 'vps_root' => 'Plain-Pw'], 'test'));
        ob_start();
        $result = HyperVHostSecret::password(['vps_id' => 440, 'vps_root' => 'S1.k1.'.str_repeat('A', 60)], 'test');
        $out = (string) ob_get_clean();
        $this->assertFalse($result);
        $this->assertStringNotContainsString('S1.k1.', $out);
    }

    public function testTasksAndWorkersAreWired(): void
    {
        $root = dirname(__DIR__);
        foreach (['Tasks/hyperv_cleanupresources.php', 'Tasks/async_hyperv_get_list.php'] as $f) {
            $src = (string) file_get_contents($root.'/'.$f);
            $this->assertStringContainsString('\\HyperVHostSecret::password($service_master, ', $src, $f);
            $this->assertStringNotContainsString("=> \$service_master['vps_root']", $src, $f);
        }
        foreach (['start_task.php', 'start_web.php', 'start_businessworker.php'] as $f) {
            $this->assertStringContainsString('\\SecretBoxProbe::register($worker);', (string) file_get_contents($root.'/Applications/Chat/'.$f), $f);
        }
    }
}
