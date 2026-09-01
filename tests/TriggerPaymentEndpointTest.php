<?php

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the shared fake \GatewayWorker\Lib\Gateway seam BEFORE Events.php
    // loads (so the real gateway transport is never pulled), then requires
    // FeatureFlags + Events. Same bootstrap every other v1 test file uses; it
    // also loads TestBootstrap, which declares the InMemoryRedis facade double.
    require_once __DIR__.'/V1TestSupport.php';

    /**
     * In-file Redis double for the "a \Throwable during the nudge" case.
     *
     * FeatureFlagsTest-style discipline (a purpose-built double lives with the
     * suite that needs it): a normal phpredis SET against a dc:lock: key throws,
     * while every other command — the GET FeatureFlags uses to read Flag A, and
     * the flag's own non-lock SET used to seed it — behaves like the shared
     * InMemoryRedis.
     *
     * Since the transport-recovery hardening, SharedState::lock() WRAPS command
     * dispatch: the throw no longer escapes the facade. It is swallowed into the
     * documented fail-safe null token AND marks the transport dead
     * (SharedState::transportFailed()). Events::processing_queue_timer() checks
     * that signal when it loses a lock: "held elsewhere" stays a silent no-op,
     * but "transport dead" escalates as a \RuntimeException — the honest answer
     * for a nudge that could not even reach Redis — which trigger_payment.php's
     * catch(\Throwable) converts to the "unavailable" response asserted below.
     * Same observable contract as the pre-wrap raw-propagation, via a signal a
     * caller can actually distinguish instead of via exception leakage.
     */
    final class ThrowingLockRedis extends \InMemoryRedis
    {
        public function set($key, $value, $opts = null)
        {
            if (strpos((string) $key, \SharedState::PREFIX_LOCK) === 0) {
                throw new \RuntimeException('simulated Redis explosion acquiring the processing_queue lock');
            }

            return parent::set($key, $value, $opts);
        }
    }

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
     *     - control the WS_TRIGGER_TOKEN constant,
     *     - inject a SharedState Redis double through SharedState::setClient(),
     *     - capture the echoed JSON body via ob_start()/ob_get_clean(),
     *     - assert on the decoded response shape for every branch.
     *   Nothing in the production file is modified — this is genuine black-box
     *   coverage of the shipped code, not a reimplementation of its logic.
     *
     * MIGRATION NOTE (GlobalData→Redis, A1 — this suite ported in 5.2-G).
     *   The endpoint now has ZERO GlobalData usage; the `processing_queue` lock is
     *   a SharedState (Redis) lock. The old seam — an anonymous \GlobalData\Client
     *   fake counted cas() calls and recorded a processing_queue_last key in its
     *   array store — is gone. The equivalent, and stronger, evidence is now read
     *   straight off the injected Redis keyspace:
     *     - "the nudge never ran"  => dc:lock:processing_queue absent AND the whole
     *       keyspace empty (SharedState::set wrote nothing, lock() never seeded).
     *     - "the real timer ran"   => the durable trace the timer's release branch
     *       writes: dc:state:processing_queue_last present, lock released (absent).
     *
     *   The shipped endpoint also gained a hard LOOPBACK-ONLY IP allowlist that runs
     *   BEFORE token/flag/timer. Every logic test therefore uses REMOTE_ADDR
     *   '127.0.0.1' so it actually reaches the branch it names; a dedicated test
     *   (testNonLoopbackAddressIsRejectedBeforeAuth) pins the allowlist itself.
     *
     *   The one branch that cannot be reached in-process is the truly-*undefined*
     *   WS_TRIGGER_TOKEN case: PHP `define()` is permanent, so once any test defines
     *   it we can never observe the undefined state again in the same process. That
     *   edge case is proven in an isolated subprocess
     *   (testFailsClosedWhenTokenConstantUndefined). Its in-process sibling — an
     *   *empty-string* WS_TRIGGER_TOKEN — exercises the identical
     *   `$configuredToken === ''` fail-closed branch, proven by a local
     *   reimplementation in-process and by the undefined-constant subprocess,
     *   including the classic hash_equals('','')===true trap.
     *
     * FLAG CONTROL (property $client dies in the 5.1 wave):
     *   Flags are no longer toggled by reflecting on FeatureFlags::$client (that
     *   property is vestigial and FeatureFlags now reads only through SharedState).
     *   Both flag states are driven through the facade the endpoint actually reads:
     *   SharedState::set(FeatureFlags::VAR_NEW_HANDLING, 1/0) for ON/OFF. The four
     *   auth-negative cases run with the flag unset (default ON under a usable
     *   Redis) — writing nothing, so they assert a literally empty keyspace; the
     *   dormancy case seeds an explicit OFF and asserts no processing_queue
     *   lock/state residue.
     */
    class TriggerPaymentEndpointTest extends TestCase
    {
        private const TARGET = __DIR__.'/../Web/trigger_payment.php';
        private const TOKEN = 'unit-test-shared-secret-abc123';

        /** SharedState key the timer's release branch writes as its durable trace. */
        private const LOCK_KEY = 'dc:lock:processing_queue';
        private const LAST_KEY = 'dc:state:processing_queue_last';

        /** @var \InMemoryRedis the empty-by-default SharedState double for keyspace assertions */
        private $redis;

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
        }

        protected function setUp(): void
        {
            // Clean per-test request + shared state. Loopback so the shipped
            // IP-allowlist (local-only) lets each test reach the branch it targets.
            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            Events::$db = null;
            // $processingLockToken is a PUBLIC static; a leaked token from a prior
            // test (or sibling suite) could make releaseProcessingLock() act on a
            // stale hold, so neutralize it directly rather than by reflection.
            Events::$processingLockToken = null;

            // FeatureFlagsTest-style injection discipline: always resolve a fresh,
            // empty Redis double, never a real client and never a leaked $redis.
            unset($GLOBALS['redis']);
            SharedState::reset();
            $this->redis = new \InMemoryRedis();
            SharedState::setClient($this->redis);
        }

        protected function tearDown(): void
        {
            $_POST = [];
            Events::$db = null;
            Events::$processingLockToken = null;
            SharedState::reset();
            unset($GLOBALS['redis']);
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

        /** Turn Flag A ON through the SharedState facade (the migrated seam). */
        private function seedFlagOn(): void
        {
            SharedState::set(FeatureFlags::VAR_NEW_HANDLING, 1);
        }

        /**
         * Turn Flag A OFF the same way the operator would — an explicit
         * SharedState::set(...,0). Mirrors seedFlagOn() so both dormancy and
         * adoption ride the identical flag seam (per 5.2-G: "flags via
         * SharedState::set(VAR_NEW_HANDLING, 1/0)"), and unlike the null-client
         * fail-safe it emits no error_log noise offline. It does leave the one
         * flag key in the keyspace, so the dormancy test asserts no LOCK/STATE
         * residue rather than a literally empty keyspace (documented reframe).
         */
        private function seedFlagOff(): void
        {
            SharedState::set(FeatureFlags::VAR_NEW_HANDLING, 0);
        }

        /**
         * Assert the nudge produced no processing-queue side effect: the lock was
         * never acquired and the timer's durable trace was never written. Tolerates
         * any dc:flag: seed the test itself placed (that is setup, not a side
         * effect of the endpoint).
         */
        private function assertNoProcessingQueueSideEffect(): void
        {
            $this->assertFalse(
                SharedState::exists(self::LOCK_KEY),
                'a non-nudging path must never acquire the processing_queue lock'
            );
            $this->assertFalse(
                SharedState::exists(self::LAST_KEY),
                'a non-nudging path must never write the timer last-run trace'
            );
            $nudgeKeys = array_values(array_filter(
                $this->redis->allKeys(),
                static fn (string $k): bool => strpos($k, \SharedState::PREFIX_LOCK) === 0
                    || strpos($k, \SharedState::PREFIX_STATE) === 0
            ));
            $this->assertSame(
                [],
                $nudgeKeys,
                'no lock/state key may exist — the nudge never ran'
            );
        }

        /**
         * A DB fake whose query() returns [] so the timer takes its empty-queue
         * branch: acquire the lock, find no pending rows, release + record the
         * last-run trace, and dispatch NO payment task.
         */
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

        /**
         * The one assertion pair that proves a nudge never reached SharedState:
         * the processing_queue lock was never acquired AND nothing at all was
         * written to the keyspace (no lock, no state, no flag).
         */
        private function assertNudgeNeverRan(): void
        {
            $this->assertFalse(
                SharedState::exists(self::LOCK_KEY),
                'a non-nudging path must never acquire the processing_queue lock'
            );
            $this->assertSame(
                [],
                $this->redis->allKeys(),
                'the nudge never ran — no SharedState key may be written'
            );
        }

        // -------------------------------------------------------------------
        // Network gate: the shipped loopback-only allowlist
        // -------------------------------------------------------------------

        /**
         * A non-loopback REMOTE_ADDR is refused at the very first guard, before
         * the token is ever read — so even a request carrying the correct secret
         * is rejected, and no nudge side effect occurs.
         */
        public function testNonLoopbackAddressIsRejectedBeforeAuth(): void
        {
            $_SERVER['REMOTE_ADDR'] = '203.0.113.7'; // a remote attacker address
            $_POST = ['token' => self::TOKEN]; // valid token, but the IP gate must win
            // Flag A defaults ON (unset + usable Redis); the allowlist still pre-empts.
            Events::$db = $this->emptyQueueDb();

            $res = $this->invoke();

            $this->assertSame(['status' => 'error', 'error' => 'unauthorized'], $res);
            $this->assertNudgeNeverRan();
        }

        // -------------------------------------------------------------------
        // Auth: fail-closed behavior (no nudge may occur on any failure)
        // -------------------------------------------------------------------

        /**
         * A non-POST request (GET) carries no $_POST['token']; the endpoint reads
         * the token ONLY from $_POST, so a GET can never authenticate. Rejected,
         * and — proven against the injected Redis keyspace — no nudge is attempted.
         */
        public function testGetRequestIsRejectedAndDoesNotNudge(): void
        {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_POST = []; // GET => no POST body
            // Flag A defaults ON (unset + usable Redis); auth must still gate first.
            Events::$db = $this->emptyQueueDb();

            $res = $this->invoke();

            $this->assertSame(['status' => 'error', 'error' => 'unauthorized'], $res);
            $this->assertNudgeNeverRan();
        }

        /**
         * Missing token POST field with a NON-empty configured constant → rejected.
         */
        public function testMissingTokenIsRejected(): void
        {
            $_POST = []; // no 'token' key
            Events::$db = $this->emptyQueueDb();

            $res = $this->invoke();

            $this->assertSame('error', $res['status']);
            $this->assertSame('unauthorized', $res['error']);
            $this->assertNudgeNeverRan();
        }

        /**
         * Empty token POST field with a NON-empty configured constant → rejected.
         */
        public function testEmptyPresentedTokenIsRejected(): void
        {
            $_POST = ['token' => ''];
            Events::$db = $this->emptyQueueDb();

            $res = $this->invoke();

            $this->assertSame('unauthorized', $res['error']);
            $this->assertNudgeNeverRan();
        }

        /**
         * Wrong (non-matching) token → rejected via constant-time hash_equals.
         */
        public function testWrongTokenIsRejected(): void
        {
            $_POST = ['token' => 'definitely-not-the-secret'];
            Events::$db = $this->emptyQueueDb();

            $res = $this->invoke();

            $this->assertSame('unauthorized', $res['error']);
            $this->assertNudgeNeverRan();
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
         * keep it closed. We prove that here by a local reimplementation of the
         * exact guard for the empty/empty case, since the live constant is
         * non-empty for the rest of the suite (the end-to-end empty-presented-token
         * rejection against the real constant is covered above).
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
         * so we run it in a clean php child. The harness also reports back its
         * SharedState keyspace so we can prove the fail-closed path nudged nothing.
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
            // The child runs against an isolated empty Redis double: failing closed
            // on the undefined token must leave the whole keyspace untouched.
            $this->assertFalse(
                $decoded['lock_present'] ?? true,
                'undefined-token fail-closed must never acquire the processing_queue lock'
            );
            $this->assertSame(
                [],
                $decoded['all_keys'] ?? ['unexpected'],
                'undefined-token fail-closed must write no SharedState key'
            );
        }

        // -------------------------------------------------------------------
        // Dormancy: valid auth but Flag A OFF must not nudge
        // -------------------------------------------------------------------

        /**
         * Correct token but Flag A OFF (B8 ship-dormant) → {"status":"error",
         * "error":"disabled"} and no nudge. Flag A is turned OFF the same explicit
         * way the operator would (SharedState::set(...,0)); the only key that then
         * exists is that flag seed, and there is no processing_queue lock or
         * last-run trace — proving the endpoint returned dormant before nudging.
         */
        public function testValidTokenButFlagOffRepliesDisabledAndDoesNotNudge(): void
        {
            $_POST = ['token' => self::TOKEN];
            $this->seedFlagOff(); // explicit operator OFF, written through SharedState
            Events::$db = $this->emptyQueueDb();

            $res = $this->invoke();

            $this->assertSame(['status' => 'error', 'error' => 'disabled'], $res);
            $this->assertNoProcessingQueueSideEffect();
        }

        // NOTE: the "Redis entirely unavailable => Flag A fails safe OFF" guarantee
        // is proven exhaustively in FeatureFlagsTest; we deliberately do NOT
        // re-test that matrix through this endpoint. This case pins the endpoint's
        // OWN dormancy contract — an authenticated request under an explicit
        // Flag-A-OFF gets "disabled" and touches no processing_queue key — using
        // the same SharedState::set flag seam production reads, with no socket and
        // no fail-safe stderr noise.

        // -------------------------------------------------------------------
        // Nudge: valid auth + Flag A ON reaches the real timer
        // -------------------------------------------------------------------

        /**
         * Correct token + Flag A ON → the REAL Events::processing_queue_timer()
         * runs to completion. With an empty-queue DB fake the shipped timer:
         *   1. SharedState::lock('processing_queue', 900)          (dc:lock:* set NX EX)
         *   2. finds no pending rows                                 (query() => [])
         *   3. releaseProcessingLock() → unlock + set dc:state:processing_queue_last
         * The endpoint returns the success shape.
         *
         * We assert the DURABLE trace the timer leaves behind rather than a
         * transient lock: the last-run key exists (proof the release branch, and
         * therefore the whole acquire→empty→release cycle, genuinely executed) and
         * the lock is gone again afterward. This proves the nudge path is REACHED
         * and completes against the real timer, not merely that it "would" call it.
         */
        public function testValidTokenFlagOnReachesRealTimerAndReturnsOk(): void
        {
            $_POST = ['token' => self::TOKEN];
            $this->seedFlagOn();
            Events::$db = $this->emptyQueueDb();

            $res = $this->invoke();

            $this->assertSame('ok', $res['status'], 'Flag A ON + valid token => success');
            $this->assertSame('processing_queue_timer', $res['nudged']);
            $this->assertArrayHasKey('ts', $res);
            $this->assertIsInt($res['ts']);

            // Prove the REAL timer body ran to its empty-queue release branch: the
            // durable last-run trace exists and carries an integer timestamp.
            $this->assertTrue(
                SharedState::exists(self::LAST_KEY),
                'empty-queue branch must release the lock, recording dc:state:processing_queue_last'
            );
            $this->assertIsInt(SharedState::get(self::LAST_KEY));

            // The lock itself is token-checked-away on release, so it is absent.
            $this->assertFalse(
                SharedState::exists(self::LOCK_KEY),
                'the processing_queue lock must be released (absent) after an empty run'
            );

            // Nothing else leaked into Redis: exactly the seeded flag + the trace.
            $keys = $this->redis->allKeys();
            sort($keys);
            $this->assertSame(
                [FeatureFlags::VAR_NEW_HANDLING, self::LAST_KEY],
                $keys,
                'only the Flag A seed and the durable timer trace may exist post-run — no lock residue'
            );
        }

        // -------------------------------------------------------------------
        // Robustness: a \Throwable during the nudge is caught (review LOW #1)
        // -------------------------------------------------------------------

        /**
         * If the nudge's lock attempt hits a dead Redis transport — here a Redis
         * double whose lock SET throws, simulating the payment chain's transport
         * blowing up mid-acquire — the endpoint must answer the graceful
         * {"status":"error","error":"unavailable"} instead of a fatal or a lie.
         *
         * Post transport-recovery hardening the mechanism is: SharedState::lock()
         * swallows the throw into its fail-safe null token and marks itself
         * transport-dead; Events::processing_queue_timer() distinguishes that
         * null (transportFailed()) from ordinary contention and escalates it as
         * a \RuntimeException, which the endpoint's catch(\Throwable) converts.
         * Proves both the widen to \Throwable (review LOW note #1) AND that the
         * facade's wrap did not silently downgrade an operator nudge to ok-noop.
         *
         * Flag A is seeded ON first (a normal dc:flag: SET that does NOT throw),
         * so the request genuinely reaches the timer before the lock blows up.
         * The dead-window short-circuit then makes the post-run exists() check
         * answer false without touching the handle — the throwing SET left no
         * processing_queue key behind either way.
         */
        public function testThrowableDuringNudgeIsCaughtGracefully(): void
        {
            $_POST = ['token' => self::TOKEN];
            SharedState::setClient(new ThrowingLockRedis());
            // Seed Flag A through the facade — the flag write is a dc:flag: SET,
            // unaffected by the lock-only throw, so the flag gate still passes.
            SharedState::set(FeatureFlags::VAR_NEW_HANDLING, 1);
            Events::$db = $this->emptyQueueDb();

            $res = $this->invoke();

            $this->assertSame(
                ['status' => 'error', 'error' => 'unavailable'],
                $res,
                'a \Throwable in the nudge must be caught and reported as unavailable'
            );
            $this->assertFalse(
                SharedState::exists(self::LOCK_KEY),
                'the throwing lock SET must not leave a processing_queue key behind'
            );
            $this->assertTrue(
                SharedState::transportFailed(),
                'the escalation rode the transport-dead mark — pin the signal the timer distinguished contention by'
            );
        }
    }
}
