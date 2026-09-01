<?php

use PHPUnit\Framework\TestCase;

// Declares InMemoryRedis (no socket can be opened to REDIS_HOST in tests)
// before SharedState is loaded — same wiring as tests/SharedStateTest.php,
// so this suite also runs standalone.
require_once __DIR__.'/TestBootstrap.php';
require_once __DIR__.'/../Applications/Chat/SharedState.php';

/**
 * Regression suite for the SharedState distributed-lock semantics that REPLACE
 * the retired GlobalData CAS locks. ⛔ Invariant: the payment (processing_queue),
 * VPS lifecycle (vps_host_*), boardctl (boardctl_asset_*) and per-host queue
 * drain (queuein:*) workers depend on exactly these guarantees.
 *
 * The GlobalData pattern under migration was
 *   `$global->cas($var, 0, time())` … release via `$global->$var = 0`
 * — no real TTL on the lock, hand-rolled stale reapers (900s / 22200s), and an
 * UNCONDITIONAL release any slow holder could fire, wiping the lock out from
 * under whoever legitimately re-acquired it after a crash. The Redis contract
 * replaces all three, and this file pins each replacement leg:
 *
 *   acquire  SET NX EX <token>       — server-enforced TTL, never manual resets
 *   release  Lua compare-token DEL   — owner-checked; stale/foreign tokens refused
 *   renew    Lua compare-token PEXPIRE — owner-checked extension, no resurrection
 *   value    RAW token (not JSON)    — the Lua scripts string-compare GET vs ARGV
 *   expiry   crashed-holder self-heal — the payment-queue safety net that made
 *                                     the stale-lock reapers necessary before
 *
 * Frozen production lock families (name @ TTL from the migration contract):
 *   processing_queue    @900     Events::processing_queue_timer stale window
 *   vps_host_<id>       @900     per-VPS lifecycle family — raised 120→900 per ops
 *                                decision: HyperV ops (esp. GetVMList) can take 10+
 *                                minutes, so the TTL must match the old GlobalData
 *                                900s stale-reap window it replaced, never shorter.
 *   boardctl_asset_<id> @22200   6h runner cap + 10min buffer
 *   queuein:<ip>        @900     name SHARED with the raw Redis queue LIST
 *                                (Web/queue.php rPushes 'queuein:<ip>' directly)
 *                                — the dc:lock: prefix isolation is load-bearing.
 *
 * All timing runs against InMemoryRedis' controllable clock (fastForward);
 * eviction is deadline <= clock, so every expiry boundary here is exact and
 * no test ever sleeps.
 *
 * @see Applications/Chat/SharedState.php  the facade under test (lock/unlock/renew)
 * @see tests/TestBootstrap.php            InMemoryRedis double
 * @see tests/SharedStateTest.php          the Phase 1 facade unit suite
 */
class SharedStateLockRegressionTest extends TestCase
{
    /** @var InMemoryRedis */
    private $redis;

    protected function setUp(): void
    {
        // $GLOBALS['redis'] must not leak in from another suite: the facade
        // prefers it over any injected client, so start every test from the
        // "no shared connection" baseline (identical to SharedStateTest).
        unset($GLOBALS['redis']);
        SharedState::reset();
        $this->redis = new InMemoryRedis();
        SharedState::setClient($this->redis);
    }

    protected function tearDown(): void
    {
        SharedState::reset();
        unset($GLOBALS['redis']);
    }

    /** Raw keyspace view of a lock value (phpredis: a MISS is false, not null). */
    private function rawLock(string $name)
    {
        return $this->redis->get(SharedState::PREFIX_LOCK.$name);
    }

    // -----------------------------------------------------------------------
    // 1. Contended acquire — mutual exclusion while the lock is held
    // -----------------------------------------------------------------------

    public function testContendedAcquireIsBlockedUntilOwnerReleases(): void
    {
        $holder = SharedState::lock('vps_host_11', 900);
        $this->assertNotNull($holder, 'the first acquire of a free lock must hand out a token');

        $this->assertNull(SharedState::lock('vps_host_11', 900), 'while held, a second acquire must be refused');
        $this->assertSame($holder, $this->rawLock('vps_host_11'), 'a refused acquire must never overwrite the holder token');

        $this->assertTrue(SharedState::unlock('vps_host_11', $holder), 'owner release must succeed');
        $this->assertFalse($this->rawLock('vps_host_11'), 'release must remove the key outright');

        $successor = SharedState::lock('vps_host_11', 900);
        $this->assertNotNull($successor, 'once released, the lock must be acquirable again');
        $this->assertNotSame($holder, $successor, 'every acquire mints a fresh ownership token');
    }

    // -----------------------------------------------------------------------
    // 2. Owner-only release — Lua compare-and-delete + admin force override
    // -----------------------------------------------------------------------

    public function testUnlockWithForeignTokenIsRefusedAndLockPersists(): void
    {
        $holder = SharedState::lock('processing_queue', 900);
        $this->assertNotNull($holder);

        $this->assertFalse(
            SharedState::unlock('processing_queue', gethostname().':'.getmypid().':0000000000000000'),
            'a well-formed but foreign token must release nothing — the Lua compares GET against ARGV'
        );
        $this->assertSame($holder, $this->rawLock('processing_queue'), 'the refused release must leave the lock held with its original token');
        $this->assertNull(SharedState::lock('processing_queue', 900), 'and the lock must still exclude new holders');

        $this->assertFalse(
            SharedState::unlock('processing_queue', ''),
            "an empty token is NOT the force path — it is a compare that must miss"
        );
        $this->assertSame($holder, $this->rawLock('processing_queue'), 'the miss must have deleted nothing');

        $this->assertTrue(SharedState::unlock('processing_queue', $holder), 'the real owner can still release afterwards');
    }

    public function testForceUnlockWithoutTokenOverridesAnyHolder(): void
    {
        $holder = SharedState::lock('boardctl_asset_77', 22200);
        $this->assertNotNull($holder);

        $this->assertTrue(SharedState::unlock('boardctl_asset_77', null), 'unlock(name, null) is the admin/stale-cleanup override and deletes unconditionally');
        $this->assertFalse($this->rawLock('boardctl_asset_77'), 'the force release must remove the key');
        $this->assertFalse(SharedState::unlock('boardctl_asset_77'), 'force-releasing an absent lock reports nothing deleted');
        $this->assertFalse(SharedState::renew('boardctl_asset_77', $holder, 22200), 'the displaced holder cannot resurrect its lock by renewing');

        $this->assertNotNull(SharedState::lock('boardctl_asset_77', 22200), 'the queue may proceed immediately after an admin override');
    }

    // -----------------------------------------------------------------------
    // 3. Expiry recovery — the crashed-holder safety net replacing reapers
    // -----------------------------------------------------------------------

    public function testExpiredLockIsReAcquirableWhenHolderCrashes(): void
    {
        $dead = SharedState::lock('processing_queue', 900);
        $this->assertNotNull($dead);
        $this->assertNull(SharedState::lock('processing_queue', 900));

        $this->redis->fastForward(899);
        $this->assertNull(SharedState::lock('processing_queue', 900), 'inside the TTL the payment queue stays serialized');

        $this->redis->fastForward(2); // t=901: past the 900s deadline
        $recovered = SharedState::lock('processing_queue', 900);
        $this->assertNotNull(
            $recovered,
            'TTL expiry is the payment-queue safety net the GlobalData CAS had to fake with manual stale-reset reapers — a crashed holder must never wedge payments'
        );
        $this->assertNotSame($dead, $recovered, 'the recovery acquire mints a fresh token for the new holder');
    }

    public function testStaleHolderCannotDeleteALockReAcquiredAfterExpiry(): void
    {
        // The exact GlobalData bug class this migration kills: a slow holder
        // finishes AFTER its lock expired and a successor took over. The old
        // `$global->$var = 0` release freed the lock out from under the
        // successor unconditionally; the compare-and-delete refuses instead.
        $slow = SharedState::lock('vps_host_11', 900);
        $this->assertNotNull($slow);

        $this->redis->fastForward(901);
        $successor = SharedState::lock('vps_host_11', 900);
        $this->assertNotNull($successor, 'precondition: the expired lock was re-acquired while the old holder was still "working"');

        $this->assertFalse(SharedState::unlock('vps_host_11', $slow), 'the stale token must be refused at release');
        $this->assertSame($successor, $this->rawLock('vps_host_11'), 'the successor keeps the lock it legitimately took');
        $this->assertFalse(SharedState::renew('vps_host_11', $slow, 900), 'the stale token cannot extend the successor\'s lock either');

        $this->assertTrue(SharedState::unlock('vps_host_11', $successor), 'only the actual owner can release');
    }

    // -----------------------------------------------------------------------
    // 4. Renew — owner-only extension, no resurrection
    // -----------------------------------------------------------------------

    public function testOwnerRenewExtendsTheDeadlineAcrossFastForward(): void
    {
        $holder = SharedState::lock('boardctl_asset_77', 3600);
        $this->assertNotNull($holder);

        $this->redis->fastForward(3599);
        $this->assertTrue(SharedState::renew('boardctl_asset_77', $holder, 3600), 'owner renew must succeed');

        $this->redis->fastForward(1); // exactly the ORIGINAL deadline
        $this->assertSame(
            $holder,
            $this->rawLock('boardctl_asset_77'),
            'the renewed deadline must carry the lock past the one it was acquired with'
        );
        $this->assertNull(SharedState::lock('boardctl_asset_77', 60), 'and the extended lock must still exclude other workers');

        $this->redis->fastForward(3600); // past the renewed window
        $this->assertNotNull(SharedState::lock('boardctl_asset_77', 60), 'renew extends, never makes the lock immortal');
    }

    public function testNonOwnerRenewFailsAndDoesNotExtend(): void
    {
        $holder = SharedState::lock('processing_queue', 900);
        $this->assertNotNull($holder);

        $this->redis->fastForward(890);
        $this->assertFalse(
            SharedState::renew('processing_queue', 'intruder:999:ffffffffffffffff', 900),
            'a foreign token must not be able to extend someone else\'s lock'
        );

        $this->redis->fastForward(11); // t=901: the ORIGINAL deadline governs
        $this->assertFalse($this->rawLock('processing_queue'), 'the refused renew must not have moved the deadline');
        $this->assertNotNull(SharedState::lock('processing_queue', 900), 'the lock frees exactly when only an owner renew could have kept it alive');
    }

    public function testRenewAfterExpiryDoesNotResurrectALostLock(): void
    {
        $holder = SharedState::lock('vps_host_12', 900);
        $this->assertNotNull($holder);

        $this->redis->fastForward(900); // deadline <= clock → evicted
        $this->assertFalse(
            SharedState::renew('vps_host_12', $holder, 900),
            'the owner of an expired lock must not silently reclaim it — its critical section is already over'
        );
        $this->assertFalse($this->rawLock('vps_host_12'), 'a failed renew must never re-create the key');
        $this->assertSame([], $this->redis->allKeys(), 'and must leave no residue in the keyspace');
    }

    // -----------------------------------------------------------------------
    // 5. Cross-"process" token handoff (Events producer → TaskWorker consumer)
    // -----------------------------------------------------------------------

    public function testTokenHandoffSurvivesJsonTaskDispatchAndValueIsRawNotJson(): void
    {
        // Producer side (Events context) takes the lock...
        $producerToken = SharedState::lock('vps_host_11', 900);
        $this->assertNotNull($producerToken);

        // ...and ships the token to the TaskWorker exactly the way
        // AsyncTcpConnection('Text://127.0.0.1:2208') transports it: inside a
        // JSON payload. The token must arrive byte-identical or the consumer's
        // compare-and-delete would never match.
        $wire = json_encode([
            'type' => 'vps_queue_task',
            'args' => ['service_id' => 11, 'lock' => 'vps_host_11', 'token' => $producerToken],
        ]);
        $consumerArgs = json_decode($wire, true)['args'];
        $this->assertSame($producerToken, $consumerArgs['token'], 'the raw string token must survive the JSON round-trip unchanged');

        // Keyspace view: the lock VALUE is the RAW token, never JSON-encoded —
        // the facade docblock pins this exception because Lua compares strings.
        $raw = $this->rawLock('vps_host_11');
        $this->assertSame($producerToken, $raw, 'the stored lock value must equal the token the producer received, character for character');
        $this->assertNotSame(json_encode($producerToken), $raw, 'a JSON-wrapped token would never equal ARGV in the compare-and-delete script');
        $this->assertNull(
            SharedState::get('dc:lock:vps_host_11'),
            'the JSON-decoding get() view cannot read raw lock tokens — lock state is read through the lock primitives, never get()'
        );

        // Consumer side — second SharedState usage against the same Redis,
        // releasing with the handed-off token as if it were its own acquire.
        $this->assertTrue(
            SharedState::unlock('vps_host_11', $consumerArgs['token']),
            'the token handoff must authorize the consumer to release'
        );
        $this->assertFalse($this->rawLock('vps_host_11'), 'after the handoff release the lock is gone');
    }

    // -----------------------------------------------------------------------
    // 6. Frozen production lock families — full-cycle regression matrix
    // -----------------------------------------------------------------------

    /**
     * name => TTL, why from the frozen contract. These four families are every
     * distributed lock the GlobalData→Redis wave replaces; a regression here
     * is a production lockout or a double-run of payments/VPS/boardctl jobs.
     *
     * @return array<string,array{0:string,1:int,2:string}>
     */
    public static function productionLockFamilies(): array
    {
        return [
            'payment processing queue' => [
                'processing_queue',
                900,
                'Events::processing_queue_timer treated a lock older than 900s as abandoned — the Redis TTL replaces that reaper',
            ],
            'vps host lifecycle' => [
                'vps_host_11',
                900,
                'per-VPS lifecycle family (vps_queue_task / async_hyperv / sync_hyperv share it); 900s mirrors the old GlobalData stale-reap window because GetVMList can take ~10min',
            ],
            'boardctl asset job' => [
                'boardctl_asset_77',
                22200,
                '6h runner cap + 10min buffer — the lock must survive hours, never reset mid-run',
            ],
            'queue drain per host' => [
                'queuein:1.2.3.4',
                900,
                'per-host drain whose name is SHARED with the raw Redis LIST — see the no-clobber test; 900s per the never-shorter-than-old-reaper ops rule',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('productionLockFamilies')]
    public function testProductionLockFamilyFullCycle(string $name, int $ttl, string $why): void
    {
        $expectedKey = 'dc:lock:'.$name; // namespace contract, hard-coded on purpose

        // --- acquire + placement ---
        $first = SharedState::lock($name, $ttl);
        $this->assertNotNull($first, "{$why} — uncontended acquire must yield a token");
        $this->assertSame(
            [$expectedKey],
            $this->redis->allKeys(),
            "the lock for '{$name}' must land at exactly {$expectedKey} and nowhere else"
        );
        $this->assertSame($first, $this->rawLock($name), "the value at {$expectedKey} must be the raw token");

        // --- contend ---
        $this->assertNull(SharedState::lock($name, $ttl), "while held, '{$name}' must exclude every other worker");

        // --- renew: foreign refused, owner extends across the original deadline ---
        $this->assertFalse(SharedState::renew($name, 'foreign:'.$name, $ttl), "a foreign renew of '{$name}' must fail");
        $this->redis->fastForward($ttl - 1);
        $this->assertTrue(SharedState::renew($name, $first, $ttl), "owner renew of '{$name}' must succeed");
        $this->redis->fastForward(1); // exactly the original deadline — survived only via the renew
        $this->assertSame($first, $this->rawLock($name), "the renewed {$name} deadline must outlive the original TTL");

        // --- expire-recover: renewed window lapses, lock self-heals ---
        $this->redis->fastForward($ttl); // clock t0+2T > renewed deadline t0+2T-1
        $this->assertFalse($this->rawLock($name), "an abandoned {$name} lock must expire without any manual reset");
        $second = SharedState::lock($name, $ttl);
        $this->assertNotNull($second, "queue progress for '{$name}' depends on an expired lock being re-acquirable");
        $this->assertNotSame($first, $second, "a recovery acquire of '{$name}' must mint a fresh token");

        // --- release + zero-residue keyspace audit ---
        $this->assertSame([$expectedKey], $this->redis->allKeys(), "the recovery lock must still live under {$expectedKey} only");
        $this->assertTrue(SharedState::unlock($name, $second), "owner release of '{$name}' must succeed");
        $this->assertSame([], $this->redis->allKeys(), "release of '{$name}' must leave NO residue in the keyspace");
    }

    // -----------------------------------------------------------------------
    // 7. Family independence + lock-vs-LIST namespace isolation
    // -----------------------------------------------------------------------

    public function testLockNamesFromDifferentFamiliesNeverCollide(): void
    {
        $families = [
            'vps_host_11' => 900,
            'vps_host_12' => 900,
            'queuein:1.2.3.4' => 900,
            'queuein:5.6.7.8' => 900,
        ];

        $tokens = [];
        foreach ($families as $name => $ttl) {
            $token = SharedState::lock($name, $ttl);
            $this->assertNotNull($token, "a free '{$name}' lock must acquire even while sibling family members are held");
            $tokens[$name] = $token;
        }
        $this->assertCount(4, array_unique($tokens), 'distinct lock names must never share a token');

        $keys = $this->redis->allKeys();
        sort($keys);
        $this->assertSame(
            ['dc:lock:queuein:1.2.3.4', 'dc:lock:queuein:5.6.7.8', 'dc:lock:vps_host_11', 'dc:lock:vps_host_12'],
            $keys,
            'each family occupies exactly its own dc:lock: key — host/id/IP suffixes cannot alias'
        );

        foreach ($families as $name => $ttl) {
            $this->assertNull(SharedState::lock($name, $ttl), "'{$name}' must stay held against contenders");
        }

        // Releasing one member must not touch any other.
        $this->assertTrue(SharedState::unlock('vps_host_11', $tokens['vps_host_11']));
        foreach (['vps_host_12', 'queuein:1.2.3.4', 'queuein:5.6.7.8'] as $other) {
            $this->assertSame($tokens[$other], $this->rawLock($other), "'{$other}' must be untouched by the vps_host_11 release");
        }

        // Ownership is per-key: another family's valid token renews nothing here.
        $this->assertFalse(
            SharedState::renew('vps_host_12', $tokens['queuein:1.2.3.4'], 900),
            'a queuein token must not extend a vps_host lock even though both are live, valid tokens'
        );

        // The released member is free again while its peers keep their holders.
        $this->assertNotNull(SharedState::lock('vps_host_11', 900), 'vps_host_11 must be independently re-acquirable');
    }

    public function testLockOperationsNeverClobberTheUnprefixedQueueList(): void
    {
        // Web/queue.php rPushes the REAL drain queue at the raw key
        // 'queuein:1.2.3.4'; the migrated lock keeps the same NAME. Isolation
        // comes solely from the facade's dc:lock: prefix — pin that the two
        // keys never fuse, for the entire lifecycle including the admin path.
        $listKey = 'queuein:1.2.3.4';
        $queueBefore = [
            json_encode(['action' => 'create_vps', 'id' => 77]),
            json_encode(['action' => 'reboot', 'id' => 78]),
        ];
        foreach ($queueBefore as $item) {
            $this->redis->rPush($listKey, $item);
        }

        $holder = SharedState::lock($listKey, 900);
        $this->assertNotNull($holder);
        $this->assertNull(SharedState::lock($listKey, 900));
        $this->assertTrue(SharedState::renew($listKey, $holder, 900));
        $this->assertTrue(SharedState::unlock($listKey), 'admin force release goes through');
        $this->assertNotNull(SharedState::lock($listKey, 900), 're-acquire after force release');
        $this->redis->fastForward(901);
        $this->assertNotNull(SharedState::lock($listKey, 900), 're-acquire after expiry');

        // The LIST survived byte-identical through every lock operation.
        $this->assertSame($queueBefore, $this->redis->lRange($listKey, 0, -1), 'no lock acquire/contend/renew/release/expiry may alter the real queue LIST');

        // And it is STILL a LIST: had a lock's string SET ever landed on this
        // exact key, the type would be string and this GET would succeed.
        try {
            $this->redis->get($listKey);
            $this->fail("{$listKey} must still be a LIST — WRONGTYPE here proves no string lock value ever landed on it");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('WRONGTYPE', $e->getMessage(), 'the raw list key must be type-untouched by the lock facade');
        }

        // Key-space audit: both keys coexist, each in its own namespace.
        $keys = $this->redis->allKeys();
        sort($keys);
        $this->assertSame(
            ['dc:lock:'.$listKey, $listKey], // sorted() order: 'd' < 'q'
            $keys,
            'the lock lives under dc:lock: — NOTHING in the lock lifecycle may touch a key literally equal to the unprefixed list name'
        );

        // Belt and braces: the facade itself refuses to ADDRESS the unprefixed
        // key, so even a typo in a future call site cannot route through it.
        try {
            SharedState::exists($listKey);
            $this->fail('the facade must reject the raw LIST key — it escapes the dc:* namespace contract');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($listKey, $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // 8. B1 gate — the payment reaper must survive the stale-lock deletion
    // -----------------------------------------------------------------------

    /**
     * Resolve one Events.php method's source region, preferring Reflection and
     * falling back to a token-based line scan when the class cannot be loaded
     * in the harness. Both paths hand back the same shape so every B1 assertion
     * is identical whether or not Events reflects — and NEITHER touches a live
     * database: these are pure source-reflection contracts.
     *
     * @return array{exists:bool,reflected:bool,file:?string,start:int,end:?int,isPublic:?bool,isStatic:?bool,source:string}
     */
    private function methodSource(string $method): array
    {
        $file = __DIR__.'/../Applications/Chat/Events.php';
        if ($this->loadEventsClass() && method_exists('Events', $method)) {
            $ref = new \ReflectionMethod('Events', $method);
            $lines = file((string) $ref->getFileName());
            $start = (int) $ref->getStartLine();
            $end = (int) $ref->getEndLine();

            return [
                'exists' => true,
                'reflected' => true,
                'file' => $ref->getFileName(),
                'start' => $start,
                'end' => $end,
                'isPublic' => $ref->isPublic(),
                'isStatic' => $ref->isStatic(),
                'source' => implode('', array_slice($lines, $start - 1, $end - $start + 1)),
            ];
        }

        return $this->extractMethodSourceByScan($file, $method);
    }

    /**
     * Load Events.php against the same fake-Gateway seam the V1 Events suites
     * use (tests/V1TestSupport.php). Returns false rather than throwing when the
     * class cannot be brought up, so callers degrade to the source scan.
     */
    private function loadEventsClass(): bool
    {
        if (class_exists('Events', false)) {
            return true;
        }
        try {
            require_once __DIR__.'/V1TestSupport.php';
        } catch (\Throwable $e) {
            return false;
        }

        return class_exists('Events', false);
    }

    /**
     * Fallback region extraction: signature line to the first 4-space-indented
     * closing brace, i.e. exactly the method body of a class-level function.
     *
     * @return array{exists:bool,reflected:bool,file:?string,start:int,end:?int,isPublic:?bool,isStatic:?bool,source:string}
     */
    private function extractMethodSourceByScan(string $file, string $method): array
    {
        $absent = [
            'exists' => false, 'reflected' => false, 'file' => $file,
            'start' => 0, 'end' => null, 'isPublic' => null, 'isStatic' => null, 'source' => '',
        ];
        if (!is_readable($file)) {
            return $absent;
        }
        $lines = file($file);
        $signature = '/^\s*(public|protected|private)\s+(static\s+)?function\s+'.preg_quote($method, '/').'\s*\(/';

        $start = null;
        $isPublic = null;
        $isStatic = null;
        foreach ($lines as $i => $line) {
            if (preg_match($signature, $line, $match)) {
                $start = $i + 1;
                $isPublic = $match[1] === 'public';
                $isStatic = isset($match[2]) && $match[2] !== '';
                break;
            }
        }
        if ($start === null) {
            return $absent;
        }

        $body = [];
        $end = null;
        $count = count($lines);
        for ($i = $start - 1; $i < $count; $i++) {
            $body[] = $lines[$i];
            if ($i > $start - 1 && preg_match('/^    \}\s*$/', $lines[$i])) {
                $end = $i + 1;
                break;
            }
        }

        return [
            'exists' => true,
            'reflected' => false,
            'file' => $file,
            'start' => $start,
            'end' => $end,
            'isPublic' => $isPublic,
            'isStatic' => $isStatic,
            'source' => implode('', $body),
        ];
    }

    public function testPaymentQueueReaperIsRetainedAndRecoversStuckRows(): void
    {
        $reaper = $this->methodSource('processing_queue_reaper');

        // B1: the payment-critical deletion of the stale-lock reaper branch is
        // only safe while this DB row-recovery path survives, byte-preserved.
        $this->assertTrue(
            $reaper['exists'],
            'Events::processing_queue_reaper() must still exist — it is the only remaining recovery for payment rows left stuck in processing'
        );
        $this->assertStringContainsString(
            'public static function processing_queue_reaper(',
            $reaper['source'],
            'the reaper stays a public static callable — Timer registers it by name'
        );

        if ($reaper['reflected']) {
            $this->assertTrue($reaper['isPublic'], 'reflection: the reaper is still publicly callable');
            $this->assertTrue($reaper['isStatic'], 'reflection: the reaper is still static');
            $this->assertStringEndsWith('Events.php', (string) $reaper['file'], 'reflection: the region is read from Events.php');
            $this->assertGreaterThan(0, $reaper['start'], 'reflection: getStartLine() resolved the reaper');
            $this->assertGreaterThanOrEqual($reaper['start'], (int) $reaper['end'], 'reflection: the reaper spans at least one line');
        }

        // The recovery contract, both stuck windows: reset abandoned 'processing'
        // rows back to 'pending', scoped to recent process_payment entries.
        $this->assertStringContainsString("UPDATE queue_log SET history_new_value='pending'", $reaper['source'], "the reaper must re-queue stuck rows back to 'pending'");
        $this->assertStringContainsString("history_section='process_payment'", $reaper['source'], "scoped to the payment-processing section");
        $this->assertStringContainsString("history_new_value='processing'", $reaper['source'], "targeting rows abandoned mid-flight in 'processing'");
        $this->assertStringContainsString('INTERVAL 15 MINUTE', $reaper['source'], 'the lower stuck threshold (processing for > 15 min) is preserved');
        $this->assertStringContainsString('INTERVAL 6 HOUR', $reaper['source'], 'the upper recent bound (only rows newer than 6 h) is preserved, so history is never mass-replayed');

        // Pin the WINDOW PAIRING, not merely that both intervals are present: the
        // lower bound must be `>= (NOW() - INTERVAL 6 HOUR)` (row newer than 6 h)
        // and the upper bound `< (NOW() - INTERVAL 15 MINUTE)` (row stuck > 15 min).
        // A swapped-window edit (`>= ... 15 MINUTE AND < ... 6 HOUR`) inverts the
        // range into an always-empty dead reaper — or, mis-swapped wider, a
        // mass-replay of history — yet still passes the two presence assertions
        // above. One /s regex anchors both comparisons, in order, on the real
        // literal spacing (whitespace-tolerant; the PHP string-concat between the
        // two lines is spanned by the dotall gap).
        $this->assertMatchesRegularExpression(
            '~>=\s*\(\s*NOW\(\)\s*-\s*INTERVAL\s+6\s+HOUR\s*\).*?<\s*\(\s*NOW\(\)\s*-\s*INTERVAL\s+15\s+MINUTE\s*\)~s',
            $reaper['source'],
            'the reaper window must pair >= with 6 HOUR (recent bound) and < with 15 MINUTE (stuck bound) — swapping them inverts the range into a dead or mass-replaying reaper'
        );
    }

    public function testStaleQueueRowRecoveryIsIndependentOfLockTtl(): void
    {
        // lock TTL expiry releases the MUTEX; only the reaper repairs ABANDONED
        // ROWS — two different failure modes (B1).
        $token = SharedState::lock('processing_queue', 900);
        $this->assertNotNull($token, 'precondition: this process holds the payment mutex');
        $this->assertNull(SharedState::lock('processing_queue', 900), 'inside the TTL the mutex excludes every other worker');

        $this->redis->fastForward(901); // past the 900s deadline
        $this->assertFalse($this->rawLock('processing_queue'), 'the expired lock key is gone — the MUTEX self-heals purely on TTL lapse');
        $this->assertNotNull(SharedState::lock('processing_queue', 900), 'a fresh holder takes the lock with no manual stale-reset anywhere in the path');

        // Independence proof: the reaper repairs rows purely from queue_log DB
        // state, so it neither reads nor needs the lock — the two failure modes
        // are orthogonal and a lock lapse cannot stop row recovery (nor vice versa).
        $reaper = $this->methodSource('processing_queue_reaper');
        $this->assertTrue($reaper['exists'], 'the reaper must be present to guarantee abandoned-row recovery at all');
        $this->assertStringContainsString('history_timestamp', $reaper['source'], 'row recovery keys off the DB row timestamp, not the lock');
        $this->assertStringContainsString('NOW()', $reaper['source'], 'the stuck windows are evaluated server-side on DB time');
        $this->assertStringNotContainsString('SharedState', $reaper['source'], 'the reaper must not consult the Redis lock facade — recovery is lock-independent');
        $this->assertStringNotContainsString('$global', $reaper['source'], 'the reaper must not read the retired GlobalData lock');
        $this->assertStringNotContainsString('dc:lock', $reaper['source'], 'the reaper must never touch the lock key: it repairs rows, not the mutex');
    }

    public function testProcessingQueueLockIsTheOnlyStaleResetMechanism(): void
    {
        $timer = $this->methodSource('processing_queue_timer');
        $this->assertTrue($timer['exists'], 'Events::processing_queue_timer() must still exist');

        // Positive anchor first — without it the negative greps below would pass
        // vacuously on an empty region. The SharedState TTL lock is now the sole
        // staleness mechanism.
        $this->assertStringContainsString(
            "SharedState::lock('processing_queue', 900)",
            $timer['source'],
            'the lock with a real 900s TTL is the replacement for the deleted stale-reset branch'
        );

        // The payment-critical deletion (agent A1) must STAY deleted: the old
        // GlobalData manual stale-reset branch (age>900 → force-reset → cas write)
        // must not reappear in the timer body.
        $this->assertStringNotContainsString('> 900', $timer['source'], "the old `time() - \$lockValue > 900` stale-window comparison must not return");
        $this->assertStringNotContainsString('$global->cas(', $timer['source'], 'the CAS-with-manual-timestamp acquire must not return');
        $this->assertStringNotContainsString('$global->$var', $timer['source'], 'the raw GlobalData lock-variable writes must not return');
        $this->assertStringNotContainsString('$lockValue', $timer['source'], 'the old hand-rolled lock-value local must not return');
        $this->assertStringNotContainsString('force-reset', $timer['source'], "the deleted 'force-resetting' stale branch must not return");
        $this->assertStringNotContainsString('time()', $timer['source'], 'the timer no longer stamps a manual time() — the Redis TTL owns staleness');
    }
}
