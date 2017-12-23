# This example prints to STDOUT. Do that only for testing purposes!

class MyWs implements Aerys\Websocket {
	public function onStart(Aerys\Websocket\Endpoint $endpoint) {
		// $endpoint is for sending
	}

	public function onHandshake(Aerys\Request $request, Aerys\Response $response) {

	}

	public function onOpen(int $clientId, $handshakeData) {
		print "Successful Handshake for user with client id $clientId\n";
	}

	public function onData(int $clientId, Aerys\Websocket\Message $msg) {
		print "User with client id $clientId sent: " . yield $msg . "\n";
	}

	public function onClose(int $clientId, int $code, string $reason) {
		print "User with client id $clientId closed connection with code $code\n";
	}

	public function onStop() {
		// when server stops, not important for now
	}
}
$router = Aerys\router()
	->get('/ws', Aerys\websocket(new MyWs));

$root = Aerys\root(__DIR__ . "/public");

(new Aerys\Host)->use($router)->use($root);
<!doctype html>
<script type="text/javascript">
var ws = new WebSocket("ws://localhost/ws");
ws.onopen = function() {
	// crappy console.log alternative for example purposes
	document.writeln("opened<br />");
	ws.send("ping");

	document.writeln("pinged<br />");
	ws.close();

	document.writeln("closed<br />");
};

ws.onerror = ws.onmessage = ws.onclose = function(e) {
	document.writeln(e);
};
</script>
