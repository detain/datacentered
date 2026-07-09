<?php

/**
 * Tests for the v1 `config.maps` + `vps.*` lifecycle ops AND the inbound
 * `enc:"gzip"` envelope-decode capability added in WS-revamp Phase 2 step 2.6
 * (docs/PROTOCOL_V1.md §2.6, §2.7, §1). Covers Events::handleConfigMaps /
 * handleVpsLock / handleVpsUnlock / handleVpsFinished / handleVpsProgress and
 * the v1DecodeEnvelopeData() gzip path, driven through the public
 * Events::dispatchV1() entry with Flag A ON.
 *
 * SEAM NOTE — same dispatchTask/dispatchQueueTask capture as EventsV1QueueTest:
 *   Events::$taskDispatcher (null/inert in production) captures ($type,$args)
 *   and injects a fake TaskWorker return, so the hub-side bridge is exercised
 *   end-to-end WITHOUT a live TaskWorker/mystage runtime.
 *
 * ⛔ config.maps BYTE-COMPAT: the four registry strings (slices/vnc/ips/mainips)
 *   are asserted to pass through the hub UNTRIMMED and UNTRANSFORMED — the exact
 *   "\n"-joined `k:v` line blocks GetMap.php produces (each line "vzid:value\n";
 *   ips lines are "mainip:addonip\n"). The HOST applies trim() before writing to
 *   disk; the hub MUST NOT touch these bytes.
 */

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__.'/V1TestSupport.php';

    if (!class_exists('ConfigVpsFakeGlobalDataClient')) {
        class ConfigVpsFakeGlobalDataClient extends \GlobalData\Client
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

            public function add($key, $value)
            {
                if (!array_key_exists($key, $this->store)) {
                    $this->store[$key] = $value;
                }
                return true;
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

    class EventsV1ConfigVpsTest extends TestCase
    {
        /** @var ConfigVpsFakeGlobalDataClient */
        private $global;

        /** @var array<int,array{type:string,args:array}> */
        private $dispatched = [];

        /** @var string|null */
        private $fakeTaskReturn = null;

        /** @var bool */
        private $fakeTaskError = false;

        protected function setUp(): void
        {
            $this->resetState();
            // Legacy fallthrough paths (onMessage) log via safeEcho with REMOTE_ADDR.
            $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
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

        private function flagAOn(): ConfigVpsFakeGlobalDataClient
        {
            $client = new ConfigVpsFakeGlobalDataClient();
            $client->store[FeatureFlags::VAR_NEW_HANDLING] = 1;
            $GLOBALS['global'] = $client;
            $this->global = $client;
            return $client;
        }

        private function flagAOff(): ConfigVpsFakeGlobalDataClient
        {
            $client = new ConfigVpsFakeGlobalDataClient();
            $GLOBALS['global'] = $client;
            $this->global = $client;
            return $client;
        }

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

        /** queue_action-shaped success envelope (for the vps.* ops). */
        private function taskOk(string $rawResult): string
        {
            return json_encode(['return' => json_encode(['ok' => true, 'result' => $rawResult])]);
        }

        /**
         * get_map-shaped success envelope: the TaskWorker wraps the task return
         * as {"return":<str>}, and get_map's return is GetMap.php's json_encode
         * of the four map strings.
         */
        private function taskMapReturn(array $map): string
        {
            return json_encode(['return' => json_encode($map)]);
        }

        private function asVpsHost(int $id): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'host', 'module' => 'vps', 'uid' => 'vps'.$id];
        }

        private function asQsHost(int $id): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'host', 'module' => 'quickservers', 'uid' => 'qs'.$id];
        }

        private function asAdmin(string $uid = 'admin-1'): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'admin', 'module' => 'vps', 'uid' => $uid];
        }

        private function dispatch(string $op, $data, int $client = 1, string $id = 'req-1', array $extra = []): void
        {
            \Events::dispatchV1($client, array_merge([
                'v' => 1, 'id' => $id, 'op' => $op, 'ts' => 1719700000, 'data' => $data
            ], $extra));
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

        /** base64(gzcompress(json_encode(x))) — the §0 b64gz / enc:"gzip" form. */
        private function b64gz($value): string
        {
            return base64_encode(gzcompress(json_encode($value)));
        }

        // ================================================================
        // config.maps — happy path + ⛔ BYTE-COMPAT
        // ================================================================

        public function testConfigMapsPullDispatchesGetMapAndReturnsFourStrings(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();

            // Multi-line ground-truth GetMap.php output (each line "k:v\n").
            $map = [
                'slices' => "101:2\n102:4\n",
                'vnc' => "101:5900\n102:5901\n",
                'ips' => "1.1.1.1:1.1.1.2\n1.1.1.1:1.1.1.3\n",
                'mainips' => "101:1.1.1.1\n102:2.2.2.2\n"
            ];
            $this->fakeTaskReturn = $this->taskMapReturn($map);

            $this->dispatch('config.maps', []);

            // Dispatch: get_map with the session-derived host id (never the payload).
            $call = $this->dispatched[0];
            $this->assertSame('get_map', $call['type']);
            $this->assertSame(1234, $call['args']['id']);

            $reply = $this->singleReply();
            $this->assertTrue($reply['ok']);
            $this->assertSame('req-1', $reply['re']);
            // ⛔ BYTE-COMPAT: the four strings pass through UNTRIMMED and untouched,
            // trailing "\n"s intact, no extra encoding.
            $this->assertSame($map['slices'], $reply['data']['slices']);
            $this->assertSame($map['vnc'], $reply['data']['vnc']);
            $this->assertSame($map['ips'], $reply['data']['ips']);
            $this->assertSame($map['mainips'], $reply['data']['mainips']);
        }

        public function testConfigMapsByteCompatSingleLineData(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $map = [
                'slices' => "500:8\n",
                'vnc' => "500:5999\n",
                'ips' => "9.9.9.9:9.9.9.10\n",
                'mainips' => "500:9.9.9.9\n"
            ];
            $this->fakeTaskReturn = $this->taskMapReturn($map);

            $this->dispatch('config.maps', []);

            $reply = $this->singleReply();
            foreach (['slices', 'vnc', 'ips', 'mainips'] as $k) {
                $this->assertSame($map[$k], $reply['data'][$k], "single-line {$k} byte-identical (trailing \\n kept)");
            }
        }

        public function testConfigMapsByteCompatEmptyData(): void
        {
            // A host with no active services: GetMap.php initializes each to '' and
            // never appends. The hub passes the empty strings through unchanged —
            // no null, no missing key, no "[]" or other transform.
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $map = ['slices' => '', 'vnc' => '', 'ips' => '', 'mainips' => ''];
            $this->fakeTaskReturn = $this->taskMapReturn($map);

            $this->dispatch('config.maps', []);

            $reply = $this->singleReply();
            $this->assertTrue($reply['ok']);
            foreach (['slices', 'vnc', 'ips', 'mainips'] as $k) {
                $this->assertSame('', $reply['data'][$k], "empty {$k} stays exactly ''");
            }
            // Assert the raw wire has empty strings (no whitespace, no other encoding).
            $raw = $this->sent()[0]['message'];
            $this->assertStringContainsString('"slices":""', $raw);
            $this->assertStringContainsString('"vnc":""', $raw);
            $this->assertStringContainsString('"ips":""', $raw);
            $this->assertStringContainsString('"mainips":""', $raw);
        }

        public function testConfigMapsPreservesLeadingAndTrailingWhitespaceExactly(): void
        {
            // The hub must not trim() — if GetMap ever emitted leading/edge
            // whitespace, the hub forwards it verbatim (the HOST trims on write).
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $map = [
                'slices' => "  101:2  \n\n",
                'vnc' => "\n101:5900\n",
                'ips' => "1.1.1.1:1.1.1.2\n   ",
                'mainips' => "\t101:1.1.1.1\n"
            ];
            $this->fakeTaskReturn = $this->taskMapReturn($map);

            $this->dispatch('config.maps', []);

            $reply = $this->singleReply();
            foreach (['slices', 'vnc', 'ips', 'mainips'] as $k) {
                $this->assertSame($map[$k], $reply['data'][$k], "no trim/whitespace mangling on {$k}");
            }
        }

        public function testConfigMapsUnexpectedTaskShapeInternalError(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();
            // Missing one of the four required keys.
            $this->fakeTaskReturn = $this->taskMapReturn(['slices' => '', 'vnc' => '', 'ips' => '']);

            $this->dispatch('config.maps', []);

            $this->assertErrorReply('internal');
        }

        public function testConfigMapsTaskDispatchFailureInternalError(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();
            $this->fakeTaskError = true;

            $this->dispatch('config.maps', []);

            $reply = $this->assertErrorReply('internal');
            $this->assertStringContainsString('get_map task dispatch failed', $reply['error']['message']);
        }

        public function testConfigMapsQuickserversForbiddenVpsOnly(): void
        {
            $this->flagAOn();
            $this->asQsHost(77);
            $this->installTaskCapture();

            $this->dispatch('config.maps', []);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->dispatched);
        }

        public function testConfigMapsNonHostRoleForbidden(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->installTaskCapture();

            $this->dispatch('config.maps', []);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->dispatched);
        }

        // ================================================================
        // vps.lock / vps.unlock — vps_id -> legacy `id`
        // ================================================================

        public function testVpsLockDispatchesLockWithIdMapping(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('');

            $this->dispatch('vps.lock', ['module' => 'vps', 'vps_id' => 55]);

            $call = $this->dispatched[0];
            $this->assertSame('queue_action', $call['type']);
            $this->assertSame('lock', $call['args']['action']);
            $this->assertSame(1234, $call['args']['host_id'], 'host_id from session');
            // §2.7 field mapping: vps_id => legacy request field `id`.
            $this->assertSame(['id' => 55], $call['args']['args']);
            $this->assertCount(0, $this->sent(), 'vps.lock fire-and-forget on success');
        }

        public function testVpsUnlockDispatchesUnlockWithIdMapping(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('');

            $this->dispatch('vps.unlock', ['module' => 'vps', 'vps_id' => 77]);

            $call = $this->dispatched[0];
            $this->assertSame('unlock', $call['args']['action']);
            $this->assertSame(['id' => 77], $call['args']['args']);
            $this->assertCount(0, $this->sent());
        }

        public function testVpsLockQuickserversAllowed(): void
        {
            // vps.lock uses queueBindIdentity (resolves qs natively) — qs allowed.
            $this->flagAOn();
            $this->asQsHost(9);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('');

            $this->dispatch('vps.lock', ['module' => 'quickservers', 'vps_id' => 3]);

            $call = $this->dispatched[0];
            $this->assertSame('quickservers', $call['args']['module']);
            $this->assertSame(9, $call['args']['host_id']);
        }

        public function testVpsLockNonPositiveIdBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('vps.lock', ['module' => 'vps', 'vps_id' => 0]);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testVpsLockModuleMismatchForbidden(): void
        {
            // Hostile: vps host claims quickservers module — rejected by queueBindIdentity.
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();

            $this->dispatch('vps.lock', ['module' => 'quickservers', 'vps_id' => 5]);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->dispatched);
        }

        public function testVpsLockIgnoresSpoofedHostId(): void
        {
            // The host_id used is ALWAYS the session's — a spoofed data.host_id is ignored.
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('');

            $this->dispatch('vps.lock', ['module' => 'vps', 'vps_id' => 5, 'host_id' => 999999]);

            $this->assertSame(1234, $this->dispatched[0]['args']['host_id'], 'spoofed host_id ignored');
        }

        // ================================================================
        // vps.finished — vps_id -> legacy `service`, command required
        // ================================================================

        public function testVpsFinishedDispatchesFinishedWithServiceMapping(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('');

            $this->dispatch('vps.finished', ['module' => 'vps', 'vps_id' => 42, 'command' => 'create']);

            $call = $this->dispatched[0];
            $this->assertSame('finished', $call['args']['action']);
            // §2.7: vps_id => legacy `service`; command passes 1:1.
            $this->assertSame(['service' => 42, 'command' => 'create'], $call['args']['args']);
            $this->assertCount(0, $this->sent());
        }

        public function testVpsFinishedMissingCommandBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('vps.finished', ['module' => 'vps', 'vps_id' => 42]);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testVpsFinishedEmptyCommandBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('vps.finished', ['module' => 'vps', 'vps_id' => 42, 'command' => '']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testVpsFinishedNonPositiveIdBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('vps.finished', ['module' => 'vps', 'vps_id' => -1, 'command' => 'create']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        // ================================================================
        // vps.progress — server/progress 1:1
        // ================================================================

        public function testVpsProgressDispatchesInstallProgress(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('');

            $this->dispatch('vps.progress', ['module' => 'vps', 'server' => '101', 'progress' => 'installing 50%']);

            $call = $this->dispatched[0];
            $this->assertSame('install_progress', $call['args']['action']);
            $this->assertSame(['server' => '101', 'progress' => 'installing 50%'], $call['args']['args']);
            $this->assertCount(0, $this->sent());
        }

        public function testVpsProgressEmptyProgressStringAllowed(): void
        {
            // progress is a string that MAY be empty (only server is required non-empty).
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('');

            $this->dispatch('vps.progress', ['module' => 'vps', 'server' => '101', 'progress' => '']);

            $call = $this->dispatched[0];
            $this->assertSame(['server' => '101', 'progress' => ''], $call['args']['args']);
        }

        public function testVpsProgressMissingServerBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('vps.progress', ['module' => 'vps', 'progress' => 'x']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testVpsProgressNonStringProgressBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('vps.progress', ['module' => 'vps', 'server' => '101', 'progress' => 12345]);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        // ================================================================
        // Dormancy — Flag A OFF: NONE of config.maps/vps.* act
        // ================================================================

        public function testConfigVpsOpsDormantWhenFlagAOff(): void
        {
            $this->flagAOff();
            $this->asVpsHost(1234);
            $this->installTaskCapture();

            $ops = [
                ['config.maps', []],
                ['vps.lock', ['module' => 'vps', 'vps_id' => 5]],
                ['vps.unlock', ['module' => 'vps', 'vps_id' => 5]],
                ['vps.finished', ['module' => 'vps', 'vps_id' => 5, 'command' => 'create']],
                ['vps.progress', ['module' => 'vps', 'server' => '5', 'progress' => 'x']]
            ];
            foreach ($ops as [$op, $data]) {
                $this->dispatch($op, $data);
            }

            $this->assertCount(0, $this->sent(), 'Flag A OFF: no config/vps replies');
            $this->assertCount(0, $this->dispatched, 'Flag A OFF: no config/vps task dispatch');
            $this->assertCount(0, $this->closed());
        }

        // ================================================================
        // enc:"gzip" decode path — the concrete new capability
        // ================================================================

        public function testGzipEnvelopeRoundTripsIntoHandler(): void
        {
            // A valid gzip-encoded envelope decodes and the handler sees data as
            // a normal array — proven via a vps.lock that reaches the dispatch.
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('');

            $payload = ['module' => 'vps', 'vps_id' => 55, 'host_id' => 999999];
            $this->dispatch('vps.lock', $this->b64gz($payload), 1, 'req-1', ['enc' => 'gzip']);

            $call = $this->dispatched[0];
            $this->assertSame('lock', $call['args']['action'], 'gzip payload decoded and dispatched');
            $this->assertSame(['id' => 55], $call['args']['args']);
            $this->assertSame(1234, $call['args']['host_id'], 'session identity still wins over decoded spoof');
        }

        public function testGzipEnvelopeInvalidBase64BadRequest(): void
        {
            // (a) invalid base64 — strict base64_decode returns false.
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('vps.lock', '!!!not base64!!!', 1, 'req-1', ['enc' => 'gzip']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testGzipEnvelopeInvalidZlibStreamBadRequest(): void
        {
            // (b) valid base64 but not a zlib/gzip stream — gzuncompress fails.
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $notZlib = base64_encode('this is valid base64 but not zlib compressed');
            $this->dispatch('vps.lock', $notZlib, 1, 'req-1', ['enc' => 'gzip']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testGzipEnvelopeNonJsonAfterInflateBadRequest(): void
        {
            // (c) valid gzip but non-JSON content after inflate.
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $garbageJson = base64_encode(gzcompress('this is not json at all {'));
            $this->dispatch('vps.lock', $garbageJson, 1, 'req-1', ['enc' => 'gzip']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testGzipEnvelopeJsonScalarAfterInflateBadRequest(): void
        {
            // (d) valid JSON after inflate but NOT an object/array (a bare number
            // and a bare string) — is_array() rejects, graceful bad_request.
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $bareNumber = base64_encode(gzcompress(json_encode(42)));
            $this->dispatch('vps.lock', $bareNumber, 1, 'req-1', ['enc' => 'gzip']);
            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);

            // Second class: bare string.
            \GatewayWorker\Lib\Gateway::reset();
            $bareString = base64_encode(gzcompress(json_encode('hello')));
            $this->dispatch('vps.lock', $bareString, 1, 'req-2', ['enc' => 'gzip']);
            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testUnknownEncValueBadRequest(): void
        {
            // enc present but not "gzip": v1DecodeEnvelopeData returns false. Note
            // isV1Envelope only accepts string data with enc:"gzip", so to reach
            // the decode we must pass the shape check. An enc:"deflate" with string
            // data does NOT match isV1Envelope (falls to legacy) — so we test the
            // decode-layer rejection via a gzip-shaped envelope whose enc is later
            // seen as non-gzip is impossible from the wire. Instead assert the
            // detector correctly rejects a non-gzip enc with string data.
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            \Events::onMessage(1, json_encode([
                'v' => 1, 'id' => 'req-1', 'op' => 'vps.lock', 'ts' => 1719700000,
                'enc' => 'deflate', 'data' => base64_encode('x')
            ]));

            // enc:"deflate" + string data is NOT a v1 envelope (isV1Envelope only
            // accepts enc:"gzip" for string data), and it has no legacy "type",
            // so it is inert: no reply, no dispatch, no close.
            $this->assertCount(0, $this->sent());
            $this->assertCount(0, $this->dispatched);
            $this->assertCount(0, $this->closed());
        }

        // ================================================================
        // isV1Envelope non-regression — bare string data WITHOUT enc
        // ================================================================

        public function testBareStringDataWithoutEncFallsThroughToLegacy(): void
        {
            // CRITICAL non-regression: a message with string data but NO enc field
            // must NOT match the v1 shape (isV1Envelope requires data to be an
            // array unless enc:"gzip"). It has no "type" either, so it is inert.
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            \Events::onMessage(1, json_encode([
                'v' => 1, 'id' => 'req-1', 'op' => 'vps.lock', 'ts' => 1719700000,
                'data' => 'a bare string, no enc'
            ]));

            $this->assertCount(0, $this->sent(), 'bare-string data w/o enc does not match v1, no v1 reply');
            $this->assertCount(0, $this->dispatched);
            $this->assertCount(0, $this->closed());
        }

        public function testBareStringDataWithTypeRoutesToLegacyDispatch(): void
        {
            // Same shape but WITH a legacy "type": must route to legacy msg* path,
            // NOT the v1 router. We use an unknown type so nothing actually runs;
            // the proof is that dispatchV1 was not entered (no v1 reply/close).
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            \Events::onMessage(1, json_encode([
                'v' => 1, 'id' => 'req-1', 'op' => 'vps.lock', 'ts' => 1719700000,
                'type' => 'some_unknown_legacy_type', 'data' => 'a bare string'
            ]));

            // Legacy dispatch for an unknown type just logs and returns — no v1
            // envelope reply, no task dispatch, no close.
            $this->assertCount(0, $this->sent());
            $this->assertCount(0, $this->dispatched);
            $this->assertCount(0, $this->closed());
        }
    }

}
