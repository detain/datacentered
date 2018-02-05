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

$process_pipes = [];

class Events {

	public static $process_handle = null;
	public static $process_pipes = null;
	public static $db = null;
	public static $db_type = 'workerman'; // workerman or react or blank for no sql

	public static function onWorkerStart($worker) {
		global $global;
		$global = new GlobalDataClient('127.0.0.1:2207');	 // initialize the GlobalData client
		$db_config = include __DIR__.'/../../../../include/config/config.db.php';
		if (self::$db_type == 'workerman') {
			self::$db = new \Workerman\MySQL\Connection($db_config['db_host'], $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name']);
		} else {
			$loop = Worker::getEventLoop();
			self::$db = new React\MySQL\Connection($loop, [
				'host'   => $db_config['db_host'],
				'dbname' => $db_config['db_name'],
				'user'   => $db_config['db_user'],
				'passwd' => $db_config['db_pass'],
			]);
			self::$db->on('error', function($e){
				echo $e;
			});
			self::$db->connect(function ($e) {
				if($e) {
					echo $e;
				} else {
					echo "connect success\n";
				}
			});
		}
		// The timer is set only on the process whose id number is 0, and the processes of other 1, 2, and 3 processes do not set the timer
		if ($worker->id === 0) {

			Timer::add(600, ['Events', 'update_vps_list_timer']);
			Timer::add(60, ['Events', 'vps_queue_timer']);

			// Save the process handle, close the handle when the process is closed
			self::$process_handle = popen('vmstat -n 1', 'r');
			if (self::$process_handle) {
				$process_connection = new TcpConnection(self::$process_handle);
				$process_connection->onMessage = function($process_connection, $data) use ($worker) {
					$msg = [
						'type' => 'vmstat',
						'content' => [
							'r' => 0,
							'b' => 0,
							'swpd' => 0,
							'free' => 0,
							'buff' => 0,
							'cache' => 0,
							'si' => 0,
							'so' => 0,
							'bi' => 0,
							'bo' => 0,
							'in' => 0,
							'cs' => 0,
							'us' => 0,
							'sy' => 0,
							'id' => 0,
							'wa' => 0,
							'st' => 0
						]
					];
					list($msg['content']['r'], $msg['content']['b'], $msg['content']['swpd'], $msg['content']['free'], $msg['content']['buff'], $msg['content']['cache'], $msg['content']['si'], $msg['content']['so'], $msg['content']['bi'], $msg['content']['bo'], $msg['content']['in'], $msg['content']['cs'], $msg['content']['us'], $msg['content']['sy'], $msg['content']['id'], $msg['content']['wa'], $msg['content']['st']) = preg_split('/ +/', trim($data));
					if (is_numeric($msg['content']['r']))
						Gateway::sendToGroup('vmstat', json_encode($msg));
				};
			} else {
			   echo "vmstat 1 fail\n";
			}
		}
	}

	public static function onWorkerStop($worker) {
		if ($worker->id == 0) {
			@shell_exec('killall vmstat');
			@pclose(self::process_handle);
		}
	}

	public static function onConnect($client_id) {
		$_SESSION['auth_timer_id'] = Timer::add(30, function($client_id){
			Gateway::closeClient($client_id);
		}, array($client_id), false);
	}

	/**
	 * When there is news
	 * @param int $client_id
	 * @param mixed $message
	 */
	public static function onMessage($client_id, $message) {
		global $process_pipes;
		echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']} client_id:{$client_id} session:".json_encode($_SESSION)." onMessage:{$message}\n"; // debug
		$message_data = json_decode($message, true); // Client is passed json data
		if (!$message_data)
			return ;
		switch ($message_data['type']) { // Depending on the type of business
			case 'pong': // The client responds to the server's heartbeat
				return;
			case 'workerman_tables':
				$all_tables = self::$db->query('show tables');
				Gateway::sendToCurrentClient(json_encode($all_tables));
				$ret = self::$db->select('*')->from('users')->where('uid>3')->offset(5)->limit(2)->query();
				return Gateway::sendToClient($client_id, json_encode($ret));
			case 'react_tables':
				self::$db->query('show tables' /*$data*/, function ($command, $mysql) use ($connection) {
					if ($command->hasError()) {
						$error = $command->getError();
					} else {
						$results = $command->resultRows;
						$fields  = $command->resultFields;
						Gateway::sendToCurrentClient(json_encode($results));
					}
				});
				return;
			case 'phptty_run':
				self::$process_pipes = Process::run($client_id, 'htop');
				return;
			case 'phptty':
				//if(ALLOW_CLIENT_INPUT)
				fwrite(self::$process_pipes->pipes[0], $message_data['content']);
				return;
			case 'login': // Client login message format: {type: login, name: xx, room_id: 1}, added to the client, broadcast to all clients xx into the chat room

				Timer::del($_SESSION['auth_timer_id']); // delete timer if successfull

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

	public function workerman_mysql() {
		self::$db->select('ID,Sex')->from('Persons')->where('sex= :sex')->bindValues(array('sex'=>'M'))->query(); // Get all rows.
		self::$db->select('ID,Sex')->from('Persons')->where("sex='F'")->query(); // Equivalent to.
		self::$db->query("SELECT ID,Sex FROM `Persons` WHERE sex='M'"); // Equivalent to.

		self::$db->select('ID,Sex')->from('Persons')->where('sex= :sex')->bindValues(array('sex'=>'M'))->row(); // Get one row.
		self::$db->select('ID,Sex')->from('Persons')->where("sex= 'F' ")->row(); // Equivalent to.
		self::$db->row("SELECT ID,Sex FROM `Persons` WHERE sex='M'"); // Equivalent to.

		self::$db->select('ID')->from('Persons')->where('sex= :sex')->bindValues(array('sex'=>'M'))->column(); // Get a column.
		self::$db->select('ID')->from('Persons')->where("sex= 'F' ")->column(); // Equivalent to.
		self::$db->column("SELECT `ID` FROM `Persons` WHERE sex='M'"); // Equivalent to.

		self::$db->select('ID,Sex')->from('Persons')->where('sex= :sex')->bindValues(array('sex'=>'M'))->single(); // Get single.
		self::$db->select('ID,Sex')->from('Persons')->where("sex= 'F' ")->single(); // Equivalent to.
		self::$db->single("SELECT ID,Sex FROM `Persons` WHERE sex='M'"); // Equivalent to.

		self::$db->select('*')->from('table1')->innerJoin('table2','table1.uid = table2.uid')->where('age > :age')->groupBy(array('aid'))->having('foo="foo"')->orderByASC/*orderByDESC*/(array('did'))->limit(10)->offset(20)->bindValues(array('age' => 13)); // Complex query.
		self::$db->query("SELECT * FROM table1 INNER JOIN table2 ON table1.uid = table2.uid WHERE age > 13 GROUP BY aid HAVING foo='foo' ORDER BY did LIMIT 10 OFFSET 20"); // Equivalent to.

		$insert_id = self::$db->insert('Persons')->cols(['Firstname'=>'abc','Lastname'=>'efg','Sex'=>'M','Age'=>13])->query(); // Insert.
		$insert_id = self::$db->query("INSERT INTO `Persons` ( `Firstname`,`Lastname`,`Sex`,`Age`) VALUES ( 'abc', 'efg', 'M', 13)"); // Equivalent to.

		$row_count = self::$db->update('Persons')->cols(array('sex'))->where('ID=1')->bindValue('sex', 'F')->query(); // Updagte.
		$row_count = self::$db->update('Persons')->cols(array('sex'=>'F'))->where('ID=1')->query(); // Equivalent to.
		$row_count = self::$db->query("UPDATE `Persons` SET `sex` = 'F' WHERE ID=1"); // Equivalent to.

		$row_count = self::$db->delete('Persons')->where('ID=9')->query(); // Delete.
		$row_count = self::$db->query("DELETE FROM `Persons` WHERE ID=9"); // Equivalent to.

		self::$db->beginTrans(); // Transaction.
		self::$db->commitTrans(); // or self::$db->rollBackTrans();
	}
}
