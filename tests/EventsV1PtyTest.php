<?php

/**
 * Test seam for the v1 `pty.*` hub-side relay handlers added in WS-revamp Phase 2
 * step 2.4 (docs/PROTOCOL_V1.md §2.3 + §5): Events::handlePtyOpen /
 * handlePtyData / handlePtyResize / handlePtyClose (+ the private ptyAudit),
 * driven through the public Events::dispatchV1() entry with Flag A ON and an
 * appropriate $_SESSION role.
 *
 * Same Gateway-stub technique as EventsV1CmdTest / EventsV1RouterTest: the shared
 * tests/V1TestSupport.php declares a lightweight fake \GatewayWorker\Lib\Gateway
 * *before* Events.php loads, capturing every reply, close, sendToUid, sendToGroup
 * and answering isUidOnline() from an in-memory online-uid set. The REAL handlers
 * run end to end (admin/party gates, pty_id validation, shell-scope elevation
 * gate, collision guard, CAS registry writes/removes, base64 pass-through) —
 * nothing is reimplemented here.
 *
 * The pty session state lives in the SEPARATE $global->ptys registry (decoupled
 * from the cmd $global->running registry), backed by the same in-memory
 * GlobalData client the other v1 tests use (extends \GlobalData\Client so the
 * FeatureFlags `instanceof` gate passes; array-backed store + a real CAS + the
 * `add(key,default)` init-if-absent the pty handler calls).
 */

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the shared fake Gateway seam, then requires FeatureFlags + Events.
    require_once __DIR__.'/V1TestSupport.php';

    /**
     * In-memory GlobalData client with a working CAS + add(), reused for Flag A
     * toggling AND for backing $global->ptys (the pty session registry the pty
     * handlers read/write via the same whole-map CAS loop the cmd path uses).
     */
    if (!class_exists('PtyFakeGlobalDataClient')) {
        class PtyFakeGlobalDataClient extends \GlobalData\Client
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

            /**
             * Init-if-absent, matching the real GlobalData add() semantics the
             * pty handler relies on ($global->add('ptys', [])): only sets the key
             * when it does not already exist, so an existing registry survives.
             */
            public function add($key, $value)
            {
                if (!array_key_exists($key, $this->store)) {
                    $this->store[$key] = $value;
                }
                return true;
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
     * Tests for the v1 pty.* handlers (WS-revamp Phase 2 step 2.4). Scope is
     * strictly the NEW step-2.4 code.
     */
    class EventsV1PtyTest extends TestCase
    {
        /** @var PtyFakeGlobalDataClient */
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
        private function flagAOn(): PtyFakeGlobalDataClient
        {
            $client = new PtyFakeGlobalDataClient();
            $client->store[FeatureFlags::VAR_NEW_HANDLING] = 1;
            $client->store['ptys'] = [];
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
        private function asAdmin($uid = 'admin-42', $name = 'Root Admin'): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'admin', 'uid' => $uid, 'name' => $name];
        }

        /** Simulate an authenticated host session bound to the given host uid. */
        private function asHost(string $uid): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'host', 'uid' => $uid];
        }

        /** Drive a pty.* op through the public dispatchV1 entry. */
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

        private function closed(): array
        {
            return \GatewayWorker\Lib\Gateway::$closed;
        }

        private function ptys(): array
        {
            return $this->global->store['ptys'] ?? [];
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

        /** Assert the single client reply is ok:false with $code. */
        private function assertErrorReply(string $code): array
        {
            $reply = $this->singleReply();
            $this->assertFalse($reply['ok'], "reply must be ok:false for {$code}");
            $this->assertSame($code, $reply['error']['code']);
            return $reply;
        }

        /** Seed a registered pty session (owning admin 'for', allocated 'host'). */
        private function seedPty(string $ptyId, string $host, string $for, array $overrides = []): void
        {
            $this->global->store['ptys'] = [$ptyId => array_merge([
                'pty_id' => $ptyId,
                'host' => $host,
                'for' => $for,
                'scope' => 'command',
                'command' => 'top',
                'cols' => 80,
                'rows' => 24,
                'started' => 1719700000
            ], $overrides)];
        }

        // ================================================================
        // 1. pty.open command-scope happy path (admin)
        // ================================================================

        public function testPtyOpenCommandScopeHappyPathAdmin(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-7', 'Alice');
            $this->online('vps1234');

            $this->dispatch('pty.open', [
                'pty_id' => 'pty-abc',
                'host' => 1234,
                'scope' => 'command',
                'command' => 'htop',
                'env' => ['LD_PRELOAD' => '/evil.so', 'PATH' => '/attacker'] // must NOT be relayed
                // cols/rows omitted -> defaults 80/24
            ]);

            // (a) A v1 pty.open envelope relayed to the host uid, correct fields,
            //     default cols/rows, and NO client env passed through.
            $this->assertCount(1, $this->sentToUid(), 'exactly one pty.open relay to a host uid');
            $relayMsg = $this->sentToUid()[0];
            $this->assertSame('vps1234', $relayMsg['uid']);
            $relay = json_decode($relayMsg['message'], true);
            $this->assertSame(1, $relay['v']);
            $this->assertSame('pty.open', $relay['op']);
            $this->assertArrayNotHasKey('re', $relay, 'a hub-originated relay is a request, not a reply');
            $this->assertSame('pty-abc', $relay['data']['pty_id']);
            $this->assertSame('command', $relay['data']['scope']);
            $this->assertSame('htop', $relay['data']['command']);
            $this->assertSame(80, $relay['data']['cols'], 'cols default 80 (width)');
            $this->assertSame(24, $relay['data']['rows'], 'rows default 24 (height)');
            $this->assertArrayNotHasKey('env', $relay['data'], 'client env must never be relayed to the host');

            // (b) The pty is registered with host/for/scope/command/cols/rows/started.
            $ptys = $this->ptys();
            $this->assertArrayHasKey('pty-abc', $ptys);
            $entry = $ptys['pty-abc'];
            $this->assertSame('vps1234', $entry['host']);
            $this->assertSame('admin-7', $entry['for'], 'for = originating admin uid from session');
            $this->assertSame('command', $entry['scope']);
            $this->assertSame('htop', $entry['command']);
            $this->assertSame(80, $entry['cols']);
            $this->assertSame(24, $entry['rows']);
            $this->assertArrayHasKey('started', $entry);
            $this->assertIsInt($entry['started']);

            // (c) The admin got an ok:true ack with the pty_id.
            $reply = $this->singleReply();
            $this->assertTrue($reply['ok']);
            $this->assertSame('req-1', $reply['re']);
            $this->assertSame('pty-abc', $reply['data']['pty_id']);
            $this->assertSame([], $this->closed());
        }

        /**
         * (d) A structured pty_audit line is emitted on open. safeEcho writes to
         * Worker::$outputStream (pointed at /dev/null by V1TestSupport). We
         * temporarily redirect it to a temp file so we CAN assert the audit line
         * — proving the §5 audit ran to completion with correct attribution.
         */
        public function testPtyOpenEmitsStructuredAuditLine(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-7', 'Alice');
            $this->online('vps1234');

            $captured = $this->captureWorkerOutput(function () {
                $this->dispatch('pty.open', [
                    'pty_id' => 'pty-audit', 'host' => 1234,
                    'scope' => 'command', 'command' => 'htop'
                ]);
            });

            $this->assertStringContainsString('pty_audit ', $captured, 'a tagged pty_audit line must be emitted');
            // Parse the JSON payload after the "pty_audit " prefix.
            $line = $this->firstAuditLine($captured);
            $this->assertNotNull($line, 'pty_audit line must be present and JSON-decodable');
            $this->assertSame('open', $line['event']);
            $this->assertSame('pty-audit', $line['pty_id']);
            $this->assertSame('admin-7', $line['who']);
            $this->assertSame('Alice', $line['who_name']);
            $this->assertSame('vps1234', $line['host']);
            $this->assertSame('command', $line['scope']);
            $this->assertSame('htop', $line['command']);
            $this->assertArrayHasKey('ts', $line);
        }

        // ================================================================
        // 2. shell-scope forbidden by default; allowed with elevation marker
        // ================================================================

        public function testPtyOpenShellScopeForbiddenByDefault(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-7'); // no pty_shell marker
            $this->online('vps1234');

            $captured = $this->captureWorkerOutput(function () {
                $this->dispatch('pty.open', [
                    'pty_id' => 'pty-sh', 'host' => 1234, 'scope' => 'shell'
                ]);
            });

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->sentToUid(), 'no relay to host on shell-scope deny');
            $this->assertArrayNotHasKey('pty-sh', $this->ptys(), 'no registry entry on shell-scope deny');

            // open_denied audit (best-effort assertion).
            $this->assertStringContainsString('pty_audit ', $captured);
            $line = $this->firstAuditLine($captured);
            $this->assertNotNull($line);
            $this->assertSame('open_denied', $line['event']);
            $this->assertSame('shell', $line['scope']);
        }

        /**
         * Setting the elevation marker $_SESSION['pty_shell']=true flips shell
         * scope to ALLOWED — proving the gate is the marker, not a hardcoded
         * deny. Documents the intended elevation path (§5).
         */
        public function testPtyOpenShellScopeAllowedWithElevationMarker(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-7');
            $_SESSION['pty_shell'] = true;
            $this->online('vps1234');

            $this->dispatch('pty.open', ['pty_id' => 'pty-sh', 'host' => 1234, 'scope' => 'shell']);

            $reply = $this->singleReply();
            $this->assertTrue($reply['ok'], 'shell scope allowed once pty_shell marker is set');
            $this->assertSame('pty-sh', $reply['data']['pty_id']);

            $this->assertCount(1, $this->sentToUid(), 'shell-scope relay reaches the host when elevated');
            $relay = json_decode($this->sentToUid()[0]['message'], true);
            $this->assertSame('shell', $relay['data']['scope']);
            $this->assertArrayNotHasKey('command', $relay['data'], 'shell scope relays no command');

            $ptys = $this->ptys();
            $this->assertArrayHasKey('pty-sh', $ptys);
            $this->assertSame('shell', $ptys['pty-sh']['scope']);
        }

        // ================================================================
        // 3. pty.open rejections
        // ================================================================

        public function testPtyOpenNonAdminForbidden(): void
        {
            $this->flagAOn();
            $this->asHost('vps5');
            $this->online('vps1234');

            $this->dispatch('pty.open', ['pty_id' => 'p', 'host' => 1234, 'command' => 'ls']);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->sentToUid(), 'no relay on forbidden');
            $this->assertArrayNotHasKey('p', $this->ptys(), 'no registry write on forbidden');
        }

        public function testPtyOpenMissingPtyIdBadRequest(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->online('vps1');

            $this->dispatch('pty.open', ['pty_id' => '  ', 'host' => 1, 'command' => 'ls']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->sentToUid());
            $this->assertSame([], $this->ptys());
        }

        public function testPtyOpenCommandScopeMissingCommandBadRequest(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->online('vps1');

            // scope defaults to command; empty command must be rejected.
            $this->dispatch('pty.open', ['pty_id' => 'p', 'host' => 1, 'command' => '']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->sentToUid());
            $this->assertSame([], $this->ptys());
        }

        public function testPtyOpenPtyIdCollisionRejectedAndDoesNotOverwrite(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-new');
            $this->online('vps1234');

            $existing = [
                'pty_id' => 'dup', 'host' => 'vps777', 'for' => 'admin-original',
                'scope' => 'command', 'command' => 'sleep 999',
                'cols' => 80, 'rows' => 24, 'started' => 111
            ];
            $this->global->store['ptys'] = ['dup' => $existing];

            $this->dispatch('pty.open', ['pty_id' => 'dup', 'host' => 1234, 'command' => 'whoami']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->sentToUid(), 'collision must NOT relay a pty.open to the host');
            $this->assertSame($existing, $this->ptys()['dup'], 'existing pty entry must be untouched');
        }

        public function testPtyOpenHostOfflineNotOnline(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            // vps1234 NOT marked online.

            $this->dispatch('pty.open', ['pty_id' => 'p', 'host' => 1234, 'command' => 'ls']);

            $this->assertErrorReply('not_online');
            $this->assertCount(0, $this->sentToUid());
            $this->assertArrayNotHasKey('p', $this->ptys(), 'no registry write when host offline');
        }

        // ================================================================
        // 4. pty.data duplex + party gating
        // ================================================================

        public function testPtyDataAdminToHostBase64Verbatim(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-9');
            $this->seedPty('pty-d', 'vps3', 'admin-9');

            // A base64 string with padding + non-alnum chars; must survive byte-identical.
            $b64 = base64_encode("\x00\x01\xffhello\n\t");
            $this->dispatch('pty.data', ['pty_id' => 'pty-d', 'data' => $b64]);

            $this->assertCount(1, $this->sentToUid());
            $this->assertSame('vps3', $this->sentToUid()[0]['uid'], 'admin-side frame relays to the host');
            $relay = json_decode($this->sentToUid()[0]['message'], true);
            $this->assertSame('pty.data', $relay['op']);
            $this->assertSame('pty-d', $relay['data']['pty_id']);
            // BYTE-IDENTICAL base64 pass-through (no hub decode/re-encode).
            $this->assertSame($b64, $relay['data']['data'], 'base64 payload must be relayed byte-identical');
            $this->assertCount(0, $this->sent(), 'pty.data has no client reply on success');
        }

        public function testPtyDataHostToAdmin(): void
        {
            $this->flagAOn();
            $this->asHost('vps3'); // host is the sender
            $this->seedPty('pty-d', 'vps3', 'admin-9');

            $b64 = base64_encode('output bytes');
            $this->dispatch('pty.data', ['pty_id' => 'pty-d', 'data' => $b64]);

            $this->assertCount(1, $this->sentToUid());
            $this->assertSame('admin-9', $this->sentToUid()[0]['uid'], 'host-side frame relays to the owning admin');
            $relay = json_decode($this->sentToUid()[0]['message'], true);
            $this->assertSame($b64, $relay['data']['data']);
        }

        public function testPtyDataThirdPartyForbidden(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-intruder'); // not for (admin-9) nor host (vps3)
            $this->seedPty('pty-d', 'vps3', 'admin-9');

            $this->dispatch('pty.data', ['pty_id' => 'pty-d', 'data' => base64_encode('x')]);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->sentToUid(), 'third party relays nothing');
        }

        public function testPtyDataUnknownPtyIdSilentlyDropped(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-9');
            // registry empty

            $this->dispatch('pty.data', ['pty_id' => 'nope', 'data' => base64_encode('x')]);

            $this->assertCount(0, $this->sentToUid(), 'unknown pty_id relays nothing');
            $this->assertCount(0, $this->sent(), 'unknown pty_id is silent (no error reply)');
            $this->assertCount(0, $this->closed());
        }

        // ================================================================
        // 5. pty.resize owner-only
        // ================================================================

        public function testPtyResizeByOwnerRelaysAndUpdatesRegistry(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-9');
            $this->seedPty('pty-r', 'vps3', 'admin-9', ['cols' => 80, 'rows' => 24]);

            $this->dispatch('pty.resize', ['pty_id' => 'pty-r', 'cols' => 200, 'rows' => 50]);

            $this->assertCount(1, $this->sentToUid());
            $this->assertSame('vps3', $this->sentToUid()[0]['uid']);
            $relay = json_decode($this->sentToUid()[0]['message'], true);
            $this->assertSame('pty.resize', $relay['op']);
            $this->assertSame(200, $relay['data']['cols']);
            $this->assertSame(50, $relay['data']['rows']);
            $this->assertCount(0, $this->sent(), 'pty.resize has no client reply on success');

            // Registry geometry updated.
            $entry = $this->ptys()['pty-r'];
            $this->assertSame(200, $entry['cols']);
            $this->assertSame(50, $entry['rows']);
        }

        public function testPtyResizeNonOwnerForbidden(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-other'); // admin role but not the owner
            $this->seedPty('pty-r', 'vps3', 'admin-9', ['cols' => 80, 'rows' => 24]);

            $this->dispatch('pty.resize', ['pty_id' => 'pty-r', 'cols' => 200, 'rows' => 50]);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->sentToUid());
            // Geometry unchanged.
            $entry = $this->ptys()['pty-r'];
            $this->assertSame(80, $entry['cols']);
            $this->assertSame(24, $entry['rows']);
        }

        public function testPtyResizeUnknownPtyIdSilentlyDropped(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-9');
            // registry empty

            $this->dispatch('pty.resize', ['pty_id' => 'nope', 'cols' => 100, 'rows' => 40]);

            $this->assertCount(0, $this->sentToUid(), 'unknown pty_id relays nothing');
            $this->assertCount(0, $this->sent(), 'unknown pty_id is silent');
        }

        // ================================================================
        // 6. pty.close either-party + removal
        // ================================================================

        public function testPtyCloseByAdminRelaysToHostAndRemoves(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-9');
            $this->seedPty('pty-c', 'vps3', 'admin-9');

            $this->dispatch('pty.close', ['pty_id' => 'pty-c']);

            $this->assertCount(1, $this->sentToUid());
            $this->assertSame('vps3', $this->sentToUid()[0]['uid'], 'admin close relays to the host');
            $relay = json_decode($this->sentToUid()[0]['message'], true);
            $this->assertSame('pty.close', $relay['op']);
            $this->assertSame('pty-c', $relay['data']['pty_id']);
            $this->assertArrayNotHasKey('pty-c', $this->ptys(), 'entry removed after close');
            $this->assertCount(0, $this->sent(), 'pty.close has no client reply on success');
        }

        public function testPtyCloseByHostRelaysToAdminWithCodeAndRemoves(): void
        {
            $this->flagAOn();
            $this->asHost('vps3');
            $this->seedPty('pty-c', 'vps3', 'admin-9');

            $this->dispatch('pty.close', ['pty_id' => 'pty-c', 'code' => 0]);

            $this->assertCount(1, $this->sentToUid());
            $this->assertSame('admin-9', $this->sentToUid()[0]['uid'], 'host close relays to the owning admin');
            $relay = json_decode($this->sentToUid()[0]['message'], true);
            // Optional code carried through verbatim (0 must survive).
            $this->assertArrayHasKey('code', $relay['data']);
            $this->assertSame(0, $relay['data']['code']);
            $this->assertArrayNotHasKey('pty-c', $this->ptys());
        }

        public function testPtyCloseNonPartyForbidden(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-intruder');
            $this->seedPty('pty-c', 'vps3', 'admin-9');

            $this->dispatch('pty.close', ['pty_id' => 'pty-c']);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->sentToUid());
            $this->assertArrayHasKey('pty-c', $this->ptys(), 'forbidden must not remove the entry');
        }

        public function testPtyCloseUnknownPtyIdSilentlyDropped(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-9');
            // registry empty

            $this->dispatch('pty.close', ['pty_id' => 'nope']);

            $this->assertCount(0, $this->sentToUid(), 'unknown pty_id relays nothing');
            $this->assertCount(0, $this->sent(), 'unknown pty_id is silent');
        }

        // ================================================================
        // 7. Dormancy + auth gate
        // ================================================================

        public function testPtyOpenDormantWhenFlagAOff(): void
        {
            // Flag A OFF: client present but ws_new_handling unset.
            $client = new PtyFakeGlobalDataClient();
            $client->store['ptys'] = [];
            $GLOBALS['global'] = $client;
            $this->global = $client;
            $this->asAdmin();
            $this->online('vps1234');

            $this->dispatch('pty.open', ['pty_id' => 'p', 'host' => 1234, 'command' => 'ls']);

            $this->assertCount(0, $this->sent(), 'Flag A OFF: pty.open produces no reply');
            $this->assertCount(0, $this->sentToUid(), 'Flag A OFF: pty.open produces no relay');
            $this->assertSame([], $this->ptys(), 'Flag A OFF: pty.open writes nothing');
        }

        public function testPtyOpenUnauthedRepliesAuthRequiredAndCloses(): void
        {
            $this->flagAOn();
            $_SESSION = []; // NOT v1-authed
            $this->online('vps1234');

            $this->dispatch('pty.open', ['pty_id' => 'p', 'host' => 1234, 'command' => 'ls'], 33);

            $reply = $this->singleReply();
            $this->assertFalse($reply['ok']);
            $this->assertSame('auth_required', $reply['error']['code']);
            $this->assertContains(33, $this->closed(), 'unauthed pty op must close the connection');
            $this->assertCount(0, $this->sentToUid());
            $this->assertSame([], $this->ptys());
        }

        // ================================================================
        // 8. Registry isolation — pty ops never touch $global->running (& vice versa)
        // ================================================================

        public function testPtyOpsDoNotLeakIntoCmdRunningRegistry(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-9');
            $this->online('vps1234');

            // Seed a cmd registry entry alongside the pty registry.
            $runEntry = [
                'run_id' => 'run-x', 'id' => 'run-x', 'host' => 'vps1234', 'for' => 'admin-9',
                'command' => 'sleep 5', 'interact' => false, 'update_after' => false,
                'rows' => 24, 'cols' => 80, 'started' => 1, 'v' => 1
            ];
            $this->global->store['running'] = ['run-x' => $runEntry];

            // Full pty lifecycle: open, resize, close.
            $this->dispatch('pty.open', ['pty_id' => 'pty-iso', 'host' => 1234, 'command' => 'top'], 1, 'o1');
            $this->dispatch('pty.resize', ['pty_id' => 'pty-iso', 'cols' => 120, 'rows' => 40], 1, 'o2');
            $this->dispatch('pty.close', ['pty_id' => 'pty-iso'], 1, 'o3');

            // $global->running is completely untouched by the pty handlers.
            $this->assertArrayHasKey('running', $this->global->store);
            $this->assertSame(['run-x' => $runEntry], $this->global->store['running'], 'cmd running registry must be byte-identical after pty ops');
            // And the pty registry is empty again after close (its own lifecycle).
            $this->assertSame([], $this->ptys());
        }

        // ------------------------------------------------------------------
        // safeEcho capture helpers (audit-line assertions)
        // ------------------------------------------------------------------

        /**
         * Redirect Worker::$outputStream to a temp file for the duration of $fn,
         * returning everything safeEcho wrote. Restores the prior stream after.
         */
        private function captureWorkerOutput(callable $fn): string
        {
            $prev = \Workerman\Worker::$outputStream;
            $tmp = tmpfile();
            \Workerman\Worker::$outputStream = $tmp;
            try {
                $fn();
            } finally {
                \Workerman\Worker::$outputStream = $prev;
            }
            rewind($tmp);
            $out = stream_get_contents($tmp);
            fclose($tmp);
            return $out;
        }

        /** Extract + JSON-decode the first `pty_audit {...}` line, or null. */
        private function firstAuditLine(string $captured): ?array
        {
            foreach (explode("\n", $captured) as $l) {
                $pos = strpos($l, 'pty_audit ');
                if ($pos !== false) {
                    $json = substr($l, $pos + strlen('pty_audit '));
                    $decoded = json_decode($json, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
            return null;
        }
    }
}
