<?php

/**
 * Used to detect business code cycle or prolonged obstruction and other issues
 * If the business card is found dead, you can open the following declare (remove the // comment), and execute php start.php reload
 * Then observe workerman.log for a period of time to see if there is a process_timeout exception
 */
//declare(ticks=1);

/**
 * Chat the main logic - Mainly onMessage onClose
 */
use \GatewayWorker\Lib\Gateway;
use \Workerman\Connection\AsyncTcpConnection;
use \Workerman\Connection\TcpConnection;
use \Workerman\Lib\Timer;
use \GlobalData\Client as GlobalDataClient;
require_once __DIR__.'/Process.php';

class Events {

	/**
	 * When there is news
	 * @param int $client_id
	 * @param mixed $message
	 */
	public static function onMessage($client_id, $message) {
		echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']} client_id:{$client_id} session:".json_encode($_SESSION)." onMessage:{$message}\n"; // debug
		$message_data = json_decode($message, true); // Client is passed json data
		if (!$message_data)
			return ;
		switch ($message_data['type']) { // Depending on the type of business
			case 'pong': // The client responds to the server's heartbeat
				return;
			case 'phptty_run':
				Process::run($client_id, 'htop');
				return;
			case 'phptty':
				//if(ALLOW_CLIENT_INPUT)
				fwrite($connection->pipes[0], $data);
				return;
			case 'login': // Client login message format: {type: login, name: xx, room_id: 1}, added to the client, broadcast to all clients xx into the chat room
				if (!isset($message_data['room_id'])) // Determine whether there is a room number
					throw new \Exception("\$message_data['room_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:{$message}");
				$room_id = $message_data['room_id']; // The room number nickname into the session
				$client_name = htmlspecialchars($message_data['client_name']);
				$_SESSION['room_id'] = $room_id;
				$_SESSION['client_name'] = $client_name;
				$clients_list = Gateway::getClientSessionsByGroup($room_id); // Get a list of all users in your room
				foreach($clients_list as $tmp_client_id=>$item)
					$clients_list[$tmp_client_id] = $item['client_name'];
				$clients_list[$client_id] = $client_name;
				$new_message = [ // Broadcast to all clients in the current room, xx into the chat room message {type:login, client_id:xx, name:xx}
					'type' => $message_data['type'],
					'client_id' => $client_id,
					'client_name' => htmlspecialchars($client_name),
					'time' => date('Y-m-d H:i:s')
				];
				Gateway::sendToGroup($room_id, json_encode($new_message));
				Gateway::joinGroup($client_id, $room_id);
				$new_message['client_list'] = $clients_list; // Send the user list to the current user
				Gateway::sendToCurrentClient(json_encode($new_message));
				return;
			case 'say': // client speaks message: {type:say, to_client_id:xx, content:xx}
				if (!isset($_SESSION['room_id'])) // illegal request
					throw new \Exception("\$_SESSION['room_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']}");
				$room_id = $_SESSION['room_id'];
				$client_name = $_SESSION['client_name'];
				if ($message_data['to_client_id'] != 'all') { // private chat
					$new_message = [
						'type' => 'say',
						'from_client_id' => $client_id,
						'from_client_name' =>$client_name,
						'to_client_id' => $message_data['to_client_id'],
						'content' => "<b>Say to you: </b>".nl2br(htmlspecialchars($message_data['content'])),
						'time' => date('Y-m-d H:i:s'),
					];
					Gateway::sendToClient($message_data['to_client_id'], json_encode($new_message));
					$new_message['content'] = "<b>You're right".htmlspecialchars($message_data['to_client_name'])."Say: </b>".nl2br(htmlspecialchars($message_data['content']));
					return Gateway::sendToCurrentClient(json_encode($new_message));
				}
				$new_message = [
					'type' => 'say',
					'from_client_id' => $client_id,
					'from_client_name' =>$client_name,
					'to_client_id' => 'all',
					'content' => nl2br(htmlspecialchars($message_data['content'])),
					'time' => date('Y-m-d H:i:s'),
				];
				return Gateway::sendToGroup($room_id ,json_encode($new_message));
		}
	}

	/**
	 * When the client is disconnected
	 * @param integer $client_id client id
	 */
	public static function onClose($client_id) {
		echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']} client_id:{$client_id} onClose:''\n"; // debug
		if (isset($_SESSION['room_id'])) { // Remove from the client's list of rooms
			$room_id = $_SESSION['room_id'];
			$new_message = [
				'type' => 'logout',
				'from_client_id' => $client_id,
				'from_client_name' => $_SESSION['client_name'],
				'time' => date('Y-m-d H:i:s')
			];
			Gateway::sendToGroup($room_id, json_encode($new_message));
		}
	}

	public static function setup_timers($worker) {
		if($worker->id === 0) { // The timer is set only on the process whose id number is 0, and the processes of other 1, 2, and 3 processes do not set the timer
			Timer::add(600, ['Events', 'update_vps_list_timer']);
			Timer::add(60, ['Events', 'vps_queue_timer']);
		}
	}

	public static function update_vps_list_timer() {
		$new_message = [
			'type' => 'log',
			'content' => nl2br(htmlspecialchars('Running Update VPS List Timer')),
			'time' => date('Y-m-d H:i:s'),
		];
		Gateway::sendToAll(json_encode($new_message));
		$task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');								// Asynchronous link with the remote task service
		$task_connection->send(json_encode(['function' => 'async_hyperv_get_list', 'args' => []]));		// send data
		$task_connection->onMessage = function($task_connection, $task_result) use ($task_connection) {	// get the result asynchronously
			 //var_dump($task_result);
			 $task_connection->close();																	// remember to turn off the asynchronous link after getting the result
		};
		$task_connection->connect();																	// execute async link
	}

	public static function vps_queue_timer() {
		$new_message = [
			'type' => 'log',
			'content' => nl2br(htmlspecialchars('Running VPS Queue Timer')),
			'time' => date('Y-m-d H:i:s'),
		];
		Gateway::sendToAll(json_encode($new_message));
		$task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');								// Asynchronous link with the remote task service
		$task_connection->send(json_encode(['function' => 'sync_hyperv_queue', 'args' => []]));			// send data
		$task_connection->onMessage = function($task_connection, $task_result) use ($task_connection) {	// get the result asynchronously
			 //var_dump($task_result);
			 $task_connection->close();																	// remember to turn off the asynchronous link after getting the result
		};
		$task_connection->connect();																	// execute async link
	}
}
