<?php
$serv = new swoole_websocket_server("0.0.0.0",3999);
// Basic service settings 
$serv->set(array(
'worker_num' => 2,
'reactor_num'=>8,
'task_worker_num'=>1,
'dispatch_mode' => 2,
'debug_mode'=> 1,
'daemonize' => true,
'log_file' => __DIR__.'/log/webs_swoole.log',
'heartbeat_check_interval' => 60,
'heartbeat_idle_time' => 600,
));
$serv->on('connect', function ($serv,$fd){
// echo "client:$fd Connect.".PHP_EOL;
});
// test receive
$serv->on("receive",function(swoole_server $serv,$fd,$from_id,$data){
// echo "receive#{$from_id}: receive $data ".PHP_EOL;
});
$serv->on('open', function($server, $req) {
// echo "server#{$server->worker_pid}: handshake success with fd#{$req->fd}".PHP_EOL;;
// echo PHP_EOL;
});
$serv->on('message',function($server,$frame) {
// echo "message: ".$frame->data.PHP_EOL;
$msg=json_decode($frame->data,true);
switch ($msg['type']){
case 'login':
$server->push($frame->fd," Welcome ~");
break;
default:
break;
}
$msg['fd']=$frame->fd;
$server->task($msg);
});
$serv->on("workerstart",function($server,$workerid){
// echo "workerstart: ".$workerid.PHP_EOL;
// echo PHP_EOL;
});
$serv->on("task","on_task");
$serv->on("finish",function($serv,$task_id,$data){
return ;
});
$serv->on('close', function($server,$fd,$from_id) {
// echo "connection close: ".$fd.PHP_EOL;
// echo PHP_EOL;
});
$serv->start();
function on_task($serv,$task_id,$from_id,$data) {
switch ($data['type']){
case 'login':
$send_msg=" say : I'm coming. ~";
break;
default:
$send_msg=" say :{$data['msg']['speak']}";
break;
}
foreach ($serv->connections as $conn){
if ($conn!=$data['fd']){
if (strpos($data['msg']['name']," Tourist ")===0){
$name=$data['msg']['name']."_".$data['fd'];
}else{
$name=$data['msg']['name'];
}
}else{
$name=" I ";
}
$serv->push($conn,$name.$send_msg);
}
return;
}
function on_finish($serv,$task_id,$data){
return true;
}
