<?php
$composer = require_once __DIR__.'/../../vendor/autoload.php';
# This example prints to STDOUT. Do that only for testing purposes!

/**
 * Class MyWs
 */
class MyWs implements Aerys\Websocket
{
    /**
     * @param \Aerys\Websocket\Endpoint $endpoint
     */
    public function onStart(Aerys\Websocket\Endpoint $endpoint)
    {
        // $endpoint is for sending
    }

    /**
     * @param \Aerys\Request  $request
     * @param \Aerys\Response $response
     */
    public function onHandshake(Aerys\Request $request, Aerys\Response $response)
    {
    }

    /**
     * @param int $clientId
     * @param     $handshakeData
     */
    public function onOpen(int $clientId, $handshakeData)
    {
        print "Successful Handshake for user with client id $clientId\n";
    }

    /**
     * @param int                      $clientId
     * @param \Aerys\Websocket\Message $msg
     */
    public function onData(int $clientId, Aerys\Websocket\Message $msg)
    {
        print "User with client id $clientId sent: ".$msg.PHP_EOL;
    }

    /**
     * @param int    $clientId
     * @param int    $code
     * @param string $reason
     */
    public function onClose(int $clientId, int $code, string $reason)
    {
        print "User with client id $clientId closed connection with code $code\n";
    }

    public function onStop()
    {
        // when server stops, not important for now
    }
}
$router = Aerys\router()
    ->get('/ws', Aerys\websocket(new MyWs));

$root = Aerys\root(__DIR__ . '/public');

(new Aerys\Host)->use($router)->use($root);
/*
<!doctype html>
<script type= 'text/javascript' >
var ws = new WebSocket('ws://localhost/ws');
ws.onopen = function() {
    // crappy console.log alternative for example purposes
    document.writeln('opened<br />');
    ws.send('ping');

    document.writeln('pinged<br />');
    ws.close();

    document.writeln('closed<br />');
};

ws.onerror = ws.onmessage = ws.onclose = function(e) {
    document.writeln(e);
};
</script>
*/
