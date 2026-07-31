<?php

/**
 * Unit tests for the side-effect-free DC helpers lifted out of the presence /
 * bot code paths, plus the two invariants that the callers depend on.
 *
 *   sanitiseRoomBounds($raw)              validate untrusted browser bounds
 *   dcRoomBounds($location)               reported-or-fallback, inset
 *   dcPresenceIsStale($pong,$sent,$now,$t) keepalive watchdog decision
 *   randomPointNear($anchor,$bounds,$r)   spawn/wander target selection
 *   randomRealClientPosition()            "wander where the humans are"
 *   botOwnerAlive($owner)                 is the timer-owning worker still up
 *   isInPeerViewport(...)                 per-recipient move filtering
 *
 * These are the cheapest place to pin the contracts the integration tests then
 * rely on, and the only place where the hostile inputs (non-numeric, INF/NAN,
 * inverted, degenerate, absurd) can be enumerated exhaustively.
 *
 * All are private statics, so they are reached by reflection; that is
 * deliberate — testing them through a handler would make the property-style
 * assertions below (hundreds of random draws staying inside the box) impossible
 * to write.
 */

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__.'/V1TestSupport.php';

    class EventsDcHelpersTest extends TestCase
    {
        use DcTransportAssertions;

        protected function setUp(): void
        {
            \GatewayWorker\Lib\Gateway::reset();
            \Channel\Client::reset();
            \GlobalData\Client::resetConstructed();
            TestTimer::install();
            unset($GLOBALS['global']);
            $_SESSION = [];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['global']);
        }

        /** Invoke a private static Events helper. */
        private static function call(string $method, ...$args)
        {
            $ref = new ReflectionMethod(\Events::class, $method);
            $ref->setAccessible(true);
            return $ref->invoke(null, ...$args);
        }

        // ====================================================================
        // sanitiseRoomBounds — untrusted browser input
        // ====================================================================

        public function testSanitiseRoomBoundsAcceptsAValidBoxAndCastsToFloat(): void
        {
            $this->assertSame(
                ['minX' => -120.0, 'maxX' => 40.5, 'minZ' => -130.0, 'maxZ' => 20.0],
                self::call('sanitiseRoomBounds', ['minX' => -120, 'maxX' => 40.5, 'minZ' => '-130', 'maxZ' => 20]),
                'numeric strings and ints are accepted and normalised to float'
            );
        }

        public function testSanitiseRoomBoundsIgnoresExtraKeys(): void
        {
            $this->assertSame(
                ['minX' => -10.0, 'maxX' => 10.0, 'minZ' => -10.0, 'maxZ' => 10.0],
                self::call('sanitiseRoomBounds', [
                    'minX' => -10, 'maxX' => 10, 'minZ' => -10, 'maxZ' => 10,
                    'minY' => 0, 'evil' => '<script>', 'nested' => ['a' => 1],
                ]),
                'only the four bound fields are read; the result is a clean 4-key array'
            );
        }

        #[\PHPUnit\Framework\Attributes\DataProvider('rejectedBoundsProvider')]
        public function testSanitiseRoomBoundsRejects($raw, string $why): void
        {
            $this->assertNull(self::call('sanitiseRoomBounds', $raw), $why);
        }

        public static function rejectedBoundsProvider(): array
        {
            $ok = ['minX' => -50, 'maxX' => 50, 'minZ' => -50, 'maxZ' => 50];
            $with = static function (array $override) use ($ok) {
                return array_merge($ok, $override);
            };

            return [
                // --- not even the right shape ---
                'null' => [null, 'a missing bounds value is simply absent'],
                'string' => ['minX=1', 'a scalar is not a bounds object'],
                'int' => [42, 'a scalar is not a bounds object'],
                'empty array' => [[], 'no fields at all'],
                'list not map' => [[-50, 50, -50, 50], 'a positional list has none of the four keys'],

                // --- missing / non-numeric fields ---
                'missing minX' => [['maxX' => 50, 'minZ' => -50, 'maxZ' => 50], 'all four fields are required'],
                'missing maxZ' => [['minX' => -50, 'maxX' => 50, 'minZ' => -50], 'all four fields are required'],
                'null field' => [$with(['minX' => null]), 'null is not numeric'],
                'string field' => [$with(['minX' => 'left']), 'a non-numeric string is rejected'],
                'bool field' => [$with(['maxX' => true]), 'a bool is not numeric'],
                'array field' => [$with(['maxX' => [1]]), 'an array is not numeric'],
                'empty string field' => [$with(['minZ' => '']), 'an empty string is not numeric'],

                // --- non-finite ---
                'INF' => [$with(['maxX' => INF]), 'INF is not finite'],
                '-INF' => [$with(['minX' => -INF]), '-INF is not finite'],
                'NAN' => [$with(['minZ' => NAN]), 'NAN is not finite'],
                'overflow to INF' => [$with(['maxZ' => 1e400]), '1e400 parses to INF'],

                // --- inverted / degenerate ---
                'minX == maxX' => [$with(['minX' => 10, 'maxX' => 10]), 'a zero-width room has no walkable area'],
                'minX > maxX' => [$with(['minX' => 60, 'maxX' => -60]), 'inverted X bounds'],
                'minZ == maxZ' => [$with(['minZ' => 5, 'maxZ' => 5]), 'a zero-depth room has no walkable area'],
                'minZ > maxZ' => [$with(['minZ' => 60, 'maxZ' => -60]), 'inverted Z bounds'],

                // --- spans below BOT_BOUNDS_MIN_SPAN (4.0) ---
                'X span 3.9' => [$with(['minX' => 0, 'maxX' => 3.9]), 'a span under BOT_BOUNDS_MIN_SPAN is degenerate'],
                'Z span 0.001' => [$with(['minZ' => 0, 'maxZ' => 0.001]), 'a span under BOT_BOUNDS_MIN_SPAN is degenerate'],

                // --- spans above BOT_BOUNDS_MAX_SPAN (5000.0) ---
                'X span 5001' => [$with(['minX' => 0, 'maxX' => 5001]), 'a span over BOT_BOUNDS_MAX_SPAN is absurd'],
                'Z span 1e6' => [$with(['minZ' => 0, 'maxZ' => 1000000]), 'a span over BOT_BOUNDS_MAX_SPAN is absurd'],

                // --- coordinates beyond BOT_BOUNDS_MAX_COORD (100000.0) ---
                'coord 100001' => [
                    ['minX' => 99000, 'maxX' => 100001, 'minZ' => -50, 'maxZ' => 50],
                    '|coord| over BOT_BOUNDS_MAX_COORD is rejected even with a sane span',
                ],
                'coord -100001' => [
                    ['minX' => -100001, 'maxX' => -99000, 'minZ' => -50, 'maxZ' => 50],
                    'negative coords are bounded too',
                ],
                'teleport attack' => [
                    ['minX' => 1e300, 'maxX' => 1e300 + 10, 'minZ' => 1e300, 'maxZ' => 1e300 + 10],
                    'a hostile client must not be able to teleport the bot to (1e300, 1e300)',
                ],
            ];
        }

        /** The exact boundary values are ACCEPTED (the checks are strict, not off-by-one). */
        public function testSanitiseRoomBoundsAcceptsTheExactLimits(): void
        {
            $minSpan = \Events::BOT_BOUNDS_MIN_SPAN;
            $this->assertIsArray(
                self::call('sanitiseRoomBounds', ['minX' => 0, 'maxX' => $minSpan, 'minZ' => 0, 'maxZ' => $minSpan]),
                'a span of exactly BOT_BOUNDS_MIN_SPAN is allowed'
            );

            $maxSpan = \Events::BOT_BOUNDS_MAX_SPAN;
            $this->assertIsArray(
                self::call('sanitiseRoomBounds', ['minX' => 0, 'maxX' => $maxSpan, 'minZ' => 0, 'maxZ' => $maxSpan]),
                'a span of exactly BOT_BOUNDS_MAX_SPAN is allowed'
            );

            $maxCoord = \Events::BOT_BOUNDS_MAX_COORD;
            $this->assertIsArray(
                self::call('sanitiseRoomBounds', [
                    'minX' => $maxCoord - 100, 'maxX' => $maxCoord,
                    'minZ' => -$maxCoord, 'maxZ' => -$maxCoord + 100,
                ]),
                'a coordinate of exactly BOT_BOUNDS_MAX_COORD is allowed'
            );
        }

        // ====================================================================
        // dcRoomBounds — reported-or-fallback, inset
        // ====================================================================

        public function testDcRoomBoundsFallsBackToTheConstantsWhenNothingReported(): void
        {
            $GLOBALS['global'] = new InMemoryGlobalData();
            $inset = \Events::BOT_BOUNDS_INSET;

            $this->assertSame([
                'minX' => \Events::BOT_BOUNDS_X_MIN + $inset,
                'maxX' => \Events::BOT_BOUNDS_X_MAX - $inset,
                'minZ' => \Events::BOT_BOUNDS_Z_MIN + $inset,
                'maxZ' => \Events::BOT_BOUNDS_Z_MAX - $inset,
            ], self::call('dcRoomBounds', 'main'));
        }

        public function testDcRoomBoundsWorksWithNoGlobalDataAtAll(): void
        {
            $GLOBALS['global'] = null;

            $bounds = self::call('dcRoomBounds', 'main');
            $this->assertIsArray($bounds, 'a null $global must not fatal — the fallback box is used');
            $this->assertSame(\Events::BOT_BOUNDS_X_MIN + \Events::BOT_BOUNDS_INSET, $bounds['minX']);
        }

        public function testDcRoomBoundsPrefersTheReportedRoomAndInsetsIt(): void
        {
            $GLOBALS['global'] = new InMemoryGlobalData([
                \Events::DC_ROOM_BOUNDS_KEY_PREFIX.'main' => [
                    'minX' => -200.0, 'maxX' => -100.0, 'minZ' => -300.0, 'maxZ' => -200.0,
                ],
            ]);
            $inset = \Events::BOT_BOUNDS_INSET;

            $this->assertSame([
                'minX' => -200.0 + $inset,
                'maxX' => -100.0 - $inset,
                'minZ' => -300.0 + $inset,
                'maxZ' => -200.0 - $inset,
            ], self::call('dcRoomBounds', 'main'), 'dc.js builds the room around x/z = -100, not the origin');
        }

        /** A stored value that no longer validates is ignored in favour of the fallback. */
        public function testDcRoomBoundsIgnoresAnInvalidStoredValue(): void
        {
            $GLOBALS['global'] = new InMemoryGlobalData([
                \Events::DC_ROOM_BOUNDS_KEY_PREFIX.'main' => ['minX' => 'x', 'maxX' => 1, 'minZ' => 0, 'maxZ' => 1],
            ]);

            $this->assertSame(
                \Events::BOT_BOUNDS_X_MIN + \Events::BOT_BOUNDS_INSET,
                self::call('dcRoomBounds', 'main')['minX']
            );
        }

        /**
         * The inset is capped at a quarter of each span, so a small room never
         * inverts into a negative-size box.
         */
        public function testDcRoomBoundsCapsTheInsetForSmallRooms(): void
        {
            $GLOBALS['global'] = new InMemoryGlobalData([
                \Events::DC_ROOM_BOUNDS_KEY_PREFIX.'tiny' => [
                    'minX' => 0.0, 'maxX' => 4.0, 'minZ' => 0.0, 'maxZ' => 4.0,
                ],
            ]);

            // inset = min(2.0, 4/4, 4/4) = 1.0
            $this->assertSame(
                ['minX' => 1.0, 'maxX' => 3.0, 'minZ' => 1.0, 'maxZ' => 3.0],
                self::call('dcRoomBounds', 'tiny')
            );
            $bounds = self::call('dcRoomBounds', 'tiny');
            $this->assertLessThan($bounds['maxX'], $bounds['minX'], 'the inset box must stay non-degenerate');
            $this->assertLessThan($bounds['maxZ'], $bounds['minZ']);
        }

        /** Bounds are per location. */
        public function testDcRoomBoundsAreScopedPerLocation(): void
        {
            $GLOBALS['global'] = new InMemoryGlobalData([
                \Events::DC_ROOM_BOUNDS_KEY_PREFIX.'main' => [
                    'minX' => -80.0, 'maxX' => 80.0, 'minZ' => -80.0, 'maxZ' => 80.0,
                ],
            ]);

            $this->assertSame(-78.0, self::call('dcRoomBounds', 'main')['minX']);
            $this->assertSame(
                \Events::BOT_BOUNDS_X_MIN + \Events::BOT_BOUNDS_INSET,
                self::call('dcRoomBounds', 'other')['minX'],
                'another location must not inherit main\'s reported bounds'
            );
        }

        // ====================================================================
        // dcPresenceIsStale — the keepalive watchdog decision (BUG-B3/B4)
        // ====================================================================

        #[\PHPUnit\Framework\Attributes\DataProvider('stalenessProvider')]
        public function testDcPresenceIsStale(int $pong, int $sent, int $now, int $threshold, bool $expected, string $why): void
        {
            $this->assertSame($expected, self::call('dcPresenceIsStale', $pong, $sent, $now, $threshold), $why);
        }

        public static function stalenessProvider(): array
        {
            $now = 1_000_000;
            $t = 90;

            return [
                // --- has ponged at some point: measure from the LAST PONG only ---
                'ponged just now' => [$now, $now, $now, $t, false, 'a fresh pong is never stale'],
                'ponged 89s ago' => [$now - 89, $now, $now, $t, false, 'inside the threshold'],
                'ponged exactly at the threshold' => [$now - 90, $now, $now, $t, false, 'the check is < (now - threshold), so exactly 90s old survives'],
                'ponged 91s ago' => [$now - 91, $now, $now, $t, true, 'past the threshold => drop'],
                'ponged long ago, just pinged' => [
                    $now - 500, $now, $now, $t, true,
                    'a recent PING must not rescue a client that stopped ponging — that was BUG-B4',
                ],
                'ponged recently, pinged long ago' => [
                    $now - 5, $now - 900, $now, $t, false,
                    'staleness is measured from the pong, never from the ping',
                ],

                // --- never ponged: only an OUTSTANDING ping can make it stale ---
                'never pinged, never ponged' => [
                    0, 0, $now, $t, false,
                    'a client we have never pinged can never be judged silent',
                ],
                'pinged 1s ago, no pong yet' => [
                    0, $now - 1, $now, $t, false,
                    'pinged-but-not-yet-ponged must NEVER be dropped',
                ],
                'pinged 89s ago, no pong yet' => [0, $now - 89, $now, $t, false, 'still inside the grace window'],
                'pinged exactly threshold ago, no pong' => [0, $now - 90, $now, $t, false, 'boundary survives'],
                'pinged 91s ago, no pong yet' => [0, $now - 91, $now, $t, true, 'an outstanding ping older than the threshold => drop'],
                'pinged 200s ago, no pong ever' => [
                    0, $now - 200, $now, $t, true,
                    'a client that never pongs at all must eventually be dropped, not be immune forever',
                ],

                // --- threshold is a parameter, not a constant ---
                'zero threshold, ponged now' => [$now, $now, $now, 0, false, 'now < now is false'],
                'tight threshold' => [$now - 2, $now, $now, 1, true, 'a small threshold drops sooner'],
            ];
        }

        /**
         * dcPresenceIsStale is PURE: no globals, no clock, no I/O. The health
         * sweep depends on that — it takes a snapshot BEFORE sending this round's
         * pings and judges from the snapshot, which only works if the decision
         * function cannot read live state.
         */
        public function testDcPresenceIsStaleIsPureAndTouchesNothing(): void
        {
            $GLOBALS['global'] = new InMemoryGlobalData(['sentinel' => 1]);

            for ($i = 0; $i < 50; $i++) {
                self::call('dcPresenceIsStale', $i, $i + 1, $i + 2, 90);
            }

            $this->assertSame(['sentinel'], $GLOBALS['global']->keys(), 'no GlobalData writes');
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$sent, 'no sends');
            $this->assertSame([], \GatewayWorker\Lib\Gateway::$closed, 'no closes');
            $this->assertSame([], TestTimer::added(), 'no timers');
        }

        // ====================================================================
        // randomPointNear
        // ====================================================================

        public function testRandomPointNearWithNoAnchorStaysUniformlyInsideBounds(): void
        {
            $bounds = ['minX' => -30.0, 'maxX' => 70.0, 'minZ' => -80.0, 'maxZ' => -10.0];

            for ($i = 0; $i < 400; $i++) {
                [$x, $z] = self::call('randomPointNear', null, $bounds, 25.0);
                $this->assertGreaterThanOrEqual($bounds['minX'], $x);
                $this->assertLessThanOrEqual($bounds['maxX'], $x);
                $this->assertGreaterThanOrEqual($bounds['minZ'], $z);
                $this->assertLessThanOrEqual($bounds['maxZ'], $z);
            }
        }

        public function testRandomPointNearAnchorStaysWithinRadiusAndBounds(): void
        {
            $bounds = ['minX' => -1000.0, 'maxX' => 1000.0, 'minZ' => -1000.0, 'maxZ' => 1000.0];
            $anchor = ['x' => 120.0, 'z' => -340.0];
            $radius = 25.0;

            for ($i = 0; $i < 400; $i++) {
                [$x, $z] = self::call('randomPointNear', $anchor, $bounds, $radius);
                $distance = sqrt(($x - $anchor['x']) ** 2 + ($z - $anchor['z']) ** 2);
                $this->assertLessThanOrEqual(
                    $radius + 1e-9,
                    $distance,
                    'with room to spare, every point must be inside the radius'
                );
            }
        }

        /**
         * An anchor near (or outside) a wall must still yield an in-bounds point:
         * the result is clamped, so the bot can never be placed through a wall.
         */
        public function testRandomPointNearClampsAnAnchorAgainstAWall(): void
        {
            $bounds = ['minX' => 0.0, 'maxX' => 10.0, 'minZ' => 0.0, 'maxZ' => 10.0];
            $anchor = ['x' => 500.0, 'z' => -500.0];   // far outside the room

            for ($i = 0; $i < 200; $i++) {
                [$x, $z] = self::call('randomPointNear', $anchor, $bounds, 30.0);
                $this->assertGreaterThanOrEqual($bounds['minX'], $x);
                $this->assertLessThanOrEqual($bounds['maxX'], $x);
                $this->assertGreaterThanOrEqual($bounds['minZ'], $z);
                $this->assertLessThanOrEqual($bounds['maxZ'], $z);
            }
        }

        /** A zero radius pins the point to the (clamped) anchor. */
        public function testRandomPointNearWithZeroRadiusReturnsTheAnchor(): void
        {
            $bounds = ['minX' => -50.0, 'maxX' => 50.0, 'minZ' => -50.0, 'maxZ' => 50.0];

            [$x, $z] = self::call('randomPointNear', ['x' => 7.0, 'z' => -3.0], $bounds, 0.0);

            $this->assertEqualsWithDelta(7.0, $x, 1e-9);
            $this->assertEqualsWithDelta(-3.0, $z, 1e-9);
        }

        /** Draws actually vary — the helper is not returning a constant. */
        public function testRandomPointNearActuallyVaries(): void
        {
            $bounds = ['minX' => -100.0, 'maxX' => 100.0, 'minZ' => -100.0, 'maxZ' => 100.0];
            $seen = [];
            for ($i = 0; $i < 50; $i++) {
                $seen[] = implode(',', self::call('randomPointNear', null, $bounds, 25.0));
            }
            $this->assertGreaterThan(40, count(array_unique($seen)), 'points must be randomly distributed');
        }

        // ====================================================================
        // randomRealClientPosition
        // ====================================================================

        public function testRandomRealClientPositionReturnsNullWithoutGlobalData(): void
        {
            $GLOBALS['global'] = null;
            $this->assertNull(self::call('randomRealClientPosition'));
        }

        public function testRandomRealClientPositionReturnsNullOnAnEmptyScene(): void
        {
            $GLOBALS['global'] = new InMemoryGlobalData(['dc_presence_clients' => []]);
            $this->assertNull(self::call('randomRealClientPosition'));
        }

        /** Bots are not real players, so a bot-only scene has no anchor. */
        public function testRandomRealClientPositionSkipsBots(): void
        {
            $GLOBALS['global'] = new InMemoryGlobalData([
                'dc_presence_clients' => ['bot_main'],
                'dc_presence:client:bot_main' => ['uid' => 'bot_main', 'x' => 5.0, 'z' => 5.0],
            ]);

            $this->assertNull(
                self::call('randomRealClientPosition'),
                'anchoring the bot on itself would make it chase its own tail'
            );
        }

        public function testRandomRealClientPositionReturnsAHumanPosition(): void
        {
            $human = dc_client_id(7);
            $GLOBALS['global'] = new InMemoryGlobalData([
                'dc_presence_clients' => ['bot_main', $human],
                'dc_presence:client:bot_main' => ['uid' => 'bot_main', 'x' => -999.0, 'z' => -999.0],
                'dc_presence:client:'.$human => ['uid' => 42, 'x' => 12.5, 'z' => -7.25],
            ]);

            $this->assertSame(
                ['x' => 12.5, 'z' => -7.25],
                self::call('randomRealClientPosition'),
                'the human\'s position is used, and cast to float'
            );
        }

        /** Entries with missing or non-numeric coordinates are skipped, not fatal. */
        public function testRandomRealClientPositionSkipsMalformedEntries(): void
        {
            $good = dc_client_id(8);
            $GLOBALS['global'] = new InMemoryGlobalData([
                'dc_presence_clients' => [dc_client_id(1), dc_client_id(2), dc_client_id(3), $good],
                'dc_presence:client:'.dc_client_id(1) => 'not an array',
                'dc_presence:client:'.dc_client_id(2) => ['uid' => 1],                        // no x/z
                'dc_presence:client:'.dc_client_id(3) => ['uid' => 1, 'x' => 'left', 'z' => 0], // non-numeric
                'dc_presence:client:'.$good => ['uid' => 1, 'x' => '3', 'z' => '4'],
            ]);

            $this->assertSame(['x' => 3.0, 'z' => 4.0], self::call('randomRealClientPosition'));
        }

        /** With several humans the choice is random but always one of theirs. */
        public function testRandomRealClientPositionPicksAmongAllHumans(): void
        {
            $a = dc_client_id(11);
            $b = dc_client_id(12);
            $GLOBALS['global'] = new InMemoryGlobalData([
                'dc_presence_clients' => [$a, $b],
                'dc_presence:client:'.$a => ['uid' => 1, 'x' => 1.0, 'z' => 1.0],
                'dc_presence:client:'.$b => ['uid' => 2, 'x' => 2.0, 'z' => 2.0],
            ]);

            $seen = [];
            for ($i = 0; $i < 100; $i++) {
                $seen[json_encode(self::call('randomRealClientPosition'))] = true;
            }
            $seen = array_keys($seen);
            sort($seen);   // insertion order depends on the draw, so normalise

            $this->assertSame(
                ['{"x":1,"z":1}', '{"x":2,"z":2}'],
                $seen,
                'every draw must be one of the two humans, and both must be reachable'
            );
        }

        /** A non-array index must not fatal. */
        public function testRandomRealClientPositionToleratesACorruptIndex(): void
        {
            $GLOBALS['global'] = new InMemoryGlobalData(['dc_presence_clients' => 'corrupt']);
            $this->assertNull(self::call('randomRealClientPosition'));
        }

        // ====================================================================
        // botOwnerAlive
        // ====================================================================

        public function testBotOwnerAliveForOurOwnPid(): void
        {
            $this->assertTrue(self::call('botOwnerAlive', getmypid()));
            $this->assertTrue(self::call('botOwnerAlive', (string) getmypid()), 'digit strings are pids too');
        }

        public function testBotOwnerAliveForADeadPid(): void
        {
            $dead = 4194303;   // above the usual pid_max, so it cannot exist
            $this->assertFalse(is_dir('/proc/'.$dead), 'fixture pid must really be dead');
            $this->assertFalse(
                self::call('botOwnerAlive', $dead),
                'a dead owner means its timers died with it and the bot must be respawned'
            );
        }

        public function testBotOwnerAliveForALivePidThatIsNotUs(): void
        {
            // pid 1 always exists on Linux and is never this process.
            $this->assertNotSame(1, getmypid());
            $this->assertTrue(self::call('botOwnerAlive', 1));
        }

        /**
         * Non-pid markers are treated as ALIVE so pre-existing/legacy marker
         * values are never mistaken for a crashed worker.
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('nonPidMarkerProvider')]
        public function testBotOwnerAliveTreatsNonPidMarkersAsAlive($marker): void
        {
            $this->assertTrue(
                self::call('botOwnerAlive', $marker),
                'a marker that is not a pid tells us nothing, so assume alive'
            );
        }

        public static function nonPidMarkerProvider(): array
        {
            return [
                'legacy timer label' => ['stale_timer_123'],
                'empty string' => [''],
                'null' => [null],
                'float' => [1234.5],
                'array' => [[1234]],
                'bool' => [true],
                'negative pid string' => ['-1'],
            ];
        }

        // ====================================================================
        // isInPeerViewport — per-recipient move filtering (BUG-B5)
        // ====================================================================

        /** Missing viewport data fails OPEN: broadcast rather than hide avatars. */
        public function testIsInPeerViewportFailsOpenOnIncompleteData(): void
        {
            $this->assertTrue(self::call('isInPeerViewport', 0.0, 0.0, []));
            $this->assertTrue(self::call('isInPeerViewport', 0.0, 0.0, ['x' => 0, 'z' => 0]));
            $this->assertTrue(
                self::call('isInPeerViewport', 0.0, 0.0, ['x' => 0, 'z' => 0, 'viewDist' => 50]),
                'without a look direction there is nothing to cull against'
            );
        }

        public function testIsInPeerViewportSeesAMoverStraightAhead(): void
        {
            $peer = ['x' => 0.0, 'z' => 0.0, 'dirX' => 0.0, 'dirZ' => 1.0, 'viewDist' => 50.0];

            $this->assertTrue(self::call('isInPeerViewport', 0.0, 10.0, $peer));
        }

        public function testIsInPeerViewportCullsAMoverBehindThePeer(): void
        {
            $peer = ['x' => 0.0, 'z' => 0.0, 'dirX' => 0.0, 'dirZ' => 1.0, 'viewDist' => 50.0];

            $this->assertFalse(self::call('isInPeerViewport', 0.0, -10.0, $peer), 'directly behind');
        }

        public function testIsInPeerViewportCullsBeyondTwiceTheViewDistance(): void
        {
            $peer = ['x' => 0.0, 'z' => 0.0, 'dirX' => 0.0, 'dirZ' => 1.0, 'viewDist' => 50.0];

            $this->assertTrue(self::call('isInPeerViewport', 0.0, 99.0, $peer), 'inside 2 x viewDist');
            $this->assertFalse(self::call('isInPeerViewport', 0.0, 101.0, $peer), 'outside 2 x viewDist');
        }

        /** The cone is 60 degrees wide, i.e. +/-30 degrees off the look axis. */
        public function testIsInPeerViewportCullsOutsideTheSixtyDegreeCone(): void
        {
            $peer = ['x' => 0.0, 'z' => 0.0, 'dirX' => 0.0, 'dirZ' => 1.0, 'viewDist' => 50.0];
            $dist = 10.0;

            // 25 degrees off-axis: inside the +/-30 half-angle.
            $a = deg2rad(25);
            $this->assertTrue(self::call('isInPeerViewport', sin($a) * $dist, cos($a) * $dist, $peer));

            // 40 degrees off-axis: outside.
            $b = deg2rad(40);
            $this->assertFalse(self::call('isInPeerViewport', sin($b) * $dist, cos($b) * $dist, $peer));
        }

        /** A mover at exactly the peer's position is always visible (dist == 0). */
        public function testIsInPeerViewportKeepsACoincidentMover(): void
        {
            $peer = ['x' => 5.0, 'z' => 5.0, 'dirX' => 1.0, 'dirZ' => 0.0, 'viewDist' => 50.0];

            $this->assertTrue(self::call('isInPeerViewport', 5.0, 5.0, $peer));
        }

        /**
         * A TILTED camera must still see what is in front of it.
         *
         * dc.js reports camera.getWorldDirection(), a unit vector in 3D, so its
         * horizontal part is only unit-length when the camera is perfectly level:
         * looking down 45 degrees leaves |(dirX,dirZ)| ~= 0.707. Without
         * normalising in the XZ plane the raw dot product can never reach
         * cos(30 degrees) = 0.866, so EVERY peer is culled — tilt the camera and
         * remote avatars freeze for up to DC_VIEWPORT_MAX_AGE seconds.
         */
        public function testIsInPeerViewportNormalisesATiltedLookDirection(): void
        {
            $halfRoot2 = sqrt(0.5);   // camera pitched 45 degrees down, facing +Z

            $peer = ['x' => 0.0, 'z' => 0.0, 'dirX' => 0.0, 'dirZ' => $halfRoot2, 'viewDist' => 50.0];
            $this->assertTrue(
                self::call('isInPeerViewport', 0.0, 10.0, $peer),
                'a mover straight ahead must be visible even when the camera is pitched'
            );

            // Even more extreme pitch: |(dirX,dirZ)| = 0.1.
            $peer['dirZ'] = 0.1;
            $this->assertTrue(self::call('isInPeerViewport', 0.0, 10.0, $peer));

            // Normalisation must not break the culling it exists to enable.
            $this->assertFalse(
                self::call('isInPeerViewport', 0.0, -10.0, $peer),
                'a mover behind the peer is still culled after normalisation'
            );
        }

        /**
         * Looking straight up or down leaves NO horizontal facing at all, so
         * there is nothing to cull against: fail open rather than blind the peer.
         */
        public function testIsInPeerViewportFailsOpenWithNoHorizontalFacing(): void
        {
            $peer = ['x' => 0.0, 'z' => 0.0, 'dirX' => 0.0, 'dirZ' => 0.0, 'viewDist' => 50.0];

            $this->assertTrue(self::call('isInPeerViewport', 0.0, 10.0, $peer));
            $this->assertTrue(self::call('isInPeerViewport', 0.0, -10.0, $peer));
        }

        /**
         * handleDcViewportUpdate() writes ALL of x/z/dirX/dirZ/viewDist with
         * (float) defaults, so the isset() fail-open can never trigger for a
         * STORED viewport — every degenerate value has to be caught inside the
         * maths or the peer goes blind.
         */
        public function testIsInPeerViewportFailsOpenOnNonFiniteGeometry(): void
        {
            $peer = ['x' => 0.0, 'z' => 0.0, 'dirX' => 0.0, 'dirZ' => 1.0, 'viewDist' => 50.0];

            $this->assertTrue(self::call('isInPeerViewport', NAN, 0.0, $peer), 'NAN mover x');
            $this->assertTrue(self::call('isInPeerViewport', INF, 0.0, $peer), 'INF mover x');
            $this->assertTrue(self::call('isInPeerViewport', 0.0, NAN, $peer), 'NAN mover z');
            $this->assertTrue(
                self::call('isInPeerViewport', 0.0, 10.0, array_merge($peer, ['x' => NAN])),
                'NAN peer position'
            );
            $this->assertTrue(
                self::call('isInPeerViewport', 0.0, 10.0, array_merge($peer, ['dirX' => NAN, 'dirZ' => NAN])),
                'a NAN look direction has no usable length'
            );
        }

        /** A zero/garbage view radius falls back to the 50-unit default. */
        public function testIsInPeerViewportFallsBackToTheDefaultViewDistance(): void
        {
            $base = ['x' => 0.0, 'z' => 0.0, 'dirX' => 0.0, 'dirZ' => 1.0];

            foreach ([0.0, -10.0, NAN, INF] as $bad) {
                $peer = array_merge($base, ['viewDist' => $bad]);
                $this->assertTrue(
                    self::call('isInPeerViewport', 0.0, 99.0, $peer),
                    'inside the default 2 x 50 radius'
                );
                $this->assertFalse(
                    self::call('isInPeerViewport', 0.0, 101.0, $peer),
                    'outside the default 2 x 50 radius — a garbage radius must not disable culling'
                );
            }
        }
    }
}
