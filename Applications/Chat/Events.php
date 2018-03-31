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

require_once __DIR__.'/Process.php';

class Events {

	public static $process_handle = null;
	public static $process_pipes = null;
	public static $db = null;

	public static function onWorkerStart($worker) {
		//$worker->maxSendBufferSize = 102400000;
		//$worker->sendToGatewayBufferSize = 102400000;
		/**
		 * @var GlobalData\Client
		 */
		global $global;
		$global = new GlobalData\Client('127.0.0.1:2207');	 // initialize the GlobalData client
		$db_config = include __DIR__.'/../../../../include/config/config.db.php';
		$loop = Worker::getEventLoop();
		self::$db = new \Workerman\MySQL\Connection($db_config['db_host'], $db_config['db_port'], $db_config['db_user'], $db_config['db_pass'], $db_config['db_name'], 'utf8mb4');
		if ($worker->id === 0) {
			$global->running = [];
			$global->hosts = [];
			$global->rooms = [
				[
					'id' => 'room_1',
					'name' => 'General Chat',
					'img' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a6/Rubik%27s_cube.svg/220px-Rubik%27s_cube.svg.png',
					'members' => [],
					'messages' => [],
				]
			];

			Timer::add(3600, ['Events', 'hyperv_update_list_timer']);
			Timer::add(60, ['Events', 'hyperv_queue_timer']);
			Timer::add(60, ['Events', 'vps_queue_timer']);
			/*
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
			}*/
		}
	}

	public static function onWorkerStop($worker) {
		if ($worker->id == 0) {
			/*@shell_exec('killall vmstat');
			@pclose(self::process_handle);*/
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
		/**
		 * @var GlobalData\Client
		 */
		global $global;
		//echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']} client_id:{$client_id} session:".json_encode($_SESSION)." onMessage:".serialize($message)."\n"; // debug
		$message_data = json_decode($message, true); // Client is passed json data
		if (!$message_data)
			return ;
		$method = 'msg'.str_replace(' ','',ucwords(str_replace(['-','_'],[' ',' '],$message_data['type'])));
		if (method_exists('Events', $method))
			call_user_func(['Events', $method], $client_id, $message_data);
		else
			echo "Wanted to call method {$method} but it doesnt exist\n";
	}

	/**
	 * When the client is disconnected
	 * @param integer $client_id client id
	 */
	public static function onClose($client_id) {
		/**
		 * @var GlobalData\Client
		 */
		global $global;
		echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']} client_id:{$client_id} onClose:''\n"; // debug
		if (isset($_SESSION['uid'])) {
			if (isset($global->rooms) && sizeof($global->rooms) > 0) {
				$new_message = [
					'type' => 'logout',
					'id' => $_SESSION['uid'],
					'time' => date('Y-m-d H:i:s')
				];
				$rooms = $global->rooms;
				$updated = false;
				foreach ($rooms as $idx => $room) {
					if (($key = array_search($_SESSION['uid'], $room['members'])) !== false) {
						$updated = true;
						unset($room['members'][$key]);
						Gateway::sendToGroup($room['id'], json_encode($new_message));
						$rooms[$idx] = $room;
					}
				}
				if ($updated === TRUE)
					$global->rooms = $rooms;
			}
		}
	}

	public static function vps_queue_timer() {
		/**
		 * @var GlobalData\Client
		 */
		global $global;
		/**
		 * @var \React\MySQL\Connection
		 */
		$results = self::$db->select('*')->from('queue_log')->leftJoin('vps', 'vps_id=history_type')->where('history_section="vpsqueue"')->query();
		if (is_array($results) && sizeof($results) > 0) {
			$queues = [];
			foreach ($results as $results[0]) {
				if (is_numeric($results[0]['history_type'])) {
					if (is_null($results[0]['vps_id'])) {
						// no vps id in db matching, delete
					} else {
						$id = $results[0]['vps_server'];
						if (in_array($id, array_keys($global->hosts))) {
							if (!in_array($id, array_keys($queues)))
								$queues[$id] = [];
							$queues[$id][] = $results[0];
						}
					}
				} else {
					$id = str_replace('vps', '', $results[0]['history_type']);
					if (in_array($id, array_keys($global->hosts))) {
						if (!in_array($id, array_keys($queues)))
							$queues[$id] = [];
						$queues[$id][] = $results[0];
					}
				}
			}
			if (sizeof($queues) > 0) {
				foreach ($queues as $server_id => $rows) {
					$server_data = $global->hosts[$server_id];
					if ($server_id != 467) {
						echo 'Wanted To Process Queues For Server '.$server_id.' '.$server_data['vps_name'].PHP_EOL;
						continue;
					} else {
						echo 'Processing Queues For Server '.$server_id.' '.$server_data['vps_name'].PHP_EOL;
					}
					$var = 'vps_host_'.$server_id;
					if (!isset($global->$var))
						$global->$var = 0;
					if ($global->cas($var, 0, 1)) {
						$task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
						$task_connection->send(json_encode(['function' => 'vps_queue_task', 'args' => [
							'id' => $server_id,
						]]));
						$task_connection->onMessage = function($task_connection, $task_result) use ($server_id, $server_data) {
							$task_result = json_decode($task_result, true);
							//echo "Got Result ".var_export($task_result, true).PHP_EOL;
							//echo "Bandwidth Update for ".$_SESSION['name']." content: ".json_encode($message_data['content'])." returned:".var_export($task_result,TRUE).PHP_EOL;
							if (trim($task_result['return']) != '') {
								self::run_command($server_id,$task_result['return'],false,'room_1');
							}
							$task_connection->close();
						};
						$task_connection->connect();
						$global->$var = 0;
					}
				}
			}
		}
	}

	public static function hyperv_update_list_timer() {
		/*$new_message = [
			'type' => 'log',
			'content' => nl2br(htmlspecialchars('Running Update VPS List Timer')),
			'time' => date('Y-m-d H:i:s'),
		];
		Gateway::sendToAll(json_encode($new_message));*/
		$task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
		$task_connection->send(json_encode(['function' => 'async_hyperv_get_list', 'args' => []]));
		$task_connection->onMessage = function($task_connection, $task_result) use ($task_connection) {
			 //var_dump($task_result);
			 $task_connection->close();
		};
		$task_connection->connect();
	}

	public static function hyperv_queue_timer() {
		/*$new_message = [
			'type' => 'log',
			'content' => nl2br(htmlspecialchars('Running VPS Queue Timer')),
			'time' => date('Y-m-d H:i:s'),
		];
		Gateway::sendToAll(json_encode($new_message));*/
		$task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
		$task_connection->send(json_encode(['function' => 'sync_hyperv_queue', 'args' => []]));
		$task_connection->onMessage = function($task_connection, $task_result) use ($task_connection) {
			 //var_dump($task_result);
			 $task_connection->close();
		};
		$task_connection->connect();
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
	public static function run_command($host, $cmd, $interact = false, $for = null) {
		global $global;
		// we need to store the command locally so we can easily react proeprly if we get a response
		if (substr($host, 0, 3) == 'vps' && is_numeric(substr($host, 3)))
			$host = substr($host, 3);
		$uid = 'vps'.$host;
		$run_id = md5($cmd);
		$json = [
			'type' => 'run',
			'command' => $cmd,
			'id' => $run_id,
			'interact' => false,
			'host' => $uid,
			'for' => $for
		];
		$running = $global->running;
		$running[$run_id] = $json;
		$global->running = $running;
		if (Gateway::isUidOnline($uid) == true) {
			Gateway::sendToUid($uid, json_encode($json));
		} else {
			// if they are not online then queue it up for later
		}
	}

	public static function say($from, $is, $to, $content, $from_name) {
		global $global;
		echo "Saying $content from $from to $to is $is name $from_name\n";
		if ($is == 'room') {
			$new_message = [
				'type' => 'say',
				'from' => $from,
				'is' => $is,
				'to' => $to,
				'content' => nl2br(htmlspecialchars($content)),
				'time' => date('Y-m-d H:i:s'),
			];
			$rooms = $global->rooms;
			$rooms[0]['messages'][] = [
				'from_id' => $from,
				'from_name' => $from_name,
				'content' => nl2br(htmlspecialchars($content)),
				'time' => date('Y-m-d H:i:s'),
			];
			$global->rooms = $rooms;
			return Gateway::sendToGroup($to, json_encode($new_message));
		} else {
			$new_message = [
				'type' => 'say',
				'from' => $from,
				'is' => $is,
				'to' => $to,
				'content' => nl2br(htmlspecialchars($content)),
				'time' => date('Y-m-d H:i:s'),
			];
			return Gateway::sendToUid($to, json_encode($new_message));
		}
	}

	/**
	 * handler for when receiving a self-update message.
	 *
	 * @param int $client_id
	 * @param array $message_data
	 */
	public static function msgSelfUpdate($client_id, $message_data) {
		if ($_SESSION['login'] == TRUE && $_SESSION['ima'] == 'admin') {
			Gateway::sendToGroup('hosts', $message);
		}
		return;
	}

	/**
	 * handler for when receiving a bandwidth message.
	 *
	 * @param int $client_id
	 * @param array $message_data
	 */
	public static function msgBandwidth($client_id, $message_data) {
		if (!is_array($message_data['content'])) {
			echo "error with bandwidth content " . var_export($message_data['content'], true).PHP_EOL;
			return;
		}
		$task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
		$task_connection->send(json_encode([
			'function' => 'bandwidth',
			'args' => [
				'name' => $_SESSION['name'],
				'uid' => $_SESSION['uid'],
				'content' => $message_data['content']
			]
		]));
		$task_connection->onMessage = function($task_connection, $task_result) use ($message_data) {
			//echo "Bandwidth Update for ".$_SESSION['name']." content: ".json_encode($message_data['content'])." returned:".var_export($task_result,TRUE).PHP_EOL;
			$task_connection->close();
		};
		$task_connection->connect();
		return;
	}

	/**
	 * handler for when receiving a clients message.
	 *
	 * @param int $client_id
	 * @param array $message_data
	 */
	public static function msgClients($client_id, $message_data) {
		if ($_SESSION['login'] == TRUE && $_SESSION['ima'] == 'admin') {
			$sessions = Gateway::getAllClientSessions();
			$clients = [];
			foreach ($sessions as $session_id => $session_data) {
				if (isset($session_data['uid'])) {
					$client = [
						'id' => $session_data['uid'],
						'name' => $session_data['name'],
						'ima' => $session_data['ima'],
						'online' => $session_data['online'],
						'messages' => [],
					];
					if ($session_data['ima'] == 'host') {
						$client['type'] = $session_data['type'];
					} else
						$client['img'] = $session_data['img'];
					$clients[] = $client;
				}
			}
			$rooms = $global->rooms;
			foreach ($rooms as $room) {
				$members = [];
				foreach ($room['members'] as $member)
					$members[] = ['contact' => $member];
				$room['members'] = $members;
				$clients[] = $room;
			}
			$new_message = [ // Send the error response
				'type' => 'clients',
				'content' => $clients,
			];
			echo "Loaded Clients, Request Length:".strlen(json_encode($new_message)).PHP_EOL;
			Gateway::sendToCurrentClient(json_encode($new_message));
		}
		return;
	}

	/**
	 * handler for when receiving a say message.
	 *
	 * @param int $client_id
	 * @param array $message_data
	 */
	public static function msgSay($client_id, $message_data) {
		if ($_SESSION['login'] == TRUE) {
			// client speaks message: {type:say, is: client|room, to:xx, content:xx}
			if (!isset($message_data['to'])) // illegal request
				throw new \Exception("\$message_data['to'] not set. client_ip:{$_SERVER['REMOTE_ADDR']}");
			if (!isset($message_data['is'])) // illegal request
				throw new \Exception("\$message_data['is'] not set. client_ip:{$_SERVER['REMOTE_ADDR']}");
			if (!isset($message_data['content'])) // illegal request
				throw new \Exception("\$message_data['content'] not set. client_ip:{$_SERVER['REMOTE_ADDR']}");
			return self::say($_SESSION['uid'], $message_data['is'], $message_data['to'], $message_data['content'], $_SESSION['name']);
		}
		return;
	}

	/**
	 * handler for when receiving a phptty_run message.
	 *
	 * @param int $client_id
	 * @param array $message_data
	 */
	public static function msgPhpttyRun($client_id, $message_data) {
		if ($_SESSION['login'] == TRUE && $_SESSION['ima'] == 'admin') {
			self::$process_pipes = Process::run($client_id, 'htop');
		}
		return;
	}

	/**
	 * handler for when receiving a phptty_run message.
	 *
	 * @param int $client_id
	 * @param array $message_data
	 */
	public static function msgPhptty($client_id, $message_data) {
		if ($_SESSION['login'] == TRUE && $_SESSION['ima'] == 'admin') {
			//if(ALLOW_CLIENT_INPUT)
			fwrite(self::$process_pipes->pipes[0], $message_data['content']);
		}
		return;
	}



	/**
	 * handler for when receiving a pong message.
	 *
	 * @param int $client_id
	 * @param array $message_data
	 */
	public static function msgPong($client_id, $message_data) {
		if(empty($_SESSION['login'])) {
			$msg = 'You have not successfully authenticated within the allowed time, goodbye.';
			echo $msg.PHP_EOL;
			//error_log($msg);
			error_log($msg);
			$new_message = [ // Send the error response
				'type' => 'error',
				'content' => $msg,
			];
			Gateway::sendToCurrentClient(json_encode($new_message));
			Gateway::closeClient($client_id);
		}
		return;
	}

	/**
	 * handler for when receiving a run message.
	 *
	 * @param int $client_id
	 * @param array $message_data
	 */
	public static function msgRun($client_id, $message_data) {
		echo "Got Run Command ".json_encode($message_data).PHP_EOL;
		if ($_SESSION['login'] == TRUE) {
			if ($_SESSION['ima'] == 'admin') {
				self::run_command($message_data['host'], $message_data['command'], false, $_SESSION['uid']);
				return;
			} else {

			}
		}
		echo "But not running it\n";
		return;
	}

	/**
	 * handler for when receiving a running message.
	 *
	 * @param int $client_id
	 * @param array $message_data
	 */
	public static function msgRunning($client_id, $message_data) {
		//echo "Got Running Command ".json_encode($message_data).PHP_EOL;
		if ($_SESSION['login'] == TRUE) {
			if ($_SESSION['ima'] == 'admin') {
				// stdin to send along
				$json = [
				];
			} else {
				// stdout or stderr to display
				$id = $message_data['id'];
				$running = $global->running;
				//print_r($running);
				$run = $running[$id];
				$message = '';
				if (isset($message_data['stdout']) && trim($message_data['stdout']) != '')
					$message .= PHP_EOL.'StdOut:'.$message_data['stdout'];
				if (isset($message_data['stderr']) && trim($message_data['stderr']) != '')
					$message .= PHP_EOL.'StdErr:'.$message_data['stderr'];
				return self::say($_SESSION['uid'], substr($run['for'], 0, 1) == '#' ? 'room' : 'client', $run['for'], $message, $_SESSION['name']);
			}
		}
		return;
	}

	/**
	 * handler for when receiving a ran message.
	 *
	 * @param int $client_id
	 * @param array $message_data
	 */
	public static function msgRan($client_id, $message_data) {
		//echo "Got Ran Command ".json_encode($message_data).PHP_EOL;
		// indicates both completion of a run process and its final exit code or terminal signal
		// response(s) from a run command
		/* $message_data = [
				'type' => 'ran',
				'id' => $message_data['id'],
				// it contains stderr output
				'stderr' => $stderr,
				// it containts stdout output
				'stdout' => $stdout,
				// it finished, if term === null then it exited with 'code', otehrwise terminated with signal 'term'
				'code' => $exitCode,
				'term' => $termSignal,
		]; */
		$id = $message_data['id'];
		$running = $global->running;
		$run = $running[$id];
		$is = substr($run['for'], 0, 1) == '#' ? 'room' : 'client';
		unset($running[$id]);
		$global->running = $running;
		$message = 'Finished Running'.PHP_EOL;
		if (isset($message_data['stdout']) && trim($message_data['stdout']) != '')
			$message .= PHP_EOL.'StdOut:'.$message_data['stdout'];
		if (isset($message_data['stderr']) && trim($message_data['stderr']) != '')
			$message .= PHP_EOL.'StdErr:'.$message_data['stderr'];
		if ($message_data['term'] === NULL)
			$message .= PHP_EOL.'Exited With Error Code '.$message_data['code'];
		else
			$message .= PHP_EOL.'Terminated With Signal '.$message_data['term'];
		return self::say($_SESSION['uid'], $is, $run['for'], $message, $_SESSION['name']);
	}

	/**
	 * handler for when receiving a login message.
	 *
	 * @param int $client_id
	 * @param array $message_data
	 */
	public static function msgLogin($client_id, $message_data) {
		$ima = isset($message_data['ima']) && in_array($message_data['ima'], ['host', 'admin']) ? $message_data['ima'] : 'admin';
		//echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']} client_id:{$client_id} session:".json_encode($_SESSION)." onMessage:".serialize($message)."\n"; // debug
		switch ($ima) {
			case 'host':
				$row = self::$db->select('*')->from('vps_masters')->where('vps_ip= :vps_ip')->bindValues(array('vps_ip'=>$_SERVER['REMOTE_ADDR']))->row();
				if ($row === FALSE) {
					//error
					$msg = 'This System '.$_SERVER['REMOTE_ADDR'].' does not appear to match up with one of our hosts.';
					echo $msg.PHP_EOL;
					error_log($msg);
					$new_message = [ // Send the error response
						'type' => 'error',
						'content' => $msg,
					];
					return Gateway::sendToCurrentClient(json_encode($new_message));
				}
				/**
				 * @var GlobalData\Client
				 */
				global $global;
				$uid = 'vps'.$row['vps_id'];
				$_SESSION['uid'] = $uid;
				$_SESSION['module'] = 'vps';
				$_SESSION['name'] = $row['vps_name'];
				$_SESSION['ima'] = $ima;
				$_SESSION['ip'] = $row['vps_ip'];
				$_SESSION['type'] = $row['vps_type'];
				$_SESSION['online'] = date('Y-m-d H:i:s');
				$_SESSION['login'] = true;
				$hosts = $global->hosts;
				$hosts[$row['vps_id']] = $row;
				$global->hosts = $hosts;
				Gateway::setSession($client_id, $_SESSION);
				Gateway::bindUid($client_id, $uid);
				Gateway::joinGroup($client_id, $ima.'s');
				echo "{$row['vps_name']} has been successfully logged in from {$_SERVER['REMOTE_ADDR']}\n";
				$new_message = [ // Send the error response
					'type' => 'login',
					'id' => $uid,
					'ip' => $row['vps_ip'],
					'img' => $row['vps_type'],
					'name' => $row['vps_name'],
					'ima' => $ima,
					'online' => time(),
				];
				return Gateway::sendToGroup('admins', json_encode($new_message));
				break;
			case 'admin':
				$results = self::$db->query('select accounts.*, account_value from accounts left join accounts_ext on accounts.account_id=accounts_ext.account_id and accounts_ext.account_key="picture" where account_ima="admin" and account_lid="'.$message_data['username'].'" and account_passwd="'.md5($message_data['password']).'"');
				if ($results[0] === FALSE) {
					//error
					$msg = 'Invalid Credentials Specified For User '.$mesage_data['username'];
					echo $msg.PHP_EOL;
					error_log($msg);
					$new_message = [ // Send the error response
						'type' => 'error',
						'content' => $msg,
					];
					return Gateway::sendToCurrentClient(json_encode($new_message));
				}
				$uid = $results[0]['account_id'];
				$_SESSION['uid'] = $uid;
				$_SESSION['name'] = $results[0]['account_lid'];
				$_SESSION['ima'] = $ima;
				$_SESSION['online'] = date('Y-m-d H:i:s');
				$_SESSION['img'] = is_null($results[0]['account_value']) ? 'https://secure.gravatar.com/avatar/'.md5(strtolower(trim($results[0]['account_lid']))).'?s=80&d=identicon&r=x' : $results[0]['account_value'];
				$_SESSION['login'] = true;
				Gateway::setSession($client_id, $_SESSION);
				Gateway::bindUid($client_id, $uid);
				Gateway::joinGroup($client_id, $ima.'s');
				$rooms = $global->rooms;
				if (!in_array($uid, $rooms[0]['members']))
					$rooms[0]['members'][] = $uid;
				$global->rooms = $rooms;
				echo "{$results[0]['account_lid']} has been successfully logged in from {$_SERVER['REMOTE_ADDR']}\n";
				$new_message = [ // Send the error response
					'type' => 'login',
					'id' => $uid,
					'email' => $results[0]['account_lid'],
					'name' => $results[0]['account_name'],
					'ima' => $ima,
					'online' => time(),
					'img' => is_null($results[0]['account_value']) ? 'https://secure.gravatar.com/avatar/'.md5(strtolower(trim($results[0]['account_lid']))).'?s=80&d=identicon&r=x' : $results[0]['account_value'],
				];
				return Gateway::sendToGroup('admins', json_encode($new_message));
				break;
			case 'client':
			case 'guest':
			default:
				$msg = 'Invalid Login Type '.$ima.'. Check back later for "client" and "guest" support to be added in addition to the "host" and "admin" types.';
				echo $msg.PHP_EOL;
				error_log($msg);
				$new_message = [ // Send the error response
					'type' => 'error',
					'content' => $msg,
				];
				Gateway::sendToCurrentClient(json_encode($new_message));
				break;
		}
		return;

	}

}


