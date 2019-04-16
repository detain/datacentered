<?php
/**
 * GlobalData is used to share variables between processes.
 * Using PHP __set __get __isset __unsetmagic method to trigger communication with GlobalData server,
 * the actual variable is stored in GlobalData server. For example, when setting a non-existent
 * property to a client class, a __setmagic method is triggered . The client class __setsends a
 * request to the GlobalData server in the method and saves it in a variable. When accessing a
 * non-existent variable in the __getclient class, the method of the class is triggered. The client
 * initiates a request to the GlobalData server to read this value, thereby completing the process
 * of variable sharing between processes.
 *
 */
use Workerman\Worker;

require_once __DIR__.'/../../vendor/workerman/globaldata/src/Server.php';

$globaldata_server = new GlobalData\Server('127.0.0.1', 2207);

$globaldata_server->onConnect = function ($connection) { // When the client is connected, set the connection onWebSocketConnect, that is, when the websocket handshake callback
	$connection->maxSendBufferSize = 100*1024*1024; // Set the current connection application layer send buffer size of the connection to 100mb, will override the default value
	$connection->maxPackageSize = 100*1024*1024; // Set the current connection application layer received packet size to 100mb (default 10mb)
};
$globaldata_server->onBufferFull = function ($connection) {
	Worker::safeEcho("GlobalData bufferFull and do not send again\n");
};
$globaldata_server->onBufferDrain = function ($connection) {
	Worker::safeEcho("GlobalData buffer drain and continue send\n");
};
$globaldata_server->onError = function ($connection, $code, $msg) {
	Worker::safeEcho("GlobalData error {$code} {$msg}\n");
};

if (!defined('GLOBAL_START')) { // If it is not started in the root directory, run the runAll method
	Worker::runAll();
}
