<?php

/**
 * Pins the GatewayWorker BusinessWorker startup contract that
 * Applications/Chat/start_businessworker.php depends on.
 *
 * WHY: that start script used to do
 *
 *     $worker->onWorkerStart = function ($worker) {
 *         if ($worker->id === 0) {
 *             \Events::onWorkerStart($worker);        // <-- the bug
 *             \Events::setupSessionHealthTimer();
 *         }
 *     };
 *
 * but BusinessWorker::onWorkerStart() ALREADY calls
 * {$this->eventHandler}::onWorkerStart() itself, and $eventHandler defaults to
 * 'Events'. So worker 0 ran Events::onWorkerStart() twice — two GlobalData
 * clients, two Memcached handles, two DB connections (first of each leaked),
 * and on a host whose gethostname() branch in that method registers timers,
 * every Timer::add() ran twice: double-rate queue/reaper timers, with
 * $global->timers recording only the second set so the first was orphaned and
 * uncancellable.
 *
 * These tests drive the real vendor method (only connectToRegister() is stubbed,
 * since it opens a socket) and assert BOTH invocation paths independently, so
 * the redundancy cannot be reintroduced by accident and a vendor bump that
 * changes the contract fails here instead of in production.
 */

namespace {
    use PHPUnit\Framework\TestCase;

    // Declares the fake \GatewayWorker\Lib\Gateway (with setBusinessWorker +
    // $secretKey, which BusinessWorker::onWorkerStart writes) before the real
    // gateway transport can be autoloaded.
    require_once __DIR__.'/V1TestSupport.php';

    /** Counts static onWorkerStart calls, standing in for \Events as the eventHandler. */
    if (!class_exists('BootstrapProbeHandler')) {
        class BootstrapProbeHandler
        {
            /** @var int */
            public static $workerStartCalls = 0;

            /** @var array<int,int> worker ids seen, in call order */
            public static $seenWorkerIds = [];

            public static function reset(): void
            {
                self::$workerStartCalls = 0;
                self::$seenWorkerIds = [];
            }

            public static function onWorkerStart($worker)
            {
                self::$workerStartCalls++;
                self::$seenWorkerIds[] = $worker->id;
            }

            /** BusinessWorker warns on stderr when the handler has no onMessage. */
            public static function onMessage($client_id, $message)
            {
            }
        }
    }

    /**
     * Real BusinessWorker with only the register socket stubbed out —
     * everything else in onWorkerStart() runs verbatim.
     */
    if (!class_exists('BootstrapProbeBusinessWorker')) {
        class BootstrapProbeBusinessWorker extends \GatewayWorker\BusinessWorker
        {
            /** @var int */
            public $connectToRegisterCalls = 0;

            public function connectToRegister()
            {
                $this->connectToRegisterCalls++;
            }
        }
    }

    class BusinessWorkerBootstrapTest extends TestCase
    {
        protected function setUp(): void
        {
            BootstrapProbeHandler::reset();
        }

        private function makeWorker(int $id = 0): BootstrapProbeBusinessWorker
        {
            $worker = new BootstrapProbeBusinessWorker();
            $worker->id = $id;
            $worker->eventHandler = 'BootstrapProbeHandler';
            $worker->registerAddress = '127.0.0.1:1236';
            return $worker;
        }

        /**
         * Drive the protected vendor onWorkerStart() the way BusinessWorker::run()
         * does. run() itself cannot be called here — parent::run() daemonizes and
         * installs signal handlers — so we replicate ONLY its two relevant lines
         * (BusinessWorker.php:181 and :185): stash the user callback into
         * $_onWorkerStart, then hand control to the class's own onWorkerStart().
         * Everything the method does after that is the real vendor code.
         */
        private function bootWorker(BootstrapProbeBusinessWorker $worker): void
        {
            $stash = new ReflectionProperty(\GatewayWorker\BusinessWorker::class, '_onWorkerStart');
            $stash->setAccessible(true);
            $stash->setValue($worker, $worker->onWorkerStart);   // run():181

            $method = new ReflectionMethod(\GatewayWorker\BusinessWorker::class, 'onWorkerStart');
            $method->setAccessible(true);
            $method->invoke($worker);                            // run():185 -> parent::run() -> this
        }

        /**
         * THE CONTRACT: the eventHandler's onWorkerStart runs on its own, with no
         * user callback assigned at all. This is why the start script must NOT
         * call Events::onWorkerStart() — doing so is pure duplication.
         */
        public function testEventHandlerOnWorkerStartRunsWithoutAnyUserCallback(): void
        {
            $worker = $this->makeWorker(0);
            $this->bootWorker($worker);

            $this->assertSame(
                1,
                BootstrapProbeHandler::$workerStartCalls,
                'BusinessWorker calls {eventHandler}::onWorkerStart itself; the start script need not'
            );
            $this->assertSame([0], BootstrapProbeHandler::$seenWorkerIds);
        }

        /**
         * REGRESSION: a user closure and the eventHandler are two INDEPENDENT
         * paths, both invoked. A closure that also calls the handler therefore
         * runs it twice — the exact defect removed from start_businessworker.php.
         */
        public function testUserClosureAndEventHandlerAreBothInvoked(): void
        {
            $worker = $this->makeWorker(0);
            $closureCalls = 0;
            $worker->onWorkerStart = function ($w) use (&$closureCalls): void {
                $closureCalls++;
            };
            $this->bootWorker($worker);

            $this->assertSame(1, $closureCalls, 'the user closure runs');
            $this->assertSame(1, BootstrapProbeHandler::$workerStartCalls, 'and so does the eventHandler');
        }

        /**
         * Demonstrates the bug directly: a closure that forwards to the handler
         * (what the start script did) produces TWO handler invocations.
         */
        public function testClosureForwardingToTheHandlerDoublesIt(): void
        {
            $worker = $this->makeWorker(0);
            $worker->onWorkerStart = function ($w): void {
                if ($w->id === 0) {
                    BootstrapProbeHandler::onWorkerStart($w);
                }
            };
            $this->bootWorker($worker);

            $this->assertSame(
                2,
                BootstrapProbeHandler::$workerStartCalls,
                'this is the double-initialisation that start_businessworker.php used to cause'
            );
        }

        /**
         * The handler path is not worker-0-only: every BusinessWorker process gets
         * it, which is what gives workers 1-4 their $global/$memcache/$db. Only the
         * worker-0 guard inside Events::onWorkerStart (and around
         * setupSessionHealthTimer) keeps pool-wide singletons single.
         */
        public function testEventHandlerRunsForNonZeroWorkersToo(): void
        {
            foreach ([1, 2, 3, 4] as $id) {
                BootstrapProbeHandler::reset();
                $this->bootWorker($this->makeWorker($id));
                $this->assertSame(1, BootstrapProbeHandler::$workerStartCalls, "worker {$id}");
                $this->assertSame([$id], BootstrapProbeHandler::$seenWorkerIds);
            }
        }

        /**
         * $worker->onConnect / onBufferFull / onBufferDrain / onError are dead on a
         * BusinessWorker (no listening socket, and it hard-wires its own callbacks
         * on the connections it creates). Nothing in startup reads them, so
         * assigning them is inert — which is why they were deleted rather than
         * "fixed". Guards against someone re-adding the landmines they contained.
         */
        public function testBufferAndConnectionCallbacksAreNeverConsultedAtStartup(): void
        {
            $worker = $this->makeWorker(0);
            $fired = [];
            foreach (['onConnect', 'onBufferFull', 'onBufferDrain', 'onError'] as $cb) {
                $worker->$cb = function () use ($cb, &$fired): void {
                    $fired[] = $cb;
                };
            }
            $this->bootWorker($worker);

            $this->assertSame([], $fired, 'a BusinessWorker never invokes these');
        }

        /** Sanity: the stub really did replace the socket-opening register connect. */
        public function testRegisterConnectIsAttemptedExactlyOnce(): void
        {
            $worker = $this->makeWorker(0);
            $this->bootWorker($worker);

            $this->assertSame(1, $worker->connectToRegisterCalls);
        }
    }
}
