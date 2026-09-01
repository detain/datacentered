<?php

use \Workerman\Worker;
use \GatewayWorker\BusinessWorker;

if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1) {
    ini_set('default_socket_timeout', 1200);
}

if (!defined('GLOBALDATA_IP')) {
    require_once '/home/my/include/config/config.settings.php';
}


$worker = new BusinessWorker(); // bussinessWorker process
//$worker->name = 'ChatBusinessWorker'; // worker name
$worker->count = 5; // bussinessWorker number of processes
$worker->registerAddress = GLOBALDATA_IP.':1236'; // Service registration address

/**
 * NO onConnect / onBufferFull / onBufferDrain / onError HERE — they never fire.
 *
 * A BusinessWorker has no listening socket, so Worker::acceptTcpConnection()
 * (the only thing that copies $worker->onConnect/onError/onBufferFull/
 * onBufferDrain onto a connection) never runs. Its two outbound connections are
 * built by BusinessWorker itself with its own callbacks hard-wired:
 * connectToRegister() sets onConnect/onClose on the register connection
 * (vendor/workerman/gateway-worker/src/BusinessWorker.php ~283), and each
 * gateway connection gets onConnect/onClose/onError = onConnectGateway/
 * onGatewayClose/onGatewayError (~449-452). It sets no buffer callbacks at all.
 *
 * This file used to define all four anyway (copied from the GatewayWorker demo).
 * They were dead — the log has never contained a single "BusinessWorker buffer
 * drain" line — and two of them were landmines waiting on that dead code ever
 * being reached:
 *   - onBufferFull did `$connection->sendBufferSize = 0` to apply backpressure.
 *     TcpConnection has no `sendBufferSize` property (it is `maxSendBufferSize`),
 *     so that only created a dynamic property Workerman never reads: the
 *     "disable sending" was inert, and onBufferDrain's matching "restore" with
 *     it. Only the 3-strikes close() would ever have done anything.
 *   - onConnect did `$connection::$maxPackageSize = 100*1024*1024`, a STATIC
 *     write to a property that only exists as an instance `public int`
 *     (the static is TcpConnection::$defaultMaxPackageSize). That is a fatal
 *     "Access to undeclared static property", not a no-op.
 *
 * The buffer size that onConnect was reaching for has a real knob:
 * $worker->sendToGatewayBufferSize (BusinessWorker.php:70, default ~10MB),
 * which BusinessWorker applies to every gateway connection's
 * maxSendBufferSize at ~453. It is left at the default here deliberately —
 * uncomment the line below to raise it, don't reintroduce an onConnect.
 */
//$worker->sendToGatewayBufferSize = 102400000;

/**
 * DO NOT call \Events::onWorkerStart() from here.
 *
 * BusinessWorker::run() stashes whatever we assign to $worker->onWorkerStart
 * into $_onWorkerStart and replaces the property with its own method
 * (BusinessWorker.php:181-185). That method calls OUR closure
 * (~212-213) and then, separately and unconditionally for every worker,
 * calls {$this->eventHandler}::onWorkerStart — and $eventHandler defaults to
 * 'Events' (BusinessWorker.php:56, called at ~216-217).
 *
 * So Events::onWorkerStart() already runs once per BusinessWorker process on
 * its own. This closure used to call it again, which meant worker 0 ran it
 * TWICE: two GlobalData clients, two Memcached handles and two DB connections
 * (the first of each leaked), and on any host whose gethostname() branch in
 * that method actually registers timers, every Timer::add() ran twice — so
 * the queue/reaper timers fired at double rate and $global->timers only
 * recorded the second set, orphaning the first with no way to cancel it.
 * On my-web-2 the branch is empty, which is why this stayed invisible here.
 *
 * setupSessionHealthTimer() is NOT called by BusinessWorker, so it does belong
 * here, and only on worker 0 so the 30s presence sweep runs once pool-wide.
 * It only does a Timer::add(); its callback reads and writes presence state
 * through the SharedState Redis facade (GlobalData→Redis migration), which
 * resolves its own client on first use, so it does not care that this closure
 * runs before Events::onWorkerStart().
 */
$worker->onWorkerStart = function ($worker) {
    if ($worker->id === 0) {
        \Events::setupSessionHealthTimer();
    }
};

/*
$worker->onWorkerStart = function($worker) { Events::setup_timers($worker); }; // start the process, open a vmstat process, and broadcast vmstat process output to all browser clients
*/

if (!defined('GLOBAL_START')) { // If it is not started in the root directory, run the runAll method
    Worker::runAll();
}
