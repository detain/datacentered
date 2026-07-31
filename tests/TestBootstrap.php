<?php

/**
 * Process-wide test bootstrap — MUST be the first thing every test file loads.
 *
 * phpunit.xml.dist points `bootstrap` at vendor/autoload.php and lives at the
 * repo root (outside tests/), so this file is wired in the only way available
 * from inside tests/: tests/V1TestSupport.php requires it, and every test file
 * requires one of the two. Because PHPUnit loads all test files into a single
 * process, whichever file is enumerated first installs these seams for the
 * whole run.
 *
 * WHAT IT INSTALLS AND WHY
 *
 * 1. An OFFLINE \GlobalData\Client (declared here so the composer autoloader
 *    never pulls vendor/workerman/globaldata/src/Client.php).
 *
 *    The real client lazily opens a TCP socket to GLOBALDATA_IP:2207 on the
 *    FIRST property access — not in the constructor. FeatureFlags::globalData()
 *    falls back to `new \GlobalData\Client(GLOBALDATA_IP.':2207')` whenever no
 *    $global has been injected, and it resolves GLOBALDATA_IP by requiring
 *    /home/my/include/config/config.settings.php. So on baseline, every
 *    FeatureFlags read from a test that did not inject $global reached out to
 *    the LIVE GlobalData server at 216.158.226.14:2207 (one leaked socket per
 *    call) and dragged the production settings file — and every constant in it
 *    — into the test process as a side effect.
 *
 *    This stub cannot connect: every accessor throws
 *    \GlobalData\ClientOfflineException, which is exactly the state
 *    FeatureFlags' fail-safe branches are specified for (`catch (\Throwable)`
 *    => documented default). We also predefine GLOBALDATA_IP below so
 *    FeatureFlags never requires config.settings.php at all.
 *
 *    Test doubles keep extending \GlobalData\Client (FeatureFlags gates on
 *    `instanceof`), overriding the accessors — unchanged and unaffected.
 *
 * 2. A DEAD-TRANSPORT TRIPWIRE for \Channel\Client (BUG-A3 regression guard).
 *
 *    Presence broadcasts used to go out via \Channel\Client::publish(), which
 *    silently vanished: nothing subscribed to 'dc_presence', publish()
 *    auto-connects to 127.0.0.1:2206 while start_channel.php binds
 *    0.0.0.0:3333, and the `channel` service only runs on myadmin1. It has
 *    been removed from all six call sites in favour of
 *    Gateway::sendToGroup(). The stub below RECORDS every attempted publish
 *    AND throws, so a reintroduction is caught even if the caller wraps it in
 *    the try/catch the old code had (which is precisely why the dead transport
 *    survived so long). Tests assert
 *    \Channel\Client::$publishAttempts === [].
 *
 * 3. A recording Workerman timer loop (TestTimer / TestEventLoop).
 *
 *    \Workerman\Timer::add() throws 'Timer can only be used in workerman
 *    running environment' outside a worker, which turned 22 of the 26
 *    EventsBotPresenceTest cases into errors. Timer already has a first-class
 *    seam — the protected static ?EventInterface $event, which add()/del()
 *    prefer over the pcntl path — so TestTimer::install() injects a recording
 *    EventInterface. Timers become inspectable and runnable instead of fatal.
 *
 * 4. InMemoryGlobalData — ONE faithful in-memory GlobalData double.
 *
 *    Four divergent hand-rolled copies existed, and their differences from the
 *    real client were not cosmetic: they returned arrays by reference from
 *    &__get (the real client returns by value — `$global->arr[$k] = $v` does
 *    NOT reach a real server) and their cas() compared with ===, treating an
 *    ABSENT key as an empty array. The real GlobalData server compares
 *    md5(serialize()) and treats an absent key as NULL
 *    (vendor/workerman/globaldata/src/Server.php, case 'cas'), so
 *    cas($absentKey, [], $new) returns FALSE forever. Modelling that faithfully
 *    is what makes the do/while CAS loops in Events.php testable at all — and
 *    it is why this double has a livelock guard (see the class docblock).
 *
 * @see tests/V1TestSupport.php    the fake \GatewayWorker\Lib\Gateway transport
 */

// ---------------------------------------------------------------------------
// 1. Offline \GlobalData\Client — declared before the autoloader can reach the
//    real one, so no test can ever open a socket to a GlobalData server.
// ---------------------------------------------------------------------------
namespace GlobalData {
    /**
     * Thrown by every accessor of the offline test stand-in for
     * \GlobalData\Client. FeatureFlags catches \Throwable around each read and
     * write, so this reproduces "GlobalData is unreachable" exactly.
     */
    class ClientOfflineException extends \RuntimeException
    {
    }

    /**
     * Offline stand-in for the real GlobalData client.
     *
     * Same public surface as vendor/workerman/globaldata/src/Client.php
     * (__get/__set/__isset/__unset/cas/add/increment) but with no socket code
     * at all. Constructing it is harmless and recorded; USING it throws.
     */
    class Client
    {
        /** @var int matches the real client's default */
        public $timeout = 5;

        /** @var int matches the real client's default */
        public $pingInterval = 25;

        /**
         * Server addresses passed to every constructed instance that did NOT
         * override the constructor — i.e. every attempt by production code to
         * build a REAL client. Tests assert this stays empty.
         *
         * @var array<int,mixed>
         */
        public static $constructed = [];

        /** @var array<int,mixed> */
        protected $_globalServers = [];

        public function __construct($servers)
        {
            if (empty($servers)) {
                throw new \Exception('servers empty');
            }
            $this->_globalServers = array_values((array) $servers);
            self::$constructed[] = $servers;
        }

        /** Forget recorded constructions (call between tests). */
        public static function resetConstructed()
        {
            self::$constructed = [];
        }

        private function offline($op, $key)
        {
            return new ClientOfflineException(
                "GlobalData is offline in tests: refused {$op}('{$key}'). Inject an "
                .'InMemoryGlobalData into $GLOBALS[\'global\'] (or FeatureFlags\' private '
                .'static $client) instead of relying on a live GlobalData server.'
            );
        }

        public function __get($key)
        {
            throw $this->offline('get', $key);
        }

        public function __set($key, $value)
        {
            throw $this->offline('set', $key);
        }

        public function __isset($key)
        {
            throw $this->offline('isset', $key);
        }

        public function __unset($key)
        {
            throw $this->offline('unset', $key);
        }

        public function cas($key, $old_value, $new_value)
        {
            throw $this->offline('cas', $key);
        }

        public function add($key, $value)
        {
            throw $this->offline('add', $key);
        }

        public function increment($key, $step = 1)
        {
            throw $this->offline('increment', $key);
        }
    }
}

// ---------------------------------------------------------------------------
// 2. \Channel\Client tripwire (BUG-A3). Records THEN throws.
// ---------------------------------------------------------------------------
namespace Channel {
    /** Thrown when anything tries to use the removed dead presence transport. */
    class DeadTransportException extends \RuntimeException
    {
    }

    /**
     * Tripwire stand-in for the Workerman channel client.
     *
     * \Channel\Client::publish() was the dead presence transport (BUG-A3):
     * nothing subscribed, wrong port, service absent on this host — and the
     * call site wrapped it in try/catch, so it failed silently forever while
     * the tests "passed". Reintroducing it must break the suite, so every
     * entry point here records the attempt (survives a swallowing try/catch)
     * and then throws (fails loudly when it is not swallowed).
     */
    class Client
    {
        /** @var array<int,array{method:string,args:array}> */
        public static $publishAttempts = [];

        public static function reset()
        {
            self::$publishAttempts = [];
        }

        private static function trip($method, array $args)
        {
            self::$publishAttempts[] = ['method' => $method, 'args' => $args];
            throw new DeadTransportException(
                "\\Channel\\Client::{$method}() is the DEAD presence transport removed in BUG-A3 "
                .'(no subscriber, port 2206 vs 3333, service only on myadmin1). Broadcast via '
                .'GatewayWorker\\Lib\\Gateway::sendToGroup()/sendToClient() instead.'
            );
        }

        public static function connect($ip = '127.0.0.1', $port = 2206)
        {
            self::trip('connect', [$ip, $port]);
        }

        public static function on($event, $callback)
        {
            self::trip('on', [$event]);
        }

        public static function subscribe($events)
        {
            self::trip('subscribe', [$events]);
        }

        public static function unsubscribe($events)
        {
            self::trip('unsubscribe', [$events]);
        }

        public static function publish($events, $data, $is_loop = false)
        {
            self::trip('publish', [$events, $data, $is_loop]);
        }

        public static function watch($channels, $callback, $autoReserve = true)
        {
            self::trip('watch', [$channels]);
        }

        public static function unwatch($channels)
        {
            self::trip('unwatch', [$channels]);
        }

        public static function enqueue($channels, $data)
        {
            self::trip('enqueue', [$channels, $data]);
        }

        public static function reserve()
        {
            self::trip('reserve', []);
        }
    }
}

// ---------------------------------------------------------------------------
// 3./4. Global-namespace helpers.
// ---------------------------------------------------------------------------
namespace {
    /**
     * Keep FeatureFlags::globalData() from requiring the production settings
     * file (/home/my/include/config/config.settings.php) just to learn
     * GLOBALDATA_IP. The offline \GlobalData\Client above already makes a
     * connect impossible; this stops the require's side effects (dozens of
     * production constants leaking into the test process — one of them,
     * WS_TRIGGER_TOKEN's neighbours, is why TriggerPaymentEndpointTest's
     * setUpBeforeClass() guard mattered).
     */
    if (!defined('GLOBALDATA_IP')) {
        define('GLOBALDATA_IP', '127.0.0.1');
    }

    /**
     * Events' auth/ALERT paths call Worker::safeEcho(), which writes to
     * Worker::$outputStream. Outside a running Workerman process that stream is
     * null and feof(null) throws a TypeError (leaving a dangling error
     * handler), so point it at /dev/null: logging becomes a harmless no-op.
     */
    if (!is_resource(\Workerman\Worker::$outputStream ?? null)) {
        \Workerman\Worker::$outputStream = fopen('/dev/null', 'w');
    }

    /**
     * Recording \Workerman\Events\EventInterface used as the Timer backend in
     * tests. Timers are never fired by a loop here — a test runs the ones it
     * cares about explicitly via TestTimer::run()/runAll().
     */
    final class TestEventLoop implements \Workerman\Events\EventInterface
    {
        /** @var array<int,array{id:int,interval:float,func:callable,args:array,persistent:bool}> */
        public array $timers = [];

        /** @var array<int,int> timer ids passed to offDelay()/offRepeat() */
        public array $deleted = [];

        private int $nextId = 1;

        private function record(float $interval, callable $func, array $args, bool $persistent): int
        {
            $id = $this->nextId++;
            $this->timers[$id] = [
                'id' => $id,
                'interval' => $interval,
                'func' => $func,
                'args' => $args,
                'persistent' => $persistent,
            ];
            return $id;
        }

        public function delay(float $delay, callable $func, array $args = []): int
        {
            return $this->record($delay, $func, $args, false);
        }

        public function repeat(float $interval, callable $func, array $args = []): int
        {
            return $this->record($interval, $func, $args, true);
        }

        public function offDelay(int $timerId): bool
        {
            $this->deleted[] = $timerId;
            if (!isset($this->timers[$timerId])) {
                return false;
            }
            unset($this->timers[$timerId]);
            return true;
        }

        public function offRepeat(int $timerId): bool
        {
            return $this->offDelay($timerId);
        }

        public function onReadable($stream, callable $func): void
        {
        }

        public function offReadable($stream): bool
        {
            return true;
        }

        public function onWritable($stream, callable $func): void
        {
        }

        public function offWritable($stream): bool
        {
            return true;
        }

        public function onSignal(int $signal, callable $func): void
        {
        }

        public function offSignal(int $signal): bool
        {
            return true;
        }

        public function deleteAllTimer(): void
        {
            $this->timers = [];
        }

        public function run(): void
        {
        }

        public function stop(): void
        {
        }

        public function getTimerCount(): int
        {
            return count($this->timers);
        }

        public function setErrorHandler(callable $errorHandler): void
        {
        }
    }

    /**
     * Static facade that installs TestEventLoop as \Workerman\Timer's backend.
     *
     * Timer::add() and Timer::del() both check `self::$event` FIRST and only
     * fall through to the pcntl/Worker path (which throws outside a worker)
     * when it is null — so injecting an EventInterface is the intended seam,
     * not a hack around a guard.
     */
    final class TestTimer
    {
        private static ?TestEventLoop $loop = null;

        private static function eventProperty(): \ReflectionProperty
        {
            $prop = new \ReflectionProperty(\Workerman\Timer::class, 'event');
            $prop->setAccessible(true);
            return $prop;
        }

        /** Install (or reuse) the recording loop and clear its records. */
        public static function install(): TestEventLoop
        {
            if (self::$loop === null) {
                self::$loop = new TestEventLoop();
            }
            self::$loop->timers = [];
            self::$loop->deleted = [];
            self::eventProperty()->setValue(null, self::$loop);
            return self::$loop;
        }

        /** Restore Workerman's default (throwing) Timer behaviour. */
        public static function uninstall(): void
        {
            self::eventProperty()->setValue(null, null);
            self::$loop = null;
        }

        public static function loop(): TestEventLoop
        {
            return self::$loop ?? self::install();
        }

        /** Alias for install(): install-if-needed + clear records. */
        public static function reset(): TestEventLoop
        {
            return self::install();
        }

        /**
         * Every live recorded timer, in creation order.
         *
         * @return array<int,array{id:int,interval:float,func:callable,args:array,persistent:bool}>
         */
        public static function added(): array
        {
            return array_values(self::loop()->timers);
        }

        /** @return array<int,int> ids passed to Timer::del() */
        public static function deleted(): array
        {
            return self::loop()->deleted;
        }

        /** @return array<int,int> ids of live recorded timers */
        public static function ids(): array
        {
            return array_keys(self::loop()->timers);
        }

        /** Live timers whose interval equals $interval. */
        public static function withInterval(float $interval): array
        {
            return array_values(array_filter(
                self::loop()->timers,
                static fn(array $t) => abs($t['interval'] - $interval) < 1e-9
            ));
        }

        /** Invoke one recorded timer's callback with its recorded args. */
        public static function run(int $id): void
        {
            $timer = self::loop()->timers[$id] ?? null;
            if ($timer === null) {
                throw new \RuntimeException("no recorded timer with id {$id}");
            }
            if (!$timer['persistent']) {
                // One-shot: Workerman drops it after firing.
                unset(self::loop()->timers[$id]);
            }
            ($timer['func'])(...$timer['args']);
        }

        /** Invoke every currently-recorded timer once (snapshot taken first). */
        public static function runAll(): void
        {
            foreach (array_keys(self::loop()->timers) as $id) {
                if (isset(self::loop()->timers[$id])) {
                    self::run($id);
                }
            }
        }
    }

    /**
     * Thrown by InMemoryGlobalData::cas() once a single key has failed the
     * compare-and-swap $casFailLimit times in a row.
     *
     * Several Events.php CAS loops are `do { ... } while (!$global->cas(...))`
     * with no attempt ceiling, so a cas() that can never succeed is an
     * unkillable busy loop. Under a fake whose cas() always returned true that
     * was invisible; under a FAITHFUL fake it hangs the test runner (this is
     * exactly why the suite used to block forever). Converting the livelock
     * into a loud exception keeps the bug visible instead of hanging CI.
     */
    class GlobalDataCasLivelockException extends \RuntimeException
    {
    }

    /**
     * The single in-memory \GlobalData\Client double for the whole suite.
     *
     * Semantics are copied from the REAL client + server, not invented:
     *   - __get      absent key => null                (Server.php case 'get' sends 'N;')
     *   - __get      returns BY VALUE                  (real client returns by value)
     *   - __isset    null !== __get($key)              (real client)
     *   - __unset    removes the key                   (Server.php case 'delete')
     *   - cas        md5(serialize(current)) === md5(serialize($old)),
     *                where an ABSENT key's current value is NULL
     *                                                  (Server.php case 'cas')
     *   - add        fails when isset($store[$key])    (Server.php case 'add')
     *   - increment  fails when the key is absent      (Server.php case 'increment')
     *
     * The cas() rule matters: cas($absentKey, [], $new) is FALSE against a real
     * server, so `do { ... } while (!$global->cas($k, [], $new))` never
     * terminates until something has seeded $k. Fakes that compared with ===
     * and treated absent as [] hid that entirely.
     */
    class InMemoryGlobalData extends \GlobalData\Client
    {
        /** @var array<string,mixed> the whole GlobalData keyspace */
        public $store = [];

        /** @var int consecutive cas() failures on one key before we throw */
        public $casFailLimit = 100;

        /** @var int total cas() calls (handy for "did this even try?" assertions) */
        public $casCalls = 0;

        /** @var array<string,int> */
        private $casFailures = [];

        /** @param array<string,mixed> $seed initial keyspace */
        public function __construct(array $seed = [])
        {
            $this->store = $seed;
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
            return null !== ($this->store[$key] ?? null);
        }

        public function __unset($key)
        {
            unset($this->store[$key]);
        }

        public function cas($key, $old_value, $new_value)
        {
            $this->casCalls++;
            $current = array_key_exists($key, $this->store) ? $this->store[$key] : null;
            if (md5(serialize($current)) === md5(serialize($old_value))) {
                $this->store[$key] = $new_value;
                unset($this->casFailures[$key]);
                return true;
            }
            $failures = ($this->casFailures[$key] ?? 0) + 1;
            $this->casFailures[$key] = $failures;
            if ($failures >= $this->casFailLimit) {
                throw new GlobalDataCasLivelockException(sprintf(
                    'CAS livelock: cas(%s) failed %d times in a row. The real GlobalData server '
                    .'compares md5(serialize()) and reports an ABSENT key as NULL, so '
                    .'cas(<absent key>, [], ...) can never succeed — a `do { } while (!cas())` '
                    .'loop over an unseeded key spins forever. Seed the key first '
                    .'(e.g. $global->add(%s, [])). current=%s expected=%s',
                    var_export($key, true),
                    $failures,
                    var_export($key, true),
                    gettype($current),
                    gettype($old_value)
                ));
            }
            return false;
        }

        public function add($key, $value)
        {
            if (isset($this->store[$key])) {
                return false;
            }
            $this->store[$key] = $value;
            return true;
        }

        public function increment($key, $step = 1)
        {
            if (!isset($this->store[$key])) {
                return false;
            }
            if (!is_numeric($this->store[$key])) {
                $this->store[$key] = 0;
            }
            $this->store[$key] += $step;
            return $this->store[$key];
        }

        /**
         * Bulk-seed keys (fluent).
         *
         * @param array<string,mixed> $kv
         * @return $this
         */
        public function seed(array $kv)
        {
            foreach ($kv as $k => $v) {
                $this->store[$k] = $v;
            }
            return $this;
        }

        /** Read a key without going through __get (bypasses nothing, just explicit). */
        public function raw($key)
        {
            return $this->store[$key] ?? null;
        }

        /** True when the key EXISTS, even if its value is null (cas() cares). */
        public function keyExists($key)
        {
            return array_key_exists($key, $this->store);
        }

        /** @return string[] */
        public function keys()
        {
            return array_keys($this->store);
        }
    }

    /**
     * Build a REALISTIC gateway client_id: a 20-character hex STRING, exactly
     * as GatewayWorker\Lib\Context::addressToClientId() produces it
     * (bin2hex(pack('NnN', local_ip, local_port, connection_id))).
     *
     * client_id is NEVER an int. The A1 crash-loop (102 fatals / 155 worker
     * restarts) was precisely an int/string confusion: trackSessionClient()
     * carried an `int $client_id` type hint, and PHP 8 raises a TypeError the
     * moment a real hex id like "7f00000138090000000a" is passed to it. Test
     * fixtures using ints such as 12345 would let that class of bug back in
     * completely undetected, so DC fixtures use this helper (or a literal
     * 20-char hex string).
     *
     * @param int    $connectionId gateway connection id (the varying part)
     * @param string $localIp      gateway internal ip
     * @param int    $localPort    gateway internal port
     * @return string 20-char lowercase hex
     */
    if (!function_exists('dc_client_id')) {
        function dc_client_id(int $connectionId, string $localIp = '127.0.0.1', int $localPort = 7272): string
        {
            return bin2hex(pack('NnN', ip2long($localIp), $localPort, $connectionId));
        }
    }

    /**
     * Shared assertions about WHICH transport carried a presence broadcast, and
     * about v1 envelope vs reply shape.
     *
     * A test that only checks "something was broadcast" is worthless here: the
     * pre-existing Events::$channelClient fake was installed in EVERY dc test's
     * setUp(), so the assertions passed while production published into the
     * void. These helpers assert the real transport
     * (Gateway::sendToGroup/sendToClient) and that the dead one was not touched.
     */
    trait DcTransportAssertions
    {
        /**
         * The dead \Channel\Client transport must never be reached. Recorded
         * (not merely thrown) so a swallowing try/catch cannot hide it.
         */
        protected function assertDeadChannelTransportUnused(string $context = ''): void
        {
            $this->assertSame(
                [],
                \Channel\Client::$publishAttempts,
                trim($context.' \\Channel\\Client is the removed dead presence transport (BUG-A3); '
                    .'presence must go out via Gateway::sendToGroup()')
            );
        }

        /**
         * The code under test must have used the INJECTED GlobalData double,
         * not FeatureFlags' lazy fallback.
         *
         * A recorded construction means something reached
         * `new \GlobalData\Client(GLOBALDATA_IP.':2207')` — harmless here only
         * because tests/TestBootstrap.php replaced that class with an offline
         * stub; in production that line opens a real socket. For a dc test it
         * also means the flag read did NOT see the fixture's flag values, so
         * whatever the test then asserted was decided by fail-safe defaults
         * rather than by the fixture.
         */
        protected function assertNoLazyGlobalDataFallback(): void
        {
            $this->assertSame(
                [],
                \GlobalData\Client::$constructed,
                'the code under test fell through to FeatureFlags\' lazy '
                .'new \\GlobalData\\Client(GLOBALDATA_IP:2207) fallback instead of using the '
                .'injected double — in production that opens a real socket, and here it means '
                .'the fixture\'s flag values were ignored'
            );
        }

        /**
         * Decoded messages sent to the dc_presence Gateway group, optionally
         * filtered to one op.
         *
         * @return array<int,array> decoded v1 envelopes
         */
        protected function presenceGroupEvents(?string $op = null): array
        {
            $out = [];
            foreach (\GatewayWorker\Lib\Gateway::$sentToGroup as $entry) {
                if ($entry['group'] !== \Events::DC_PRESENCE_GROUP) {
                    continue;
                }
                $decoded = json_decode($entry['message'], true);
                if (!is_array($decoded)) {
                    continue;
                }
                if ($op !== null && ($decoded['op'] ?? null) !== $op) {
                    continue;
                }
                $out[] = $decoded;
            }
            return $out;
        }

        /**
         * Decoded messages sent directly to one client id.
         *
         * @return array<int,array>
         */
        protected function messagesToClient(string $clientId): array
        {
            $out = [];
            foreach (\GatewayWorker\Lib\Gateway::$sent as $entry) {
                if ((string) $entry['client_id'] !== $clientId) {
                    continue;
                }
                $decoded = json_decode($entry['message'], true);
                if (is_array($decoded)) {
                    $out[] = $decoded;
                }
            }
            return $out;
        }

        /**
         * Assert an EVENT envelope (v1Envelope): v/id/op/ts/data, and — the
         * part that matters — NO `ok` and NO `re`. dc-ws.js short-circuits on
         * `ok === false && error`, so an event must never carry `ok`.
         */
        protected function assertIsV1Event(array $msg, string $op): void
        {
            $this->assertSame(1, $msg['v'] ?? null, 'event must declare v:1');
            $this->assertSame($op, $msg['op'] ?? null, 'event op mismatch');
            $this->assertArrayHasKey('id', $msg, 'event must carry a fresh envelope id');
            $this->assertArrayHasKey('ts', $msg, 'event must carry ts');
            $this->assertArrayHasKey('data', $msg, 'event must carry data');
            $this->assertArrayNotHasKey('ok', $msg, 'an EVENT must not carry ok (replies do)');
            $this->assertArrayNotHasKey('re', $msg, 'an EVENT must not carry re (replies do)');
        }

        /**
         * Assert a REPLY envelope: correlates by `re` + carries `ok`, and
         * carries NO `op` (PROTOCOL_V1 §1 — replies are identified by re/ok,
         * never by an op name; there is no "auth.welcome" op).
         */
        protected function assertIsV1Reply(array $msg, string $re, bool $ok = true): void
        {
            $this->assertSame(1, $msg['v'] ?? null, 'reply must declare v:1');
            $this->assertSame($re, $msg['re'] ?? null, 'reply must correlate by re = the request id');
            $this->assertSame($ok, $msg['ok'] ?? null, 'reply ok mismatch');
            $this->assertArrayNotHasKey(
                'op',
                $msg,
                'a REPLY must NOT carry an op — replies correlate by re + ok (there is no auth.welcome op)'
            );
        }
    }
}
