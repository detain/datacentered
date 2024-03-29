<?php
class ProxyServer
{
    protected $clients;
    protected $backends;
    protected $serv;

    public function run()
    {
        $serv = new swoole_server("127.0.0.1", 9509);
        $serv->set([
            'timeout' => 1, //select and epoll_wait timeout.
            'poll_thread_num' => 1, //reactor thread num
            'worker_num' => 32, //reactor thread num
            'backlog' => 128, //listen backlog
            'max_conn' => 10000,
            'dispatch_mode' => 2,
            //'open_tcp_keepalive' => 1,
            //'log_file' => '/tmp/swoole.log', //swoole error log
        ]);
        $serv->on('WorkerStart', [$this, 'onStart']);
        $serv->on('Connect', [$this, 'onConnect']);
        $serv->on('Receive', [$this, 'onReceive']);
        $serv->on('Close', [$this, 'onClose']);
        $serv->on('WorkerStop', [$this, 'onShutdown']);
        //swoole_server_addtimer($serv, 2);
        #swoole_server_addtimer($serv, 10);
        $serv->start();
    }

    public function onStart($serv)
    {
        $this->serv = $serv;
        echo "Server: start.Swoole version is [" . SWOOLE_VERSION . "]\n";
    }

    public function onShutdown($serv)
    {
        echo "Server: onShutdown\n";
    }

    public function onClose($serv, $fd, $from_id)
    {
    }

    public function onConnect($serv, $fd, $from_id)
    {
    }

    public function onReceive($serv, $fd, $from_id, $data)
    {
        $socket = new swoole_client(SWOOLE_SOCK_TCP);
        if ($socket->connect('127.0.0.1', 80, 0.5)) {
            $socket->send($data);
            $serv->send($fd, $socket->recv(8192, 0));
        }
        unset($socket);
        $serv->close($fd);
    }
}

$serv = new ProxyServer();
$serv->run();
