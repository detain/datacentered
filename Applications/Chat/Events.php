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
use Workerman\Worker;
use GatewayWorker\Lib\Gateway;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;
use GlobalData\Client as GlobalDataClient;
require_once __DIR__.'/Process.php';

$process_pipes = [];

class Events {

	public static $process_handle = null;
	public static $process_pipes = null;
	public static $db = null;
	public static $db_type = 'react'; // workerman or react or blank for no sql

	public static function onWorkerStart($worker) {
		global $global;
		$global = new GlobalDataClient('127.0.0.1:2207');	 // initialize the GlobalData client
		$db_config = include __DIR__.'/../../../../include/config/config.db.php';
		if (self::$db_type == 'workerman') {
			self::$db = new \Workerman\MySQL\Connection($db_config['db_host'], $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name']);
		} else {
			$loop = Worker::getEventLoop();
			self::$db = new \React\MySQL\Connection($loop, [
				'host'   => $db_config['db_host'],
				'dbname' => $db_config['db_name'],
				'user'   => $db_config['db_user'],
				'passwd' => $db_config['db_pass'],
			]);
			self::$db->on('error', function($e){
				echo 'ERROR:'.$e.PHP_EOL;
				myadmin_log('vps', 'error', 'Got an error '.$e.' while connecting to DB', __LINE__, __FILE__);
			});
			self::$db->connect(function ($e) {
				if($e) {
					echo 'ERROR:'.$e.PHP_EOL;
					myadmin_log('vps', 'error', 'Got an error '.$e.' while connecting to DB', __LINE__, __FILE__);
				} else {
					echo "SQL connect success\n";
				}
			});
		}
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
	}

	/**
	 * When there is news
	 * @param int $client_id
	 * @param mixed $message
	 */
	public static function onMessage($client_id, $message) {
		global $process_pipes;
		echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']} client_id:{$client_id} session:".json_encode($_SESSION)." onMessage:".json_encode($message)."\n"; // debug
		$message_data = json_decode($message, true); // Client is passed json data
		if (!$message_data)
			return ;
		$connection = Events::$db;
		switch ($message_data['type']) { // Depending on the type of business
			case 'pong': // The client responds to the server's heartbeat
				if(empty($_SESSION['login'])) {
					$msg = 'You have not successfully authenticated within the allowed time, goodbye.';
					echo $msg.PHP_EOL;
					//myadmin_log('vps', 'error', $msg, __LINE__, __FILE__);
					error_log($msg);
					$new_message = [ // Send the error response
						'type' => 'error',
						'content' => $msg,
					];
					Gateway::sendToCurrentClient(json_encode($new_message));
					Gateway::closeClient($client_id);
				}
				return;
			case 'workerman_tables':
				$all_tables = self::$db->query('show tables');
				Gateway::sendToCurrentClient(json_encode($all_tables));
				$ret = self::$db->select('*')->from('users')->where('uid>3')->offset(5)->limit(2)->query();
				return Gateway::sendToClient($client_id, json_encode($ret));
			case 'react_tables':
				$connection->query('show tables' /*$data*/, function ($command, $mysql) use ($client_id) {
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
			case 'login':
				// Client login message format: {type: login, name: xx, room_id: 1}, added to the client, broadcast to all clients xx into the chat room
				// Client Types:
				//  host, admin,
				//  client, guest?  (not right now)
				$ima = isset($message_data['ima']) && in_array($message_data['ima'], ['host', 'admin']) ? $message_data['ima'] : 'admin';
				switch ($ima) {
					case 'host':
						$connection->query('select * from vps_masters where vps_ip = ?', function ($command, $conn) use ($client_id, $ima) {
							if ($command->hasError()) { //test whether the query was executed successfully
								//error
								$error = $command->getError();// get the error object, instance of Exception.
								$msg = 'Got an error '.$error->getMessage().' while connecting to DB';
								echo $msg.PHP_EOL;
								error_log('vps', 'error', $msg, __LINE__, __FILE__);
								$new_message = [ // Send the error response
									'type' => 'error',
									'content' => $msg,
								];
								Gateway::sendToCurrentClient(json_encode($new_message));
							} else {
								$results = $command->resultRows; //get the results
								if (sizeof($results) == 0) {
									//error
									$msg = 'This System '.$_SERVER['REMOTE_ADDR'].' does not appear to match up with one of our hosts.';
									echo $msg.PHP_EOL;
									myadmin_log('vps', 'error', $msg, __LINE__, __FILE__);
									$new_message = [ // Send the error response
										'type' => 'error',
										'content' => $msg,
									];
									Gateway::sendToCurrentClient(json_encode($new_message));
								} else {
									$uid = 'vps'.$results[0]['vps_id'];
									Gateway::bindUid($client_id, $uid);
									Gateway::joinGroup($client_id, $ima.'s');
									$_SESSION['row'] = $results[0];
									$_SESSION['ima'] = $ima;
									$_SESSION['login'] = true;
									//echo 'Results:';
									//var_export($results);
									//echo PHP_EOL;

									//$fields  = $command->resultFields; // get table fields
									//echo 'Fields:';
									//var_export($fields);
									//echo PHP_EOL;
								}
							}
							//$loop->stop(); //stop the main loop.
						}, [$_SERVER['REMOTE_ADDR']]);
						break;
					case 'admin':
						$connection->query('select * from accounts where account_lid = ? and account_passwd = ?', function ($command, $conn) use ($client_id, $ima) {
							if ($command->hasError()) { //test whether the query was executed successfully
								//error
								$error = $command->getError();// get the error object, instance of Exception.
								$msg = 'Got an error '.$error->getMessage().' while connecting to DB';
								echo $msg.PHP_EOL;
								myadmin_log('vps', 'error', $msg, __LINE__, __FILE__);
								$new_message = [ // Send the error response
									'type' => 'error',
									'content' => $msg,
								];
								Gateway::sendToCurrentClient(json_encode($new_message));
							} else {
								$results = $command->resultRows; //get the results
								if (sizeof($results) == 0) {
									//error
									$msg = 'Invalid Credentials Specified For User '.$mesage_data['username'];
									echo $msg.PHP_EOL;
									myadmin_log('vps', 'error', $msg, __LINE__, __FILE__);
									$new_message = [ // Send the error response
										'type' => 'error',
										'content' => $msg,
									];
									Gateway::sendToCurrentClient(json_encode($new_message));

								} else {
									$uid = $results[0]['account_id'];
									Gateway::bindUid($client_id, $uid);
									Gateway::joinGroup($client_id, $ima.'s');
									$_SESSION['row'] = $results[0];
									$_SESSION['ima'] = $ima;
									$_SESSION['login'] = true;
									//echo 'Results:';
									//var_export($results);
									//echo PHP_EOL;

									//$fields  = $command->resultFields; // get table fields
									//echo 'Fields:';
									//var_export($fields);
									//echo PHP_EOL;
								}
							}
							$loop->stop(); //stop the main loop.
						}, [$message_data['username'], md5($message_data['password'])]);
						break;
					case 'client':
					case 'guest':
					default:
						$msg = 'Invalid Login Type '.$ima.'. Check back later for "client" and "guest" support to be added in addition to the "host" and "admin" types.';
						echo $msg.PHP_EOL;
						myadmin_log('vps', 'error', $msg, __LINE__, __FILE__);
						$new_message = [ // Send the error response
							'type' => 'error',
							'content' => $msg,
						];
						Gateway::sendToCurrentClient(json_encode($new_message));
						break;
				}

				//Timer::del($_SESSION['auth_timer_id']); // delete timer if successfull
/*
				if (!isset($message_data['room_id'])) // Determine whether there is a room number
					throw new \Exception("\$message_data['room_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:{$message}");
				$room_id = $message_data['room_id']; // The room number nickname into the session
				$client_name = htmlspecialchars($message_data['name']);
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
				*/
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
			case 'bandwidth':
				foreach ($message_data['content'] as $ip => $data) {
					$rrdFile = __DIR__.'/../../../../logs/rrd'.$_SESSION['client_name'].'_'.$ip.'.rrd';
					if (!file_exists($rrdFile)) {
						@mkdir($rrdFile = __DIR__.'/../../rrd/'.$_SESSION['client_name'], 777, TRUE);
						$rrd = new RRDCreator($rrdFile, 'now', 60);
						$rrd->addDataSource('in:ABSOLUTE:60:U:U');
						$rrd->addDataSource('out:ABSOLUTE:60:U:U');
						$rrd->addArchive('AVERAGE:0.5:1:10080');
						$rrd->addArchive('MIN:0.5:1:10080');
						$rrd->addArchive('MAX:0.5:1:10080');
						$rrd->addArchive('AVERAGE:0.5:60:8760');
						$rrd->addArchive('MIN:0.5:60:8760');
						$rrd->addArchive('MAX:0.5:60:8760');
						$rrd->addArchive('AVERAGE:0.5:1440:3650');
						$rrd->addArchive('MIN:0.5:1440:3650');
						$rrd->addArchive('MAX:0.5:1440:3650');
						$rrd->save();
					}
					$updater = new RRDUpdater($rrdFile);
					$updater->update(['in' => $data['in'],'out' => $data['out']]);
				}
				return;
		}
	}

	/**
	 * When the client is disconnected
	 * @param integer $client_id client id
	 */
	public static function onClose($client_id) {
		echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']} client_id:{$client_id} onClose:''\n"; // debug
		/*
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
		*/
	}

	public static function update_vps_list_timer() {
		/*$new_message = [
			'type' => 'log',
			'content' => nl2br(htmlspecialchars('Running Update VPS List Timer')),
			'time' => date('Y-m-d H:i:s'),
		];
		Gateway::sendToAll(json_encode($new_message));*/
		$task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');								// Asynchronous link with the remote task service
		$task_connection->send(json_encode(['function' => 'async_hyperv_get_list', 'args' => []]));		// send data
		$task_connection->onMessage = function($task_connection, $task_result) use ($task_connection) {	// get the result asynchronously
			 //var_dump($task_result);
			 $task_connection->close();																	// remember to turn off the asynchronous link after getting the result
		};
		$task_connection->connect();																	// execute async link
	}

	public static function vps_queue_timer() {
		/*$new_message = [
			'type' => 'log',
			'content' => nl2br(htmlspecialchars('Running VPS Queue Timer')),
			'time' => date('Y-m-d H:i:s'),
		];
		Gateway::sendToAll(json_encode($new_message));*/
		$task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');								// Asynchronous link with the remote task service
		$task_connection->send(json_encode(['function' => 'sync_hyperv_queue', 'args' => []]));			// send data
		$task_connection->onMessage = function($task_connection, $task_result) use ($task_connection) {	// get the result asynchronously
			 //var_dump($task_result);
			 $task_connection->close();																	// remember to turn off the asynchronous link after getting the result
		};
		$task_connection->connect();																	// execute async link
	}

	/**
	 * runs a command on a given host.
	 *
	 * @param int $host the host server id to run it on
	 * @param string $cmd the command to run
	 * @param bool $interact defaults false, if true the host will open up the process for stdin and handle forwarding i/o
	 * @param mixed $for null for nobody, or a uid or reserved word to indicate how the response if any should be handled
	 * @return void
	 */
	public function run_command($host, $cmd, $interact = false, $for = null) {
		// we need to store the command locally so we can easily react proeprly if we get a response
		$uid = 'vps'.$host;
		$json = [
			'type' => 'run',
			'command' => $cmd,
			'interact' => false,
			'host' => $uid,
			'for' => $for
		];
		if (Gateway::isUidOnline($uid) == true) {
			Gateway::sendToUid($uid, json_encode($json));
		} else {
			// if they are not online then queue it up for later
		}

	}
}
