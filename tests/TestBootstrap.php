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
 * The retired \GlobalData\Client seams — the offline stub, its in-memory double
 * and that double's CAS-livelock guard — were deleted in GlobalData→Redis
 * migration wave 5.1. All cross-process state now flows through the SharedState
 * Redis facade, whose in-memory double (item 3) is the seam the whole suite runs
 * against.
 *
 * 1. A DEAD-TRANSPORT TRIPWIRE for \Channel\Client (BUG-A3 regression guard).
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
 * 2. A recording Workerman timer loop (TestTimer / TestEventLoop).
 *
 *    \Workerman\Timer::add() throws 'Timer can only be used in workerman
 *    running environment' outside a worker, which turned 22 of the 26
 *    EventsBotPresenceTest cases into errors. Timer already has a first-class
 *    seam — the protected static ?EventInterface $event, which add()/del()
 *    prefer over the pcntl path — so TestTimer::install() injects a recording
 *    EventInterface. Timers become inspectable and runnable instead of fatal.
 *
 * 3. InMemoryRedis — the in-memory duck-type of the phpredis \Redis subset the
 *    SharedState facade uses (GlobalData→Redis migration, Phase 1).
 *
 *    No test may open a socket to REDIS_HOST either. The facade resolves its
 *    client via $GLOBALS['redis'] / setClient(), so tests inject this double
 *    through SharedState::setClient() — it deliberately does NOT extend \Redis
 *    (duck-typing keeps the seam honest and works with the extension loaded or
 *    not). TTLs run against a controllable clock (fastForward()) so lock
 *    expiry/renew are deterministic.
 *
 * @see tests/V1TestSupport.php    the fake \GatewayWorker\Lib\Gateway transport
 * @see tests/SharedStateTest.php  the facade suite this double backs
 */

// ---------------------------------------------------------------------------
// 1. \Channel\Client tripwire (BUG-A3). Records THEN throws.
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
// 2. Global-namespace helpers.
// ---------------------------------------------------------------------------
namespace {
    /**
     * The Workerman start files (Applications/Chat/start_*.php) still
     * reference the GLOBALDATA_IP constant, so it must be defined for any
     * test that loads those files to parse and load them (no test currently
     * does — the define stays purely defensive). Predefining it here also
     * means nothing reaches for
     * /home/my/include/config/config.settings.php (dozens of production
     * constants leaking into the test process) just to learn the value.
     *
     * No live GlobalData server is contacted: runtime $global usage was removed
     * in the GlobalData→Redis migration, and the offline \GlobalData\Client
     * stub that once backed these tests was deleted in wave 5.1.
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
     * In-memory duck-type of the phpredis \Redis subset SharedState uses.
     *
     * Models the parts of Redis semantics the migration must not get wrong:
     *   - SET options array with nx/xx + ex/px (case-insensitive), returning
     *     exactly bool true/false like phpredis 5.x (SharedState::add/lock
     *     compare === true);
     *   - EXISTS has no NULL-vs-empty trap: a stored "" or "[]" blocks NX, and
     *     GET of a missing key returns false (never null);
     *   - WRONGTYPE on cross-type access, like the real server;
     *   - LTRIM/LRANGE negative-index + clamping rules;
     *   - TTLs against a controllable clock — fastForward($sec) advances time
     *     and evicts, so lock expiry/renew tests never sleep.
     *
     * eval() implements the two Lua scripts SharedState ships (compare-token
     * DEL and compare-token PEXPIRE) natively with identical replies, detected
     * by their distinguishing commands; an unknown script throws loudly.
     *
     * Only RPUSH/LTRIM participate in multi(PIPELINE) queueing because those
     * are the only commands SharedState pipelines.
     *
     * @see Applications/Chat/SharedState.php
     */
    class InMemoryRedis
    {
        /** @var array<string,array{type:string,value:mixed}> the whole keyspace */
        public $data = [];

        /** @var array<string,float> key => eviction deadline on $clock */
        public $expires = [];

        /** @var float controllable clock in seconds (no wall time involved) */
        public $clock = 1000000.0;

        /** @var bool flipped by close() */
        protected $connected = true;

        /** @var \Closure[] queued while in a pipeline */
        protected $pipeline = [];

        /** @var bool */
        protected $inPipeline = false;

        // -------------------------------------------------------------------
        // Test controls
        // -------------------------------------------------------------------

        /** Advance the controllable clock and expire whatever it passed. */
        public function fastForward($seconds): void
        {
            $this->clock += (float) $seconds;
            $this->evict();
        }

        /** Every key currently in the keyspace (post-eviction). */
        public function allKeys(): array
        {
            $this->evict();
            return array_keys($this->data);
        }

        /** Wipe the keyspace and clock between tests. */
        public function flushAll(): void
        {
            $this->data = [];
            $this->expires = [];
            $this->pipeline = [];
            $this->inPipeline = false;
            $this->connected = true;
        }

        public function close(): bool
        {
            $this->connected = false;
            return true;
        }

        public function isConnected(): bool
        {
            return $this->connected;
        }

        // -------------------------------------------------------------------
        // Strings
        // -------------------------------------------------------------------

        public function get($key)
        {
            $this->guard();
            $entry = $this->getEntry($key);
            if ($entry === null) {
                return false;
            }
            $this->assertType($key, 'string');

            return $this->data[$key]['value'];
        }

        public function set($key, $value, $opts = null)
        {
            $this->guard();
            $nx = false;
            $xx = false;
            $ttl = null;
            if (is_array($opts)) {
                foreach ($opts as $name => $optValue) {
                    $flag = strtolower(is_int($name) ? (string) $optValue : $name);
                    if ($flag === 'nx') {
                        $nx = true;
                    } elseif ($flag === 'xx') {
                        $xx = true;
                    } elseif ($flag === 'ex') {
                        $ttl = (float) $optValue;
                    } elseif ($flag === 'px') {
                        $ttl = ((float) $optValue) / 1000;
                    }
                }
            }
            if ($nx && $this->hasEntry($key)) {
                return false;
            }
            if ($xx && !$this->hasEntry($key)) {
                return false;
            }
            $this->remove($key);
            $this->data[$key] = ['type' => 'string', 'value' => (string) $value];
            if ($ttl !== null) {
                $this->expires[$key] = $this->clock + $ttl;
            }

            return true;
        }

        public function exists($key)
        {
            $this->guard();

            return $this->hasEntry($key) ? 1 : 0;
        }

        public function del($key)
        {
            $this->guard();
            $keys = func_get_args();
            if (count($keys) === 1 && is_array($keys[0])) {
                $keys = $keys[0];
            }
            $deleted = 0;
            foreach ($keys as $one) {
                if ($this->hasEntry($one)) {
                    $this->remove($one);
                    $deleted++;
                }
            }

            return $deleted;
        }

        // -------------------------------------------------------------------
        // Hashes
        // -------------------------------------------------------------------

        public function hSet($key, $field, $value)
        {
            $this->guard();
            $this->ensureType($key, 'hash', []);
            $isNew = !array_key_exists($field, $this->data[$key]['value']);
            $this->data[$key]['value'][(string) $field] = (string) $value;

            return $isNew ? 1 : 0;
        }

        public function hSetNx($key, $field, $value)
        {
            $this->guard();
            $this->ensureType($key, 'hash', []);
            if (array_key_exists($field, $this->data[$key]['value'])) {
                return 0;
            }
            $this->data[$key]['value'][(string) $field] = (string) $value;

            return 1;
        }

        public function hGet($key, $field)
        {
            $this->guard();
            if (!$this->hasEntry($key)) {
                return false;
            }
            $this->assertType($key, 'hash');
            $value = $this->data[$key]['value'];
            if (!array_key_exists($field, $value)) {
                return false;
            }

            return $value[$field];
        }

        public function hGetAll($key)
        {
            $this->guard();
            if (!$this->hasEntry($key)) {
                return [];
            }
            $this->assertType($key, 'hash');

            return $this->data[$key]['value'];
        }

        public function hDel($key, $field)
        {
            $this->guard();
            $fields = func_get_args();
            array_shift($fields);
            if (count($fields) === 1 && is_array($fields[0])) {
                $fields = $fields[0];
            }
            if (!$this->hasEntry($key)) {
                return 0;
            }
            $this->assertType($key, 'hash');
            $removed = 0;
            foreach ($fields as $one) {
                if (array_key_exists($one, $this->data[$key]['value'])) {
                    unset($this->data[$key]['value'][$one]);
                    $removed++;
                }
            }
            if ($this->data[$key]['value'] === []) {
                $this->remove($key);
            }

            return $removed;
        }

        public function hIncrBy($key, $field, $by)
        {
            $this->guard();
            $this->ensureType($key, 'hash', []);
            $value = $this->data[$key]['value'];
            $current = array_key_exists($field, $value) ? $value[$field] : '0';
            if (!is_numeric($current)) {
                throw new \RuntimeException('ERR hash value is not an integer or out of range');
            }
            $next = (int) $current + (int) $by;
            $this->data[$key]['value'][(string) $field] = (string) $next;

            return $next;
        }

        // -------------------------------------------------------------------
        // Lists (RPUSH/LTRIM are pipeline-queueable; SharedState pipelines them)
        // -------------------------------------------------------------------

        public function rPush($key, $value)
        {
            return $this->queueOrRun(__FUNCTION__, func_get_args(), function ($key, $value) {
                $this->ensureType($key, 'list', []);
                $this->data[$key]['value'][] = (string) $value;

                return count($this->data[$key]['value']);
            });
        }

        /**
         * EXPIRE. Pipeline-queueable, because SharedState::rPushLtrim() sets the
         * chat history's idle TTL inside its pipeline (decision E). Real Redis
         * returns 1 when the TTL was applied and 0 when the key does not exist.
         */
        public function expire($key, $ttl)
        {
            return $this->queueOrRun(__FUNCTION__, func_get_args(), function ($key, $ttl) {
                if (!$this->hasEntry($key)) {
                    return 0;
                }
                if ((int) $ttl > 0) {
                    $this->expires[$key] = $this->clock + (int) $ttl;
                } else {
                    $this->remove($key);
                }

                return 1;
            });
        }

        public function lRange($key, $start, $stop)
        {
            $this->guard();
            if (!$this->hasEntry($key)) {
                return [];
            }
            $this->assertType($key, 'list');

            return $this->sliceList($this->data[$key]['value'], $start, $stop);
        }

        public function lTrim($key, $start, $stop)
        {
            return $this->queueOrRun(__FUNCTION__, func_get_args(), function ($key, $start, $stop) {
                if (!$this->hasEntry($key)) {
                    return true;
                }
                $this->assertType($key, 'list');
                $sliced = $this->sliceList($this->data[$key]['value'], $start, $stop);
                if ($sliced === []) {
                    $this->remove($key);
                } else {
                    $this->data[$key]['value'] = $sliced;
                }

                return true;
            });
        }

        // -------------------------------------------------------------------
        // Sets
        // -------------------------------------------------------------------

        public function sAdd($key, $member)
        {
            $this->guard();
            $this->ensureType($key, 'set', []);
            $value = (string) $member;
            $isNew = !isset($this->data[$key]['value'][$value]);
            $this->data[$key]['value'][$value] = true;

            return $isNew ? 1 : 0;
        }

        public function sRem($key, $member)
        {
            $this->guard();
            $members = func_get_args();
            array_shift($members);
            if (count($members) === 1 && is_array($members[0])) {
                $members = $members[0];
            }
            if (!$this->hasEntry($key)) {
                return 0;
            }
            $this->assertType($key, 'set');
            $removed = 0;
            foreach ($members as $one) {
                if (isset($this->data[$key]['value'][(string) $one])) {
                    unset($this->data[$key]['value'][(string) $one]);
                    $removed++;
                }
            }
            if ($this->data[$key]['value'] === []) {
                $this->remove($key);
            }

            return $removed;
        }

        public function sMembers($key)
        {
            $this->guard();
            if (!$this->hasEntry($key)) {
                return [];
            }
            $this->assertType($key, 'set');

            return array_keys($this->data[$key]['value']);
        }

        // -------------------------------------------------------------------
        // Sorted sets
        // -------------------------------------------------------------------

        public function zAdd($key, $score, $value)
        {
            $this->guard();
            $this->ensureType($key, 'zset', []);
            // phpredis ZADD replies int 1 for a NEW member, int 0 when it only
            // updates an existing member's score (false on error). Modeling the
            // 0 is what lets a facade test pin the F1 heartbeat-return bug.
            $member = (string) $value;
            $isNew = !array_key_exists($member, $this->data[$key]['value']);
            $this->data[$key]['value'][$member] = (float) $score;

            return $isNew ? 1 : 0;
        }

        public function zRange($key, $start, $stop)
        {
            $this->guard();
            if (!$this->hasEntry($key)) {
                return [];
            }
            $this->assertType($key, 'zset');
            $ordered = $this->sortedMembers($this->data[$key]['value']);

            return $this->sliceList($ordered, $start, $stop);
        }

        public function zRangeByScore($key, $min, $max)
        {
            $this->guard();
            if (!$this->hasEntry($key)) {
                return [];
            }
            $this->assertType($key, 'zset');
            $lower = $this->scoreBound($min);
            $upper = $this->scoreBound($max);
            $within = [];
            foreach ($this->data[$key]['value'] as $member => $score) {
                if ($score >= $lower && $score <= $upper) {
                    $within[$member] = $score;
                }
            }

            return $this->sortedMembers($within);
        }

        public function zRem($key, $member)
        {
            $this->guard();
            $members = func_get_args();
            array_shift($members);
            if (count($members) === 1 && is_array($members[0])) {
                $members = $members[0];
            }
            if (!$this->hasEntry($key)) {
                return 0;
            }
            $this->assertType($key, 'zset');
            $removed = 0;
            foreach ($members as $one) {
                if (array_key_exists((string) $one, $this->data[$key]['value'])) {
                    unset($this->data[$key]['value'][(string) $one]);
                    $removed++;
                }
            }
            if ($this->data[$key]['value'] === []) {
                $this->remove($key);
            }

            return $removed;
        }

        public function zRemRangeByScore($key, $min, $max)
        {
            $this->guard();
            if (!$this->hasEntry($key)) {
                return 0;
            }
            $this->assertType($key, 'zset');
            $removed = 0;
            foreach ($this->data[$key]['value'] as $member => $score) {
                if ($score >= (float) $min && $score <= (float) $max) {
                    unset($this->data[$key]['value'][$member]);
                    $removed++;
                }
            }
            if ($this->data[$key]['value'] === []) {
                $this->remove($key);
            }

            return $removed;
        }

        // -------------------------------------------------------------------
        // Scripting (the two SharedState lock scripts, natively)
        // -------------------------------------------------------------------

        public function eval($script, $args = [], $numKeys = 0)
        {
            $this->guard();
            $keys = array_slice($args, 0, (int) $numKeys);
            $argv = array_slice($args, (int) $numKeys);
            $key = $keys[0];

            if (stripos($script, 'PEXPIRE') !== false) {
                // Renew: compare-token then set TTL in milliseconds.
                if (!$this->stringMatches($key, $argv[0])) {
                    return 0;
                }
                $this->expires[$key] = $this->clock + ((float) $argv[1] / 1000);

                return 1;
            }
            if (stripos($script, 'DEL') !== false) {
                // Release: compare-token then delete.
                if (!$this->stringMatches($key, $argv[0])) {
                    return 0;
                }
                $this->remove($key);

                return 1;
            }

            throw new \RuntimeException('InMemoryRedis::eval received an unsupported Lua script: '.$script);
        }

        // -------------------------------------------------------------------
        // Pipelining
        // -------------------------------------------------------------------

        public function multi($mode = -1)
        {
            $this->guard();
            $this->inPipeline = true;
            $this->pipeline = [];

            return $this;
        }

        /**
         * DISCARD — drop a queued pipeline without executing it. SharedState's
         * rPushLtrim() calls this when the pipeline throws, so that a failure can
         * never strand the shared handle in pipeline mode.
         */
        public function discard()
        {
            $this->pipeline = [];
            $this->inPipeline = false;

            return true;
        }

        public function exec()
        {
            if (!$this->inPipeline) {
                throw new \RuntimeException('InMemoryRedis::exec() without multi()');
            }
            $results = [];
            foreach ($this->pipeline as $queued) {
                $results[] = $queued();
            }
            $this->pipeline = [];
            $this->inPipeline = false;

            return $results;
        }

        // -------------------------------------------------------------------
        // Internals
        // -------------------------------------------------------------------

        private function queueOrRun($name, array $args, \Closure $run)
        {
            $this->guard();
            if ($this->inPipeline) {
                $this->pipeline[] = static function () use ($args, $run) {
                    return $run(...$args);
                };

                return $this;
            }

            return $run(...$args);
        }

        /** Evict expired keys, then refuse every command on a closed client. */
        private function guard(): void
        {
            $this->evict();
            if (!$this->connected) {
                throw new \RuntimeException('Redis connection is closed');
            }
        }

        private function evict(): void
        {
            foreach ($this->expires as $key => $deadline) {
                if ($deadline <= $this->clock) {
                    $this->remove($key);
                }
            }
        }

        private function getEntry($key)
        {
            return $this->data[$key] ?? null;
        }

        private function hasEntry($key): bool
        {
            return array_key_exists($key, $this->data);
        }

        private function remove($key): void
        {
            unset($this->data[$key], $this->expires[$key]);
        }

        // Intentional divergence from real phpredis (default OPT_THROW_ON_ERROR=false
        // would return false): cross-type reads routed through here — get() against a
        // HASH, hGet() against a STRING — and hIncrBy() on a non-numeric field all
        // THROW. Fail-loud is the point: to a test oracle a type mismatch is a bug,
        // never a silent falsy. Unreachable in production by key-shape discipline:
        // SharedState gives every key exactly one writer-side type and reads only with
        // matching commands (never get() a HASH, never hGet() a STRING), and the
        // facade's hIncr() has zero callers.
        private function assertType($key, string $type): void
        {
            if (($this->data[$key]['type'] ?? null) !== $type) {
                throw new \RuntimeException('WRONGTYPE Operation against a key holding the wrong kind of value');
            }
        }

        private function ensureType($key, string $type, $emptyValue): void
        {
            if (!$this->hasEntry($key)) {
                $this->data[$key] = ['type' => $type, 'value' => $emptyValue];
            }
            $this->assertType($key, $type);
        }

        /** GET + string equality, as the Lua `redis.call('GET',..)==ARGV[1]` does. */
        private function stringMatches($key, $expected)
        {
            $entry = $this->getEntry($key);
            if ($entry === null) {
                return false;
            }
            // Real Lua redis.call('GET', key) raises WRONGTYPE against a hash/
            // list/set/zset, so an unlock/renew aimed at a cross-type key must
            // error loudly rather than be mistaken for a token miss (F6). An
            // ABSENT key still legitimately matches nothing (returns false).
            $this->assertType($key, 'string');

            return $entry['value'] === (string) $expected;
        }

        /** Redis LRANGE/LTRIM index rules: negatives count back, bounds clamp. */
        private function sliceList(array $list, $start, $stop): array
        {
            $len = count($list);
            if ($start < 0) {
                $start = max(0, $len + $start);
            }
            if ($stop < 0) {
                $stop = $len + $stop;
            }
            $stop = min($stop, $len - 1);
            if ($start > $stop || $start >= $len) {
                return [];
            }

            return array_values(array_slice($list, $start, $stop - $start + 1));
        }

        /**
         * Order raw members by score ascending, breaking ties by member name
         * lexicographically — the guarantee Redis ZRANGE/ZRANGEBYSCORE give
         * (F7). asort() only kept insertion order on equal scores, which let a
         * heartbeat-present ZSET read back in an arbitrary, load-bearing order.
         *
         * @param array<string,float> $members member => score
         * @return string[] members in Redis order
         */
        private function sortedMembers(array $members): array
        {
            $ordered = array_keys($members);
            usort($ordered, static function ($a, $b) use ($members) {
                if ($members[$a] != $members[$b]) {
                    return $members[$a] < $members[$b] ? -1 : 1;
                }

                return strcmp((string) $a, (string) $b);
            });

            return $ordered;
        }

        /**
         * Resolve a ZRANGEBYSCORE bound to a float. PHP's (float) cast turns the
         * strings 'inf'/'-inf'/'+inf' into 0.0, so the open-range spellings the
         * Redis command accepts must be mapped to the INF constants explicitly.
         *
         * @param float|int|string $bound
         */
        private function scoreBound($bound): float
        {
            if (is_string($bound)) {
                switch (strtolower(trim($bound))) {
                    case '-inf':
                        return -INF;
                    case 'inf':
                    case '+inf':
                        return INF;
                }
            }

            return (float) $bound;
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
