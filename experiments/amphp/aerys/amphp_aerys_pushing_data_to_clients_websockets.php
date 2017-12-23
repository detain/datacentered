<?php
$composer = require_once __DIR__.'/../../vendor/autoload.php';

/**
 * Class MiniChat
 */
class MiniChat implements Aerys\Websocket {
	private $ws;

	/**
	 * @param \Aerys\Websocket\Endpoint $endpoint
	 */
	public function onStart(Aerys\Websocket\Endpoint $endpoint) {
		$this->ws = $endpoint;
	}

	/**
	 * @param \Aerys\Request  $request
	 * @param \Aerys\Response $response
	 */
	public function onHandshake(Aerys\Request $request, Aerys\Response $response) {
		// not important for now, can be used to check origin for example
	}

	/**
	 * @param int $clientId
	 * @param     $handshakeData
	 */
	public function onOpen(int $clientId, $handshakeData) {
		$this->ws->broadcast(null, "Welcome new client $clientId!");
	}

	/**
	 * @param int                      $clientId
	 * @param \Aerys\Websocket\Message $msg
	 * @return \Generator
	 */
	public function onData(int $clientId, Aerys\Websocket\Message $msg) {
		$text = yield $msg;
		$this->ws->send($clientId, '<i>Message received ... Sending in 5 seconds ...</i>');
		yield new Amp\Pause(5000);
		$this->ws->send(null, "Client $clientId said: $text");
	}

	/**
	 * @param int    $clientId
	 * @param int    $code
	 * @param string $reason
	 */
	public function onClose(int $clientId, int $code, string $reason) {
		$this->ws->send(null, "User with client id $clientId closed connection with code $code");
	}

	public function onStop() {
		// when server stops, not important for now
	}
}
$router = Aerys\router()
	->get('/ws', Aerys\websocket(new MiniChat))

$root = Aerys\root(__DIR__ . '/public');

(new Aerys\Host)->use($router)->use($root);
<!doctype html>
<script type= 'text/javascript' >
var ws = new WebSocket('ws://localhost/ws');
document.write('<input id="in" /><input type="submit" id="sub" /><br />');

document.getElementById('sub').onClick(function() {
	ws.send(document.getElementById('in').value);
});

ws.onopen = function() {
	// crappy console.log alternative for example purposes
	document.writeln('opened<br />');
};

ws.onmessage = function(msg) {
	document.writeln(msg.data + '<br />');
};

ws.onerror = ws.onclose = function(e) {
	document.writeln(e);
};
</script>
