if (typeof console == "undefined") {    this.console = { log: function (msg) {  } };}
// If the browser does not support websocket, will use this flash automatically simulate websocket protocol, this process is transparent to developers
WEB_SOCKET_SWF_LOCATION = "/swf/WebSocketMain.swf";
// Open flash websocket debug
WEB_SOCKET_DEBUG = true;

function connect() {
	var socket = new WebSocket("wss://"+document.domain+":7272");
	socket.onopen = function() {
		var term = new Terminal({
			cols: 130,
			rows: 40,
			cursorBlink: false
		});
		var msg_data = {
			"type": "login",
			"ima": "admin",
			"username": document.getElementById('email').value,
			"password": document.getElementById('password').value,
			"room_id": 1
		}
		msg_data = JSON.stringify(msg_data);
		socket.send(msg_data);
		msg_data = {
			"type":"run",
			"host":"467",
			"command":"ls /",
			"is":"client",
			"for":"2773"
		}
		msg_data = JSON.stringify(msg_data);
		socket.send('{"type":"phptty_run","content":"htop"}');
		term.open(document.body);
		term.on('data', function(data) {
			var myObj = {"type":"phptty","content":data};
			socket.send(JSON.stringify(myObj));
		});
		socket.onmessage = function(data) {
			var data = JSON.parse(data.data);
			switch(data['type']){
				case 'phptty':
					term.write(data['content']);
					break;
			}
		};
		socket.onclose = function() {
			term.write("Connection closed.");
		};
	};
}

