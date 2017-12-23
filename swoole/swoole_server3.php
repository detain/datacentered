<?php
/**
 * @param \swoole_server $server
 */
function my_onStart(swoole_server $server) {
	global $argv;
	swoole_set_process_name("php {$argv[0]}: master");
	echo "MasterPid={$server->master_pid}|Manager_pid={$server->manager_pid}\n";
	echo 'Server: start.Swoole version is [' .SWOOLE_VERSION."]\n";
	//$server->addtimer(5000);
}

/**
 * @param $msg
 */
function my_log($msg) {
	echo '#' .posix_getpid()."\t".$msg.PHP_EOL;
}

/**
 * @param $server
 */
function my_onShutdown($server) {
	echo "Server: onShutdown\n";
}

/**
 * @param $server
 * @param $interval
 */
function my_onTimer($server, $interval) {
	my_log("Server:Timer Call.Interval=$interval");
}

/**
 * @param $server
 * @param $fd
 * @param $from_id
 */
function my_onClose($server, $fd, $from_id) {
	//my_log("Client[$fd@$from_id]: fd=$fd is closed");
}

/**
 * @param $server
 * @param $fd
 * @param $from_id
 */
function my_onConnect($server, $fd, $from_id) {
	//throw new Exception("hello world");
	//echo "Client[$fd@$from_id]: Connect.\n";
}

/**
 * @param $server
 * @param $worker_id
 */
function my_onWorkerStart($server, $worker_id) {
	global $argv;
	if($worker_id >= $server->setting['worker_num']) {
		swoole_set_process_name("php {$argv[0]}: task_worker");
	} else {
		swoole_set_process_name("php {$argv[0]}: worker");
	}
	echo "WorkerStart: MasterPid={$server->master_pid}|Manager_pid={$server->manager_pid}";
	echo "|WorkerId={$server->worker_id}|WorkerPid={$server->worker_pid}\n";

	//if ($worker_id == 1)
	//{
	//	$server->addtimer(2000); //500ms
	//	$server->addtimer(6000); //500ms
	//}
}

/**
 * @param $server
 * @param $worker_id
 */
function my_onWorkerStop($server, $worker_id) {
	echo "WorkerStop[$worker_id]|pid=".posix_getpid().".\n";
}

/**
 * @param \swoole_server $server
 * @param                $fd
 * @param                $from_id
 * @param                $data
 */
function my_onReceive(swoole_server $server, $fd, $from_id, $data) {
	my_log("received: $data");
	$cmd = trim($data);
	if($cmd == 'reload')
	{
		$server->reload($server);
	}
	elseif($cmd == 'task')
	{
		$task_id = $server->task('hello world');
		echo "Dispath AsyncTask: id=$task_id\n";
	}
	elseif($cmd == 'taskwait')
	{
		$result = $server->taskwait('hello world', 2);
		echo "SyncTask: result=$result\n";
	}
	elseif($cmd == 'info')
	{
		$info = $server->connection_info($fd);
		$server->send($fd, 'Info: '.var_export($info, true).PHP_EOL);
	}
	elseif($cmd == 'broadcast')
	{
		$start_fd = 0;
		while(true)
		{
			$conn_list = $server->connection_list($start_fd, 10);
			if($conn_list === false)
			{
				break;
			}
			$start_fd = end($conn_list);
			foreach($conn_list as $conn)
			{
				if($conn === $fd) continue;
				$server->send($conn, "hello from $fd\n");
			}
		}
	}
	//Here deliberately call a function that does not exist
	elseif($cmd == 'error')
	{
		hello_no_exists();
	}
	// close fd
	elseif(mb_substr($cmd, 0, 5) == 'close')
	{
		$close_fd = mb_substr($cmd, 6);
		$server->close($close_fd);
	}
	elseif($cmd == 'shutdown')
	{
		$server->shutdown();
	} else {
		$server->send($fd, 'Swoole: '.$data, $from_id);
		//$server->close($fd);
	}
	//echo "Client:Data. fd=$fd|from_id=$from_id|data=$data";
	//$server->deltimer(800);
	//swoole_server_send($server, $other_fd, "Server: $data", $other_from_id);
}

/**
 * @param \swoole_server $server
 * @param                $task_id
 * @param                $from_id
 * @param                $data
 * @return string
 */
function my_onTask(swoole_server $server, $task_id, $from_id, $data) {
	echo 'AsyncTask[PID=' .posix_getpid()."]: task_id=$task_id.".PHP_EOL;
	return 'Task OK';
}

/**
 * @param \swoole_server $server
 * @param                $task_id
 * @param                $data
 */
function my_onFinish(swoole_server $server, $task_id, $data) {
	echo "AsyncTask Finish: result={$data}. PID=".posix_getpid().PHP_EOL;
}

/**
 * @param \swoole_server $server
 * @param                $worker_id
 * @param                $worker_pid
 * @param                $exit_code
 */
function my_onWorkerError(swoole_server $server, $worker_id, $worker_pid, $exit_code) {
	echo "worker abnormal exit. WorkerId=$worker_id|Pid=$worker_pid|ExitCode=$exit_code\n";
}

function my_onRequest($request, $response) { 
	echo "hello world\n"; 
}


$server = new swoole_server("127.0.0.1", 9501, SWOOLE_BASE, SWOOLE_SOCK_TCP);
//$server = new swoole_server("0.0.0.0", 9501, SWOOLE_PROCESS, SWOOLE_SOCK_TCP | SWOOLE_SSL);
$server->set([
	//'chroot' => '/data/server/', // Redirect the root path of worker process. This configuration is to separate the operation to the file system in the worker process from the real file system.
	'worker_num' => 4, // The number of worker processes. If the code of logic is asynchronous and non-blocking, set the worker_num to the value from one times to four times of cpu cores. If the code of logic is synchronous and blocking, set the worker_num according to the consuming time of request and load of os.
	'daemonize' => true, // Daemonize the swoole server process. If the value of daemonize is more than 1, the swoole server will be daemonized. The program which wants to run long time must enable this configuration. If the daemonize has been enabled, the standard output and error of the program will be redirected to logfile. And if the configuration of log_file hasn't been setted, the standard output and error of the program will be redirected to /dev/null. After daemonizing the processes, the value of CWD will change and the relative path of file will be different. So it must use absolute path in the program.
	'backlog' => 128, // The configuration is an integer representing the number of pending connections that waits for accept.
	//'open_eof_check' => true, // This configuration is to check the package eof. The package eof is setted by the configuration package_eof. If this configuration has been enabled, the swoole will check the end of data from the client. If the end of data from client is the string setted by package_eof, the swoole will send the data to the worker process otherwise the swoole will continue receive and joint the data from client.
	//'package_eof' => "\r\n", // Set the end string of package. This configuration should work with open_eof_check. The max length of this string is 8 bytes.
	'task_worker_num' => 2, // the number of task worker process. To enable this parameter, it needs to register the callback function of task event and finish event. The task worker process is synchronous and blocking. According to the speed of sending task and handling task, set a reasonable value of task_worker_num.
	//'task_ipc_mode' => 1, // Set the communication mode between the task worker process and worker process.  1, default mode, use unix socket to communicate  2, use the message queue to communicate  3, use the message queue to communicate and set the mode to competition the difference between mode 2 and 3 : mode 2 supports the feature of sending task to a specified task worker process by $serv->task($data, $task_worker_id) while the mode 3 don't support to specify task worker process. The message queue uses the memory queue provided by os to store the data. If the configuration mssage_queue_key hasn't been seted, the message queue would use the private queue and this private queue would been deleted after the close of swoole server. If the configuration mssage_queue_key has been seted, the data of message queue would not be deleted and the swoole server could get the data after restart. Use ipcrm -q message_queue_id to delete the data of message queue
	'dispatch_mode' => 1,
	//'log_file' => '/tmp/swoole.log', // Set the log path of swoole. Tips: In the log file, there are some labels to distinguish the thread or process that output the log item.  # Master process     $ Manager process   * Worker process    ^ Task worker process     Reopen the log file: If the log file has been mv or unlink, the log can't be recorded normally. In this situation, you can send SIGRTMIN
	//'heartbeat_idle_time' => 10, // This configuration which works with the heartbeat_check_interval stands for the max idle time of connection.
	//'heartbeat_check_interval' => 10, // This configuration heartbeat_check_interval is the interval of polling every TCP connection. If the connection hasn't sent any data to the server in the last interval of heartbeat_check_interval, the connection will be closed. The swoole server would not send the heartbeat packet to the client but only wait for the heartbeat packet from the client. The heartbeat check of swoole server only check the last time of sending data from the client. If the time exceeds heartbeat_check_interval, the connection between the server and the client will be closed.
	//'ssl_method' => SWOOLE_SSLv3_CLIENT_METHOD, // this configuration is available for the swoole whose version is higher than 1.7.20
	//'ssl_cert_file' => __DIR__ . '/config/ssl.cert',
	//'ssl_key_file' => __DIR__ . '/config/ssl.key',
	//'ssl_ciphers' => 'ALL:!ADH:!EXPORT56:RC4+RSA:+HIGH:+MEDIUM:+LOW:+SSLv2:+EXP', // the swoole use the default algorithm when the `ssl_ciphers` is empty
	//'open_websocket_protocol' => true, // Enable the process of websocket protocal. The swoole will enable this configuration open_http_protocol automatically if the configuration open_websocket_protocol has been enabled. The swoole_websocket_server will enable this configuration automaticly.
	//'open_http2_protocol' => true, // Enable the process of http2 protocal.
]);
$server->on('Request', "my_onRequest");
$server->on('Connect', 'my_onConnect');
$server->on('Receive', 'my_onReceive');
$server->on('Close', 'my_onClose');
$server->on('Start', 'my_onStart');
$server->on('Connect', 'my_onConnect');
$server->on('Receive', 'my_onReceive');
$server->on('Close', 'my_onClose');
$server->on('Shutdown', 'my_onShutdown');
$server->on('Timer', 'my_onTimer');
$server->on('WorkerStart', 'my_onWorkerStart');
$server->on('WorkerStop', 'my_onWorkerStop');
$server->on('Task', 'my_onTask');
$server->on('Finish', 'my_onFinish');
$server->on('WorkerError', 'my_onWorkerError');
$server->on('ManagerStart', function($server) {
	global $argv;
	swoole_set_process_name("php {$argv[0]}: manager");
});
$server->start();
echo 'Manager PID:'.$server->manager_pid.PHP_EOL; // PID of manager process, send SIGUSR1 to this process to reload the application
echo 'Master PID:'.$server->master_pid.PHP_EOL;  // PID of master process, send SIGTERM signal to this process to shutdown the server
echo 'Connections:'.$server->connections.PHP_EOL; // The connections established
