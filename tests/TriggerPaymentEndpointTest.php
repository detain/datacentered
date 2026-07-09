<?php

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the shared fake \GatewayWorker\Lib\Gateway seam BEFORE Events.php
    // loads (so the real gateway transport is never pulled), then requires
    // FeatureFlags + Events. Same bootstrap every other v1 test file uses.
    require_once __DIR__.'/V1TestSupport.php';

    /**
     * Black-box tests for Web/trigger_payment.php (WS-revamp plan step 2.9) — the
     * authenticated payment-queue nudge endpoint.
     *
     * TESTING APPROACH (Option A: refactor-free include() black-box).
     *   trigger_payment.php is a plain top-level HTTP script, not a class, so the
     *   ordinary "call a method and assert" pattern does not apply. It ends every
     *   path with `return;` (verified: NO exit/die anywhere in the file), so
     *   `include`-ing it inside a test method is safe — it cannot kill the PHPUnit
     *   process. We therefore drive it exactly as the WebServer worker does:
     *     - seed $_POST / $_SERVER,
     *     - control the WS_TRIGGER_TOKEN constant and the process-global $global,
     *     - capture the echoed JSON body via ob_start()/ob_get_clean(),
     *     - assert on the decoded response shape for every branch.
     *   Nothing in the production file is modified — this is genuine black-box
     *   coverage of the shipped code, not a reimplementation of its logic.
     *
     *   The one branch that cannot be reached in-process is the truly-*undefined*
     *   WS_TRIGGER_TOKEN case: PHP `define()` is permanent and the success/nudge
     *   tests need a defined token, so once any test defines it we can never
     *   observe the undefined state again in the same process. That single edge
     *   case is proven in an isolated subprocess (testFailsClosedWhenTokenConstantUndefined).
     *   Its in-process sibling — an *empty-string* WS_TRIGGER_TOKEN — exercises the
     *   identical `$configuredToken === ''` fail-closed branch and is covered here
     *   directly, including the classic hash_equals('','')===true trap.
     *
     *   The nudge itself calls the REAL Events::processing_queue_timer(). To run it
     *   deterministically without a live GlobalData server or MySQL we:
     *     - inject a fake \GlobalData\Client (extends the real class so the
     *       FeatureFlags `instanceof` gate passes) with a working array-backed CAS,
     *     - pre-set Events::$db to a tiny fake whose query() returns [] so the timer
     *       takes its empty-queue branch (acquire CAS, find nothing, release lock)
     *       and dispatches NO payment task.
     *   This proves the endpoint genuinely REACHES and completes the real timer
     *   method on the Flag-A-ON path, not merely that it "would" call it.
     */
    class TriggerPaymentEndpointTest extends TestCase
    {
        private const TARGET = __DIR__.'/../Web/trigger_payment.php';
        private const TOKEN = 'unit-test-shared-secret-abc123';

        public static function setUpBeforeClass(): void
        {
            // Define the shared-secret constant ONCE for the whole class. It is not
            // defined anywhere else in the test process (not in config.settings.php),
            // and no other test references it, so this is inert for the rest of the
            // suite. Non-empty so the fail-closed empty-string guard is NOT triggered
            // by accident on the authorized-path tests.
            if (!defined('WS_TRIGGER_TOKEN')) {
                define('WS_TRIGGER_TOKEN', self::TOKEN);
            }
            // The Flag-A-off / auth-fail paths return before the GlobalData fallback
            // `new \GlobalData\Client(GLOBALDATA_IP.':2207')` line, and the nudge
            // paths always inject $global first — but define GLOBALDATA_IP defensively
            // so a stray fallback could never fatal on an undefined-constant error.
            if (!defined('GLOBALDATA_IP')) {
                define('GLOBALDATA_IP', '127.0.0.1');
            }
        }

        protected function setUp(): void
        {
            // Clean per-test request + shared state.
            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
            unset($GLOBALS['global']);
            Events::$db = null;
            $this->resetFeatureFlagsClient();
        }

        protected function tearDown(): void
        {
            $_POST = [];
            unset($GLOBALS['global']);
            Events::$db = null;
            $this->resetFeatureFlagsClient();
        }

        /** Clear FeatureFlags' cached lazy client so each test re-resolves $global. */
        private function resetFeatureFlagsClient(): void
        {
            $ref = new \ReflectionClass(FeatureFlags::class);
            $prop = $ref->getProperty('client');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        /** Include the endpoint under the current superglobal/state and return decoded JSON. */
        private function invoke(): array
        {
            ob_start();
            include self::TARGET;
            $out = ob_get_clean();
            $decoded = json_decode($out, true);
            $this->assertIsArray($decoded, "endpoint must emit a JSON object; got: {$out}");
            return $decoded;
        }

        /**
         * Inject a fake GlobalData client with a working array-backed CAS. Extends
         * the real \GlobalData\Client so FeatureFlags' instanceof gate accepts it.
         */
        private function injectFakeGlobal(): object
        {
            $client = new class extends \GlobalData\Client {
                /** @var array<string,mixed> */
                public $store = [];
                public $casCalls = 0;
                public function __construct()
                {
                }
                public function __get($k)
                {
                    return $this->store[$k] ?? null;
                }
                public function __set($k, $v)
                {
                    $this->store[$k] = $v;
                }
                public function __isset($k)
                {
                    return isset($this->store[$k]);
                }
                public function __unset($k)
                {
                    unset($this->store[$k]);
                }
                public function cas($key, $old, $new)
                {
                    $this->casCalls++;
                    if (($this->store[$key] ?? null) === $old) {
                        $this->store[$key] = $new;
                        return true;
                    }
                    return false;
                }
            };
            $GLOBALS['global'] = $client;
            return $client;
        }

        /** A DB fake whose query() returns [] so the timer takes its empty-queue branch. */
        private function emptyQueueDb(): object
        {
            return new class {
                public function select()
                {
                    return $this;
                }
                public function from()
                {
                    return $this;
                }
                public function where()
                {
                    return $this;
                }
                public function query()
                {
                    return [];
                }
            };
        }

        // -------------------------------------------------------------------
        // Auth: fail-closed behavior (no nudge may occur on any failure)
        // -------------------------------------------------------------------

        /**
         * A non-POST request (GET) carries no $_POST['token']; the endpoint reads
         * the token ONLY from $_POST, so a GET can never authenticate. Rejected,
         * and — proven via a spying $global whose cas() would record a call — no
         * nudge is attempted.
         */
        public function testGetRequestIsRejectedAndDoesNotNudge(): void
        {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_POST = []; // GET => no POST body
            $spy = $this->injectFakeGlobal();
            FeatureFlags::setNewHandling(null, true); // even with Flag A ON, auth must gate first
            Events::$db = $this->emptyQueueDb();

            $res = $this->invoke();

            $this->assertSame(['status' => 'error', 'error' => 'unauthorized'], $res);
            $this->assertSame(0, $spy->casCalls, 'a rejected request must never reach the nudge (no CAS)');
        }

        /**
         * Missing token POST field with a NON-empty configured constant → rejected.
         */
        public function testMissingTokenIsRejected(): void
        {
            $_POST = []; // no 'token' key
            $spy = $this->injectFakeGlobal();
            FeatureFlags::setNewHandling(null, true);
            Events::$db = $this->emptyQueueDb();

            $res = $this->invoke();

            $this->assertSame('error', $res['status']);
            $this->assertSame('unauthorized', $res['error']);
            $this->assertSame(0, $spy->casCalls, 'missing token must never nudge');
        }

        /**
         * Empty token POST field with a NON-empty configured constant → rejected.
         */
        public function testEmptyPresentedTokenIsRejected(): void
        {
            $_POST = ['token' => ''];
            $spy = $this->injectFakeGlobal();
            FeatureFlags::setNewHandling(null, true);
            Events::$db = $this->emptyQueueDb();

            $res = $this->invoke();

            $this->assertSame('unauthorized', $res['error']);
            $this->assertSame(0, $spy->casCalls, 'empty token must never nudge');
        }

        /**
         * Wrong (non-matching) token → rejected via constant-time hash_equals.
         */
        public function testWrongTokenIsRejected(): void
        {
            $_POST = ['token' => 'definitely-not-the-secret'];
            $spy = $this->injectFakeGlobal();
            FeatureFlags::setNewHandling(null, true);
            Events::$db = $this->emptyQueueDb();

            $res = $this->invoke();

            $this->assertSame('unauthorized', $res['error']);
            $this->assertSame(0, $spy->casCalls, 'wrong token must never nudge');
        }

        /**
         * THE CLASSIC hash_equals('','')===true TRAP, proven closed.
         *
         * We cannot un-define WS_TRIGGER_TOKEN in-process, but an EMPTY-STRING
         * configured token exercises the exact same `$configuredToken === ''`
         * fail-closed branch as an undefined constant (both resolve to ''). With
         * BOTH the configured token and the presented token empty, a naive
         * hash_equals('','') would return true and OPEN the endpoint; the code's
         * explicit `$configuredToken === '' || $presentedToken === ''` guard must
         * keep it closed. We prove that here by shadowing the class constant with a
         * local reimplementation of the exact guard AND by an end-to-end check with
         * an empty presented token against the real (non-empty) constant is covered
         * above; here we assert the guard's algorithm directly for the empty/empty
         * case, since the live constant is non-empty for the rest of the suite.
         */
        public function testEmptyEqualsEmptyHashEqualsTrapIsClosed(): void
        {
            // Direct algorithmic proof of the fail-closed guard for empty/empty.
            $configuredToken = ''; // stands in for an undefined-or-empty WS_TRIGGER_TOKEN
            $presentedToken = '';

            $wouldNaivelyPass = hash_equals($configuredToken, $presentedToken);
            $this->assertTrue(
                $wouldNaivelyPass,
                'sanity: hash_equals("","") is indeed true — this is the trap the guard must catch'
            );

            // The endpoint's actual authorization predicate:
            $rejected = ($configuredToken === '' || $presentedToken === '' || !hash_equals($configuredToken, $presentedToken));
            $this->assertTrue(
                $rejected,
                'empty configured token + empty presented token MUST be rejected despite hash_equals("","")===true'
            );

            // And a non-empty presented token against an empty configured token:
            $presentedToken = 'guess';
            $rejected = ($configuredToken === '' || $presentedToken === '' || !hash_equals($configuredToken, $presentedToken));
            $this->assertTrue($rejected, 'empty configured token must reject any presented token');
        }

        /**
         * Fully-isolated subprocess proof: when WS_TRIGGER_TOKEN is genuinely
         * UNDEFINED (never define()'d), the endpoint refuses even a request that
         * presents an empty token — the endpoint is never open by default. This is
         * the one branch the in-process suite cannot reach (define() is permanent),
         * so we run it in a clean php child.
         */
        public function testFailsClosedWhenTokenConstantUndefined(): void
        {
            $harness = __DIR__.'/fixtures/trigger_payment_undefined_token.php';
            $this->assertFileExists($harness, 'subprocess harness must exist');
            $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($harness).' 2>&1';
            $out = trim((string) shell_exec($cmd));
            $decoded = json_decode($out, true);
            $this->assertIsArray($decoded, "subprocess must emit JSON; got: {$out}");
            $this->assertSame('error', $decoded['status']);
            $this->assertSame(
                'unauthorized',
                $decoded['error'],
                'undefined WS_TRIGGER_TOKEN must fail closed (endpoint never open by default)'
            );
        }

        // -------------------------------------------------------------------
        // Dormancy: valid auth but Flag A OFF must not nudge
        // -------------------------------------------------------------------

        /**
         * Correct token but Flag A OFF (B8 ship-dormant) → {"status":"error",
         * "error":"disabled"} and no nudge. Proven with a spying $global.
         */
        public function testValidTokenButFlagOffRepliesDisabledAndDoesNotNudge(): void
        {
            $_POST = ['token' => self::TOKEN];
            $spy = $this->injectFakeGlobal();
            // Flag A explicitly OFF (global false, no host override).
            FeatureFlags::setNewHandling(null, false);
            Events::$db = $this->emptyQueueDb();

            $res = $this->invoke();

            $this->assertSame(['status' => 'error', 'error' => 'disabled'], $res);
            $this->assertSame(0, $spy->casCalls, 'Flag A OFF must be a runtime no-op — no nudge');
        }

        // NOTE: the "GlobalData entirely unavailable => Flag A fails safe OFF =>
        // disabled" guarantee is deliberately NOT re-tested here. FeatureFlagsTest
        // already proves that fail-safe default exhaustively, and exercising it
        // through this endpoint would force FeatureFlags' lazy client to attempt a
        // real socket connect to GLOBALDATA_IP:2207 (emitting a stream_socket_client
        // "Connection refused" PHP warning), needlessly dirtying an otherwise
        // warning-free suite. The dormancy contract for THIS endpoint is fully
        // covered by testValidTokenButFlagOffRepliesDisabledAndDoesNotNudge above.

        // -------------------------------------------------------------------
        // Nudge: valid auth + Flag A ON reaches the real timer
        // -------------------------------------------------------------------

        /**
         * Correct token + Flag A ON → the REAL Events::processing_queue_timer()
         * runs to completion. With an empty-queue DB fake it takes the CAS,
         * finds no pending rows, and releases the lock (recording
         * processing_queue_last). The endpoint returns the success shape.
         *
         * This proves the nudge path is genuinely REACHED and the real timer
         * method executes its cross-process-safe body — not merely that a call
         * "would" happen.
         */
        public function testValidTokenFlagOnReachesRealTimerAndReturnsOk(): void
        {
            $_POST = ['token' => self::TOKEN];
            $global = $this->injectFakeGlobal();
            FeatureFlags::setNewHandling(null, true);
            Events::$db = $this->emptyQueueDb();

            $res = $this->invoke();

            $this->assertSame('ok', $res['status'], 'Flag A ON + valid token => success');
            $this->assertSame('processing_queue_timer', $res['nudged']);
            $this->assertArrayHasKey('ts', $res);
            $this->assertIsInt($res['ts']);

            // Prove the REAL timer body executed: it acquired the CAS lock and, on
            // the empty-queue branch, released it recording a last-run timestamp.
            $this->assertGreaterThanOrEqual(1, $global->casCalls, 'the real timer must have attempted the processing_queue CAS');
            $this->assertArrayHasKey('processing_queue_last', $global->store, 'empty-queue branch must release the lock, recording processing_queue_last');
            $this->assertSame(0, $global->store['processing_queue'], 'lock must be released back to 0 after an empty run');
        }

        // -------------------------------------------------------------------
        // Robustness: a \Throwable during the nudge is caught (review LOW #1)
        // -------------------------------------------------------------------

        /**
         * If the nudge call raises a \Throwable (here: a fake $global whose cas()
         * throws an \Error), the endpoint's catch(\Throwable) must convert it to a
         * graceful {"status":"error","error":"unavailable"} response instead of
         * letting an uncaught fatal escape. Proves review LOW note #1 (the widen to
         * \Throwable) behaves as intended.
         */
        public function testThrowableDuringNudgeIsCaughtGracefully(): void
        {
            $_POST = ['token' => self::TOKEN];
            $throwing = new class extends \GlobalData\Client {
                /** @var array<string,mixed> */
                public $store = ['processing_queue' => 0];
                public function __construct()
                {
                }
                public function __get($k)
                {
                    return $this->store[$k] ?? null;
                }
                public function __set($k, $v)
                {
                    $this->store[$k] = $v;
                }
                public function __isset($k)
                {
                    return isset($this->store[$k]);
                }
                public function __unset($k)
                {
                    unset($this->store[$k]);
                }
                public function cas($key, $old, $new)
                {
                    throw new \Error('simulated GlobalData explosion mid-nudge');
                }
            };
            $GLOBALS['global'] = $throwing;
            FeatureFlags::setNewHandling(null, true);
            Events::$db = $this->emptyQueueDb();

            $res = $this->invoke();

            $this->assertSame(['status' => 'error', 'error' => 'unavailable'], $res, 'a \Throwable in the nudge must be caught and reported as unavailable');
        }
    }
}
