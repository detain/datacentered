// 127.0.0.1 replaced by the actual workerman where ip
ws = new WebSocket("ws://127.0.0.1:4236");
ws.onmessage = function(e) {
    alert("Received server message:" + e.data);
};

// broadcast a message
ws.send('hello world');
