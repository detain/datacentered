<?php

use \GatewayWorker\Lib\Gateway;
use \Workerman\Connection\TcpConnection;

class Process
{
    public $client_id;
    public $cmd = '';
    public $fd;
    public $tag = '';
    public $process_stdout;
    public $process_stdin;
    public $process_stderr;
    // To do this, PHP_CAN_DO_PTS must be enabled. See ext/standard/proc_open.c in PHP directory.
    public $descriptorspec = [
        0 => ['pty'],
        1 => ['pty'],
        2 => ['pty']
    ];
    //Pipe can not do PTY. Thus, many features of PTY can not be used. e.g. sudo, w3m, luit, all C programs using termios.h, etc.
    /* public $descriptorspec = [
        0 => ['pipe','r'],
        1 => ['pipe','w'],
        2 => ['pipe','w']
    ]; */
    public $pipes;
    public $env;

    public function __construct($client_id, $cmd, $tag = '')
    {
        $this->client_id = $client_id;
        $this->cmd = $cmd;
        $this->tag = $tag;
        $this->env = [
            'TERM' => 'xterm',
            'COLUMNS' => 80,
            'LINES' => 24
        ];
    }

    public function start_process()
    {
        $this->fd = proc_open($this->cmd, $this->descriptorspec, $this->pipes, null, $this->env);
        stream_set_blocking($this->pipes[0], 0);
        $this->process_stdout = new TcpConnection($this->pipes[1]);
        $this->process_stdout->onMessage = function ($process_connection, $data) {
            Gateway::sendToClient($this->client_id, json_encode(['type' => 'phptty', 'content' => $data]));
        };
        $this->process_stdout->onClose = function ($process_connection) {
            Gateway::closeClient($this->client_id);  // Close WebSocket connection on process exit.
        };
        $this->process_stdin = new TcpConnection($this->pipes[2]);
        $this->process_stdin->onMessage = function ($process_connection, $data) {
            Gateway::sendToClient($this->client_id, json_encode(['type' => 'phptty', 'content' => $data]));
        };
        return $this;
    }

    public static function run($client_id, $cmd)
    {
        $process = new Process($client_id, $cmd);
        $process->start_process();
        return $process;
    }
}
