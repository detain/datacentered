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
require_once __DIR__ . '/../../../../vendor/workerman/channel/src/Server.php';
require_once __DIR__ . '/../../../../vendor/workerman/channel/src/Client.php';

// Initialize a Channel server
$channel_server = new Channel\Server('0.0.0.0', 2206);
