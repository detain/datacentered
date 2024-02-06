<?php

class DebugServer
{
    protected $alloc_point = [];
    protected $free_point = [];
    protected $index = 0;

    public function package_decode($pkg)
    {
        [$tag, $_vars] = explode(':', $pkg, 2);
        $tag = trim($tag);
        $vars = explode(',', trim($_vars));
        $data = [];
        foreach ($vars as $str) {
            [$k, $v] = explode('=', trim($str));
            $data[$k] = $v;
        }

        if ($tag == 'alloc') {
            file_put_contents(__DIR__.'/alloc.log', $data['ptr']."\n", FILE_APPEND);
        } elseif ($tag =='memory') {
            var_dump($tag, $data);
        } elseif ($tag == 'free') {
            file_put_contents(__DIR__.'/free.log', $data['ptr']."\n", FILE_APPEND);
        } elseif ($tag == 'invalid') {
            foreach ($this->alloc_point as $k => $v) {
                echo "$k => $v\n";
            }
        } else {
            //var_dump($tag, $data);
        }
    }

    public function run()
    {
        unlink(__DIR__.'/alloc.log');
        unlink(__DIR__.'/free.log');
        $socket = stream_socket_server("udp://127.0.0.1:9999", $errno, $errstr, STREAM_SERVER_BIND);
        if (!$socket) {
            die("$errstr ($errno)");
        }
        while (1) {
            $pkt = stream_socket_recvfrom($socket, 65535, 0, $peer);
            $this->package_decode($pkt);
            //echo "$peer: $pkt\n";
            //stream_socket_sendto($socket, date("D M j H:i:s Y\r\n"), 0, $peer);
        }
    }
}

$svr = new DebugServer;
$svr->run();
