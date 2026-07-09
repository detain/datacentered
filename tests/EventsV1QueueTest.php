<?php

/**
 * Test seam for the v1 `queue.*` parity bridge added in WS-revamp Phase 2
 * step 2.5 (docs/PROTOCOL_V1.md §2.4; the HIGHEST-RISK step — the
 * queue-processing byte-compat invariant). Covers Events::handleQueueAction /
 * handleQueuePull / handleQueueProvision / handleQueueAck and the shared
 * queueBindIdentity / dispatchQueueTask plumbing, driven through the public
 * Events::dispatchV1() entry with Flag A ON and an authed host session.
 *
 * SEAM NOTE — dispatchTask capture:
 *   queue.* dispatches to the TaskWorker (Tasks/queue_action.php) via
 *   Events::dispatchTask(), which in production opens an AsyncTcpConnection and
 *   needs a running Workerman event loop (fatals without one). The BusinessWorker
 *   bridge under test is the arg-marshalling + reply-shaping — NOT the TaskWorker
 *   executor. So we inject Events::$taskDispatcher (a strict-null-guarded test
 *   seam that is null/inert in production) to CAPTURE the ($type,$args) that would
 *   have been dispatched and to INJECT a fake task result, exercising the real
 *   handlers end-to-end on the hub side.
 *
 * MYSTAGE-RUNTIME SEAM (documented, not faked green):
 *   The real vps_queue_handler/qs_queue_handler/ServiceQueueHandler live in the
 *   /home/my mystage tree and only load inside the bootstrapped TaskWorker; they
 *   are NOT loadable in this datacentered PHPUnit harness. Full end-to-end
 *   "WS reply === direct vps_queue_handler() output" parity therefore requires the
 *   TaskWorker/mystage runtime and is NOT executed here. What IS proven here,
 *   which is the actual risk of an additive bridge, is STRUCTURAL parity:
 *     (a) the bridge injects the client args VERBATIM into the task dispatch and
 *         resolves module/host_id/uid from the AUTHED SESSION (never the client);
 *     (b) the raw handler result is returned to the WS client UNMODIFIED
 *         (byte-identical passthrough, no re-encode/transform);
 *     (c) the Tasks/queue_action.php superglobal save/restore wrapper is proven
 *         directly with a fake handler (verbatim $_REQUEST injection + finally
 *         restore, including on throw) — see the QueueActionTaskShim test below.
 *   Live-handler parity + the HTTP byte-diff are guaranteed structurally by the
 *   ⛔ byte-compat regression (git evidence: Web/queue.php + all queue tasks +
 *   ServiceQueueHandler/ResponseHandlers/Commands unchanged) reported separately.
 */

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the shared fake Gateway seam, then requires FeatureFlags + Events.
    require_once __DIR__.'/V1TestSupport.php';

    /** In-memory GlobalData client with a working CAS (Flag A toggling). */
    if (!class_exists('QueueFakeGlobalDataClient')) {
        class QueueFakeGlobalDataClient extends \GlobalData\Client
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
     * Tests for the v1 queue.* handlers (WS-revamp Phase 2 step 2.5). Scope is
     * strictly the NEW step-2.5 bridge code.
     */
    class EventsV1QueueTest extends TestCase
    {
        /** @var QueueFakeGlobalDataClient */
        private $global;

        /** @var array<int,array{type:string,args:array}> captured dispatchTask calls */
        private $dispatched = [];

        /**
         * Controls what the faked TaskWorker returns to the onResult callback.
         * Format mirrors the real TaskWorker envelope: {"return":"<task json>"}.
         * When null, the fake dispatcher captures only (no callback fired) so we
         * can assert the pre-dispatch behavior (rejections dispatch nothing).
         * @var string|null
         */
        private $fakeTaskReturn = null;

        /** When true, the fake dispatcher fires the $onError path instead. */
        private $fakeTaskError = false;

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
            \Events::$taskDispatcher = null;
            unset($GLOBALS['global']);
            $this->dispatched = [];
            $this->fakeTaskReturn = null;
            $this->fakeTaskError = false;

            $ref = new ReflectionClass(FeatureFlags::class);
            $prop = $ref->getProperty('client');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        // ------------------------------------------------------------------
        // Fixtures / helpers
        // ------------------------------------------------------------------

        /** Inject the in-memory GlobalData client and flip Flag A ON. */
        private function flagAOn(): QueueFakeGlobalDataClient
        {
            $client = new QueueFakeGlobalDataClient();
            $client->store[FeatureFlags::VAR_NEW_HANDLING] = 1;
            $GLOBALS['global'] = $client;
            $this->global = $client;
            return $client;
        }

        /**
         * Install the capturing task-dispatch seam. Every dispatchTask() call is
         * recorded; if a fake return/error is configured, the corresponding
         * callback is invoked synchronously (as the real onMessage/onClose would).
         */
        private function installTaskCapture(): void
        {
            \Events::$taskDispatcher = function ($type, $args, $onResult, $onError) {
                $this->dispatched[] = ['type' => $type, 'args' => $args];
                if ($this->fakeTaskError) {
                    if ($onError) {
                        $onError();
                    }
                    return;
                }
                if ($this->fakeTaskReturn !== null && $onResult) {
                    $onResult($this->fakeTaskReturn);
                }
            };
        }

        /** Build the TaskWorker-shaped result envelope for a successful handler run. */
        private function taskOk(string $rawResult): string
        {
            return json_encode(['return' => json_encode(['ok' => true, 'result' => $rawResult])]);
        }

        /** Build the TaskWorker-shaped result envelope for a failed task. */
        private function taskErr(string $msg): string
        {
            return json_encode(['return' => json_encode(['ok' => false, 'error' => $msg])]);
        }

        /** Authed vps host session bound to host uid "vps<id>". */
        private function asVpsHost(int $id): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'host', 'module' => 'vps', 'uid' => 'vps'.$id];
        }

        /** Authed quickservers host session bound to uid "qs<id>". */
        private function asQsHost(int $id): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'host', 'module' => 'quickservers', 'uid' => 'qs'.$id];
        }

        /** Authed bot session (module "bot", not host-bound). */
        private function asBot(string $uid = 'bot-1'): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'bot', 'module' => 'bot', 'uid' => $uid];
        }

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

        private function closed(): array
        {
            return \GatewayWorker\Lib\Gateway::$closed;
        }

        private function singleReply(): array
        {
            $sent = $this->sent();
            $this->assertCount(1, $sent, 'expected exactly one client reply on the wire');
            $decoded = json_decode($sent[0]['message'], true);
            $this->assertIsArray($decoded);
            return $decoded;
        }

        private function assertErrorReply(string $code): array
        {
            $reply = $this->singleReply();
            $this->assertFalse($reply['ok'], "reply must be ok:false for {$code}");
            $this->assertSame($code, $reply['error']['code']);
            return $reply;
        }

        /**
         * Redirect Worker::$outputStream to a temp file for the duration of $fn,
         * returning everything safeEcho wrote (same technique as the pty test).
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

        // ================================================================
        // 1. Bridge dispatch correctness — identity from SESSION, args verbatim
        // ================================================================

        public function testQueueActionDispatchesTaskWithSessionIdentityAndVerbatimArgs(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('SCRIPT-BODY');

            $clientArgs = ['id' => 55, 'nested' => ['a' => 1, 'b' => [2, 3]], 'blank' => ''];
            $this->dispatch('queue.action', [
                'module' => 'vps',
                'action' => 'lock',
                'args' => $clientArgs,
                // Hostile/ignored client identity fields — must NOT reach the task.
                'host_id' => 999999,
                'uid' => 'vps999999'
            ]);

            $this->assertCount(1, $this->dispatched, 'exactly one queue_action dispatch');
            $call = $this->dispatched[0];
            $this->assertSame('queue_action', $call['type']);
            // module/host_id/uid come from the SESSION, never the client envelope.
            $this->assertSame('vps', $call['args']['module']);
            $this->assertSame(1234, $call['args']['host_id'], 'host_id derived from session uid, not client');
            $this->assertSame('vps1234', $call['args']['uid'], 'uid from session, not client');
            $this->assertSame('lock', $call['args']['action'], 'action from validated envelope');
            // args passed through byte-identically (no transform, no re-key).
            $this->assertSame($clientArgs, $call['args']['args'], 'args injected verbatim');
        }

        public function testQueueActionQuickserversModuleAndPrefix(): void
        {
            $this->flagAOn();
            $this->asQsHost(77);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('');

            $this->dispatch('queue.action', ['module' => 'quickservers', 'action' => 'get_info', 'args' => []]);

            $call = $this->dispatched[0];
            $this->assertSame('quickservers', $call['args']['module']);
            $this->assertSame(77, $call['args']['host_id']);
            $this->assertSame('qs77', $call['args']['uid']);
        }

        public function testQueueActionOmittedArgsDefaultsToEmptyObject(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('');

            $this->dispatch('queue.action', ['module' => 'vps', 'action' => 'get_queue']);

            $this->assertSame([], $this->dispatched[0]['args']['args'], 'missing args -> {}');
        }

        // ================================================================
        // 2. Identity / auth rejections (queueBindIdentity)
        // ================================================================

        public function testQueueActionRoleAdminForbidden(): void
        {
            $this->flagAOn();
            $_SESSION = ['v1_authed' => true, 'ima' => 'admin', 'module' => 'vps', 'uid' => 'admin-1'];
            $this->installTaskCapture();

            $this->dispatch('queue.action', ['module' => 'vps', 'action' => 'get_queue']);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->dispatched, 'no task dispatch on forbidden role');
        }

        public function testQueueActionBotRoleForbidden(): void
        {
            // bot role passes the host/bot gate but its module is "bot", so the
            // module-match check rejects it (documented conservative deny).
            $this->flagAOn();
            $this->asBot();
            $this->installTaskCapture();

            $this->dispatch('queue.action', ['module' => 'vps', 'action' => 'get_queue']);

            $reply = $this->assertErrorReply('forbidden');
            $this->assertStringContainsString('not a registered vps host', $reply['error']['message']);
            $this->assertCount(0, $this->dispatched);
        }

        public function testQueueActionMissingModuleBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('queue.action', ['action' => 'get_queue']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testQueueActionInvalidModuleBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('queue.action', ['module' => 'dedicated', 'action' => 'get_queue']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testQueueActionModuleMismatchWithSessionForbidden(): void
        {
            // Session is a vps host but the client claims quickservers — can't
            // claim a module you're not registered as.
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();

            $this->dispatch('queue.action', ['module' => 'quickservers', 'action' => 'get_queue']);

            $reply = $this->assertErrorReply('forbidden');
            $this->assertStringContainsString('not a registered quickservers host', $reply['error']['message']);
            $this->assertCount(0, $this->dispatched);
        }

        public function testQueueActionMissingActionBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('queue.action', ['module' => 'vps', 'action' => '   ']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testQueueActionNonObjectArgsBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('queue.action', ['module' => 'vps', 'action' => 'lock', 'args' => 'not-an-object']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testQueueActionUnauthedRepliesAuthRequiredAndCloses(): void
        {
            $this->flagAOn();
            $_SESSION = []; // not v1-authed
            $this->installTaskCapture();

            $this->dispatch('queue.action', ['module' => 'vps', 'action' => 'get_queue'], 42);

            $reply = $this->singleReply();
            $this->assertFalse($reply['ok']);
            $this->assertSame('auth_required', $reply['error']['code']);
            $this->assertContains(42, $this->closed(), 'unauthed queue op must close the connection');
            $this->assertCount(0, $this->dispatched);
        }

        public function testQueueActionDormantWhenFlagAOff(): void
        {
            // Flag A OFF: client present but ws_new_handling unset.
            $client = new QueueFakeGlobalDataClient();
            $GLOBALS['global'] = $client;
            $this->global = $client;
            $this->asVpsHost(1234);
            $this->installTaskCapture();

            $this->dispatch('queue.action', ['module' => 'vps', 'action' => 'get_queue']);

            $this->assertCount(0, $this->sent(), 'Flag A OFF: queue.action produces no reply');
            $this->assertCount(0, $this->dispatched, 'Flag A OFF: queue.action dispatches nothing');
            $this->assertCount(0, $this->closed());
        }

        // Same dormancy/rejection surface applies to every queue.* alias.
        public function testQueuePullPullProvisionAckAllDormantWhenFlagAOff(): void
        {
            $client = new QueueFakeGlobalDataClient();
            $GLOBALS['global'] = $client;
            $this->global = $client;
            $this->asVpsHost(1);
            $this->installTaskCapture();

            foreach (['queue.pull', 'queue.provision', 'queue.ack'] as $op) {
                $this->dispatch($op, ['module' => 'vps', 'history_id' => 5, 'status' => 'done', 'output' => '']);
            }
            $this->assertCount(0, $this->sent());
            $this->assertCount(0, $this->dispatched);
        }

        // ================================================================
        // 3. queue.ack — ZERO-WRITE proof
        // ================================================================

        public function testQueueAckDoesNotDispatchAnyTaskAndRepliesOk(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();

            $this->dispatch('queue.ack', ['history_id' => 42, 'status' => 'done', 'output' => 'done output', 'module' => 'vps']);

            // The core zero-write proof: queue.ack NEVER reaches the queue_action
            // task (or any task), so no DB-writing / queueold-flipping path is
            // even reachable — it is purely a logged acknowledgement.
            $this->assertCount(0, $this->dispatched, 'queue.ack must NOT dispatch any task (no DB write path reachable)');

            $reply = $this->singleReply();
            $this->assertTrue($reply['ok']);
            $this->assertSame('req-1', $reply['re']);
            // data is an empty object {} (stdClass). Assert it serialized as {}
            // (not [] and not carrying any fields).
            $rawMsg = $this->sent()[0]['message'];
            $this->assertStringContainsString('"data":{}', $rawMsg, 'queue.ack data must be an empty object');
            $this->assertSame([], (array) $reply['data']);
        }

        public function testQueueAckEmitsStructuredLogLineNoTaskNoDbWrite(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();

            $captured = $this->captureWorkerOutput(function () {
                $this->dispatch('queue.ack', ['history_id' => 7, 'status' => 'failed', 'output' => 'boom', 'module' => 'vps']);
            });

            $this->assertCount(0, $this->dispatched, 'still zero task dispatch when logging');
            $this->assertMatchesRegularExpression('/^queue_ack \{.*\}$/m', $captured, 'structured queue_ack line emitted');
            // Parse the JSON payload of the ack line and assert additive-only fields.
            $this->assertSame(1, preg_match('/queue_ack (\{.*\})/', $captured, $m));
            $ack = json_decode($m[1], true);
            $this->assertIsArray($ack);
            $this->assertSame(7, $ack['history_id']);
            $this->assertSame('failed', $ack['status']);
            $this->assertSame('vps', $ack['module']);
            $this->assertSame(1234, $ack['host_id'], 'host_id from session, not client');
            $this->assertSame('vps1234', $ack['who']);
            // Only output LENGTH is logged, never the body (log-sanity).
            $this->assertSame(4, $ack['output_len']);
            $this->assertArrayNotHasKey('output', $ack, 'ack line must not carry the raw output body');
        }

        public function testQueueAckBadStatusBadRequestNoDispatch(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('queue.ack', ['history_id' => 1, 'status' => 'maybe', 'output' => '', 'module' => 'vps']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testQueueAckNonPositiveHistoryIdBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('queue.ack', ['history_id' => 0, 'status' => 'done', 'output' => '', 'module' => 'vps']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testQueueAckNonStringOutputBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('queue.ack', ['history_id' => 1, 'status' => 'done', 'output' => 123, 'module' => 'vps']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        // ================================================================
        // 4. queue.pull / queue.provision alias mapping + reply shape
        // ================================================================

        public function testQueuePullForcesGetQueueActionAndAggregateWrap(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk("#!/bin/sh\necho hi\n");

            $this->dispatch('queue.pull', ['module' => 'vps']);

            // Alias forces action=get_queue with empty args.
            $call = $this->dispatched[0];
            $this->assertSame('get_queue', $call['args']['action']);
            $this->assertSame([], $call['args']['args']);
            $this->assertSame(1234, $call['args']['host_id']);

            $reply = $this->singleReply();
            $this->assertTrue($reply['ok']);
            $jobs = $reply['data']['jobs'];
            $this->assertCount(1, $jobs, 'non-empty result -> single aggregate job');
            $this->assertSame(0, $jobs[0]['history_id'], 'aggregate sentinel history_id 0');
            $this->assertSame('get_queue', $jobs[0]['command']);
            // Raw script carried verbatim in args.script.
            $this->assertSame("#!/bin/sh\necho hi\n", $jobs[0]['args']['script']);
        }

        public function testQueuePullEmptyResultYieldsEmptyJobs(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk(''); // LOW-1: empty => jobs:[]

            $this->dispatch('queue.pull', ['module' => 'vps']);

            $reply = $this->singleReply();
            $this->assertTrue($reply['ok']);
            $this->assertSame([], $reply['data']['jobs'], 'empty handler output => jobs:[]');
        }

        public function testQueuePullWhitespaceOnlyResultIsIncluded(): void
        {
            // LOW-1 boundary: only '' is treated empty; whitespace-only is a real
            // (non-empty) body and MUST be included verbatim.
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk("   \n");

            $this->dispatch('queue.pull', ['module' => 'vps']);

            $reply = $this->singleReply();
            $jobs = $reply['data']['jobs'];
            $this->assertCount(1, $jobs, 'whitespace-only output is non-empty => included');
            $this->assertSame("   \n", $jobs[0]['args']['script'], 'whitespace preserved verbatim (no trim)');
        }

        public function testQueueProvisionVpsForcesGetNewVps(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('PROVISION-SCRIPT');

            $this->dispatch('queue.provision', ['module' => 'vps']);

            $this->assertSame('get_new_vps', $this->dispatched[0]['args']['action']);
            $this->assertSame([], $this->dispatched[0]['args']['args']);

            $reply = $this->singleReply();
            $this->assertTrue($reply['ok']);
            $this->assertSame('PROVISION-SCRIPT', $reply['data']['script']);
        }

        public function testQueueProvisionQuickserversForcesGetNewQs(): void
        {
            $this->flagAOn();
            $this->asQsHost(77);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('');

            $this->dispatch('queue.provision', ['module' => 'quickservers']);

            $this->assertSame('get_new_qs', $this->dispatched[0]['args']['action']);
            $reply = $this->singleReply();
            $this->assertSame('', $reply['data']['script'], 'empty provision script passes through as ""');
        }

        public function testQueuePullNonHostRoleForbidden(): void
        {
            $this->flagAOn();
            $_SESSION = ['v1_authed' => true, 'ima' => 'admin', 'module' => 'vps', 'uid' => 'admin-1'];
            $this->installTaskCapture();

            $this->dispatch('queue.pull', ['module' => 'vps']);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->dispatched);
        }

        // ================================================================
        // 5. Task-failure passthrough (dispatchQueueTask error mapping)
        // ================================================================

        public function testQueueActionTaskFailureMapsToInternalError(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskErr('no vps master row for host_id 1');

            $this->dispatch('queue.action', ['module' => 'vps', 'action' => 'get_queue']);

            $reply = $this->assertErrorReply('internal');
            $this->assertSame('no vps master row for host_id 1', $reply['error']['message'], 'inner task error surfaced');
        }

        public function testQueueActionTaskDispatchFailureMapsToInternalError(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();
            $this->fakeTaskError = true; // onError path (connection failed)

            $this->dispatch('queue.action', ['module' => 'vps', 'action' => 'get_queue']);

            $reply = $this->assertErrorReply('internal');
            $this->assertSame('queue task dispatch failed', $reply['error']['message']);
        }

        // ================================================================
        // 6. Output passthrough VERBATIM (byte-identity through the bridge)
        // ================================================================

        public function testQueueActionResultReturnedByteIdentical(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            // A representative "hostile" payload: JSON-ish text, embedded quotes,
            // unicode, control chars — must survive verbatim with no re-encode
            // of the inner result and no trimming.
            $raw = "{\"a\":1}\n\ttrailing spaces   \r\n\"quoted\" \\backslash\\ üñîçødé \x01\x02 end";
            $this->fakeTaskReturn = $this->taskOk($raw);

            $this->dispatch('queue.action', ['module' => 'vps', 'action' => 'server_info', 'args' => ['x' => 1]]);

            $reply = $this->singleReply();
            $this->assertTrue($reply['ok']);
            $this->assertSame($raw, $reply['data']['result'], 'handler result must be byte-identical through the WS reply');
            // Structural reply shape per §2.4: {v,re,ok,data:{result}}.
            $this->assertSame(1, $reply['v']);
            $this->assertSame('req-1', $reply['re']);
            $this->assertArrayHasKey('result', $reply['data']);
        }
    }

}
