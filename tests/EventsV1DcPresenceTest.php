<?php

/**
 * Tests for the dc.presence.* v1 ops — Events::handleDcPresenceJoin(),
 * handleDcPresenceMove(), handleDcPresenceLeave(), handleDcViewportUpdate() and
 * the batched flush (flushPresenceBatch).
 *
 * PORTED (GlobalData→Redis migration, phase 5.2-C): all presence state now
 * lives behind the SharedState Redis facade. The suite injects a fresh
 * InMemoryRedis double through SharedState::setClient() in setUp and REVERSES
 * the injection in tearDown (setClient(null) + unset $GLOBALS['redis'] +
 * SharedState::reset()) — the same discipline tests/EventsV1AuthHelloTest.php,
 * tests/FeatureFlagsTest.php and tests/SharedStateTest.php use. Leaving a
 * live-but-empty double installed would leak process-wide and flip later
 * suites' FeatureFlags fail-safe semantics (unset flag over a live client
 * reads ON; unreachable store throws and reads OFF). Seeding goes through the
 * SAME facade methods production uses
 * (SharedState::set / zAdd), never by hand-encoding: the facade JSON-encodes
 * ZSET members, so a hand-encoded member would round-trip as a quoted string
 * and every index assertion would silently target a different value.
 *
 * Production shapes asserted here (Events.php is authoritative):
 *   - dc:presence:client:<id>  STRING JSON, EX 90s (PRESENCE_STALE_TTL),
 *     refreshed on join / move / pong (touchPresence).
 *   - dc:presence:index and dc:presence:active  ZSETs, score = last-seen unix
 *     ts, member = raw client id (both written by presenceIndexAdd()).
 *   - Session/throttle/bounds keys under dc:presence:* — client_session,
 *     session_clients, move_batch, move_throttle, viewport, room_bounds, ping,
 *     ping_sent, cleanup. Read via the actual builders in Events.php.
 *   - Lock VALUES are RAW (never JSON): a lock may only be planted through the
 *     double's raw set($key, $token, ['nx','ex'=>…]). No presence op takes a
 *     lock, so this file plants none; the rule is pinned here because the
 *     facade's set() would silently corrupt one.
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
 *  - KEY SHAPE. Presence is per-CONNECTION: dc:presence:client:<client_id>,
 *    indexed by the dc:presence:index / dc:presence:active ZSETs (multiple tabs
 *    share a uid). Nothing is ever keyed by uid.
 *
 *  - MOVE. There is no per-move dc.presence.updated broadcast. Moves are
 *    written to dc:presence:move_batch:<client_id> and coalesced by a one-shot
 *    50ms timer into ONE dc.presence.batch_updated event (BUG-B7). The timer is
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
 * @see Applications/Chat/SharedState.php
 */

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the Channel tripwire, the recording Timer loop and the fake
    // Gateway, then loads FeatureFlags + Events (and through them SharedState).
    // The offline legacy shared-state stub it also declares belongs to sibling
    // suites still mid-migration; this suite never touches it.
    require_once __DIR__.'/V1TestSupport.php';

    class EventsV1DcPresenceTest extends TestCase
    {
        use DcTransportAssertions;

        private const REMOTE = '203.0.113.10';
        private const UID = 77;
        private const NAME = 'adminuser';

        /** @var InMemoryRedis the SharedState double injected by setUp() */
        private $redis;

        /** @var string 20-char hex gateway client id (never an int) */
        private string $clientId;

        /** @var string a second connection, for peer/viewport cases */
        private string $peerId;

        protected function setUp(): void
        {
            $this->clientId = dc_client_id(10);
            $this->peerId = dc_client_id(11);

            // SharedState prefers $GLOBALS['redis'] over any injected client, so
            // clear it first, then hand the facade a fresh in-memory double —
            // no $GLOBALS['redis'] leak from another suite may decide presence
            // behavior here.
            unset($GLOBALS['redis']);
            $this->redis = new InMemoryRedis();
            SharedState::setClient($this->redis);

            $this->resetState();
            $_SERVER['REMOTE_ADDR'] = self::REMOTE;
        }

        protected function tearDown(): void
        {
            $this->resetState();
            // REVERSE the injection rather than re-installing a fresh-but-empty
            // double: SharedState::$client is a process static, and a leaked
            // live client would invert FeatureFlags' fail-safe semantics for
            // every later suite (unset flag reads ON over a live client, OFF
            // only when the store is unreachable). Mirrors AuthHello exactly.
            SharedState::setClient(null);
            unset($GLOBALS['redis']);
            SharedState::reset();
        }

        /** Clear Gateway/session/Events statics only; the Redis double lives in setUp/tearDown. */
        private function resetState(): void
        {
            \GatewayWorker\Lib\Gateway::reset();
            \Channel\Client::reset();
            TestTimer::install();
            $_SESSION = [];
            \Events::$db = null;
            // PRODUCTION CONFIGURATION: no channel seam. The old suite installed
            // one in setUp() and thereby hid BUG-A3 completely.
            \Events::$channelClient = null;
            // $moveBatch is a dead relic (the batch moved into
            // dc:presence:move_batch:* keys); $moveBatchTimer is still live
            // process-local static state consulted by scheduleDcPresenceFlush().
            \Events::$moveBatchTimer = null;
        }

        /**
         * Flag A ON + an authenticated admin session, optionally with presence
         * members already in the scene, given as [client_id => presence entry].
         *
         * Each member is seeded through the SAME facade calls production makes:
         * SharedState::set for the dc:presence:client:<id> record and
         * SharedState::zAdd for both index ZSETs (presenceIndexAdd's shape), so
         * the facade's JSON member encoding round-trips exactly like a live join.
         *
         * No index "seeding" sentinel exists anymore: Redis zAdd creates each
         * index on first write (the retired CAS-era seedClientIndex() had no
         * job here beyond that — see Events::presenceIndexAdd()'s migration
         * note). Flag C (bot presence) is OFF so the bot system does not
         * interfere with plain presence assertions; EventsBotPresenceTest
         * covers it with C on.
         *
         * @param array<string,array> $members client_id => presence entry
         */
        private function authedSession(array $members = []): void
        {
            // Flags are plain dc:flag: keys with no TTL; written through the
            // facade exactly like FeatureFlags' own operators do.
            SharedState::set(FeatureFlags::VAR_NEW_HANDLING, 1);
            SharedState::set(FeatureFlags::VAR_DC_BOT_PRESENCE, 0);

            foreach ($members as $clientId => $entry) {
                $this->seedPresenceMember((string) $clientId, $entry);
            }

            $_SESSION['v1_authed'] = true;
            $_SESSION['uid'] = self::UID;
            $_SESSION['name'] = self::NAME;
            $_SESSION['ima'] = 'admin';
            $_SESSION['login'] = true;
        }

        /** Put one member in the scene the way a completed join would have. */
        private function seedPresenceMember(string $clientId, array $entry): void
        {
            SharedState::set(\Events::DC_PRESENCE_KEY_PREFIX.$clientId, $entry, \Events::PRESENCE_STALE_TTL);
            SharedState::zAdd(\Events::DC_PRESENCE_INDEX_KEY, $entry['ts'], $clientId);
            SharedState::zAdd(\Events::DC_ACTIVE_INDEX_KEY, $entry['ts'], $clientId);
        }

        /** The per-connection presence record, decoded through the facade. */
        private function presenceRecord(string $clientId)
        {
            return SharedState::get(\Events::DC_PRESENCE_KEY_PREFIX.$clientId);
        }

        /** @return array<int,mixed> raw client ids, score order */
        private function presenceIndex(): array
        {
            return SharedState::zRange(\Events::DC_PRESENCE_INDEX_KEY, 0, -1);
        }

        /** @return array<int,mixed> raw client ids, score order */
        private function activeIndex(): array
        {
            return SharedState::zRange(\Events::DC_ACTIVE_INDEX_KEY, 0, -1);
        }

        /** @return array<int,string> every key currently in the raw keyspace */
        private function keys(): array
        {
            return $this->redis->allKeys();
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
         * reached it. Nothing in this path may narrow client_id to int — and
         * the ZSET round-trip must hand the raw hex string back, never a
         * mangled or double-encoded variant.
         */
        public function testHexStringClientIdFlowsThroughPresenceWithoutTypeError(): void
        {
            $this->authedSession();

            $this->assertSame(20, strlen($this->clientId), 'fixture must be a REAL 20-char hex client_id');
            $this->assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $this->clientId);
            $this->assertIsString($this->clientId);
            $this->assertNotSame(
                (string) (int) $this->clientId,
                $this->clientId,
                'the fixture must be an id that intval() would mangle — that is the bug being guarded'
            );

            $this->presenceOp('dc.presence.join', ['x' => 1.5, 'z' => 2.5, 'yaw' => 0.25], null, 'join-1');

            // The record key and BOTH index members must carry the full hex
            // string verbatim — key builder and facade JSON round-trip alike.
            $this->assertContains(
                'dc:presence:client:'.$this->clientId,
                $this->keys(),
                'presence key must be built from the hex client_id verbatim'
            );
            $this->assertSame([$this->clientId], $this->presenceIndex(), 'index member must round-trip as the raw hex string');
            $this->assertSame([$this->clientId], $this->activeIndex());

            $this->presenceOp('dc.presence.move', ['x' => 3.5, 'z' => 4.5, 'yaw' => 0.75], null, 'move-1');
            TestTimer::runAll();
            $this->presenceOp('dc.presence.leave', [], null, 'leave-1');

            $this->assertNull($this->presenceRecord($this->clientId));
            $this->assertSame([], $this->presenceIndex());
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
            $this->authedSession();

            $method = new ReflectionMethod(\Events::class, 'trackSessionClient');
            $method->setAccessible(true);
            $method->invoke(null, $this->clientId, 'mystage-session-abc');

            $this->assertSame(
                'mystage-session-abc',
                SharedState::get('dc:presence:client_session:'.$this->clientId),
                'client_id -> session mapping must be keyed by the hex string'
            );
            $this->assertSame(
                [$this->clientId],
                SharedState::get('dc:presence:session_clients:mystage-session-abc'),
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
            $this->authedSession();

            $this->presenceOp('dc.presence.join', ['x' => 10.5, 'z' => -3.25, 'yaw' => 1.57]);

            $entry = $this->presenceRecord($this->clientId);
            $this->assertIsArray($entry, 'join must store dc:presence:client:<client_id>');
            $this->assertSame(10.5, $entry['x']);
            $this->assertSame(-3.25, $entry['z']);
            $this->assertSame(1.57, $entry['yaw']);
            $this->assertSame(self::UID, $entry['uid']);
            $this->assertSame(self::NAME, $entry['name']);
            $this->assertSame($this->clientId, $entry['client_id'], 'entry carries its own hex client_id');
            $this->assertArrayHasKey('ts', $entry);

            $this->assertSame([$this->clientId], $this->presenceIndex());
            $this->assertSame([$this->clientId], $this->activeIndex());

            // Presence is per-connection: nothing may be written under the uid.
            $this->assertNotContains('dc:presence:'.self::UID, $this->keys());
            $this->assertNotContains('dc:presence:client:'.self::UID, $this->keys());
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
            $this->authedSession();

            $this->presenceOp('dc.presence.join', [
                'x' => 0.0, 'z' => 0.0, 'yaw' => 0.0,
                'bounds' => ['minX' => -120.0, 'maxX' => 40.0, 'minZ' => -130.0, 'maxZ' => 20.0],
            ]);

            // Numeric equality, not identical(): the facade's JSON trip types
            // integral floats as ints on the wire (-120.0 arrives as -120), and
            // the browser's JSON.parse would see the very same shape.
            $this->assertEquals(
                ['minX' => -120.0, 'maxX' => 40.0, 'minZ' => -130.0, 'maxZ' => 20.0],
                SharedState::get(\Events::DC_ROOM_BOUNDS_KEY_PREFIX.\Events::BOT_DEFAULT_LOCATION)
            );
        }

        public function testJoinIgnoresHostileRoomBounds(): void
        {
            $this->authedSession();

            $this->presenceOp('dc.presence.join', [
                'x' => 0.0, 'z' => 0.0, 'yaw' => 0.0,
                'bounds' => ['minX' => -1e300, 'maxX' => 1e300, 'minZ' => -1e300, 'maxZ' => 1e300],
            ]);

            $this->assertNull(
                SharedState::get(\Events::DC_ROOM_BOUNDS_KEY_PREFIX.\Events::BOT_DEFAULT_LOCATION),
                'absurd bounds must be rejected outright, not recorded'
            );
        }

        // ====================================================================
        // dc.presence.move (fire-and-forget + 50ms batch coalescing)
        // ====================================================================

        public function testMoveUpdatesEntryAndQueuesBatchWithoutReplying(): void
        {
            $this->authedSession([
                $this->clientId => $this->presenceEntry($this->clientId),
            ]);

            $this->presenceOp('dc.presence.move', ['x' => 99.5, 'z' => -77.25, 'yaw' => 3.0]);

            // (float) reads tolerate the JSON wire's int/float unification of
            // integral values (3.0 round-trips as int 3) without loosening the
            // exactness of the comparison itself.
            $entry = $this->presenceRecord($this->clientId);
            $this->assertSame(99.5, $entry['x']);
            $this->assertSame(-77.25, $entry['z']);
            $this->assertSame(3.0, (float) $entry['yaw']);

            $this->assertEmpty($this->sent(), 'move is fire-and-forget: NO reply to the mover');
            $this->assertSame(
                [],
                \GatewayWorker\Lib\Gateway::$sentToGroup,
                'a move must not broadcast immediately — it is coalesced by the 50ms batch timer'
            );

            $queued = SharedState::get('dc:presence:move_batch:'.$this->clientId);
            $this->assertIsArray($queued, 'move must queue the entry array at dc:presence:move_batch:<client_id>');
            $this->assertSame(99.5, $queued['x']);
        }

        /**
         * The one-shot flush timer coalesces queued moves into ONE
         * dc.presence.batch_updated event on the dc_presence group.
         */
        public function testBatchFlushBroadcastsBatchUpdatedViaGatewayGroup(): void
        {
            $this->authedSession([
                $this->clientId => $this->presenceEntry($this->clientId),
                $this->peerId => $this->presenceEntry($this->peerId),
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
            $this->assertNull(SharedState::get('dc:presence:move_batch:'.$this->clientId));
            $this->assertNull(SharedState::get('dc:presence:move_batch:'.$this->peerId));

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

            $this->authedSession([
                $mover => $this->presenceEntry($mover, 500.0, 500.0),
                $watcher => $this->presenceEntry($watcher),
                $blind => $this->presenceEntry($blind),
            ]);
            // flushPresenceBatch() only sends to clients with a session mapping.
            SharedState::set('dc:presence:client_session:'.$watcher, 'sess-w');
            SharedState::set('dc:presence:client_session:'.$blind, 'sess-b');
            SharedState::set('dc:presence:viewport:'.$watcher, [
                'x' => 0.0, 'z' => 0.0, 'dirX' => -1.0, 'dirZ' => 0.0,
                'viewDist' => 50.0, 'ts' => time(),
            ]);
            SharedState::set('dc:presence:move_batch:'.$mover, $this->presenceEntry($mover, 500.0, 500.0));

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
            $this->authedSession([
                $this->clientId => $this->presenceEntry($this->clientId),
                $victim => $this->presenceEntry($victim, 100.0, 100.0),
            ]);

            $this->presenceOp('dc.presence.move', [
                'x' => -1.0, 'z' => -1.0, 'yaw' => 0.0, 'clientId' => $victim,
            ]);

            $victimEntry = $this->presenceRecord($victim);
            $this->assertSame(100.0, (float) $victimEntry['x'], 'another connection\'s avatar must not be moved');
            $this->assertSame(100.0, (float) $victimEntry['z']);

            $ownEntry = $this->presenceRecord($this->clientId);
            $this->assertSame(0.0, (float) $ownEntry['x'], 'a mismatching supplied clientId drops the move entirely');
            $this->assertNull(SharedState::get('dc:presence:move_batch:'.$this->clientId));
        }

        /** An echoed-back OWN clientId (older clients do this) is tolerated. */
        public function testMoveWithOwnClientIdEchoedBackIsAccepted(): void
        {
            $this->authedSession([
                $this->clientId => $this->presenceEntry($this->clientId),
            ]);

            $this->presenceOp('dc.presence.move', [
                'x' => 12.0, 'z' => 13.0, 'yaw' => 0.0, 'clientId' => $this->clientId,
            ]);

            $this->assertSame(12.0, (float) $this->presenceRecord($this->clientId)['x']);
        }

        /** 150ms per-connection throttle mirrors the client THROTTLE_MS. */
        public function testMoveIsThrottledPerConnection(): void
        {
            $this->authedSession([
                $this->clientId => $this->presenceEntry($this->clientId),
            ]);
            SharedState::set('dc:presence:move_throttle:'.$this->clientId, microtime(true));

            $this->presenceOp('dc.presence.move', ['x' => 42.0, 'z' => 42.0, 'yaw' => 0.0]);

            $this->assertSame(
                0.0,
                (float) $this->presenceRecord($this->clientId)['x'],
                'a move within 150ms of the previous one must be dropped'
            );
        }

        // ====================================================================
        // dc.presence.leave
        // ====================================================================

        public function testLeaveRemovesEntryRepliesAndBroadcastsLeft(): void
        {
            $this->authedSession([
                $this->clientId => $this->presenceEntry($this->clientId),
            ]);

            $this->presenceOp('dc.presence.leave', [], null, 'leave-req');

            $this->assertNull($this->presenceRecord($this->clientId), 'entry must be cleared');
            $this->assertSame([], $this->presenceIndex(), 'index must drop the client');
            $this->assertSame([], $this->activeIndex(), 'the recipient index must drop the client too');

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
         *
         * The old fixture also seeded the 'running' and 'rooms' shared-state
         * registries; both are Redis HASH/SETs now (dc:state:rooms,
         * dc:state:running_ids) that read as empty when absent, so onClose
         * needs no pre-seeding of them at all.
         */
        public function testOnCloseBroadcastsLeftAndCleansUpConnectionKeys(): void
        {
            $this->authedSession([
                $this->clientId => $this->presenceEntry($this->clientId),
            ]);
            SharedState::set('dc:presence:client_session:'.$this->clientId, 'sess-1');
            SharedState::set('dc:presence:session_clients:sess-1', [$this->clientId]);
            SharedState::set(\Events::DC_PONG_KEY_PREFIX.$this->clientId, time());
            SharedState::set(\Events::DC_PING_SENT_KEY_PREFIX.$this->clientId, time());
            SharedState::set('dc:presence:viewport:'.$this->clientId, ['ts' => time()]);
            SharedState::set('dc:presence:move_throttle:'.$this->clientId, microtime(true));
            \GatewayWorker\Lib\Gateway::$uidClientIds[self::UID] = [$this->clientId];

            \Events::onClose($this->clientId);

            $events = $this->presenceGroupEvents('dc.presence.left');
            $this->assertCount(1, $events, 'onClose must announce the departure');
            $this->assertSame($this->clientId, $events[0]['data']['clientId']);

            $this->assertNull($this->presenceRecord($this->clientId));
            $this->assertSame([], $this->presenceIndex());
            $this->assertSame([], $this->activeIndex());
            $this->assertNull(SharedState::get('dc:presence:client_session:'.$this->clientId));
            $this->assertSame([], SharedState::get('dc:presence:session_clients:sess-1'));
            $this->assertNull(SharedState::get(\Events::DC_PONG_KEY_PREFIX.$this->clientId));
            $this->assertNull(SharedState::get(\Events::DC_PING_SENT_KEY_PREFIX.$this->clientId));
            $this->assertNull(SharedState::get('dc:presence:viewport:'.$this->clientId));
            $this->assertNull(SharedState::get('dc:presence:move_throttle:'.$this->clientId));
            $this->assertNull(SharedState::get('dc:presence:cleanup:'.$this->clientId), 'the cleanup sentinel must be released');

            $this->assertDeadChannelTransportUnused('onClose:');
        }

        // ====================================================================
        // pong / liveness keys (BUG-B3/B4)
        // ====================================================================

        /**
         * A pong records the RECEIPT time under dc:presence:ping: and must not
         * touch dc:presence:ping_sent:. Writing the send time into the pong key
         * (the old bug) made a correctly answering client indistinguishable
         * from a silent one.
         */
        public function testPongRecordsReceiptTimeUnderPongKeyOnly(): void
        {
            $this->authedSession();
            SharedState::set(\Events::DC_PING_SENT_KEY_PREFIX.$this->clientId, 1000);

            \Events::dispatchV1($this->clientId, [
                'v' => 1, 'id' => 'keepalive', 'op' => 'pong', 'ts' => time(), 'data' => [],
            ]);

            $this->assertGreaterThan(
                0,
                (int) SharedState::get(\Events::DC_PONG_KEY_PREFIX.$this->clientId),
                'pong must record the receipt time under dc:presence:ping:<client_id>'
            );
            $this->assertSame(
                1000,
                SharedState::get(\Events::DC_PING_SENT_KEY_PREFIX.$this->clientId),
                'pong must NOT overwrite dc:presence:ping_sent: (that is the hub\'s send record)'
            );
            $this->assertSame([], $this->sent(), 'a pong is not answered');
        }

        // ====================================================================
        // dc.viewport.update
        // ====================================================================

        public function testViewportUpdateStoresNormalisedViewportForConnection(): void
        {
            $this->authedSession();

            \Events::dispatchV1($this->clientId, [
                'v' => 1, 'id' => 'vp-1', 'op' => 'dc.viewport.update', 'ts' => time(),
                'data' => ['x' => 1, 'y' => 2, 'z' => 3, 'dirX' => 0, 'dirY' => 0, 'dirZ' => -1, 'viewDist' => 80],
            ]);

            $vp = SharedState::get('dc:presence:viewport:'.$this->clientId);
            $this->assertIsArray($vp);
            // Handler casts to float; the JSON wire types integral floats as
            // ints, so the (float) read is the faithful numeric assertion.
            $this->assertSame(1.0, (float) $vp['x']);
            $this->assertSame(-1.0, (float) $vp['dirZ']);
            $this->assertSame(80.0, (float) $vp['viewDist']);
            $this->assertArrayHasKey('ts', $vp);
            $this->assertSame([], $this->sent(), 'viewport updates are fire-and-forget');
        }

        public function testViewportUpdateRequiresLogin(): void
        {
            $this->authedSession();
            unset($_SESSION['login']);

            \Events::dispatchV1($this->clientId, [
                'v' => 1, 'id' => 'vp-1', 'op' => 'dc.viewport.update', 'ts' => time(),
                'data' => ['x' => 1, 'z' => 3, 'dirX' => 0, 'dirZ' => -1],
            ]);

            $this->assertNull(SharedState::get('dc:presence:viewport:'.$this->clientId));
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
            $this->authedSession();
            FeatureFlags::setNewHandling(null, false);

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0]);

            $this->assertSame([], $this->sent(), 'Flag A OFF: no reply');
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$sentToGroup, 'Flag A OFF: no broadcast');
            $this->assertNull($this->presenceRecord($this->clientId));
            $this->assertSame([], $this->presenceIndex());
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
        // Cold start: a fresh empty store must just work
        // ====================================================================

        /**
         * MIGRATION REFRAME (phase 5.2-C).
         *
         * This slot used to pin a CAS livelock: on the retired shared-state
         * store, an unseeded presence index key made every bounded index
         * compare-and-swap loop unstartable until something called
         * seedClientIndex()/add() first. All of that machinery —
         * seedClientIndex(), casShouldRetry(), CAS_MAX_ATTEMPTS and the
         * add('running', []) cold-start sentinel — is RETIRED; the authoritative
         * note lives in Events::presenceIndexAdd() (Events.php, migration A2):
         * Redis zAdd CREATES the index on first write, there is no compare step
         * to lose and nothing to seed. Likewise onWorkerStart's boot gate is now
         * SharedState::lock('startup_reap', 60) + an hSetNx room seed
         * (Events.php ~:505-538) and touches no presence key at all.
         *
         * What remains to pin is the positive half: against a completely fresh,
         * empty Redis the FIRST dc.presence.join must run to completion — reply
         * sent, broadcast out — and create both index ZSETs with the joiner in
         * them.
         */
        public function testJoinCreatesBothIndexesOnAFreshEmptyStore(): void
        {
            $this->authedSession(); // flags + session only; no presence key exists yet

            $this->assertFalse(SharedState::exists(\Events::DC_PRESENCE_INDEX_KEY), 'precondition: full-membership index absent');
            $this->assertFalse(SharedState::exists(\Events::DC_ACTIVE_INDEX_KEY), 'precondition: recipient index absent');

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0], null, 'join-1');

            $this->assertSame([$this->clientId], $this->presenceIndex(), 'join must create the index and record the joiner');
            $this->assertSame([$this->clientId], $this->activeIndex(), 'and the recipient index in the same stroke');
            $this->assertIsArray($this->presenceRecord($this->clientId), 'the record key must exist beside the indexes');

            $reply = $this->singleReply();
            $this->assertIsV1Reply($reply, 'join-1');
            $this->assertCount(1, $this->presenceGroupEvents('dc.presence.joined'));
        }

        /**
         * The same fresh-store guarantee for the whole live path, not just the
         * first write: join -> move -> 50ms flush -> leave against a cold,
         * empty store. The flush enumerates the ZSET indexes the join itself
         * created, so this also proves the facade's JSON member encoding
         * round-trips the raw hex ids exactly (zAdd in, zRange out) — the
         * indexes must never contain a double-encoded or mangled member.
         */
        public function testMoveAndFlushWorkEndToEndOnAFreshEmptyStore(): void
        {
            $this->authedSession();

            $this->presenceOp('dc.presence.join', ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0]);
            $this->presenceOp('dc.presence.move', ['x' => 7.5, 'z' => 8.5, 'yaw' => 0.0]);

            TestTimer::runAll();

            $events = $this->presenceGroupEvents('dc.presence.batch_updated');
            $this->assertCount(1, $events, 'the flush must find the move queued via the self-created index');
            $this->assertArrayHasKey($this->clientId, $events[0]['data'], 'raw hex member round-tripped through the index');
            $this->assertEqualsWithDelta(7.5, $events[0]['data'][$this->clientId]['x'], 0.001);

            $this->assertNull(SharedState::get('dc:presence:move_batch:'.$this->clientId), 'batch key cleared after flush');

            $this->presenceOp('dc.presence.leave', [], null, 'leave-req');
            $this->assertSame([], $this->presenceIndex(), 'leave empties the index the cold join created');
            $this->assertSame([], $this->activeIndex());
        }
    }
}
