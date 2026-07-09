<?php

/**
 * Test seam for the v1 `cmd.*` exec-relay handlers added in WS-revamp Phase 2
 * step 2.3 (docs/PROTOCOL_V1.md §2.2): Events::handleCmdExec / handleCmdStdin /
 * handleCmdOutput / handleCmdExit / handleCmdKill, driven through the public
 * Events::dispatchV1() entry with Flag A ON and an appropriate $_SESSION role.
 *
 * Same Gateway-stub technique as EventsV1RouterTest / EventsV1AuthHelloTest: the
 * shared tests/V1TestSupport.php declares a lightweight fake
 * \GatewayWorker\Lib\Gateway *before* Events.php loads, capturing every reply,
 * close, sendToUid, sendToGroup and answering isUidOnline() from an in-memory
 * online-uid set. The REAL handlers run end to end (role gates, run_id
 * validation, collision guard, CAS registry writes/removes, exit-code
 * pass-through) — nothing is reimplemented here.
 *
 * The shared $global->running registry is backed by the same in-memory
 * GlobalData client the other v1 tests use (extends \GlobalData\Client so the
 * FeatureFlags `instanceof` gate passes; array-backed store + a real CAS).
 */

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the shared fake Gateway seam, then requires FeatureFlags + Events.
    require_once __DIR__.'/V1TestSupport.php';

    /**
     * In-memory GlobalData client with a working CAS, reused for Flag A toggling
     * AND for backing $global->running (the shared run registry the cmd handlers
     * read/write via the same whole-map CAS loop the legacy paths use).
     */
    if (!class_exists('CmdFakeGlobalDataClient')) {
        class CmdFakeGlobalDataClient extends \GlobalData\Client
        {
            /** @var array<string,mixed> */
            public $store = [];

            public function __construct()
            {
            }

            public function __get($key)
            {
                return $this->store[$key] ?? null;
            }

            public function __set($key, $value)
            {
                $this->store[$key] = $value;
            }

            public function __isset($key)
            {
                return isset($this->store[$key]);
            }

            public function __unset($key)
            {
                unset($this->store[$key]);
            }

            /** Whole-map compare-and-swap (strict value equality, like the real client). */
            public function cas($key, $old, $new)
            {
                $current = $this->store[$key] ?? null;
                if ($current === $old) {
                    $this->store[$key] = $new;
                    return true;
                }
                return false;
            }
        }
    }

    /**
     * Tests for the v1 cmd.* handlers (WS-revamp Phase 2 step 2.3). Scope is
     * strictly the NEW step-2.3 code.
     */
    class EventsV1CmdTest extends TestCase
    {
        /** @var CmdFakeGlobalDataClient */
        private $global;

        protected function setUp(): void
        {
            $this->resetState();
        }

        protected function tearDown(): void
        {
            $this->resetState();
        }

        private function resetState(): void
        {
            \GatewayWorker\Lib\Gateway::reset();
            $_SESSION = [];
            \Events::$db = null;
            unset($GLOBALS['global']);

            $ref = new ReflectionClass(FeatureFlags::class);
            $prop = $ref->getProperty('client');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        // ------------------------------------------------------------------
        // Fixtures / helpers
        // ------------------------------------------------------------------

        /** Inject the in-memory GlobalData client and flip Flag A ON. */
        private function flagAOn(): CmdFakeGlobalDataClient
        {
            $client = new CmdFakeGlobalDataClient();
            $client->store[FeatureFlags::VAR_NEW_HANDLING] = 1;
            $client->store['running'] = [];
            $GLOBALS['global'] = $client;
            $this->global = $client;
            return $client;
        }

        /** Mark a uid online for isUidOnline(). */
        private function online(string $uid): void
        {
            \GatewayWorker\Lib\Gateway::$onlineUids[$uid] = true;
        }

        /** Simulate an authenticated admin session with the given uid. */
        private function asAdmin($uid = 'admin-42'): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'admin', 'uid' => $uid];
        }

        /** Simulate an authenticated host session bound to the given host uid. */
        private function asHost(string $uid): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'host', 'uid' => $uid];
        }

        /** Drive a cmd.* op through the public dispatchV1 entry. */
        private function dispatch(string $op, array $data, int $client = 1, string $id = 'req-1'): void
        {
            \Events::dispatchV1($client, [
                'v' => 1, 'id' => $id, 'op' => $op, 'ts' => 1719700000, 'data' => $data
            ]);
        }

        private function sent(): array
        {
            return \GatewayWorker\Lib\Gateway::$sent;
        }

        private function sentToUid(): array
        {
            return \GatewayWorker\Lib\Gateway::$sentToUid;
        }

        private function sentToGroup(): array
        {
            return \GatewayWorker\Lib\Gateway::$sentToGroup;
        }

        private function closed(): array
        {
            return \GatewayWorker\Lib\Gateway::$closed;
        }

        private function running(): array
        {
            return $this->global->store['running'] ?? [];
        }

        /** Decode the single client reply; assert exactly one was sent. */
        private function singleReply(): array
        {
            $sent = $this->sent();
            $this->assertCount(1, $sent, 'expected exactly one client reply on the wire');
            $decoded = json_decode($sent[0]['message'], true);
            $this->assertIsArray($decoded);
            return $decoded;
        }

        /** Assert the single client reply is ok:false with $code, and nothing was relayed. */
        private function assertErrorReply(string $code): array
        {
            $reply = $this->singleReply();
            $this->assertFalse($reply['ok'], "reply must be ok:false for {$code}");
            $this->assertSame($code, $reply['error']['code']);
            return $reply;
        }

        // ================================================================
        // 1. cmd.exec happy path (admin)
        // ================================================================

        public function testCmdExecHappyPathAdmin(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-7');
            $this->online('vps1234');

            $this->dispatch('cmd.exec', [
                'run_id' => 'run-abc',
                'host' => 1234,
                'command' => 'uptime'
                // interact/rows/cols/update_after omitted -> defaults
            ]);

            // (a) A v1 cmd.exec envelope relayed to the host uid.
            $this->assertCount(1, $this->sentToUid(), 'exactly one cmd.exec relay to a host uid');
            $relayMsg = $this->sentToUid()[0];
            $this->assertSame('vps1234', $relayMsg['uid']);
            $relay = json_decode($relayMsg['message'], true);
            $this->assertSame(1, $relay['v']);
            $this->assertSame('cmd.exec', $relay['op']);
            $this->assertArrayNotHasKey('re', $relay, 'a hub-originated relay is a request, not a reply');
            $this->assertSame('run-abc', $relay['data']['run_id']);
            $this->assertSame('uptime', $relay['data']['command']);
            $this->assertFalse($relay['data']['interact']);
            // CORRECTED §2.2 defaults: rows=24 (height), cols=80 (width) — NOT the
            // legacy swapped run_command defaults.
            $this->assertSame(24, $relay['data']['rows']);
            $this->assertSame(80, $relay['data']['cols']);
            $this->assertFalse($relay['data']['update_after']);

            // (b) The run is registered with host/for and the legacy `id` alias.
            $running = $this->running();
            $this->assertArrayHasKey('run-abc', $running);
            $entry = $running['run-abc'];
            $this->assertSame('vps1234', $entry['host']);
            $this->assertSame('admin-7', $entry['for'], 'for = originating admin uid from session');
            $this->assertSame('run-abc', $entry['id'], 'legacy `id` alias must mirror run_id');
            $this->assertSame('run-abc', $entry['run_id']);

            // (c) The admin got an ok:true ack with the run_id.
            $reply = $this->singleReply();
            $this->assertTrue($reply['ok']);
            $this->assertSame('req-1', $reply['re']);
            $this->assertSame('run-abc', $reply['data']['run_id']);
            $this->assertSame([], $this->closed());
        }

        public function testCmdExecCarriesExplicitFields(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->online('vps9');

            $this->dispatch('cmd.exec', [
                'run_id' => 'run-2',
                'host' => 'vps9', // "vps<id>" uid form accepted
                'command' => 'top',
                'interact' => true,
                'rows' => 50,
                'cols' => 200,
                'update_after' => true
            ]);

            $relay = json_decode($this->sentToUid()[0]['message'], true);
            $this->assertSame('vps9', $this->sentToUid()[0]['uid']);
            $this->assertTrue($relay['data']['interact']);
            $this->assertSame(50, $relay['data']['rows']);
            $this->assertSame(200, $relay['data']['cols']);
            $this->assertTrue($relay['data']['update_after']);
        }

        // ================================================================
        // 2. cmd.exec rejections
        // ================================================================

        public function testCmdExecNonAdminForbidden(): void
        {
            $this->flagAOn();
            $this->asHost('vps5');
            $this->online('vps1234');

            $this->dispatch('cmd.exec', ['run_id' => 'r', 'host' => 1234, 'command' => 'ls']);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->sentToUid(), 'no relay on forbidden');
            $this->assertArrayNotHasKey('r', $this->running(), 'no registry write on forbidden');
        }

        public function testCmdExecMissingRunIdBadRequest(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->online('vps1');

            $this->dispatch('cmd.exec', ['run_id' => '  ', 'host' => 1, 'command' => 'ls']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->sentToUid());
            $this->assertSame([], $this->running());
        }

        public function testCmdExecMissingCommandBadRequest(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->online('vps1');

            $this->dispatch('cmd.exec', ['run_id' => 'r', 'host' => 1]);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->sentToUid());
            $this->assertSame([], $this->running());
        }

        public function testCmdExecHostOfflineNotOnline(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            // vps1234 NOT marked online.

            $this->dispatch('cmd.exec', ['run_id' => 'r', 'host' => 1234, 'command' => 'ls']);

            $this->assertErrorReply('not_online');
            $this->assertCount(0, $this->sentToUid());
            $this->assertArrayNotHasKey('r', $this->running(), 'no registry write when host offline');
        }

        public function testCmdExecRunIdCollisionRejectedAndDoesNotOverwrite(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-new');
            $this->online('vps1234');

            // Pre-existing in-flight entry under the same run_id, owned by a
            // different host / for target.
            $existing = [
                'run_id' => 'dup', 'id' => 'dup', 'host' => 'vps777',
                'for' => 'admin-original', 'command' => 'sleep 999',
                'interact' => false, 'update_after' => false,
                'rows' => 24, 'cols' => 80, 'started' => 111, 'v' => 1
            ];
            $this->global->store['running'] = ['dup' => $existing];

            $this->dispatch('cmd.exec', ['run_id' => 'dup', 'host' => 1234, 'command' => 'whoami']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->sentToUid(), 'collision must NOT relay a cmd.exec to the host');
            // Pre-existing entry intact (not overwritten).
            $this->assertSame($existing, $this->running()['dup'], 'existing run entry must be untouched');
        }

        // ================================================================
        // 3. cmd.stdin
        // ================================================================

        public function testCmdStdinRelaysToHost(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->global->store['running'] = ['run-x' => [
                'run_id' => 'run-x', 'id' => 'run-x', 'host' => 'vps3',
                'for' => 'admin-1', 'command' => 'cat', 'interact' => true,
                'update_after' => false, 'rows' => 24, 'cols' => 80,
                'started' => 1, 'v' => 1
            ]];

            $this->dispatch('cmd.stdin', ['run_id' => 'run-x', 'data' => "hello\n"]);

            $this->assertCount(1, $this->sentToUid());
            $this->assertSame('vps3', $this->sentToUid()[0]['uid']);
            $relay = json_decode($this->sentToUid()[0]['message'], true);
            $this->assertSame('cmd.stdin', $relay['op']);
            $this->assertSame('run-x', $relay['data']['run_id']);
            $this->assertSame("hello\n", $relay['data']['data']);
            $this->assertCount(0, $this->sent(), 'cmd.stdin has no client reply on success');
        }

        public function testCmdStdinUnknownRunIdSilentlyDropped(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            // registry empty

            $this->dispatch('cmd.stdin', ['run_id' => 'nope', 'data' => 'x']);

            $this->assertCount(0, $this->sentToUid(), 'unknown run_id must relay nothing');
            $this->assertCount(0, $this->sent(), 'unknown run_id is silent (no error reply)');
            $this->assertCount(0, $this->closed());
        }

        public function testCmdStdinNonAdminForbidden(): void
        {
            $this->flagAOn();
            $this->asHost('vps3');
            $this->global->store['running'] = ['run-x' => [
                'run_id' => 'run-x', 'id' => 'run-x', 'host' => 'vps3',
                'for' => 'admin-1', 'command' => 'cat', 'interact' => true,
                'update_after' => false, 'rows' => 24, 'cols' => 80, 'started' => 1, 'v' => 1
            ]];

            $this->dispatch('cmd.stdin', ['run_id' => 'run-x', 'data' => 'x']);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->sentToUid());
        }

        // ================================================================
        // 4. cmd.output
        // ================================================================

        private function seedRun(string $runId, string $host, $for): void
        {
            $this->global->store['running'] = [$runId => [
                'run_id' => $runId, 'id' => $runId, 'host' => $host,
                'for' => $for, 'command' => 'x', 'interact' => false,
                'update_after' => false, 'rows' => 24, 'cols' => 80,
                'started' => 1, 'v' => 1
            ]];
        }

        public function testCmdOutputOwningHostRelaysToUidTarget(): void
        {
            $this->flagAOn();
            $this->asHost('vps3');
            $this->seedRun('run-o', 'vps3', 'admin-9');

            $this->dispatch('cmd.output', ['run_id' => 'run-o', 'stream' => 'stdout', 'data' => 'line1']);

            $this->assertCount(1, $this->sentToUid());
            $this->assertCount(0, $this->sentToGroup());
            $this->assertSame('admin-9', $this->sentToUid()[0]['uid']);
            $relay = json_decode($this->sentToUid()[0]['message'], true);
            $this->assertSame('cmd.output', $relay['op']);
            $this->assertSame('run-o', $relay['data']['run_id']);
            $this->assertSame('stdout', $relay['data']['stream']);
            $this->assertSame('line1', $relay['data']['data']);
            $this->assertCount(0, $this->sent(), 'cmd.output has no reply on success');
        }

        public function testCmdOutputOwningHostRelaysToGroupTarget(): void
        {
            $this->flagAOn();
            $this->asHost('vps3');
            $this->seedRun('run-g', 'vps3', '#noc'); // #group delivery target

            $this->dispatch('cmd.output', ['run_id' => 'run-g', 'stream' => 'stderr', 'data' => 'err']);

            $this->assertCount(1, $this->sentToGroup());
            $this->assertCount(0, $this->sentToUid());
            $this->assertSame('#noc', $this->sentToGroup()[0]['group']);
            $relay = json_decode($this->sentToGroup()[0]['message'], true);
            $this->assertSame('stderr', $relay['data']['stream']);
            $this->assertSame('err', $relay['data']['data']);
        }

        public function testCmdOutputNonOwningHostForbidden(): void
        {
            $this->flagAOn();
            $this->asHost('vps99'); // sender is NOT the run's host (vps3)
            $this->seedRun('run-o', 'vps3', 'admin-9');

            $this->dispatch('cmd.output', ['run_id' => 'run-o', 'stream' => 'stdout', 'data' => 'x']);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->sentToUid());
            $this->assertCount(0, $this->sentToGroup());
        }

        public function testCmdOutputNonHostRoleForbidden(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-9'); // admin role, not host
            $this->seedRun('run-o', 'vps3', 'admin-9');

            $this->dispatch('cmd.output', ['run_id' => 'run-o', 'stream' => 'stdout', 'data' => 'x']);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->sentToUid());
        }

        // ================================================================
        // 5. cmd.exit — EXIT-CODE INVARIANT
        // ================================================================

        public function testCmdExitNormalCodeZeroVerbatimAndRemovesRun(): void
        {
            $this->flagAOn();
            $this->asHost('vps3');
            $this->seedRun('run-e', 'vps3', 'admin-9');

            $this->dispatch('cmd.exit', ['run_id' => 'run-e', 'code' => 0, 'term' => null]);

            $relay = json_decode($this->sentToUid()[0]['message'], true);
            $this->assertSame('cmd.exit', $relay['op']);
            // 0 MUST survive verbatim — not dropped, not defaulted, not null.
            $this->assertArrayHasKey('code', $relay['data']);
            $this->assertSame(0, $relay['data']['code']);
            $this->assertNull($relay['data']['term']);
            $this->assertArrayHasKey('term', $relay['data']);

            // Run removed from the registry after exit.
            $this->assertArrayNotHasKey('run-e', $this->running());
            $this->assertCount(0, $this->sent(), 'cmd.exit has no client reply on success');
        }

        public function testCmdExitCodeOneVerbatim(): void
        {
            $this->flagAOn();
            $this->asHost('vps3');
            $this->seedRun('run-e', 'vps3', 'admin-9');

            $this->dispatch('cmd.exit', ['run_id' => 'run-e', 'code' => 1, 'term' => null]);

            $relay = json_decode($this->sentToUid()[0]['message'], true);
            $this->assertSame(1, $relay['data']['code']);
            $this->assertNull($relay['data']['term']);
        }

        public function testCmdExitSignalTerminationVerbatim(): void
        {
            $this->flagAOn();
            $this->asHost('vps3');
            $this->seedRun('run-e', 'vps3', 'admin-9');

            $this->dispatch('cmd.exit', ['run_id' => 'run-e', 'code' => null, 'term' => 9]);

            $relay = json_decode($this->sentToUid()[0]['message'], true);
            $this->assertNull($relay['data']['code']);
            $this->assertSame(9, $relay['data']['term']);
            $this->assertArrayNotHasKey('run-e', $this->running());
        }

        public function testCmdExitCarriesOptionalStdoutStderr(): void
        {
            $this->flagAOn();
            $this->asHost('vps3');
            $this->seedRun('run-e', 'vps3', 'admin-9');

            $this->dispatch('cmd.exit', [
                'run_id' => 'run-e', 'code' => 0, 'term' => null,
                'stdout' => 'final out', 'stderr' => 'final err'
            ]);

            $relay = json_decode($this->sentToUid()[0]['message'], true);
            $this->assertSame('final out', $relay['data']['stdout']);
            $this->assertSame('final err', $relay['data']['stderr']);
        }

        public function testCmdExitToGroupTarget(): void
        {
            $this->flagAOn();
            $this->asHost('vps3');
            $this->seedRun('run-e', 'vps3', '#noc');

            $this->dispatch('cmd.exit', ['run_id' => 'run-e', 'code' => 0, 'term' => null]);

            $this->assertCount(1, $this->sentToGroup());
            $this->assertSame('#noc', $this->sentToGroup()[0]['group']);
            $this->assertArrayNotHasKey('run-e', $this->running());
        }

        public function testCmdExitNonOwningHostForbidden(): void
        {
            $this->flagAOn();
            $this->asHost('vps99');
            $this->seedRun('run-e', 'vps3', 'admin-9');

            $this->dispatch('cmd.exit', ['run_id' => 'run-e', 'code' => 0, 'term' => null]);

            $this->assertErrorReply('forbidden');
            $this->assertArrayHasKey('run-e', $this->running(), 'forbidden must not remove the run');
        }

        public function testCmdExitNonHostRoleForbidden(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-9');
            $this->seedRun('run-e', 'vps3', 'admin-9');

            $this->dispatch('cmd.exit', ['run_id' => 'run-e', 'code' => 0, 'term' => null]);

            $this->assertErrorReply('forbidden');
        }

        // ================================================================
        // 6. cmd.kill
        // ================================================================

        public function testCmdKillRelaysAndKeepsRegistryEntry(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->seedRun('run-k', 'vps3', 'admin-9');

            $this->dispatch('cmd.kill', ['run_id' => 'run-k']);

            $this->assertCount(1, $this->sentToUid());
            $this->assertSame('vps3', $this->sentToUid()[0]['uid']);
            $relay = json_decode($this->sentToUid()[0]['message'], true);
            $this->assertSame('cmd.kill', $relay['op']);
            $this->assertSame('run-k', $relay['data']['run_id']);

            // Entry DELIBERATELY kept — host's cmd.exit performs the cleanup.
            $this->assertArrayHasKey('run-k', $this->running(), 'cmd.kill must NOT remove the run entry');
        }

        public function testCmdKillNonAdminForbidden(): void
        {
            $this->flagAOn();
            $this->asHost('vps3');
            $this->seedRun('run-k', 'vps3', 'admin-9');

            $this->dispatch('cmd.kill', ['run_id' => 'run-k']);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->sentToUid());
            $this->assertArrayHasKey('run-k', $this->running());
        }

        // ================================================================
        // 7. Dormancy
        // ================================================================

        public function testCmdExecDormantWhenFlagAOff(): void
        {
            // Flag A OFF: client present but ws_new_handling unset.
            $client = new CmdFakeGlobalDataClient();
            $client->store['running'] = [];
            $GLOBALS['global'] = $client;
            $this->global = $client;
            $this->asAdmin();
            $this->online('vps1234');

            $this->dispatch('cmd.exec', ['run_id' => 'r', 'host' => 1234, 'command' => 'ls']);

            $this->assertCount(0, $this->sent(), 'Flag A OFF: cmd.exec produces no reply');
            $this->assertCount(0, $this->sentToUid(), 'Flag A OFF: cmd.exec produces no relay');
            $this->assertSame([], $this->running(), 'Flag A OFF: cmd.exec writes nothing');
        }

        public function testCmdExecUnauthedRepliesAuthRequiredAndCloses(): void
        {
            $this->flagAOn();
            // NOT v1-authed: no $_SESSION['v1_authed'].
            $_SESSION = [];
            $this->online('vps1234');

            $this->dispatch('cmd.exec', ['run_id' => 'r', 'host' => 1234, 'command' => 'ls'], 33);

            $reply = $this->singleReply();
            $this->assertFalse($reply['ok']);
            $this->assertSame('auth_required', $reply['error']['code']);
            $this->assertContains(33, $this->closed(), 'unauthed cmd op must close the connection');
            $this->assertCount(0, $this->sentToUid());
            $this->assertSame([], $this->running());
        }

        // ================================================================
        // 8. Legacy unaffected — shared registry coexistence
        // ================================================================

        public function testLegacyMd5KeyedEntryCoexistsWithV1Exec(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-7');
            $this->online('vps1234');

            // Seed a legacy-style md5-keyed run entry (as run_command writes).
            $legacyKey = md5('service httpd restart');
            $legacyEntry = [
                'type' => 'run', 'command' => 'service httpd restart', 'id' => $legacyKey,
                'interact' => false, 'update_after' => false, 'host' => 'vps1234',
                'rows' => 80, 'cols' => 24, 'for' => 'admin-legacy'
            ];
            $this->global->store['running'] = [$legacyKey => $legacyEntry];

            // Run a v1 cmd.exec — different (uuid-style) key.
            $this->dispatch('cmd.exec', ['run_id' => 'v1-run', 'host' => 1234, 'command' => 'uptime']);

            $reply = $this->singleReply();
            $this->assertTrue($reply['ok']);

            $running = $this->running();
            // Both entries present; legacy entry byte-identical (CAS coexistence).
            $this->assertArrayHasKey($legacyKey, $running, 'legacy md5-keyed entry must survive a v1 cmd.exec');
            $this->assertSame($legacyEntry, $running[$legacyKey], 'legacy entry must be untouched');
            $this->assertArrayHasKey('v1-run', $running, 'v1 run must be registered alongside legacy');
        }

        /** A v1 cmd.exit must remove only its own run, leaving a legacy entry intact. */
        public function testCmdExitRemovesOnlyItsRunLeavingLegacyEntry(): void
        {
            $this->flagAOn();
            $this->asHost('vps3');

            $legacyKey = md5('legacy cmd');
            $legacyEntry = ['type' => 'run', 'id' => $legacyKey, 'host' => 'vps3', 'for' => 'admin-l', 'command' => 'legacy cmd'];
            $this->global->store['running'] = [
                $legacyKey => $legacyEntry,
                'run-e' => [
                    'run_id' => 'run-e', 'id' => 'run-e', 'host' => 'vps3', 'for' => 'admin-9',
                    'command' => 'x', 'interact' => false, 'update_after' => false,
                    'rows' => 24, 'cols' => 80, 'started' => 1, 'v' => 1
                ]
            ];

            $this->dispatch('cmd.exit', ['run_id' => 'run-e', 'code' => 0, 'term' => null]);

            $running = $this->running();
            $this->assertArrayNotHasKey('run-e', $running, 'v1 run removed on exit');
            $this->assertArrayHasKey($legacyKey, $running, 'legacy entry must survive v1 cmd.exit CAS remove');
            $this->assertSame($legacyEntry, $running[$legacyKey]);
        }
    }
}
