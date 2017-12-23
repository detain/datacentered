// certificate will check the domain name, please use the domain name connection
ws = new WebSocket("wss://域名:4431");
ws.onopen = function() {
	alert("connect successfully");
	ws.send('tom');
	alert('send a string to the server: tom');
};
ws.onmessage = function(e) {
	alert("Received server message:" + e.data);
};

