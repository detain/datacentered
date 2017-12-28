<?php
/**
 * Channel is a distributed communication component used to complete inter-process communication or server-to-server communication
 * based on the subscription release model with non-blocking IO
 *
 * principle
 * - Channel contains Channel/Server server and Channel/Client client
 * - Channel/Client connect to Channel/Server via connect interface and keep long connection
 * - Channel/Client tells Channel/Server which events to focus on by calling the on interface and registers the event
 *   callback function (the callback occurs in the process where Channel/Client is located)
 * - Channel/Client publishes data related to an event and event to Channel/Server through the publish interface
 * - After Channel/Server receives the event and data, it will be distributed to Channel/Client concerned about this event
 * - Channel/Client receives events and data trigger on the interface settings callback
 * - Channel/Client will only receive their own attention events and trigger callbacks
 */
use Workerman\Worker;
require_once __DIR__ . '/Workerman/Autoloader.php';
require_once __DIR__ . '/Channel/src/Server.php';
require_once __DIR__ . '/Channel/src/Client.php';

// Initialize a Channel server
$channel_server = new Channel\Server('0.0.0.0', 2206);

// websocket server
$worker = new Worker('websocket://0.0.0.0:4236');
$worker->name = 'websocket';
$worker->count = 6;
// Each worker process starts
$worker->onWorkerStart = function($worker)
{
	// Channel client connected to the Channel server
	Channel\Client::connect('127.0.0.1', 2206);
	// Subscribe to the broadcast event and register the event callback
	Channel\Client::on('broadcast', function($event_data)use($worker){
		// Broadcast messages to all clients of the current worker process
		foreach($worker->connections as $connection)
		{
			$connection->send($event_data);
		}
	});
	Channel\Client::on('login', function($event_data)use($worker){
	list($login, $password) = $event_data;
	if ($login == 'test' && $password == 'test') {
	} else {
	}
		// Broadcast messages to all clients of the current worker process
		foreach($worker->connections as $connection)
		{
			$connection->send($event_data);
		}
	});
	// Channel\Client::unsubscribe($event_name);
};

$worker->onMessage = function($connection, $data)
{
   // data sent by the client as event data
   $words = explode(' ', $data);
   if ($words[0] == 'login') {
	$event_name = array_shift($words);
	$event_data = $words;
   } else {
	$event_name = 'broadcast';
	$event_data = $data;
   }
   // Publish broadcast events to all worker processes
   \Channel\Client::publish($event_name, $event_data);
};

Worker::runAll();
