<?php

/**
 * Tests for the dc.presence.* v1 ops — Events::handleDcPresenceJoin(),
 * handleDcPresenceMove(), handleDcPresenceLeave(), handleDcViewportUpdate() and
 * the batched flush (flushPresenceBatch).
 *
 * WHAT CHANGED HERE AND WHY (this file was asserting fiction)
 *
 *  - TRANSPORT. Every broadcast assertion went through a FakeChannelClient
 *    installed into Events::$channelClient in setUp(). The seam was ALWAYS
 *    installed, so the tests could never notice that the production branch was
 *    \Channel\Client::publish() — a transport with no subscriber, on the wrong
 *    port, whose service does not even run on this host (BUG-A3). Presence
 *    broadcasts vanished for months while this file was green.
 *    Now: Events::$channelClient stays NULL (production configuration) and the
 *    assertions are made against Gateway::sendToGroup('dc_presence', …) /
 *    Gateway::sendToClient(). The dead transport is a tripwire class that
 *    records + throws, and every test asserts it was never touched.
 *
 *  - client_id TYPE. Fixtures used int 1. A gateway client_id is a 20-char hex
 *    STRING (Context::addressToClientId = bin2hex(pack('NnN',…))). The A1
 *    crash-loop (102 fatals / 155 worker restarts) was exactly this confusion:
 *    an `int $client_id` type hint on trackSessionClient() TypeErrors the moment
 *    a real id arrives. Int fixtures would let that straight back in, so every
 *    id here comes from dc_client_id().
 *
 *  - KEY SHAPE. Assertions targeted $global->{'dc_presence:'.$uid}. Presence is
 *    per-CONNECTION: dc_presence:client:<client_id>, indexed by
 *    dc_presence_clients / dc_active_clients (multiple tabs share a uid).
 *
 *  - MOVE. There is no per-move dc.presence.updated broadcast. Moves are
 *    written to dc_move_batch:<client_id> and coalesced by a one-shot 50ms
 *    timer into ONE dc.presence.batch_updated event (BUG-B7). The timer is
 *    recorded by TestTimer and fired explicitly.
 *
 *  - EVENT vs REPLY SHAPE. Events are v1Envelope() — v/id/op/ts/data and
 *    deliberately NO `ok` (BUG-B6: dc-ws.js short-circuits on
 *    `ok === false && error`). Replies carry re + ok and NO op.
 *
 *  - FLAG A DORMANCY. `ws_new_handling` unset now means ON (commit 9eabb50),
 *    so a dormancy test must set it to 0 explicitly instead of leaving it unset.
 *
 * @see Events::handleDcPresenceJoin()
 * @see Events::handleDcPresenceMove()
 * @see Events::handleDcPresenceLeave()
 * @see Events::flushPresenceBatch()
 * @see Events::broadcastDcPresence()
 */

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the offline \GlobalData\Client, the \Channel\Client tripwire, the
    // recording Timer loop and the fake Gateway, then loads FeatureFlags + Events.
    require_once __DIR__.'/V1TestSupport.php';

    class EventsV1DcPresenceTest extends TestCase
    {
        use DcTransportAssertions;

        private const REMOTE = '203.0.113.10';
        private const UID = 77;
        private const NAME = 'adminuser';

        /** @var string 20-char hex gateway client id (never an int) */
        private string $clientId;

        /** @var string a second connection, for peer/viewport cases */
        private string $peerId;

        protected function setUp(): void
        {
            $this->clientId = dc_client_id(10);
            $this->peerId = dc_client_id(11);
            $this->resetState();
            $_SERVER['REMOTE_ADDR'] = self::REMOTE;
        }

        protected function tearDown(): void
        {
            $this->resetState();
        }

        private function resetState(): void
        {
            \GatewayWorker\Lib\Gateway::reset();
            \Channel\Client::reset();
            \GlobalData\Client::resetConstructed();
            TestTimer::install();
            $_SESSION = [];
            \Events::$db = null;
            // PRODUCTION CONFIGURATION: no channel seam. The old suite installed
            // one in setUp() and thereby hid BUG-A3 completely.
            \Events::$channelClient = null;
            \Events::$moveBatch = [];
            \Events::$moveBatchTimer = null;
            unset($GLOBALS['global']);

            $ref = new ReflectionClass(FeatureFlags::class);
            $prop = $ref->getProperty('client');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        /**
         * Flag A ON + an authenticated admin session + a GlobalData keyspace
         * seeded the way a live worker's is.
         *
         * dc_active_clients is seeded because onWorkerStart() seeds it on a cold
         * start ($global->dc_active_clients = []). dc_presence_clients is seeded
         * here too — see
         * testJoinCasLoopLivelocksWhenPresenceIndexWasNeverSeeded() for why
         * production must do the same and currently does not.
         *
         * Flag C (bot presence) is OFF so the bot system does not interfere with
         * plain presence assertions; EventsBotPresenceTest covers it with C on.
         */
        private function authedSession(array $seed = []): InMemoryGlobalData
        {
            $global = new InMemoryGlobalData(array_merge([
                FeatureFlags::VAR_NEW_HANDLING => 1,
                FeatureFlags::VAR_DC_BOT_PRESENCE => 0,
                'hosts' => [],
                'dc_presence_clients' => [],
                'dc_active_clients' => [],
            ], $seed));
            $GLOBALS['global'] = $global;

            $_SESSION['v1_authed'] = true;
            $_SESSION['uid'] = self::UID;
            $_SESSION['name'] = self::NAME;
            $_SESSION['ima'] = 'admin';
            $_SESSION['login'] = true;

            return $global;
        }

        /** Send dc.presence.<op> through the real dispatchV1 entry point. */
        private function presenceOp(string $op, array $data, ?string $clientId = null, string $id = 'req-presence'): void
        {
            \Events::dispatchV1($clientId ?? $this->clientId, [
                'v' => 1,
                'id' => $id,
                'op' => $op,
                'ts' => time(),
                'data' => $data,
            ]);
        }

        /** A presence entry as handleDcPresenceJoin would have written it. */
        private function presenceEntry(string $clientId, float $x = 0.0, float $z = 0.0, float $yaw = 0.0): array
        {
            return [
                'uid' => self::UID,
                'name' => self::NAME,
                'x' => $x,
                'z' => $z,
                'yaw' => $yaw,
                'ts' => time(),
                'client_id' => $clientId,
            ];
        }

        private function sent(): array
        {
            return \GatewayWorker\Lib\Gateway::$sent;
        }

        /** Decode the single reply; assert exactly one was sent. */
        private function singleReply(): array
        {
            $sent = $this->sent();
            $this->assertCount(1, $sent, 'expected exactly one reply on the wire');
            $decoded = json_decode($sent[0]['message'], true);
            $this->assertIsArray($decoded);
            return $decoded;
        }

        // ====================================================================
        // client_id is a STRING, end to end (the A1 crash class)
        // ====================================================================

        /**
         * A realistic 20-char hex client_id must survive the whole
         * join -> move -> flush -> leave path with no TypeError.
         *
         * trackSessionClient() carried `int $client_id` and crash-looped the
         * BusinessWorker (102 fatals / 155 restarts) the first time a real id
         * reached it. Nothing in this path may narrow client_id to int.
         */
        public function testHexStringClientIdFlowsThroughPresenceWithoutTypeError(): void
        {
            $global = $this->authedSession();

            $this->assertSame(20, strlen($this->clientId), 'fixture must be a REAL 20-char hex client_id');
            $this->assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $this->clientId);
            $this->assertIsString($this->clientId);
            $this->assertNotSame(
                (string) (int) $this->clientId,
                $this->clientId,
                'the fixture must be an id that intval() would mangle — that is the bug being guarded'
            );

            $this->presenceOp('dc.presence.join', ['x' => 1.5, 'z' => 2.5, 'yaw' => 0.25], null, 'join-1');
            $this->presenceOp('dc.presence.move', ['x' => 3.5, 'z' => 4.5, 'yaw' => 0.75], null, 'move-1');
            TestTimer::runAll();
            $this->presenceOp('dc.presence.leave', [], null, 'leave-1');

            // The keys used along the way must contain the full hex string.
            $this->assertContains(
                'dc_presence:client:'.$this->clientId,
                $global->keys(),
                'presence key must be built from the hex client_id verbatim'
            );
            $this->assertNoLazyGlobalDataFallback();
            $this->assertDeadChannelTransportUnused();
        }

        /**
         * trackSessionClient() must accept a hex-string client_id.
         *
         * This is the exact A1 signature regression: an `int $client_id`
         * parameter here throws TypeError under PHP 8 for every real id.
         */
        public function testTrackSessionClientAcceptsHexStringClientId(): void
        {
            $global = $this->authedSession();

            $method = new ReflectionMethod(\Events::class, 'trackSessionClient');
            $method->setAccessible(true);
            $method->invoke(null, $this->clientId, 'mystage-session-abc');

            $this->assertSame(
                'mystage-session-abc',
                $global->raw('dc_client_session:'.$this->clientId),
                'client_id -> session mapping must be keyed by the hex string'
            );
            $this->assertSame(
                [$this->clientId],
                $global->raw('dc_session_clients:mystage-session-abc'),
                'the session -> clients list must hold the hex string, not an int'
            );

            // Reflection also pins the signature so an int hint cannot come back.
            $param = $method->getParameters()[0];
            $this->assertFalse(
                $param->hasType() && (string) $param->getType() === 'int',
                'trackSessionClient($client_id) must NOT be typed int — client_id is a 20-char hex string'
            );
        }

        // ====================================================================
        // dc.presence.join
        // ====================================================================

        public function testJoinStoresPerConnectionEntryAndIndexes(): void
        {
            $global = $this->authedSession();

            $this->presenceOp('dc.presence.join', ['x' => 10.5, 'z' => -3.25, 'yaw' => 1.57]);

            $entry = $global->raw('dc_presence:client:'.$this->clientId);
            $this->assertIsArray($entry, 'join must store dc_presence:client:<client_id>');
            $this->assertSame(10.5, $entry['x']);
            $this->assertSame(-3.25, $entry['z']);
            $this->assertSame(1.57, $entry['yaw']);
            $this->assertSame(self::UID, $entry['uid']);
            $this->assertSame(self::NAME, $entry['name']);
            $this->assertSame($this->clientId, $entry['client_id'], 'entry carries its own hex client_id');
            $this->assertArrayHasKey('ts', $entry);

            $this->assertSame([$this->clientId], $global->raw('dc_presence_clients'));
            $this->assertSame([$this->clientId], $global->raw('dc_active_clients'));

            // Presence is per-connection: nothing may be written under the uid.
            $this->assertNotContains('dc_presence:'.self::UID, $global->keys());
        }

        /**
         * join replies with a correlated REPLY: re + ok, and NO op.
         */
        public function testJoinRepliesCorrelatedOkWithoutOp(): void
        {
            $this->authedSession();

            $this->presenceOp('dc.presence.join', ['x' => 1.0, 'z' => 2.0, 'yaw' => 0.5], null, 'join-req');

            $reply = $this->singleReply();
            $this->assertIsV1Reply($reply, 'join-req');
            $this->assertSame($this->clientId, (string) $this->sent()[0]['client_id']);
        }

        /**
         * TRANSPORT ASSERTION (BUG-A3): the joined event goes out on
         * Gateway::sendToGroup('dc_presence', …) — not the dead Channel client.
         */
        public function testJoinBroadcastsJoinedViaGatewaySendToGroup(): void
        {
            $this->authedSession();

            $this->presenceOp('dc.presence.join', ['x' => 4.0, 'z' => 5.0, 'yaw' => 0.5]);

            $groupSends = \GatewayWorker\Lib\Gateway::$sentToGroup;
            $this->assertCount(1, $groupSends, 'exactly one group broadcast for a join');
            $this->assertSame(
                'dc_presence',
                $groupSends[0]['group'],
                'presence must fan out to the dc_presence Gateway group clients are joined to at auth'
            );

            $events = $this->presenceGroupEvents('dc.presence.joined');
            $this->assertCount(1, $events);
            $this->assertIsV1Event($events[0], 'dc.presence.joined');

            $data = $events[0]['data'];
            $this->assertSame(self::UID, $data['uid']);
            $this->assertSame($this->clientId, $data['clientId'], 'frontend expects camelCase clientId');
            $this->assertArrayNotHasKey('client_id', $data, 'snake_case client_id must be renamed, not duplicated');
            $this->assertEqualsWithDelta(4.0, $data['x'], 0.001);
            $this->assertEqualsWithDelta(5.0, $data['z'], 0.001);

            $this->assertDeadChannelTransportUnused('join:');
        }

        /**
         * The joined EVENT must not carry `ok` (BUG-B6). dc-ws.js treats
         * `ok === false && error` as an error reply, so an event that carries an
         * `ok` field can be misclassified by the browser.
         */
        public function testPresenceEventsAreEnvelopesWithoutOkField(): void
        {
            $this->authedSession();

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0]);

            $raw = \GatewayWorker\Lib\Gateway::$sentToGroup[0]['message'];
            $decoded = json_decode($raw, true);
            $this->assertArrayNotHasKey('ok', $decoded, 'a presence EVENT must never carry ok');
            $this->assertArrayNotHasKey('re', $decoded, 'a presence EVENT is unsolicited: no re');
            $this->assertIsString($decoded['id'], 'event carries a fresh uuid envelope id');
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $decoded['id'],
                'v1Envelope() ids are RFC 4122 v4 uuids'
            );
        }

        /**
         * The browser MAY report the real room extents on join (contract
         * BOT-BOUNDS); a valid set is recorded for the bot to wander in.
         */
        public function testJoinRecordsValidBrowserReportedRoomBounds(): void
        {
            $global = $this->authedSession();

            $this->presenceOp('dc.presence.join', [
                'x' => 0.0, 'z' => 0.0, 'yaw' => 0.0,
                'bounds' => ['minX' => -120.0, 'maxX' => 40.0, 'minZ' => -130.0, 'maxZ' => 20.0],
            ]);

            $this->assertSame(
                ['minX' => -120.0, 'maxX' => 40.0, 'minZ' => -130.0, 'maxZ' => 20.0],
                $global->raw(\Events::DC_ROOM_BOUNDS_KEY_PREFIX.\Events::BOT_DEFAULT_LOCATION)
            );
        }

        public function testJoinIgnoresHostileRoomBounds(): void
        {
            $global = $this->authedSession();

            $this->presenceOp('dc.presence.join', [
                'x' => 0.0, 'z' => 0.0, 'yaw' => 0.0,
                'bounds' => ['minX' => -1e300, 'maxX' => 1e300, 'minZ' => -1e300, 'maxZ' => 1e300],
            ]);

            $this->assertNull(
                $global->raw(\Events::DC_ROOM_BOUNDS_KEY_PREFIX.\Events::BOT_DEFAULT_LOCATION),
                'absurd bounds must be rejected outright, not recorded'
            );
        }

        // ====================================================================
        // dc.presence.move (fire-and-forget + 50ms batch coalescing)
        // ====================================================================

        public function testMoveUpdatesEntryAndQueuesBatchWithoutReplying(): void
        {
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->clientId],
                'dc_active_clients' => [$this->clientId],
                'dc_presence:client:'.$this->clientId => $this->presenceEntry($this->clientId),
            ]);

            $this->presenceOp('dc.presence.move', ['x' => 99.5, 'z' => -77.25, 'yaw' => 3.0]);

            $entry = $global->raw('dc_presence:client:'.$this->clientId);
            $this->assertSame(99.5, $entry['x']);
            $this->assertSame(-77.25, $entry['z']);
            $this->assertSame(3.0, $entry['yaw']);

            $this->assertEmpty($this->sent(), 'move is fire-and-forget: NO reply to the mover');
            $this->assertSame(
                [],
                \GatewayWorker\Lib\Gateway::$sentToGroup,
                'a move must not broadcast immediately — it is coalesced by the 50ms batch timer'
            );

            $queued = json_decode($global->raw('dc_move_batch:'.$this->clientId), true);
            $this->assertIsArray($queued, 'move must be queued at dc_move_batch:<client_id>');
            $this->assertSame(99.5, $queued['x']);
        }

        /**
         * The one-shot flush timer coalesces queued moves into ONE
         * dc.presence.batch_updated event on the dc_presence group.
         */
        public function testBatchFlushBroadcastsBatchUpdatedViaGatewayGroup(): void
        {
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->clientId, $this->peerId],
                'dc_active_clients' => [$this->clientId, $this->peerId],
                'dc_presence:client:'.$this->clientId => $this->presenceEntry($this->clientId),
                'dc_presence:client:'.$this->peerId => $this->presenceEntry($this->peerId),
            ]);

            $this->presenceOp('dc.presence.move', ['x' => 1.0, 'z' => 2.0, 'yaw' => 0.0]);
            $this->presenceOp('dc.presence.move', ['x' => 8.0, 'z' => 9.0, 'yaw' => 0.0], $this->peerId);

            // Exactly one flush timer must be armed for N moves (one-shot, 50ms).
            $armed = TestTimer::withInterval(0.05);
            $this->assertCount(1, $armed, 'moves share ONE armed 50ms flush timer');
            $this->assertFalse($armed[0]['persistent'], 'the flush timer is one-shot');

            TestTimer::run($armed[0]['id']);

            $events = $this->presenceGroupEvents('dc.presence.batch_updated');
            $this->assertCount(1, $events, 'the whole batch goes out as ONE event');
            $this->assertIsV1Event($events[0], 'dc.presence.batch_updated');

            $batch = $events[0]['data'];
            $this->assertArrayHasKey($this->clientId, $batch, 'batch is keyed by hex client_id');
            $this->assertArrayHasKey($this->peerId, $batch);
            $this->assertEqualsWithDelta(1.0, $batch[$this->clientId]['x'], 0.001);
            $this->assertEqualsWithDelta(8.0, $batch[$this->peerId]['x'], 0.001);

            // Batch keys are cleared after the flush (CRIT-7).
            $this->assertNull($global->raw('dc_move_batch:'.$this->clientId));
            $this->assertNull($global->raw('dc_move_batch:'.$this->peerId));

            $this->assertNull(\Events::$moveBatchTimer, 'the flush must disarm itself so the next move re-arms');
            $this->assertDeadChannelTransportUnused('batch flush:');
        }

        /**
         * BUG-B5: a client WITH fresh viewport data gets a filtered unicast; a
         * client with no/stale viewport data still gets the full batch. Both go
         * out via Gateway::sendToClient(), never the channel transport.
         */
        public function testBatchFlushUnicastsFilteredSubsetToViewportOwnersOnly(): void
        {
            $mover = dc_client_id(20);
            $watcher = dc_client_id(21);   // fresh viewport, looking away from the mover
            $blind = dc_client_id(22);     // no viewport data at all

            $global = $this->authedSession([
                'dc_presence_clients' => [$mover, $watcher, $blind],
                'dc_active_clients' => [$mover, $watcher, $blind],
                'dc_presence:client:'.$mover => $this->presenceEntry($mover, 500.0, 500.0),
                'dc_presence:client:'.$watcher => $this->presenceEntry($watcher),
                'dc_presence:client:'.$blind => $this->presenceEntry($blind),
                // flushPresenceBatch() only sends to clients with a session mapping.
                'dc_client_session:'.$watcher => 'sess-w',
                'dc_client_session:'.$blind => 'sess-b',
                'dc_viewport:'.$watcher => [
                    'x' => 0.0, 'z' => 0.0, 'dirX' => -1.0, 'dirZ' => 0.0,
                    'viewDist' => 50.0, 'ts' => time(),
                ],
                'dc_move_batch:'.$mover => json_encode($this->presenceEntry($mover, 500.0, 500.0)),
            ]);

            $flush = new ReflectionMethod(\Events::class, 'flushPresenceBatch');
            $flush->setAccessible(true);
            $flush->invoke(null);

            // The watcher can't see a mover 700 units away behind it: filtered to nothing.
            $this->assertSame([], $this->messagesToClient($watcher), 'out-of-viewport movers are filtered out');

            $blindMsgs = $this->messagesToClient($blind);
            $this->assertCount(1, $blindMsgs, 'a client with no viewport data receives the unfiltered batch');
            $this->assertIsV1Event($blindMsgs[0], 'dc.presence.batch_updated');
            $this->assertArrayHasKey($mover, $blindMsgs[0]['data']);

            $this->assertSame(
                [],
                \GatewayWorker\Lib\Gateway::$sentToGroup,
                'once ANY client has fresh viewport data the flush unicasts; no group broadcast'
            );
            $this->assertDeadChannelTransportUnused('viewport flush:');
            unset($global);
        }

        public function testMoveSilentlyIgnoresUnjoinedConnection(): void
        {
            $this->authedSession();

            $this->presenceOp('dc.presence.move', ['x' => 5.0, 'z' => 5.0, 'yaw' => 0.0]);

            $this->assertEmpty($this->sent(), 'move for an unjoined connection must send no reply');
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$sentToGroup);
            $this->assertSame([], TestTimer::added(), 'nothing to flush, so no timer is armed');
        }

        /**
         * BUG-B1: a client-supplied clientId is NEVER authoritative. The
         * connection's own id is the only authority, and a mismatching supplied
         * id drops the move (it used to be intval()'d, which both mangled the
         * hex id AND let one client move another client's avatar).
         */
        public function testMoveWithForeignClientIdIsDroppedNotApplied(): void
        {
            $victim = dc_client_id(30);
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->clientId, $victim],
                'dc_active_clients' => [$this->clientId, $victim],
                'dc_presence:client:'.$this->clientId => $this->presenceEntry($this->clientId),
                'dc_presence:client:'.$victim => $this->presenceEntry($victim, 100.0, 100.0),
            ]);

            $this->presenceOp('dc.presence.move', [
                'x' => -1.0, 'z' => -1.0, 'yaw' => 0.0, 'clientId' => $victim,
            ]);

            $victimEntry = $global->raw('dc_presence:client:'.$victim);
            $this->assertSame(100.0, $victimEntry['x'], 'another connection\'s avatar must not be moved');
            $this->assertSame(100.0, $victimEntry['z']);

            $ownEntry = $global->raw('dc_presence:client:'.$this->clientId);
            $this->assertSame(0.0, $ownEntry['x'], 'a mismatching supplied clientId drops the move entirely');
            $this->assertNull($global->raw('dc_move_batch:'.$this->clientId));
        }

        /** An echoed-back OWN clientId (older clients do this) is tolerated. */
        public function testMoveWithOwnClientIdEchoedBackIsAccepted(): void
        {
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->clientId],
                'dc_active_clients' => [$this->clientId],
                'dc_presence:client:'.$this->clientId => $this->presenceEntry($this->clientId),
            ]);

            $this->presenceOp('dc.presence.move', [
                'x' => 12.0, 'z' => 13.0, 'yaw' => 0.0, 'clientId' => $this->clientId,
            ]);

            $this->assertSame(12.0, $global->raw('dc_presence:client:'.$this->clientId)['x']);
        }

        /** 150ms per-connection throttle mirrors the client THROTTLE_MS. */
        public function testMoveIsThrottledPerConnection(): void
        {
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->clientId],
                'dc_active_clients' => [$this->clientId],
                'dc_presence:client:'.$this->clientId => $this->presenceEntry($this->clientId),
                'dc_move_throttle:'.$this->clientId => microtime(true),
            ]);

            $this->presenceOp('dc.presence.move', ['x' => 42.0, 'z' => 42.0, 'yaw' => 0.0]);

            $this->assertSame(
                0.0,
                $global->raw('dc_presence:client:'.$this->clientId)['x'],
                'a move within 150ms of the previous one must be dropped'
            );
        }

        // ====================================================================
        // dc.presence.leave
        // ====================================================================

        public function testLeaveRemovesEntryRepliesAndBroadcastsLeft(): void
        {
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->clientId],
                'dc_active_clients' => [$this->clientId],
                'dc_presence:client:'.$this->clientId => $this->presenceEntry($this->clientId),
            ]);

            $this->presenceOp('dc.presence.leave', [], null, 'leave-req');

            $this->assertNull($global->raw('dc_presence:client:'.$this->clientId), 'entry must be cleared');
            $this->assertSame([], $global->raw('dc_presence_clients'), 'index must drop the client');

            $reply = $this->singleReply();
            $this->assertIsV1Reply($reply, 'leave-req');

            $events = $this->presenceGroupEvents('dc.presence.left');
            $this->assertCount(1, $events);
            $this->assertIsV1Event($events[0], 'dc.presence.left');
            $this->assertSame(self::UID, $events[0]['data']['uid']);
            $this->assertSame($this->clientId, $events[0]['data']['clientId']);
            $this->assertDeadChannelTransportUnused('leave:');
        }

        public function testLeaveWithoutJoinRepliesForbidden(): void
        {
            $this->authedSession();

            $this->presenceOp('dc.presence.leave', [], null, 'leave-req');

            $reply = $this->singleReply();
            $this->assertIsV1Reply($reply, 'leave-req', false);
            $this->assertSame('forbidden', $reply['error']['code']);
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$sentToGroup, 'nothing to announce');
        }

        // ====================================================================
        // onClose
        // ====================================================================

        /**
         * A dropped socket announces dc.presence.left over the Gateway group
         * before cleaning up, so remaining avatars disappear immediately rather
         * than waiting up to 30s for the health timer.
         */
        public function testOnCloseBroadcastsLeftAndCleansUpConnectionKeys(): void
        {
            $global = $this->authedSession([
                'dc_presence_clients' => [$this->clientId],
                'dc_active_clients' => [$this->clientId],
                'dc_presence:client:'.$this->clientId => $this->presenceEntry($this->clientId),
                'dc_client_session:'.$this->clientId => 'sess-1',
                'dc_session_clients:sess-1' => [$this->clientId],
                \Events::DC_PONG_KEY_PREFIX.$this->clientId => time(),
                \Events::DC_PING_SENT_KEY_PREFIX.$this->clientId => time(),
                'dc_viewport:'.$this->clientId => ['ts' => time()],
                'dc_move_throttle:'.$this->clientId => microtime(true),
                'running' => [],
                'rooms' => [],
            ]);
            \GatewayWorker\Lib\Gateway::$uidClientIds[self::UID] = [$this->clientId];

            \Events::onClose($this->clientId);

            $events = $this->presenceGroupEvents('dc.presence.left');
            $this->assertCount(1, $events, 'onClose must announce the departure');
            $this->assertSame($this->clientId, $events[0]['data']['clientId']);

            $this->assertNull($global->raw('dc_presence:client:'.$this->clientId));
            $this->assertSame([], $global->raw('dc_presence_clients'));
            $this->assertSame([], $global->raw('dc_active_clients'));
            $this->assertNull($global->raw('dc_client_session:'.$this->clientId));
            $this->assertSame([], $global->raw('dc_session_clients:sess-1'));
            $this->assertNull($global->raw(\Events::DC_PONG_KEY_PREFIX.$this->clientId));
            $this->assertNull($global->raw(\Events::DC_PING_SENT_KEY_PREFIX.$this->clientId));
            $this->assertNull($global->raw('dc_viewport:'.$this->clientId));
            $this->assertNull($global->raw('dc_move_throttle:'.$this->clientId));
            $this->assertNull($global->raw('dc_cleanup:'.$this->clientId), 'the cleanup sentinel must be released');

            $this->assertDeadChannelTransportUnused('onClose:');
        }

        // ====================================================================
        // pong / liveness keys (BUG-B3/B4)
        // ====================================================================

        /**
         * A pong records the RECEIPT time under dc_ping: and must not touch
         * dc_ping_sent:. Writing the send time into dc_ping: (the old bug) made
         * a correctly answering client indistinguishable from a silent one.
         */
        public function testPongRecordsReceiptTimeUnderPongKeyOnly(): void
        {
            $global = $this->authedSession([
                \Events::DC_PING_SENT_KEY_PREFIX.$this->clientId => 1000,
            ]);

            \Events::dispatchV1($this->clientId, [
                'v' => 1, 'id' => 'keepalive', 'op' => 'pong', 'ts' => time(), 'data' => [],
            ]);

            $this->assertGreaterThan(
                0,
                $global->raw(\Events::DC_PONG_KEY_PREFIX.$this->clientId),
                'pong must record the receipt time under dc_ping:<client_id>'
            );
            $this->assertSame(
                1000,
                $global->raw(\Events::DC_PING_SENT_KEY_PREFIX.$this->clientId),
                'pong must NOT overwrite dc_ping_sent: (that is the hub\'s send record)'
            );
            $this->assertSame([], $this->sent(), 'a pong is not answered');
        }

        // ====================================================================
        // dc.viewport.update
        // ====================================================================

        public function testViewportUpdateStoresNormalisedViewportForConnection(): void
        {
            $global = $this->authedSession();

            \Events::dispatchV1($this->clientId, [
                'v' => 1, 'id' => 'vp-1', 'op' => 'dc.viewport.update', 'ts' => time(),
                'data' => ['x' => 1, 'y' => 2, 'z' => 3, 'dirX' => 0, 'dirY' => 0, 'dirZ' => -1, 'viewDist' => 80],
            ]);

            $vp = $global->raw('dc_viewport:'.$this->clientId);
            $this->assertIsArray($vp);
            $this->assertSame(1.0, $vp['x']);
            $this->assertSame(-1.0, $vp['dirZ']);
            $this->assertSame(80.0, $vp['viewDist']);
            $this->assertArrayHasKey('ts', $vp);
            $this->assertSame([], $this->sent(), 'viewport updates are fire-and-forget');
        }

        public function testViewportUpdateRequiresLogin(): void
        {
            $global = $this->authedSession();
            unset($_SESSION['login']);

            \Events::dispatchV1($this->clientId, [
                'v' => 1, 'id' => 'vp-1', 'op' => 'dc.viewport.update', 'ts' => time(),
                'data' => ['x' => 1, 'z' => 3, 'dirX' => 0, 'dirZ' => -1],
            ]);

            $this->assertNull($global->raw('dc_viewport:'.$this->clientId));
        }

        // ====================================================================
        // Auth & Flag A gates
        // ====================================================================

        public function testPresenceOpsRequireAuth(): void
        {
            $this->authedSession();
            $_SESSION['v1_authed'] = false;

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0], null, 'join-req');

            $reply = $this->singleReply();
            $this->assertIsV1Reply($reply, 'join-req', false);
            $this->assertSame('auth_required', $reply['error']['code']);
            $this->assertContains($this->clientId, \GatewayWorker\Lib\Gateway::$closed);
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$sentToGroup);
        }

        /**
         * With Flag A EXPLICITLY off, dc.presence.* is dormant.
         *
         * NOTE the "explicitly": leaving ws_new_handling unset means ON now
         * (commit 9eabb50 flipped the default), so the old version of this test
         * — which just omitted the variable — was asserting dormancy while the
         * handler actually ran.
         */
        public function testPresenceOpsAreDormantWhenFlagAIsExplicitlyOff(): void
        {
            $global = $this->authedSession([FeatureFlags::VAR_NEW_HANDLING => 0]);

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0]);

            $this->assertSame([], $this->sent(), 'Flag A OFF: no reply');
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$sentToGroup, 'Flag A OFF: no broadcast');
            $this->assertNull($global->raw('dc_presence:client:'.$this->clientId));
            $this->assertSame([], $global->raw('dc_presence_clients'));
            $this->assertDeadChannelTransportUnused('flag A off:');
        }

        // ====================================================================
        // The optional $channelClient seam still works (but is NOT the default)
        // ====================================================================

        /**
         * Events::$channelClient remains a supported test seam: when non-null it
         * REPLACES the Gateway send. This test exists to document the seam and,
         * more importantly, to prove the DEFAULT (null) path is the Gateway one —
         * the previous suite installed this seam in setUp() for every test and so
         * asserted only ever on the seam.
         */
        public function testChannelClientSeamOverridesGatewayWhenExplicitlyInstalled(): void
        {
            $this->authedSession();
            $captured = [];
            \Events::$channelClient = function ($group, $payload) use (&$captured) {
                $captured[] = ['group' => $group, 'payload' => $payload];
            };

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0]);

            $this->assertCount(1, $captured, 'an installed seam receives the broadcast');
            $this->assertSame('dc_presence', $captured[0]['group']);
            $this->assertSame(
                [],
                \GatewayWorker\Lib\Gateway::$sentToGroup,
                'the seam REPLACES the Gateway send (which is why installing it by default hid BUG-A3)'
            );
        }

        // ====================================================================
        // PRODUCTION BUG pinned: unseeded dc_presence_clients CAS livelock
        // ====================================================================

        /**
         * PRODUCTION BUG (Applications/Chat/Events.php — not editable from here).
         *
         * handleDcPresenceJoin() maintains its client index with
         *
         *     do {
         *         $currentList = $global->$clientIndexKey;              // null when unseeded
         *         ...
         *         $oldForCas = is_array($currentList) ? $currentList : [];   // []
         *         if ($currentList === $clientList || $global->cas($clientIndexKey, $oldForCas, $clientList)) break;
         *     } while (true);
         *
         * The real GlobalData server compares md5(serialize($old)) against the
         * CURRENT value and reports an ABSENT key as NULL
         * (vendor/workerman/globaldata/src/Server.php, case 'cas'), so
         * cas('dc_presence_clients', [], [$cid]) can NEVER succeed while the key
         * does not exist — and `$currentList === $clientList` is null === [$cid],
         * also false. The loop has no attempt ceiling: the BusinessWorker spins
         * at 100% CPU forever on the FIRST dc.presence.join after a GlobalData
         * cold start.
         *
         * onWorkerStart() seeds `dc_active_clients` ($global->dc_active_clients = [])
         * inside its `$global->add('running', [])` cold-start block, but NOTHING
         * anywhere seeds `dc_presence_clients`. This is also why this test file
         * used to hang the runner forever rather than fail.
         *
         * FIXED (2026-07-31) by seedClientIndex(), which calls $global->add($key, [])
         * before every index CAS loop — add() is atomic set-if-absent, so it is safe
         * with the three datacentered instances sharing one GlobalData store and never
         * overwrites a list another instance is maintaining. Every such loop is now
         * additionally bounded by casShouldRetry()/CAS_MAX_ATTEMPTS, so a CAS that can
         * never succeed fails loudly instead of wedging a worker.
         *
         * This test was written to PIN the livelock and now pins the FIX: a cold
         * GlobalData keyspace must let a join through, and must not spin.
         */
        public function testJoinSeedsTheIndexWhenGlobalDataWasColdInsteadOfLivelocking(): void
        {
            $global = $this->authedSession();
            // Remove the fixture seed to reproduce a cold GlobalData server.
            unset($global->store['dc_presence_clients']);
            $this->assertFalse($global->keyExists('dc_presence_clients'));

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0]);

            $this->assertSame(
                [$this->clientId],
                $global->raw('dc_presence_clients'),
                'an unseeded index must be created and the joiner recorded, not spun on'
            );
            $this->assertTrue(
                $global->keyExists('dc_presence_clients'),
                'seedClientIndex() must have created the key via add()'
            );
        }

        /**
         * The same cold-start guarantee for dc_active_clients. It escaped the
         * livelock in production only because onWorkerStart()'s cold-start block
         * happens to seed it; that is a coincidence of ordering, not a guarantee,
         * so the join path must not depend on it.
         */
        public function testJoinSeedsActiveClientsWhenGlobalDataWasCold(): void
        {
            $global = $this->authedSession();
            unset($global->store['dc_active_clients']);
            $this->assertFalse($global->keyExists('dc_active_clients'));

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0]);

            $this->assertSame([$this->clientId], $global->raw('dc_active_clients'));
        }
    }
}
