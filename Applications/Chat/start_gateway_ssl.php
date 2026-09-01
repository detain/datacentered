<?php

use \Workerman\Worker;
use \GatewayWorker\Gateway;

//require __DIR__.'/Events.php';

if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1) {
    ini_set('default_socket_timeout', 1200);
}

if (!defined('GLOBALDATA_IP')) {
    require_once '/home/my/include/config/config.settings.php';
}

$context = [																						// Certificate is best to apply for a certificate
    'ssl' => [																						// use the absolute/full paths
        'local_cert' => '/etc/apache2/interserver.net.crt',							// can also be a crt file
        'local_pk' => '/etc/apache2/interserver.net.key',
        'cafile' => '/etc/apache2/AlphaSSL.root.crt',
        'verify_peer' => false,
        'verify_peer_name' => false,
    ]
];
$gateway_ssl = new Gateway("websocket://0.0.0.0:7272", $context);
$gateway_ssl->name = 'SslChatGateway';
$gateway_ssl->transport = 'ssl';
$gateway_ssl->count = 5; // Set the number of processes, the number of gateway process recommendations and cpu the same
$gateway_ssl->lanIp = '127.0.0.1'; // When distributed deployment set to intranet ip (non 127.0.0.1)
$gateway_ssl->startPort = 2400; // Internal communication start port. If $ gateway-> count = 4, the starting port is 2300. 2300 2301 2302 2303 4 ports are generally used as the internal communication port
$gateway_ssl->pingInterval = 60; // Heartbeat interval
$gateway_ssl->pingNotResponseLimit = 2;
$gateway_ssl->pingData = '{"type":"ping"}'; // heartbeat data
$gateway_ssl->registerAddress = GLOBALDATA_IP.':1236'; // Service registration address
//$gateway->maxSendBufferSize = 102400000;
//$gateway->onWorkerStart = function($worker) {};
$gateway_ssl->onConnect = function ($connection) { // When the client is connected, set the connection onWebSocketConnect, that is, when the websocket handshake callback
    $connection->maxSendBufferSize = 100*1024*1024; // Set the current connection application layer send buffer size of the connection to 100mb, will override the default value
    $connection->maxPackageSize = 100*1024*1024; // Set the current connection application layer received packet size to 100mb (default 10mb)
    //$connection->onWebSocketConnect = function($connection , $http_header) {
    //if (!preg_match('/\.interserver\.net(:[0-9]+)*/m', $_SERVER['HTTP_ORIGIN'])) // Here you can determine whether the source of the connection is legal, illegal to turn off the connection.  $_SERVER['HTTP_ORIGIN'] Identifies which site's web-initiated websocket link
    //$connection->close();
    // onWebSocketConnect Inside $_GET $_SERVER is available  var_dump($_GET, $_SERVER);
    //};
};
/**
 * BUFFER LOGGING — why onBufferDrain is conditional here.
 *
 * Workerman 5's TcpConnection::send() never attempts a direct fwrite() on an
 * `ssl` transport: it unconditionally appends to $sendBuffer and arms the
 * writable watcher (src/Connection/TcpConnection.php, the
 * `if ($this->transport === 'ssl')` branch inside `if ($this->sendBuffer === '')`).
 * baseWrite() then empties the buffer on the next event-loop pass and fires
 * onBufferDrain. So on a wss:// gateway a "drain" fires for EVERY frame sent to
 * EVERY client — it does not mean backpressure cleared, it means "a frame went
 * out", which is not worth a log line.
 *
 * Echoing it unconditionally (the GatewayWorker demo default this was copied
 * from) put a Worker::safeEcho() on the hot path of every dc presence batch and
 * bot-move broadcast. safeEcho is not cheap: four str_replace() passes, a
 * set_error_handler()/restore_error_handler() pair, an fwrite() AND an
 * fflush() — an unbuffered syscall — per frame, per connection, synchronously
 * inside the gateway event loop, with all 5 gateway processes contending on the
 * same billingd.log fd. It was also the single most common line in that log.
 *
 * Only the full -> drain transition carries information, so only that is
 * logged. onBufferFull remains unconditional: it means maxSendBufferSize
 * (100MB, set in onConnect above) was exceeded and sends are now failing.
 */
$gateway_ssl->onBufferFull = function ($connection) {
    $connection->dcBufferWasFull = true;
    Worker::safeEcho('GateWaySSL bufferFull, dropping sends to '.$connection->getRemoteAddress()."\n");
};
$gateway_ssl->onBufferDrain = function ($connection) {
    if (empty($connection->dcBufferWasFull)) {
        // Ordinary per-frame ssl drain (see above) — carries no information.
        return;
    }
    $connection->dcBufferWasFull = false;
    Worker::safeEcho('GateWaySSL buffer drained, resuming sends to '.$connection->getRemoteAddress()."\n");
};
$gateway_ssl->onError = function ($connection, $code, $msg) {
    Worker::safeEcho("GateWaySSL error {$code} {$msg}\n");
};

// If it is not started in the root directory, run the runAll method
if (!defined('GLOBAL_START')) {
    Worker::runAll();
}
