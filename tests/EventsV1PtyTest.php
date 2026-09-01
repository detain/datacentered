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
 * gate, collision guard, HSETNX registration / HDEL removal, base64
 * pass-through) — nothing is reimplemented here.
 *
 * The pty session state lives in the SEPARATE Events::PTYS_REGISTRY_KEY
 * ('dc:state:ptys') Redis HASH — one field per pty_id, JSON entry value,
 * registered atomically via HSETNX (the collision guard) — decoupled from the
 * cmd running registry (Events::RUNNING_KEY_PREFIX per-run STRING keys plus
 * the Events::RUNNING_INDEX_KEY SET). Both are driven through the SharedState
 * facade onto the InMemoryRedis double injected in setUp(), so this suite
 * seeds and asserts the REAL production keys via the REAL key constants.
 */

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the shared fake Gateway seam, then requires FeatureFlags + Events.
    require_once __DIR__.'/V1TestSupport.php';

    /**
     * Tests for the v1 pty.* handlers (WS-revamp Phase 2 step 2.4). Scope is
     * strictly the NEW step-2.4 code.
     */
    class EventsV1PtyTest extends TestCase
    {
        /** @var InMemoryRedis SharedState double injected by setUp() */
        private $redis;

        /**
         * SharedState discipline copied from tests/SharedStateTest.php: no
         * leaked shared connection, fresh in-memory keyspace per test, client
         * dropped + facade reset on teardown so nothing bleeds across suites.
         */
        protected function setUp(): void
        {
            unset($GLOBALS['redis']);
            $this->redis = new \InMemoryRedis();
            \SharedState::setClient($this->redis);
            $this->resetState();
        }

        protected function tearDown(): void
        {
            $this->resetState();
            \SharedState::setClient(null);
            unset($GLOBALS['redis']);
            \SharedState::reset();
        }

        private function resetState(): void
        {
            \GatewayWorker\Lib\Gateway::reset();
            $_SESSION = [];
            \Events::$db = null;
        }

        // ------------------------------------------------------------------
        // Fixtures / helpers
        // ------------------------------------------------------------------

        /**
         * Flip Flag A ON: int 1 at dc:flag:ws_new_handling via the facade.
         * The ptys hash needs no init — HSETNX creates the key on first
         * registration and hGetAll on an absent key reads [].
         */
        private function flagAOn(): void
        {
            \SharedState::set(FeatureFlags::VAR_NEW_HANDLING, 1);
        }

        /**
         * Flip Flag A OFF: int 0 stored explicitly — with a usable Redis
         * client an UNSET flag reads ON (new handling is the default).
         */
        private function flagAOff(): void
        {
            \SharedState::set(FeatureFlags::VAR_NEW_HANDLING, 0);
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

        /** Full pty registry read: HGETALL on the real dc:state:ptys key. */
        private function ptys(): array
        {
            return \SharedState::hGetAll(\Events::PTYS_REGISTRY_KEY);
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
            \SharedState::hSet(\Events::PTYS_REGISTRY_KEY, $ptyId, array_merge([
                'pty_id' => $ptyId,
                'host' => $host,
                'for' => $for,
                'scope' => 'command',
                'command' => 'top',
                'cols' => 80,
                'rows' => 24,
                'started' => 1719700000
            ], $overrides));
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
            // Field 'dup' exists in the hash, so the handler's HSETNX must lose.
            \SharedState::hSet(\Events::PTYS_REGISTRY_KEY, 'dup', $existing);
            // REVIEW-FIX: the existing pty must be represented as GENUINELY LIVE,
            // or this test no longer exercises what it claims. The registry entry
            // alone is not enough now: pty.open reclaims an entry whose host AND
            // owning admin are both offline, because dc:state:ptys has no TTL and is
            // cleared only by pty.close, so a dropped session used to block its
            // pty_id forever. Mark the recorded host online so this remains a
            // collision with a live pty — the case the guard exists for.
            $this->online('vps777');

            $this->dispatch('pty.open', ['pty_id' => 'dup', 'host' => 1234, 'command' => 'whoami']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->sentToUid(), 'collision must NOT relay a pty.open to the host');
            $this->assertSame($existing, $this->ptys()['dup'], 'existing pty entry must be untouched');
        }

        /**
         * REVIEW-FIX (ghost ptys): an entry whose host and owning admin are BOTH
         * offline is a corpse, not an in-flight session, and must not block its
         * pty_id forever.
         *
         * dc:state:ptys carries no TTL and is removed only by pty.close. Under
         * GlobalData the whole registry died with the store on every restart, so
         * this self-healed; in Redis it persists, so an admin or host dropping
         * mid-session (or a hard-killed hub) permanently poisoned that pty_id with
         * no recovery short of editing Redis by hand.
         */
        public function testPtyOpenReclaimsAnOrphanedPtyIdWhoseSessionsAreGone(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-new');
            $this->online('vps1234');

            // Left behind by a session that never got to run pty.close.
            \SharedState::hSet(\Events::PTYS_REGISTRY_KEY, 'dup', [
                'pty_id' => 'dup', 'host' => 'vps777', 'for' => 'admin-original',
                'scope' => 'command', 'command' => 'sleep 999',
                'cols' => 80, 'rows' => 24, 'started' => 111
            ]);
            // Deliberately do NOT mark vps777 / admin-original online.

            $this->dispatch('pty.open', ['pty_id' => 'dup', 'host' => 1234, 'command' => 'whoami']);

            $reclaimed = $this->ptys()['dup'];
            $this->assertSame('vps1234', $reclaimed['host'], 'the orphaned pty_id is reclaimed by the new open');
            $this->assertSame('admin-new', $reclaimed['for']);
            $this->assertSame('whoami', $reclaimed['command']);
            $this->assertCount(1, $this->sentToUid(), 'the reclaimed open is relayed to the host');
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
            // Flag A OFF: usable client, dc:flag:ws_new_handling explicitly 0.
            $this->flagAOff();
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
        // 8. Registry isolation — pty ops never touch the cmd running registry
        // ================================================================

        public function testPtyOpsDoNotLeakIntoCmdRunningRegistry(): void
        {
            $this->flagAOn();
            $this->asAdmin('admin-9');
            $this->online('vps1234');

            // Seed a cmd run alongside the pty registry, on the REAL cmd keys:
            // one STRING entry at dc:state:running:<run_id> plus its id in the
            // dc:state:running_ids SET index (migration A2 layout).
            $runEntry = [
                'run_id' => 'run-x', 'id' => 'run-x', 'host' => 'vps1234', 'for' => 'admin-9',
                'command' => 'sleep 5', 'interact' => false, 'update_after' => false,
                'rows' => 24, 'cols' => 80, 'started' => 1, 'v' => 1
            ];
            $runKey = \Events::RUNNING_KEY_PREFIX.'run-x';
            \SharedState::set($runKey, $runEntry, \Events::RUNNING_ENTRY_TTL);
            \SharedState::sAdd(\Events::RUNNING_INDEX_KEY, 'run-x');

            // Full pty lifecycle: open, resize, close.
            $this->dispatch('pty.open', ['pty_id' => 'pty-iso', 'host' => 1234, 'command' => 'top'], 1, 'o1');
            $this->dispatch('pty.resize', ['pty_id' => 'pty-iso', 'cols' => 120, 'rows' => 40], 1, 'o2');
            $this->dispatch('pty.close', ['pty_id' => 'pty-iso'], 1, 'o3');

            // The cmd run entry and its index are completely untouched by the
            // pty handlers — same value, same TTL-key, still a set member.
            $this->assertNotNull(\SharedState::get($runKey), 'cmd run key must survive pty ops');
            $this->assertSame($runEntry, \SharedState::get($runKey), 'cmd run entry must be value-identical after pty ops');
            $this->assertSame(['run-x'], \SharedState::sMembers(\Events::RUNNING_INDEX_KEY), 'cmd run index must be untouched');
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
