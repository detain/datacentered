<?php

/**
 * Tests for the v1 `telemetry.*` host→hub metric ops added in WS-revamp Phase 2
 * step 2.6 (docs/PROTOCOL_V1.md §2.5). Covers Events::handleTelemetryHost /
 * handleTelemetryHostExtra / handleTelemetryCpu / handleTelemetryBandwidth /
 * handleTelemetryInventory / handleTelemetrySysinfo and the shared
 * telemetryBindIdentity plumbing, driven through the public Events::dispatchV1()
 * entry with Flag A ON and an authed host/admin session.
 *
 * SEAM NOTE — dispatchTask/dispatchQueueTask capture:
 *   telemetry ops dispatch to the TaskWorker (Tasks/vps_update_info.php,
 *   Tasks/bandwidth.php, Tasks/vps_get_list.php, or the queue_action bridge for
 *   host_extra/cpu). In production that opens an AsyncTcpConnection needing a
 *   running Workerman event loop. We inject Events::$taskDispatcher (the strict-
 *   null-guarded test seam, null/inert in production) to CAPTURE ($type,$args)
 *   and to INJECT a fake task result, exercising the real handlers end-to-end on
 *   the hub side WITHOUT a live TaskWorker/mystage runtime.
 *
 * The sysinfo relay leg uses the in-memory GlobalData fake (extends
 * \GlobalData\Client) with a working add()/cas() so the correlation registry
 * ($global->sysinfos) round-trips exactly like production.
 */

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__.'/V1TestSupport.php';

    /** In-memory GlobalData client with add()/cas() matching production semantics. */
    if (!class_exists('TelemetryFakeGlobalDataClient')) {
        class TelemetryFakeGlobalDataClient extends \GlobalData\Client
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

            /** Init-if-absent (real GlobalData add() semantics). */
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

    class EventsV1TelemetryTest extends TestCase
    {
        /** @var TelemetryFakeGlobalDataClient */
        private $global;

        /** @var array<int,array{type:string,args:array}> captured dispatchTask calls */
        private $dispatched = [];

        /** @var string|null fake TaskWorker return ({"return":"<task json>"}) */
        private $fakeTaskReturn = null;

        /** @var bool when true the fake dispatcher fires $onError instead */
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

        private function flagAOn(): TelemetryFakeGlobalDataClient
        {
            $client = new TelemetryFakeGlobalDataClient();
            $client->store[FeatureFlags::VAR_NEW_HANDLING] = 1;
            $GLOBALS['global'] = $client;
            $this->global = $client;
            return $client;
        }

        private function flagAOff(): TelemetryFakeGlobalDataClient
        {
            $client = new TelemetryFakeGlobalDataClient();
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

        private function taskOk(string $rawResult): string
        {
            return json_encode(['return' => json_encode(['ok' => true, 'result' => $rawResult])]);
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

        private function asBot(string $uid = 'bot-1'): void
        {
            $_SESSION = ['v1_authed' => true, 'ima' => 'bot', 'module' => 'bot', 'uid' => $uid];
        }

        private function dispatch(string $op, array $data, int $client = 1, string $id = 'req-1', array $extra = []): void
        {
            \Events::dispatchV1($client, array_merge([
                'v' => 1, 'id' => $id, 'op' => $op, 'ts' => 1719700000, 'data' => $data
            ], $extra));
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

        // ================================================================
        // 1. telemetry.host — happy path (plain-obj -> vps_update_info)
        // ================================================================

        public function testTelemetryHostDispatchesVpsUpdateInfoWithNestedContent(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();

            $metrics = ['load' => 0.5, 'cores' => 8, 'ram' => 32000, 'cpu_model' => 'EPYC'];
            $this->dispatch('telemetry.host', $metrics);

            $this->assertCount(1, $this->dispatched, 'exactly one vps_update_info dispatch');
            $call = $this->dispatched[0];
            $this->assertSame('vps_update_info', $call['type']);
            // host_id from the session uid, NEVER the payload.
            $this->assertSame(1234, $call['args']['id']);
            // §2.5 flat obj wrapped into the nested legacy content shape {server: data}.
            $this->assertSame(['server' => $metrics], $call['args']['content']);
            // Fire-and-forget: no reply on the wire on success.
            $this->assertCount(0, $this->sent(), 'telemetry.host is fire-and-forget (no success reply)');
        }

        public function testTelemetryHostEmptyDataBadRequestNoDispatch(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('telemetry.host', []);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testTelemetryHostQuickserversForbiddenVpsOnly(): void
        {
            // vps-only Task (legacy-WS parity): a qs-module host is rejected.
            $this->flagAOn();
            $this->asQsHost(77);
            $this->installTaskCapture();

            $this->dispatch('telemetry.host', ['load' => 1]);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->dispatched);
        }

        public function testTelemetryHostNonHostRoleForbidden(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            $this->installTaskCapture();

            $this->dispatch('telemetry.host', ['load' => 1]);

            $reply = $this->assertErrorReply('forbidden');
            $this->assertStringContainsString('require role host', $reply['error']['message']);
            $this->assertCount(0, $this->dispatched);
        }

        // Identity: a hostile payload trying to spoof another host_id has no effect.
        public function testTelemetryHostIgnoresSpoofedIdentityFields(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();

            $this->dispatch('telemetry.host', [
                'load' => 2,
                // Hostile fields attempting to redirect the metric to another host.
                'id' => 999999,
                'host_id' => 999999,
                'uid' => 'vps999999',
                'module' => 'quickservers'
            ]);

            $call = $this->dispatched[0];
            $this->assertSame(1234, $call['args']['id'], 'id derived from session, not payload');
            // The whole data object (including the spoof fields) is wrapped verbatim
            // as content.server — but the DISPATCH TARGET id is session-derived, so
            // the spoof cannot redirect the write to another host.
            $this->assertSame(999999, $call['args']['content']['server']['id']);
            $this->assertArrayNotHasKey('module', $call['args'], 'no top-level module override from payload');
        }

        // ================================================================
        // 2. telemetry.host_extra — queue_action bridge (base64 json encoding)
        // ================================================================

        public function testTelemetryHostExtraDispatchesQueueActionWithEncodedServers(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('');

            $this->dispatch('telemetry.host_extra', ['cpu_flags' => 'sse sse2 avx', 'speed' => 1000]);

            $call = $this->dispatched[0];
            $this->assertSame('queue_action', $call['type']);
            $this->assertSame('server_info_extra', $call['args']['action']);
            $this->assertSame('vps', $call['args']['module']);
            $this->assertSame(1234, $call['args']['host_id']);
            // §2.4 AMENDMENT 1: hub applies base64(json) so myadmin_unstringify decodes it.
            $encoded = $call['args']['args']['servers'];
            $this->assertSame(['cpu_flags' => 'sse sse2 avx', 'speed' => 1000], json_decode(base64_decode($encoded), true));
            // Fire-and-forget: no success reply.
            $this->assertCount(0, $this->sent());
        }

        public function testTelemetryHostExtraAllowsQuickservers(): void
        {
            // host_extra goes through queue_action which resolves qs_masters natively.
            $this->flagAOn();
            $this->asQsHost(77);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('');

            $this->dispatch('telemetry.host_extra', ['cpu_flags' => 'sse', 'speed' => 100]);

            $call = $this->dispatched[0];
            $this->assertSame('quickservers', $call['args']['module']);
            $this->assertSame(77, $call['args']['host_id']);
        }

        public function testTelemetryHostExtraMissingCpuFlagsBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('telemetry.host_extra', ['speed' => 100]);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testTelemetryHostExtraNonNumericSpeedBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('telemetry.host_extra', ['cpu_flags' => 'sse', 'speed' => 'fast']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        // ================================================================
        // 3. telemetry.cpu — HOST-AT-INDEX-0 array_shift-compatible ordering
        // ================================================================

        public function testTelemetryCpuAssemblesHostAtIndexZeroThenPerVps(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('');

            $host = ['cpu' => 12.5, 'label' => 'HOST'];
            $perVps = ['101' => ['cpu' => 3.5], '202' => ['cpu' => 4.5], '303' => ['cpu' => 5.5]];
            $this->dispatch('telemetry.cpu', ['host' => $host, 'per_vps' => $perVps]);

            $call = $this->dispatched[0];
            $this->assertSame('queue_action', $call['type']);
            $this->assertSame('cpu_usage', $call['args']['action']);

            // CpuUsage.php array_shift()s the FIRST element as the host entry, then
            // treats remaining keys as veids. Assert the reassembled legacy shape.
            $assembled = json_decode($call['args']['args']['cpu_usage'], true);
            $this->assertIsArray($assembled);
            // Host recoverable at key/index 0 (array_shift takes the first element).
            // NOTE: json_decode restores numeric-string keys as int keys.
            $this->assertArrayHasKey(0, $assembled);
            $this->assertSame($host, $assembled[0], 'host object sits at index 0 (array_shift target)');
            // The per_vps veid keys are preserved (array union, not array_merge renumbering).
            $this->assertSame(['cpu' => 3.5], $assembled[101]);
            $this->assertSame(['cpu' => 4.5], $assembled[202]);
            $this->assertSame(['cpu' => 5.5], $assembled[303]);
            // Ordering: host FIRST, then per_vps in original insertion order.
            $keys = array_keys($assembled);
            $this->assertSame([0, 101, 202, 303], $keys, 'host at index 0 first, per_vps order preserved');
            $this->assertCount(0, $this->sent(), 'telemetry.cpu is fire-and-forget');
        }

        public function testTelemetryCpuEmptyPerVpsStillHostAtZero(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();
            $this->fakeTaskReturn = $this->taskOk('');

            $host = ['cpu' => 1.5];
            $this->dispatch('telemetry.cpu', ['host' => $host, 'per_vps' => []]);

            $assembled = json_decode($this->dispatched[0]['args']['args']['cpu_usage'], true);
            $this->assertSame([$host], array_values($assembled));
            $this->assertSame([0], array_keys($assembled));
        }

        public function testTelemetryCpuMissingHostCpuBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('telemetry.cpu', ['host' => ['label' => 'x'], 'per_vps' => []]);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testTelemetryCpuNonArrayPerVpsBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('telemetry.cpu', ['host' => ['cpu' => 1], 'per_vps' => 'nope']);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        // ================================================================
        // 4. telemetry.bandwidth — plain-obj -> Tasks/bandwidth.php
        // ================================================================

        public function testTelemetryBandwidthDispatchesBandwidthTask(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();

            $perIp = ['1.2.3.4' => ['vps' => '101', 'in' => 100, 'out' => 200]];
            $this->dispatch('telemetry.bandwidth', ['per_ip' => $perIp]);

            $call = $this->dispatched[0];
            $this->assertSame('bandwidth', $call['type']);
            // uid is the full session uid string for byte-parity with msgBandwidth.
            $this->assertSame('vps1234', $call['args']['uid']);
            $this->assertSame($perIp, $call['args']['content']);
            $this->assertCount(0, $this->sent());
        }

        public function testTelemetryBandwidthQuickserversForbiddenVpsOnly(): void
        {
            $this->flagAOn();
            $this->asQsHost(77);
            $this->installTaskCapture();

            $this->dispatch('telemetry.bandwidth', ['per_ip' => []]);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->dispatched);
        }

        public function testTelemetryBandwidthMissingPerIpBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('telemetry.bandwidth', []);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        // ================================================================
        // 5. telemetry.inventory — host demoted to servers[0]
        // ================================================================

        public function testTelemetryInventoryDemotesHostToServersZero(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1234);
            $this->installTaskCapture();

            $host = ['bw_usage' => 5, 'os_info' => 'linux'];
            $servers = ['101' => ['veid' => 101], '202' => ['veid' => 202]];
            $ips = ['101' => ['1.1.1.1'], '202' => ['2.2.2.2']];
            $this->dispatch('telemetry.inventory', ['servers' => $servers, 'ips' => $ips, 'host' => $host]);

            $call = $this->dispatched[0];
            $this->assertSame('vps_get_list', $call['type']);
            $this->assertSame(1234, $call['args']['id']);
            // host DEMOTED back into legacy servers[0] slot; veid keys/order preserved.
            $assembledServers = $call['args']['content']['servers'];
            $this->assertSame($host, $assembledServers[0], 'host at servers[0] (ServerList special-cases index 0)');
            $this->assertSame(['veid' => 101], $assembledServers[101]);
            $this->assertSame(['veid' => 202], $assembledServers[202]);
            $this->assertSame([0, 101, 202], array_keys($assembledServers));
            $this->assertSame($ips, $call['args']['content']['ips']);
            $this->assertCount(0, $this->sent());
        }

        public function testTelemetryInventoryMissingServersBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('telemetry.inventory', ['ips' => [], 'host' => []]);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testTelemetryInventoryMissingHostBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('telemetry.inventory', ['servers' => [], 'ips' => []]);

            $this->assertErrorReply('bad_request');
            $this->assertCount(0, $this->dispatched);
        }

        public function testTelemetryInventoryEmptyHostAllowed(): void
        {
            // host may be empty ({}); an empty array passes is_array().
            $this->flagAOn();
            $this->asVpsHost(1);
            $this->installTaskCapture();

            $this->dispatch('telemetry.inventory', ['servers' => [], 'ips' => [], 'host' => []]);

            $call = $this->dispatched[0];
            $this->assertSame('vps_get_list', $call['type']);
            $this->assertSame([[]], $call['args']['content']['servers'], 'empty host still occupies servers[0]');
            $this->assertCount(0, $this->sent());
        }

        public function testTelemetryInventoryQuickserversForbiddenVpsOnly(): void
        {
            $this->flagAOn();
            $this->asQsHost(77);
            $this->installTaskCapture();

            $this->dispatch('telemetry.inventory', ['servers' => [], 'ips' => [], 'host' => []]);

            $this->assertErrorReply('forbidden');
            $this->assertCount(0, $this->dispatched);
        }

        // ================================================================
        // 6. telemetry.sysinfo — BOTH legs (relay request + correlated reply)
        // ================================================================

        public function testSysinfoRequestLegRelaysToHostAndRecordsRegistry(): void
        {
            $client = $this->flagAOn();
            $this->asAdmin('admin-42');
            \GatewayWorker\Lib\Gateway::$onlineUids['vps5'] = true;

            $this->dispatch('telemetry.sysinfo', ['host' => 'vps5', 'params' => ['x' => 1]], 7, 'admin-req-1');

            // Hub relays a FRESH request envelope to the host uid (not a reply).
            $toUid = $this->sentToUid();
            $this->assertCount(1, $toUid, 'exactly one relay to the host');
            $this->assertSame('vps5', $toUid[0]['uid']);
            $relay = json_decode($toUid[0]['message'], true);
            $this->assertSame('telemetry.sysinfo', $relay['op']);
            $this->assertSame(1, $relay['v']);
            $this->assertArrayHasKey('id', $relay, 'relay carries a fresh request id');
            $this->assertArrayNotHasKey('re', $relay, 'relay is a request, not a reply');
            // host coerced to int, params relayed verbatim.
            $this->assertSame(5, $relay['data']['host']);
            $this->assertSame(['x' => 1], $relay['data']['params']);
            // No immediate reply to the admin (ok reply arrives when host answers).
            $this->assertCount(0, $this->sent());

            // Registry entry recorded under the relay id => {for, re, host}.
            $sysinfos = $client->store['sysinfos'];
            $this->assertIsArray($sysinfos);
            $this->assertArrayHasKey($relay['id'], $sysinfos);
            $entry = $sysinfos[$relay['id']];
            $this->assertSame('admin-42', $entry['for'], 'registry records requesting admin uid');
            $this->assertSame('admin-req-1', $entry['re'], 'registry records admin original envelope id');
            $this->assertSame('vps5', $entry['host']);
        }

        public function testSysinfoReplyLegRoutesBackToOriginatingAdmin(): void
        {
            $client = $this->flagAOn();
            // Pre-seed the registry as if the request leg already ran.
            $client->store['sysinfos'] = [
                'relay-id-1' => ['for' => 'admin-42', 're' => 'admin-req-1', 'host' => 'vps5', 'ts' => 100]
            ];
            // The reply arrives from the host (role host, uid vps5), re=relay id.
            $this->asVpsHost(5);

            $this->dispatch('telemetry.sysinfo', ['some' => 'payload'], 9, 'host-fresh-id', ['re' => 'relay-id-1']);

            // Forwarded a v1 REPLY to the recorded admin uid, keyed by the admin's
            // ORIGINAL envelope id (admin-req-1), not the relay id.
            $toUid = $this->sentToUid();
            $this->assertCount(1, $toUid);
            $this->assertSame('admin-42', $toUid[0]['uid']);
            $reply = json_decode($toUid[0]['message'], true);
            $this->assertTrue($reply['ok']);
            $this->assertSame('admin-req-1', $reply['re'], 'reply re = admin original id (correlation)');
            $this->assertSame('payload', $reply['data']['some']);
            // host overwritten from the authed host session (never trusted from payload).
            $this->assertSame(5, $reply['data']['host']);
            // Registry entry consumed (removed).
            $this->assertArrayNotHasKey('relay-id-1', $client->store['sysinfos']);
        }

        public function testSysinfoReplyLegOverwritesPayloadHostFromSession(): void
        {
            $client = $this->flagAOn();
            $client->store['sysinfos'] = [
                'relay-id-1' => ['for' => 'admin-1', 're' => 'orig', 'host' => 'vps5', 'ts' => 100]
            ];
            $this->asVpsHost(5);

            // Host tries to spoof a different host id in the payload.
            $this->dispatch('telemetry.sysinfo', ['host' => 'vps999', 'data' => 'x'], 9, 'fresh', ['re' => 'relay-id-1']);

            $reply = json_decode($this->sentToUid()[0]['message'], true);
            $this->assertSame(5, $reply['data']['host'], 'host forced from session, spoofed payload host ignored');
        }

        public function testSysinfoReplyLegUnknownRelayIdSilentlyDropped(): void
        {
            $client = $this->flagAOn();
            $client->store['sysinfos'] = [];
            $this->asVpsHost(5);

            $this->dispatch('telemetry.sysinfo', ['data' => 'x'], 9, 'fresh', ['re' => 'never-seen']);

            // Response racing a restart/expiry — dropped silently, no error, no forward.
            $this->assertCount(0, $this->sent());
            $this->assertCount(0, $this->sentToUid());
        }

        public function testSysinfoReplyLegWrongHostForbidden(): void
        {
            $client = $this->flagAOn();
            $client->store['sysinfos'] = [
                'relay-id-1' => ['for' => 'admin-1', 're' => 'orig', 'host' => 'vps5', 'ts' => 100]
            ];
            // A DIFFERENT host (vps99) tries to answer a relay addressed to vps5.
            $this->asVpsHost(99);

            $this->dispatch('telemetry.sysinfo', ['data' => 'x'], 9, 'fresh', ['re' => 'relay-id-1']);

            $this->assertErrorReply('forbidden');
            // Registry entry NOT consumed (wrong host cannot dequeue it).
            $this->assertArrayHasKey('relay-id-1', $client->store['sysinfos']);
        }

        public function testSysinfoRequestLegHostNotOnlineNotOnline(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            // vps5 not registered online.

            $this->dispatch('telemetry.sysinfo', ['host' => 'vps5', 'params' => []]);

            $reply = $this->assertErrorReply('not_online');
            $this->assertCount(0, $this->sentToUid());
        }

        public function testSysinfoRequestLegBadHostBadRequest(): void
        {
            $this->flagAOn();
            $this->asAdmin();

            $this->dispatch('telemetry.sysinfo', ['host' => 'not-a-host', 'params' => []]);

            $this->assertErrorReply('bad_request');
        }

        public function testSysinfoRequestLegMissingParamsBadRequest(): void
        {
            $this->flagAOn();
            $this->asAdmin();
            \GatewayWorker\Lib\Gateway::$onlineUids['vps5'] = true;

            $this->dispatch('telemetry.sysinfo', ['host' => 'vps5']);

            $this->assertErrorReply('bad_request');
        }

        public function testSysinfoReplyLegMissingReBadRequest(): void
        {
            $this->flagAOn();
            $this->asVpsHost(5);

            // Host role but no envelope re => bad_request.
            $this->dispatch('telemetry.sysinfo', ['data' => 'x']);

            $this->assertErrorReply('bad_request');
        }

        public function testSysinfoBotRoleForbidden(): void
        {
            $this->flagAOn();
            $this->asBot();

            $this->dispatch('telemetry.sysinfo', ['host' => 'vps5', 'params' => []]);

            $this->assertErrorReply('forbidden');
        }

        // ================================================================
        // 7. Dormancy — Flag A OFF: NONE of the telemetry ops act
        // ================================================================

        public function testAllTelemetryOpsDormantWhenFlagAOff(): void
        {
            $this->flagAOff();
            $this->asVpsHost(1234);
            $this->installTaskCapture();

            $ops = [
                ['telemetry.host', ['load' => 1]],
                ['telemetry.host_extra', ['cpu_flags' => 'sse', 'speed' => 100]],
                ['telemetry.cpu', ['host' => ['cpu' => 1], 'per_vps' => []]],
                ['telemetry.bandwidth', ['per_ip' => []]],
                ['telemetry.inventory', ['servers' => [], 'ips' => [], 'host' => []]],
                ['telemetry.sysinfo', ['host' => 'vps5', 'params' => []]]
            ];
            foreach ($ops as [$op, $data]) {
                $this->dispatch($op, $data);
            }

            $this->assertCount(0, $this->sent(), 'Flag A OFF: no telemetry replies');
            $this->assertCount(0, $this->sentToUid(), 'Flag A OFF: no telemetry relays');
            $this->assertCount(0, $this->dispatched, 'Flag A OFF: no telemetry task dispatch');
            $this->assertCount(0, $this->closed());
            $this->assertArrayNotHasKey('sysinfos', $this->global->store, 'Flag A OFF: sysinfo registry untouched');
        }

        // ================================================================
        // 8. Unauthed telemetry op => auth_required + close (before flag decode)
        // ================================================================

        public function testTelemetryUnauthedRepliesAuthRequiredAndCloses(): void
        {
            $this->flagAOn();
            $_SESSION = []; // not v1-authed
            $this->installTaskCapture();

            $this->dispatch('telemetry.host', ['load' => 1], 42);

            $reply = $this->singleReply();
            $this->assertSame('auth_required', $reply['error']['code']);
            $this->assertContains(42, $this->closed());
            $this->assertCount(0, $this->dispatched);
        }
    }

}
