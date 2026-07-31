<?php

/**
 * Tests for the DataCenter bot-presence system: a simulated visitor avatar that
 * walks the 3D scene while real users are there.
 *
 * WHAT CHANGED HERE AND WHY
 *
 *  - 22 of 26 cases were ERRORS on baseline: every spawn/move/cleanup path ends
 *    in \Workerman\Timer::add(), which throws 'Timer can only be used in
 *    workerman running environment' outside a worker. TestTimer (see
 *    tests/TestBootstrap.php) injects a recording
 *    Workerman\Events\EventInterface into Timer's own $event seam, so the bot
 *    timers are now created, inspected, DELETED and FIRED for real.
 *
 *  - The fake GlobalData double's cas() treated an absent key as [] and compared
 *    with ===, so the CAS loops always succeeded first try. The real server
 *    compares md5(serialize()) and reports an absent key as NULL, which is what
 *    InMemoryGlobalData now models — see the dc_presence_clients livelock pinned
 *    in EventsV1DcPresenceTest.
 *
 *  - Broadcast assertions went through Events::$channelClient, which the old
 *    setUp() ALWAYS installed. That is precisely how the dead
 *    \Channel\Client::publish() transport (BUG-A3) stayed green for months.
 *    The seam is gone; broadcasts are asserted on
 *    Gateway::sendToGroup('dc_presence', …) and the dead transport is a
 *    recording tripwire every test checks.
 *
 *  - Real-user fixtures used int client_id 999. A gateway client_id is a 20-char
 *    hex STRING; the bot's is the non-hex sentinel 'bot_<location>'. Both shapes
 *    coexist in dc_presence_clients and the bot/real distinction is
 *    `strpos($cid, 'bot_') === 0`, so the fixtures must use realistic hex ids
 *    (dc_client_id()) or that discrimination is never really exercised.
 *
 *  - testMoveBotSkipsWhenNoRealUsers asserted only that bot state still
 *    existed — true whether or not anything was skipped. Replaced with the real
 *    invariants (walk step, target selection, clamp, timer ownership).
 *
 * @see Events::spawnBotForLocation()
 * @see Events::moveBot()
 * @see Events::cleanupBotForLocation()
 * @see Events::hasRealUsersAtLocation()
 */

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__.'/V1TestSupport.php';

    class EventsBotPresenceTest extends TestCase
    {
        use DcTransportAssertions;

        private const LOCATION = 'main';
        private const BOT_ID = 'bot_main';

        /** @var string a real user's 20-char hex connection id */
        private string $userId;

        protected function setUp(): void
        {
            $this->userId = dc_client_id(2001);
            $this->resetState();
            $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
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
            \Events::$channelClient = null;   // production configuration
            \Events::$moveBatch = [];
            \Events::$moveBatchTimer = null;
            unset($GLOBALS['global']);

            // Bot timer ids are process-local statics that survive between tests.
            $botTimers = new ReflectionProperty(\Events::class, 'botTimers');
            $botTimers->setAccessible(true);
            $botTimers->setValue(null, []);

            $ref = new ReflectionClass(FeatureFlags::class);
            $prop = $ref->getProperty('client');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        /** Flag C (dc_bot_presence) ON, with the indexes a live worker seeds. */
        private function botFlagOn(array $seed = []): InMemoryGlobalData
        {
            $global = new InMemoryGlobalData(array_merge([
                FeatureFlags::VAR_NEW_HANDLING => 1,
                FeatureFlags::VAR_DC_BOT_PRESENCE => 1,
                'dc_presence_clients' => [],
                'dc_active_clients' => [],
            ], $seed));
            $GLOBALS['global'] = $global;
            return $global;
        }

        /** Flag C explicitly OFF. */
        private function botFlagOff(array $seed = []): InMemoryGlobalData
        {
            return $this->botFlagOn(array_merge([FeatureFlags::VAR_DC_BOT_PRESENCE => 0], $seed));
        }

        /** Add a real (non-bot) user with a hex client_id at ($x,$z). */
        private function addRealUser(InMemoryGlobalData $global, string $clientId, float $x = 0.0, float $z = 0.0): void
        {
            $global->store['dc_presence:client:'.$clientId] = [
                'uid' => 4242, 'name' => 'admin', 'x' => $x, 'z' => $z, 'yaw' => 0.0,
                'ts' => time(), 'client_id' => $clientId,
            ];
            $list = $global->raw('dc_presence_clients') ?? [];
            $list[] = $clientId;
            $global->store['dc_presence_clients'] = array_values(array_unique($list));
            $active = $global->raw('dc_active_clients') ?? [];
            $active[] = $clientId;
            $global->store['dc_active_clients'] = array_values(array_unique($active));
        }

        /**
         * The ownership marker spawnBotForLocation() writes for THIS process.
         *
         * datacentered runs on three systems sharing one GlobalData store, and pids
         * are not unique across them, so the marker is host-qualified "<host>:<pid>"
         * rather than a bare pid. Fixtures elsewhere in this file deliberately still
         * seed the legacy bare-pid form: markerIsSelf()/botOwnerAlive() must keep
         * honouring it or a rolling restart across the three hosts would have the
         * owning process fail to recognise its own marker and retire its own timer,
         * killing the bot on its first tick.
         */
        private function selfMarker(): string
        {
            $host = gethostname();
            return (($host === false || $host === '') ? 'unknown-host' : $host).':'.getmypid();
        }

        /** The recorded repeating bot move timer(s). */
        private function botMoveTimers(): array
        {
            return array_values(array_filter(
                TestTimer::added(),
                static fn(array $t) => abs($t['interval'] - \Events::BOT_MOVE_INTERVAL) < 1e-9
                    && $t['args'] === [self::LOCATION]
            ));
        }

        // ====================================================================
        // spawnBotForLocation
        // ====================================================================

        public function testSpawnBotCreatesStatePresenceEntryAndIndexes(): void
        {
            $global = $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);

            $entry = $global->raw('dc_presence:client:'.self::BOT_ID);
            $this->assertIsArray($entry, 'the bot gets a presence entry like any client');
            $this->assertSame(self::BOT_ID, $entry['uid']);
            $this->assertSame(self::BOT_ID, $entry['client_id']);
            $this->assertSame(self::LOCATION, $entry['location']);
            $this->assertIsString($entry['name']);
            $this->assertIsFloat($entry['x']);
            $this->assertIsFloat($entry['z']);
            $this->assertIsFloat($entry['yaw']);

            $state = $global->raw('dc_bot_state:'.self::LOCATION);
            $this->assertIsArray($state);
            $this->assertArrayHasKey('target_x', $state);
            $this->assertArrayHasKey('target_z', $state);
            $this->assertSame($entry['x'], $state['x'], 'presence entry and bot state start identical');

            $this->assertSame([self::BOT_ID], $global->raw('dc_presence_clients'));
            $this->assertSame([self::BOT_ID], $global->raw('dc_active_clients'));
            $this->assertDeadChannelTransportUnused('spawn:');
        }

        /**
         * A bot client_id is the non-hex sentinel 'bot_<location>'. Everything
         * that separates bots from sockets keys off that prefix
         * (`strpos($cid, 'bot_') === 0`), so it must never be hex-shaped.
         */
        public function testBotClientIdIsThePrefixedSentinelNotAHexId(): void
        {
            $global = $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);

            $this->assertSame(self::BOT_ID, $global->raw('dc_presence_clients')[0]);
            $this->assertStringStartsWith('bot_', $global->raw('dc_presence_clients')[0]);
            $this->assertDoesNotMatchRegularExpression(
                '/^[0-9a-f]{20}$/',
                $global->raw('dc_presence_clients')[0],
                'a bot id must be distinguishable from a real 20-char hex client_id'
            );
        }

        public function testSpawnBotStartsARepeatingMoveTimerOwnedByThisPid(): void
        {
            $global = $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);

            $timers = $this->botMoveTimers();
            $this->assertCount(1, $timers, 'exactly one bot move timer');
            $this->assertTrue($timers[0]['persistent'], 'the bot walks on a REPEATING timer');
            $this->assertSame(\Events::BOT_MOVE_INTERVAL, $timers[0]['interval']);
            $this->assertSame(['Events', 'moveBot'], $timers[0]['func']);

            // THE BOT #4: GlobalData carries the OWNING PROCESS (host-qualified); the
            // raw (process-local) timer id must stay in the private static, because
            // Timer::del() of another process's id kills an unrelated timer in this one.
            $this->assertSame($this->selfMarker(), $global->raw('dc_bot_timer:'.self::LOCATION));
            $this->assertNotSame($timers[0]['id'], $global->raw('dc_bot_timer:'.self::LOCATION));

            $botTimers = new ReflectionProperty(\Events::class, 'botTimers');
            $botTimers->setAccessible(true);
            $this->assertSame([self::LOCATION => $timers[0]['id']], $botTimers->getValue());
        }

        /** The joined announcement goes out on the Gateway group, as an event. */
        public function testSpawnBotAnnouncesJoinedOverGatewayGroup(): void
        {
            $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);

            $events = $this->presenceGroupEvents('dc.presence.joined');
            $this->assertCount(1, $events, 'the bot must announce itself so frontends create the avatar');
            $this->assertIsV1Event($events[0], 'dc.presence.joined');

            $data = $events[0]['data'];
            $this->assertSame(self::BOT_ID, $data['clientId'], 'camelCase clientId, as the frontend expects');
            $this->assertArrayNotHasKey('client_id', $data);
            $this->assertSame(self::LOCATION, $data['location']);
            $this->assertArrayHasKey('name', $data);
            $this->assertArrayHasKey('x', $data);
            $this->assertArrayHasKey('z', $data);
            $this->assertArrayHasKey('yaw', $data);
            $this->assertDeadChannelTransportUnused('spawn announce:');
        }

        public function testSpawnBotSkippedWhenFlagCDisabled(): void
        {
            $global = $this->botFlagOff();

            \Events::spawnBotForLocation(self::LOCATION);

            $this->assertNull($global->raw('dc_presence:client:'.self::BOT_ID));
            $this->assertNull($global->raw('dc_bot_state:'.self::LOCATION));
            $this->assertSame([], $this->botMoveTimers(), 'no timer may be armed with Flag C off');
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$sentToGroup);
        }

        /** A live bot with a live owner is not respawned. */
        public function testSpawnBotIsIdempotentWhileTheOwnerIsAlive(): void
        {
            $global = $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);
            $first = $global->raw('dc_bot_state:'.self::LOCATION);
            $timerCountAfterFirst = count($this->botMoveTimers());

            \Events::spawnBotForLocation(self::LOCATION);

            $this->assertSame($first, $global->raw('dc_bot_state:'.self::LOCATION), 'state must not be rewritten');
            $this->assertCount($timerCountAfterFirst, $this->botMoveTimers(), 'no second move timer');
        }

        /**
         * A marker naming a DEAD pid means that worker's timer died with it, so
         * the bot would sit frozen forever. Ownership must be taken here.
         */
        public function testSpawnBotTakesOverWhenTheOwnerPidIsGone(): void
        {
            // pid 1 exists, so use an implausible one that is not us.
            $deadPid = 4194303;
            $global = $this->botFlagOn([
                'dc_bot_timer:'.self::LOCATION => $deadPid,
                'dc_bot_state:'.self::LOCATION => [
                    'uid' => self::BOT_ID, 'name' => 'Ghost', 'x' => 1.0, 'z' => 2.0, 'yaw' => 0.0,
                    'target_x' => 1.0, 'target_z' => 2.0, 'client_id' => self::BOT_ID,
                    'location' => self::LOCATION,
                ],
            ]);
            $this->assertFalse(is_dir('/proc/'.$deadPid), 'fixture pid must really be dead');

            \Events::spawnBotForLocation(self::LOCATION);

            $this->assertSame(
                $this->selfMarker(),
                $global->raw('dc_bot_timer:'.self::LOCATION),
                'ownership must transfer to the respawning worker'
            );
            $this->assertCount(1, $this->botMoveTimers(), 'a fresh move timer is armed here');
            $this->assertNotSame(
                'Ghost',
                $global->raw('dc_bot_state:'.self::LOCATION)['name'],
                'the frozen bot is replaced, not adopted'
            );
        }

        /** A marker with no state at all is stale: drop it and respawn. */
        public function testSpawnBotClearsAStaleMarkerWithNoState(): void
        {
            $global = $this->botFlagOn([
                'dc_bot_timer:'.self::LOCATION => 4194303,
            ]);

            \Events::spawnBotForLocation(self::LOCATION);

            $this->assertIsArray($global->raw('dc_bot_state:'.self::LOCATION));
            $this->assertSame($this->selfMarker(), $global->raw('dc_bot_timer:'.self::LOCATION));
        }

        // ====================================================================
        // Cross-host ownership (three datacentered instances, one GlobalData)
        // ====================================================================

        /**
         * A marker owned by ANOTHER instance whose bot is still being walked must be
         * left completely alone. Pre-fix, ownership was a bare pid checked with a LOCAL
         * is_dir('/proc/<pid>'), so a foreign pid that did not happen to exist on this
         * box read as "owner gone" and this host spawned a SECOND bot over one another
         * host was actively driving.
         */
        public function testForeignHostBotWithFreshHeartbeatIsNotTakenOver(): void
        {
            $global = $this->botFlagOn([
                'dc_bot_timer:'.self::LOCATION => 'my-web-9.example.net:4194303',
                'dc_bot_state:'.self::LOCATION => [
                    'uid' => self::BOT_ID, 'name' => 'RemoteWalker',
                    'x' => 5.0, 'z' => 6.0, 'yaw' => 0.0,
                    'target_x' => 5.0, 'target_z' => 6.0, 'client_id' => self::BOT_ID,
                    'location' => self::LOCATION,
                    'ts' => time(),   // heartbeat: moveBot() refreshes this every 0.5s
                ],
            ]);

            \Events::spawnBotForLocation(self::LOCATION);

            $this->assertSame(
                'my-web-9.example.net:4194303',
                $global->raw('dc_bot_timer:'.self::LOCATION),
                'must not steal ownership of a bot another instance is walking'
            );
            $this->assertSame(
                'RemoteWalker',
                $global->raw('dc_bot_state:'.self::LOCATION)['name'],
                'the remote bot state must be left intact'
            );
            $this->assertSame([], $this->botMoveTimers(), 'no competing move timer here');
        }

        /**
         * ...but if that instance has stopped refreshing the heartbeat it is presumed
         * dead and we DO take over — otherwise a bot orphaned by a dead host sits
         * frozen forever, which is indistinguishable from "the bot is broken". We
         * cannot inspect a remote /proc, so the bot's own ts is the liveness signal.
         */
        public function testForeignHostBotWithStaleHeartbeatIsTakenOver(): void
        {
            $global = $this->botFlagOn([
                'dc_bot_timer:'.self::LOCATION => 'my-web-9.example.net:4194303',
                'dc_bot_state:'.self::LOCATION => [
                    'uid' => self::BOT_ID, 'name' => 'Abandoned',
                    'x' => 5.0, 'z' => 6.0, 'yaw' => 0.0,
                    'target_x' => 5.0, 'target_z' => 6.0, 'client_id' => self::BOT_ID,
                    'location' => self::LOCATION,
                    'ts' => time() - (\Events::BOT_OWNER_HEARTBEAT_MAX_AGE + 5),
                ],
            ]);

            \Events::spawnBotForLocation(self::LOCATION);

            $this->assertSame(
                $this->selfMarker(),
                $global->raw('dc_bot_timer:'.self::LOCATION),
                'a heartbeat this stale means the owning instance is gone'
            );
            $this->assertNotSame(
                'Abandoned',
                $global->raw('dc_bot_state:'.self::LOCATION)['name'],
                'the frozen bot is replaced, not adopted'
            );
            $this->assertCount(1, $this->botMoveTimers());
        }

        /**
         * The owning process must recognise its OWN host-qualified marker. If it does
         * not, moveBot()'s duplicate-timer guard retires the very timer that drives the
         * bot, and the bot stops after a single tick.
         */
        public function testOwningProcessRecognisesItsOwnHostQualifiedMarker(): void
        {
            $global = $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            $this->assertSame($this->selfMarker(), $global->raw('dc_bot_timer:'.self::LOCATION));

            $before = $this->botMoveTimers();
            $this->assertCount(1, $before);

            \Events::moveBot(self::LOCATION);

            $botTimers = new ReflectionProperty(\Events::class, 'botTimers');
            $botTimers->setAccessible(true);
            $this->assertSame(
                [self::LOCATION => $before[0]['id']],
                $botTimers->getValue(),
                'the owner must NOT retire its own timer'
            );
            $this->assertNotNull($global->raw('dc_bot_state:'.self::LOCATION));
        }

        // ====================================================================
        // Spawn position (contract BOT-BOUNDS)
        // ====================================================================

        /**
         * With no reported bounds the bot spawns inside the FALLBACK +/-50 box,
         * inset so it never clips the walls.
         */
        public function testSpawnPositionStaysInsideTheInsetFallbackBounds(): void
        {
            $global = $this->botFlagOn();

            \Events::spawnBotForLocation(self::LOCATION);
            $state = $global->raw('dc_bot_state:'.self::LOCATION);

            $inset = \Events::BOT_BOUNDS_INSET;
            $this->assertGreaterThanOrEqual(\Events::BOT_BOUNDS_X_MIN + $inset, $state['x']);
            $this->assertLessThanOrEqual(\Events::BOT_BOUNDS_X_MAX - $inset, $state['x']);
            $this->assertGreaterThanOrEqual(\Events::BOT_BOUNDS_Z_MIN + $inset, $state['z']);
            $this->assertLessThanOrEqual(\Events::BOT_BOUNDS_Z_MAX - $inset, $state['z']);
            $this->assertSame(
                [
                    'minX' => \Events::BOT_BOUNDS_X_MIN + $inset,
                    'maxX' => \Events::BOT_BOUNDS_X_MAX - $inset,
                    'minZ' => \Events::BOT_BOUNDS_Z_MIN + $inset,
                    'maxZ' => \Events::BOT_BOUNDS_Z_MAX - $inset,
                ],
                $state['bounds'],
                'the recorded bounds are the INSET walkable box'
            );
        }

        /**
         * THE BOT #1: with browser-reported bounds the bot spawns inside the REAL
         * room, which dc.js builds nowhere near the world origin (racks start at
         * offsetX/offsetZ = -100), so the fallback box would put it out of sight.
         */
        public function testSpawnUsesBrowserReportedRoomBounds(): void
        {
            $global = $this->botFlagOn();
            $reported = ['minX' => -200.0, 'maxX' => -100.0, 'minZ' => -200.0, 'maxZ' => -100.0];

            \Events::spawnBotForLocation(self::LOCATION, null, $reported);
            $state = $global->raw('dc_bot_state:'.self::LOCATION);

            $this->assertSame($reported, $global->raw(\Events::DC_ROOM_BOUNDS_KEY_PREFIX.self::LOCATION));
            $this->assertGreaterThanOrEqual(-198.0, $state['x'], 'inside the reported room, not the +/-50 box');
            $this->assertLessThanOrEqual(-102.0, $state['x']);
            $this->assertGreaterThanOrEqual(-198.0, $state['z']);
            $this->assertLessThanOrEqual(-102.0, $state['z']);
        }

        /**
         * THE BOT #1/#3: the bot spawns within BOT_SPAWN_RADIUS of the joining
         * player, so it is actually visible instead of wandering empty space.
         */
        public function testSpawnIsNearTheJoiningPlayer(): void
        {
            $global = $this->botFlagOn();
            $reported = ['minX' => -300.0, 'maxX' => 300.0, 'minZ' => -300.0, 'maxZ' => 300.0];
            $near = ['x' => 150.0, 'z' => -150.0];

            \Events::spawnBotForLocation(self::LOCATION, $near, $reported);
            $state = $global->raw('dc_bot_state:'.self::LOCATION);

            $distance = sqrt(
                ($state['x'] - $near['x']) ** 2 + ($state['z'] - $near['z']) ** 2
            );
            $this->assertLessThanOrEqual(
                \Events::BOT_SPAWN_RADIUS + 1e-9,
                $distance,
                'the bot must spawn within BOT_SPAWN_RADIUS of the joining player'
            );
        }

        /**
         * With no $near, the anchor falls back to a random REAL player's last
         * known position (THE BOT #3) rather than a uniform point in the box.
         */
        public function testSpawnFallsBackToARealPlayerPositionWhenNoNearGiven(): void
        {
            $global = $this->botFlagOn();
            $reported = ['minX' => -400.0, 'maxX' => 400.0, 'minZ' => -400.0, 'maxZ' => 400.0];
            $global->store[\Events::DC_ROOM_BOUNDS_KEY_PREFIX.self::LOCATION] = $reported;
            $this->addRealUser($global, $this->userId, 300.0, 300.0);

            \Events::spawnBotForLocation(self::LOCATION);
            $state = $global->raw('dc_bot_state:'.self::LOCATION);

            $distance = sqrt(($state['x'] - 300.0) ** 2 + ($state['z'] - 300.0) ** 2);
            $this->assertLessThanOrEqual(
                \Events::BOT_SPAWN_RADIUS + 1e-9,
                $distance,
                'the only real player\'s position must be used as the spawn anchor'
            );
        }

        // ====================================================================
        // moveBot — the step-clamp invariant
        // ====================================================================

        /**
         * THE CLAMP INVARIANT.
         *
         * One tick moves BOT_WALK_SPEED * BOT_MOVE_INTERVAL = 11.7 * 0.5 = 5.85
         * units, which is far MORE than BOT_TARGET_THRESHOLD (1.0). An unclamped
         * step therefore overshoots the target, the next tick overshoots back,
         * and the bot oscillates around the target forever without ever getting
         * within the threshold to pick a new one. moveBot() must clamp the step
         * to the remaining distance:
         *     min(BOT_WALK_SPEED * BOT_MOVE_INTERVAL, $distance)
         *
         * Here the target is 2.0 units away — less than one tick — so after the
         * tick the bot must be exactly ON the target, never past it.
         */
        public function testMoveBotClampsTheStepToTheRemainingDistance(): void
        {
            $tick = \Events::BOT_WALK_SPEED * \Events::BOT_MOVE_INTERVAL;
            $this->assertGreaterThan(
                \Events::BOT_TARGET_THRESHOLD,
                $tick,
                'precondition: one tick is longer than the arrival threshold, so the clamp is load-bearing'
            );

            $global = $this->botFlagOn([
                'dc_room_bounds:'.self::LOCATION => ['minX' => -100.0, 'maxX' => 100.0, 'minZ' => -100.0, 'maxZ' => 100.0],
                'dc_bot_timer:'.self::LOCATION => getmypid(),
                'dc_bot_state:'.self::LOCATION => [
                    'uid' => self::BOT_ID, 'name' => 'Walker',
                    'x' => 0.0, 'z' => 0.0, 'yaw' => 0.0,
                    // 2.0 units away: more than the 1.0 threshold, less than a 5.85 tick.
                    'target_x' => 2.0, 'target_z' => 0.0,
                    'ts' => time(), 'client_id' => self::BOT_ID, 'location' => self::LOCATION,
                ],
            ]);
            $this->addRealUser($global, $this->userId, 0.0, 0.0);

            \Events::moveBot(self::LOCATION);
            $state = $global->raw('dc_bot_state:'.self::LOCATION);

            $this->assertEqualsWithDelta(
                2.0,
                $state['x'],
                1e-9,
                'the step must be clamped to the remaining 2.0 units — an unclamped 5.85 step '
                .'would land at x=5.85 and overshoot the target'
            );
            $this->assertEqualsWithDelta(0.0, $state['z'], 1e-9);
            $this->assertLessThanOrEqual(
                2.0,
                sqrt($state['x'] ** 2 + $state['z'] ** 2),
                'the bot must never travel further than the distance to its target'
            );
            // Having arrived, the next tick is free to pick a new target.
            $arrival = sqrt(($state['target_x'] - $state['x']) ** 2 + ($state['target_z'] - $state['z']) ** 2);
            $this->assertLessThan(
                \Events::BOT_TARGET_THRESHOLD,
                $arrival,
                'after a clamped step the bot is within the arrival threshold — which is the whole '
                .'point: without the clamp it can never get there'
            );
        }

        /** A far target produces exactly one full tick of travel. */
        public function testMoveBotWalksOneFullTickTowardADistantTarget(): void
        {
            $tick = \Events::BOT_WALK_SPEED * \Events::BOT_MOVE_INTERVAL;
            $global = $this->botFlagOn([
                'dc_room_bounds:'.self::LOCATION => ['minX' => -500.0, 'maxX' => 500.0, 'minZ' => -500.0, 'maxZ' => 500.0],
                'dc_bot_timer:'.self::LOCATION => getmypid(),
                'dc_bot_state:'.self::LOCATION => [
                    'uid' => self::BOT_ID, 'name' => 'Walker',
                    'x' => 0.0, 'z' => 0.0, 'yaw' => 0.0,
                    'target_x' => 400.0, 'target_z' => 0.0,
                    'ts' => time(), 'client_id' => self::BOT_ID, 'location' => self::LOCATION,
                ],
            ]);
            $this->addRealUser($global, $this->userId, 0.0, 0.0);

            \Events::moveBot(self::LOCATION);
            $state = $global->raw('dc_bot_state:'.self::LOCATION);

            $this->assertEqualsWithDelta($tick, $state['x'], 1e-9, 'one tick = speed * interval = 5.85 units');
            $this->assertSame(400.0, $state['target_x'], 'a distant target is kept, not re-rolled');
        }

        /**
         * Repeated ticks must CONVERGE. Without the clamp this loop oscillates
         * around the target forever and the assertion below never holds.
         */
        public function testRepeatedTicksConvergeOnTheTargetInsteadOfOscillating(): void
        {
            $global = $this->botFlagOn([
                'dc_room_bounds:'.self::LOCATION => ['minX' => -100.0, 'maxX' => 100.0, 'minZ' => -100.0, 'maxZ' => 100.0],
                'dc_bot_timer:'.self::LOCATION => getmypid(),
                'dc_bot_state:'.self::LOCATION => [
                    'uid' => self::BOT_ID, 'name' => 'Walker',
                    'x' => 0.0, 'z' => 0.0, 'yaw' => 0.0,
                    'target_x' => 20.0, 'target_z' => 0.0,
                    'ts' => time(), 'client_id' => self::BOT_ID, 'location' => self::LOCATION,
                ],
            ]);
            // No real players: randomRealClientPosition() returns null, so a new
            // target would be uniform in bounds — but we assert convergence on the
            // ORIGINAL target, which is only reachable if the step is clamped.
            $arrived = false;
            for ($tick = 0; $tick < 4; $tick++) {
                \Events::moveBot(self::LOCATION);
                $state = $global->raw('dc_bot_state:'.self::LOCATION);
                if (abs($state['x'] - 20.0) < \Events::BOT_TARGET_THRESHOLD && abs($state['z']) < 1e-9) {
                    $arrived = true;
                    break;
                }
                $this->assertLessThanOrEqual(
                    20.0,
                    $state['x'],
                    'the bot must never step PAST the target (overshoot = oscillation forever)'
                );
            }
            $this->assertTrue(
                $arrived,
                '20 units at 5.85/tick must be covered in 4 ticks; failing this means the step '
                .'overshoots and the bot never "arrives" to pick a new target'
            );
        }

        /** On arrival a new target is chosen, inside the room bounds. */
        public function testMoveBotPicksANewInBoundsTargetOnArrival(): void
        {
            $bounds = ['minX' => -60.0, 'maxX' => 60.0, 'minZ' => -60.0, 'maxZ' => 60.0];
            $global = $this->botFlagOn([
                'dc_room_bounds:'.self::LOCATION => $bounds,
                'dc_bot_timer:'.self::LOCATION => getmypid(),
                'dc_bot_state:'.self::LOCATION => [
                    'uid' => self::BOT_ID, 'name' => 'Walker',
                    'x' => 0.0, 'z' => 0.0, 'yaw' => 0.0,
                    // Already within BOT_TARGET_THRESHOLD of the target.
                    'target_x' => 0.1, 'target_z' => 0.1,
                    'ts' => time(), 'client_id' => self::BOT_ID, 'location' => self::LOCATION,
                ],
            ]);
            $this->addRealUser($global, $this->userId, 10.0, 10.0);

            \Events::moveBot(self::LOCATION);
            $state = $global->raw('dc_bot_state:'.self::LOCATION);

            $this->assertNotEquals(0.1, $state['target_x'], 'arrival must trigger a new target');
            $inset = \Events::BOT_BOUNDS_INSET;
            $this->assertGreaterThanOrEqual($bounds['minX'] + $inset, $state['target_x']);
            $this->assertLessThanOrEqual($bounds['maxX'] - $inset, $state['target_x']);
            $this->assertGreaterThanOrEqual($bounds['minZ'] + $inset, $state['target_z']);
            $this->assertLessThanOrEqual($bounds['maxZ'] - $inset, $state['target_z']);

            // THE BOT #3: the new target is near the only real player.
            $distance = sqrt(($state['target_x'] - 10.0) ** 2 + ($state['target_z'] - 10.0) ** 2);
            $this->assertLessThanOrEqual(
                \Events::BOT_WANDER_RADIUS + 1e-9,
                $distance,
                'targets are picked within BOT_WANDER_RADIUS of a real player'
            );
        }

        /** Movement is queued for the shared batch flush (BUG-B7), not broadcast directly. */
        public function testMoveBotQueuesTheBatchAndArmsTheSharedFlush(): void
        {
            $global = $this->botFlagOn([
                'dc_room_bounds:'.self::LOCATION => ['minX' => -100.0, 'maxX' => 100.0, 'minZ' => -100.0, 'maxZ' => 100.0],
                'dc_bot_timer:'.self::LOCATION => getmypid(),
                'dc_bot_state:'.self::LOCATION => [
                    'uid' => self::BOT_ID, 'name' => 'Walker',
                    'x' => 0.0, 'z' => 0.0, 'yaw' => 0.0,
                    'target_x' => 50.0, 'target_z' => 0.0,
                    'ts' => time(), 'client_id' => self::BOT_ID, 'location' => self::LOCATION,
                ],
                'dc_presence:client:'.self::BOT_ID => ['uid' => self::BOT_ID, 'client_id' => self::BOT_ID],
                'dc_presence_clients' => [self::BOT_ID],
                'dc_active_clients' => [self::BOT_ID],
            ]);
            $this->addRealUser($global, $this->userId, 0.0, 0.0);

            \Events::moveBot(self::LOCATION);

            $queued = json_decode((string) $global->raw('dc_move_batch:'.self::BOT_ID), true);
            $this->assertIsArray($queued, 'the bot move must be queued at dc_move_batch:bot_<location>');
            $this->assertArrayHasKey('x', $queued);
            $this->assertArrayHasKey('yaw', $queued);
            $this->assertSame(
                [],
                \GatewayWorker\Lib\Gateway::$sentToGroup,
                'moveBot must not broadcast directly — it arms the SHARED 50ms flush'
            );

            $flush = TestTimer::withInterval(0.05);
            $this->assertCount(1, $flush, 'moveBot arms exactly one shared flush timer');

            TestTimer::run($flush[0]['id']);

            $events = $this->presenceGroupEvents('dc.presence.batch_updated');
            $this->assertCount(1, $events, 'the flush emits the bot move as a batch event');
            $this->assertArrayHasKey(self::BOT_ID, $events[0]['data']);
            $this->assertDeadChannelTransportUnused('bot move flush:');
        }

        /**
         * The bot yaw faces the direction of travel: yaw = atan2(-dirX, -dirZ),
         * i.e. 0 = facing +Z.
         */
        public function testMoveBotFacesItsDirectionOfTravel(): void
        {
            $global = $this->botFlagOn([
                'dc_room_bounds:'.self::LOCATION => ['minX' => -100.0, 'maxX' => 100.0, 'minZ' => -100.0, 'maxZ' => 100.0],
                'dc_bot_timer:'.self::LOCATION => getmypid(),
                'dc_bot_state:'.self::LOCATION => [
                    'uid' => self::BOT_ID, 'name' => 'Walker',
                    'x' => 0.0, 'z' => 0.0, 'yaw' => 123.0,
                    'target_x' => -50.0, 'target_z' => 50.0,   // north-west diagonal
                    'ts' => time(), 'client_id' => self::BOT_ID, 'location' => self::LOCATION,
                ],
            ]);

            \Events::moveBot(self::LOCATION);
            $state = $global->raw('dc_bot_state:'.self::LOCATION);

            // dirX = -1/sqrt(2), dirZ = +1/sqrt(2) => atan2(0.7071, -0.7071) = 3*PI/4.
            $this->assertEqualsWithDelta(3 * M_PI / 4, $state['yaw'], 1e-9);
            // Sanity: the heading really points at the movement delta.
            $this->assertEqualsWithDelta(
                atan2(-$state['x'], -$state['z']),
                $state['yaw'],
                1e-9,
                'yaw must be derived from the actual displacement'
            );
        }

        /** Positions are clamped into the room even if state drifted outside it. */
        public function testMoveBotClampsPositionIntoTheRoomBounds(): void
        {
            $bounds = ['minX' => -10.0, 'maxX' => 10.0, 'minZ' => -10.0, 'maxZ' => 10.0];
            $global = $this->botFlagOn([
                'dc_room_bounds:'.self::LOCATION => $bounds,
                'dc_bot_timer:'.self::LOCATION => getmypid(),
                'dc_bot_state:'.self::LOCATION => [
                    'uid' => self::BOT_ID, 'name' => 'Walker',
                    'x' => 7.0, 'z' => 0.0, 'yaw' => 0.0,
                    'target_x' => 1000.0, 'target_z' => 0.0,   // far outside the room
                    'ts' => time(), 'client_id' => self::BOT_ID, 'location' => self::LOCATION,
                ],
            ]);

            \Events::moveBot(self::LOCATION);
            $state = $global->raw('dc_bot_state:'.self::LOCATION);

            $inset = min(\Events::BOT_BOUNDS_INSET, 20.0 / 4, 20.0 / 4);
            $this->assertLessThanOrEqual($bounds['maxX'] - $inset, $state['x'], 'must never clip through a wall');
            $this->assertGreaterThanOrEqual($bounds['minX'] + $inset, $state['x']);
        }

        // ====================================================================
        // moveBot — ownership + flag control
        // ====================================================================

        public function testMoveBotCleansUpWhenFlagCGoesOff(): void
        {
            $global = $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            $this->assertIsArray($global->raw('dc_bot_state:'.self::LOCATION));
            $timerId = $this->botMoveTimers()[0]['id'];

            $global->store[FeatureFlags::VAR_DC_BOT_PRESENCE] = 0;
            \Events::moveBot(self::LOCATION);

            $this->assertNull($global->raw('dc_bot_state:'.self::LOCATION), 'state removed');
            $this->assertNull($global->raw('dc_presence:client:'.self::BOT_ID), 'avatar removed');
            $this->assertContains($timerId, TestTimer::deleted(), 'the move timer must be deleted');
        }

        public function testMoveBotCleansUpWhenBotStateVanishes(): void
        {
            $global = $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            $timerId = $this->botMoveTimers()[0]['id'];
            unset($global->store['dc_bot_state:'.self::LOCATION]);

            \Events::moveBot(self::LOCATION);

            $this->assertContains($timerId, TestTimer::deleted());
            $this->assertNotContains(self::BOT_ID, $global->raw('dc_presence_clients'));
        }

        /**
         * THE BOT #4: if another worker owns the marker, this process retires ITS
         * OWN timer instead of double-driving the shared state — and it must
         * delete only the id it created itself.
         */
        public function testMoveBotRetiresItsOwnTimerWhenAnotherWorkerOwnsTheBot(): void
        {
            $global = $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            $ourTimerId = $this->botMoveTimers()[0]['id'];
            $stateBefore = $global->raw('dc_bot_state:'.self::LOCATION);

            // Another BusinessWorker has taken ownership.
            $global->store['dc_bot_timer:'.self::LOCATION] = 4194303;

            \Events::moveBot(self::LOCATION);

            $this->assertContains($ourTimerId, TestTimer::deleted(), 'we retire OUR timer');
            $this->assertSame(
                $stateBefore,
                $global->raw('dc_bot_state:'.self::LOCATION),
                'the shared state must not be touched by the retiring process'
            );
            $this->assertSame(
                4194303,
                $global->raw('dc_bot_timer:'.self::LOCATION),
                'the other worker keeps ownership'
            );
        }

        // ====================================================================
        // cleanupBotForLocation
        // ====================================================================

        public function testCleanupRemovesEverythingAboutTheBot(): void
        {
            $global = $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            $timerId = $this->botMoveTimers()[0]['id'];
            $global->store['dc_move_batch:'.self::BOT_ID] = json_encode(['x' => 1]);

            \Events::cleanupBotForLocation(self::LOCATION);

            $this->assertNull($global->raw('dc_bot_state:'.self::LOCATION));
            $this->assertNull($global->raw('dc_bot_timer:'.self::LOCATION));
            $this->assertNull($global->raw('dc_presence:client:'.self::BOT_ID));
            $this->assertNull($global->raw('dc_move_batch:'.self::BOT_ID));
            $this->assertNotContains(self::BOT_ID, $global->raw('dc_presence_clients'));
            $this->assertNotContains(self::BOT_ID, $global->raw('dc_active_clients'));
            $this->assertContains($timerId, TestTimer::deleted());
        }

        /**
         * Despawn must announce dc.presence.left. spawn announces joined, so
         * without the matching left the cleaned-up bot lingers as a ghost avatar
         * until the page reloads — visible now that presence broadcasts actually
         * reach clients (BUG-A3).
         */
        public function testCleanupAnnouncesLeftOverGatewayGroup(): void
        {
            $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            \GatewayWorker\Lib\Gateway::reset();

            \Events::cleanupBotForLocation(self::LOCATION);

            $events = $this->presenceGroupEvents('dc.presence.left');
            $this->assertCount(1, $events, 'a despawned bot must be announced or it ghosts');
            $this->assertIsV1Event($events[0], 'dc.presence.left');
            $this->assertSame(self::BOT_ID, $events[0]['data']['uid']);
            $this->assertSame(self::BOT_ID, $events[0]['data']['clientId']);
            $this->assertDeadChannelTransportUnused('cleanup:');
        }

        public function testCleanupLeavesRealUsersAlone(): void
        {
            $global = $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            $this->addRealUser($global, $this->userId, 5.0, 5.0);

            \Events::cleanupBotForLocation(self::LOCATION);

            $this->assertIsArray($global->raw('dc_presence:client:'.$this->userId));
            $this->assertContains($this->userId, $global->raw('dc_presence_clients'));
            $this->assertContains($this->userId, $global->raw('dc_active_clients'));
        }

        /**
         * A process that does not own the timer must NOT delete a timer id it did
         * not create; it clears the shared state and leaves the marker for the
         * owner's next tick to reap.
         */
        public function testCleanupFromANonOwningProcessDoesNotDeleteForeignTimers(): void
        {
            $global = $this->botFlagOn([
                'dc_bot_timer:'.self::LOCATION => 4194303,
                'dc_bot_state:'.self::LOCATION => [
                    'uid' => self::BOT_ID, 'name' => 'Walker', 'x' => 0.0, 'z' => 0.0, 'yaw' => 0.0,
                    'target_x' => 0.0, 'target_z' => 0.0, 'client_id' => self::BOT_ID,
                    'location' => self::LOCATION,
                ],
                'dc_presence_clients' => [self::BOT_ID],
                'dc_active_clients' => [self::BOT_ID],
                'dc_presence:client:'.self::BOT_ID => ['uid' => self::BOT_ID, 'client_id' => self::BOT_ID],
            ]);

            \Events::cleanupBotForLocation(self::LOCATION);

            $this->assertSame(
                [],
                TestTimer::deleted(),
                'a Workerman timer id is only valid in its creating process — never Timer::del() a foreign id'
            );
            $this->assertSame(
                4194303,
                $global->raw('dc_bot_timer:'.self::LOCATION),
                'the marker is left for the owner to reap on its next tick'
            );
            $this->assertNull(
                $global->raw('dc_bot_state:'.self::LOCATION),
                'clearing the state is what makes the owner stop next tick'
            );
        }

        // ====================================================================
        // hasRealUsersAtLocation
        // ====================================================================

        private function hasRealUsers(): bool
        {
            $method = new ReflectionMethod(\Events::class, 'hasRealUsersAtLocation');
            $method->setAccessible(true);
            return $method->invoke(null, self::LOCATION);
        }

        public function testHasRealUsersIsFalseWithOnlyBots(): void
        {
            $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);

            $this->assertFalse($this->hasRealUsers());
        }

        public function testHasRealUsersIsTrueWithAHexClientIdPresent(): void
        {
            $global = $this->botFlagOn();
            \Events::spawnBotForLocation(self::LOCATION);
            $this->addRealUser($global, $this->userId);

            $this->assertTrue($this->hasRealUsers(), 'a 20-char hex client_id is a real user, not a bot');
        }

        public function testHasRealUsersIsFalseOnAnEmptyScene(): void
        {
            $this->botFlagOn();

            $this->assertFalse($this->hasRealUsers());
        }

        /**
         * The bot/real split is a 'bot_' PREFIX test, so an id that merely
         * CONTAINS "bot" is still a real user. Hex ids can never start with
         * 'bot_' (b, o and t are not all hex), which is why the sentinel is safe.
         */
        public function testOnlyThePrefixMakesAClientIdABot(): void
        {
            $global = $this->botFlagOn();
            $lookalike = 'abbot_main';
            $global->store['dc_presence:client:'.$lookalike] = ['uid' => 1, 'client_id' => $lookalike];
            $global->store['dc_presence_clients'] = [$lookalike];

            $this->assertTrue($this->hasRealUsers(), 'only a leading "bot_" marks a bot');
        }

        // ====================================================================
        // Integration: join spawns, last leave despawns
        // ====================================================================

        public function testRealUserJoinSpawnsTheBotNearThem(): void
        {
            $global = $this->botFlagOn();
            $_SESSION = [
                'v1_authed' => true, 'uid' => 4242, 'name' => 'admin',
                'ima' => 'admin', 'login' => true,
            ];

            \Events::dispatchV1($this->userId, [
                'v' => 1, 'id' => 'join-1', 'op' => 'dc.presence.join', 'ts' => time(),
                'data' => [
                    'x' => 40.0, 'z' => -40.0, 'yaw' => 0.0,
                    'bounds' => ['minX' => -200.0, 'maxX' => 200.0, 'minZ' => -200.0, 'maxZ' => 200.0],
                ],
            ]);

            $state = $global->raw('dc_bot_state:'.self::LOCATION);
            $this->assertIsArray($state, 'a real user joining must spawn the bot');
            $distance = sqrt(($state['x'] - 40.0) ** 2 + ($state['z'] + 40.0) ** 2);
            $this->assertLessThanOrEqual(
                \Events::BOT_SPAWN_RADIUS + 1e-9,
                $distance,
                'the bot spawns near the joining player, not at the world origin'
            );

            // Both the user's join and the bot's join reach clients over the group.
            $joined = $this->presenceGroupEvents('dc.presence.joined');
            $this->assertCount(2, $joined, 'the user and the bot are both announced');
            $ids = array_map(static fn(array $e) => $e['data']['clientId'], $joined);
            $this->assertContains($this->userId, $ids);
            $this->assertContains(self::BOT_ID, $ids);
            $this->assertDeadChannelTransportUnused('join+spawn:');
        }

        public function testLastRealUserLeavingDespawnsTheBot(): void
        {
            $global = $this->botFlagOn();
            $_SESSION = [
                'v1_authed' => true, 'uid' => 4242, 'name' => 'admin',
                'ima' => 'admin', 'login' => true,
            ];

            \Events::dispatchV1($this->userId, [
                'v' => 1, 'id' => 'join-1', 'op' => 'dc.presence.join', 'ts' => time(),
                'data' => ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0],
            ]);
            $this->assertIsArray($global->raw('dc_bot_state:'.self::LOCATION));
            $timerId = $this->botMoveTimers()[0]['id'];

            \Events::dispatchV1($this->userId, [
                'v' => 1, 'id' => 'leave-1', 'op' => 'dc.presence.leave', 'ts' => time(), 'data' => [],
            ]);

            $this->assertNull($global->raw('dc_bot_state:'.self::LOCATION), 'the bot leaves with the last human');
            $this->assertNull($global->raw('dc_presence:client:'.self::BOT_ID));
            $this->assertContains($timerId, TestTimer::deleted());

            $left = $this->presenceGroupEvents('dc.presence.left');
            $ids = array_map(static fn(array $e) => $e['data']['clientId'], $left);
            $this->assertContains($this->userId, $ids, 'the user is announced as gone');
            $this->assertContains(self::BOT_ID, $ids, 'the bot avatar is announced as gone (no ghost)');
        }

        /** A second human leaving while one remains must NOT despawn the bot. */
        public function testBotSurvivesWhileAnotherRealUserRemains(): void
        {
            $global = $this->botFlagOn();
            $other = dc_client_id(2002);
            $_SESSION = [
                'v1_authed' => true, 'uid' => 4242, 'name' => 'admin',
                'ima' => 'admin', 'login' => true,
            ];

            \Events::dispatchV1($this->userId, [
                'v' => 1, 'id' => 'join-1', 'op' => 'dc.presence.join', 'ts' => time(),
                'data' => ['x' => 0.0, 'z' => 0.0, 'yaw' => 0.0],
            ]);
            \Events::dispatchV1($other, [
                'v' => 1, 'id' => 'join-2', 'op' => 'dc.presence.join', 'ts' => time(),
                'data' => ['x' => 1.0, 'z' => 1.0, 'yaw' => 0.0],
            ]);

            \Events::dispatchV1($other, [
                'v' => 1, 'id' => 'leave-2', 'op' => 'dc.presence.leave', 'ts' => time(), 'data' => [],
            ]);

            $this->assertIsArray(
                $global->raw('dc_bot_state:'.self::LOCATION),
                'the bot must stay while a human is still in the scene'
            );
        }

        // ====================================================================
        // Constants
        // ====================================================================

        public function testBotConstants(): void
        {
            $this->assertSame(0.5, \Events::BOT_MOVE_INTERVAL, 'BOT_MOVE_INTERVAL should be 0.5s');
            // 11.7 u/s, NOT 1.2. The scene's unit is UNITS_PER_INCH = 15/70 (1 unit ~= 0.12 m),
            // so 1.2 u/s was ~0.14 m/s — a crawl, which is why the bot looked frozen/absent.
            // A 1.4 m/s human walk is ~11.7 u/s; the client player walks at WALK = 14 u/s
            // (public_html/js/dc-multi.js). This assertion previously froze the bug in place.
            $this->assertSame(11.7, \Events::BOT_WALK_SPEED, 'BOT_WALK_SPEED should be ~1.4 m/s in scene units');
            $this->assertSame(1.0, \Events::BOT_TARGET_THRESHOLD, 'BOT_TARGET_THRESHOLD should be 1.0 units');
            // One tick moves BOT_WALK_SPEED * BOT_MOVE_INTERVAL = 5.85 units, which EXCEEDS
            // BOT_TARGET_THRESHOLD (1.0). moveBot() must therefore clamp the step to the
            // remaining distance or the bot oscillates around its target and never arrives
            // (proved behaviourally in testMoveBotClampsTheStepToTheRemainingDistance).
            $this->assertGreaterThan(
                \Events::BOT_TARGET_THRESHOLD,
                \Events::BOT_WALK_SPEED * \Events::BOT_MOVE_INTERVAL,
                'per-tick step exceeds the arrival threshold, so the step MUST be clamped in moveBot()'
            );
            // Retained as the FALLBACK bounds only — dcRoomBounds() now prefers the
            // browser-reported dc_room_bounds:<location> when one has been recorded.
            $this->assertSame(-50.0, \Events::BOT_BOUNDS_X_MIN);
            $this->assertSame(50.0, \Events::BOT_BOUNDS_X_MAX);
            $this->assertSame(-50.0, \Events::BOT_BOUNDS_Z_MIN);
            $this->assertSame(50.0, \Events::BOT_BOUNDS_Z_MAX);
            $this->assertSame('main', \Events::BOT_DEFAULT_LOCATION);
            $this->assertSame(2.0, \Events::BOT_BOUNDS_INSET);
            $this->assertSame(25.0, \Events::BOT_SPAWN_RADIUS);
            $this->assertSame(30.0, \Events::BOT_WANDER_RADIUS);
            $this->assertSame('dc_room_bounds:', \Events::DC_ROOM_BOUNDS_KEY_PREFIX);
        }
    }
}
