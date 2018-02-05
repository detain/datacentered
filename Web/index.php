<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>PHP multi-process + Websocket (HTML5 / Flash) + PHP Socket real-time push technology</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link href="//my.interserver.net/bower_components/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="//my.interserver.net/bower_components/bootstrap/dist/css/bootstrap-theme.min.css" rel="stylesheet">
	<link href="//my.interserver.net/bower_components/font-awesome/css/font-awesome.min.css " rel="stylesheet">
	<link href="css/jquery-sinaEmotion-2.1.0.min.css" rel="stylesheet">
	<link href="css/vmstat.css" rel="stylesheet">
	<link href="css/chat.css" rel="stylesheet">
	<script src="//my.interserver.net/bower_components/jquery-1.11.x/dist/jquery.min.js" type="text/javascript"></script>
	<script src="//my.interserver.net/bower_components/bootstrap/dist/js/bootstrap.min.js" type="text/javascript"></script>
	<script src="//my.interserver.net/bower_components/sugar/release/sugar.min.js" type="text/javascript"></script>
	<script src="//my.interserver.net/lib/ReconnectingWebSocket/reconnecting-websocket.min.js" type="text/javascript"></script>
	<script src="//my.interserver.net/bower_components/smoothie/smoothie.js" type="text/javascript"></script>
	<script src="//my.interserver.net/bower_components/chroma-js/chroma.min.js" type="text/javascript"></script>
	<script src="js/swfobject.js" type="text/javascript"></script>
	<script src="js/web_socket.js" type="text/javascript"></script>
	<script src="js/vmstat.js" type="text/javascript"></script>
	<script src="js/jquery-sinaEmotion-2.1.0.min.js" type="text/javascript"></script>
	<script>
if (typeof console == "undefined") {    this.console = { log: function (msg) {  } };}
// If the browser does not support websocket, will use this flash automatically simulate websocket protocol, this process is transparent to developers
WEB_SOCKET_SWF_LOCATION = "/swf/WebSocketMain.swf";
// Open flash websocket debug
WEB_SOCKET_DEBUG = true;
var ws, name, client_list={}, roomId;

// Connect to the server
function connect() {
	ws = new ReconnectingWebSocket("wss://"+document.domain+":7272"); // create websocket
	//ws = new WebSocket("wss://"+document.domain+":7272");
	ws.onopen = onopen; // When the socket connection is open, enter the user name
	ws.onmessage = onmessage; // When there is a message according to the type of message shows different information
	ws.onclose = function() {
		console.log("Connection is closed, timing reconnection");
		connect();
	};
	ws.onerror = function() {
		console.log("An error occurred");
	};
}

// When there is a message according to the type of message shows different information......
function onopen() {
	if(!name) {
		show_prompt();
	}
	roomId = "<?php echo isset($_GET['room_id']) ? $_GET['room_id'] : 1?>";
	var login_data = '{"type":"login","client_name":"'+name.replace(/"/g, '\\"')+'","room_id":"'+roomId+'"}'; // log in
	console.log("websocket handshake successfully, send login data: "+login_data);
	ws.send(login_data);
	if (roomId == "phptty") {
		var term = new Terminal({
			cols: 130,
			rows: 40,
			cursorBlink: false
		});
		ws.send('{"type":"phptty_run","content":"htop"}');
		term.open(document.body);
		term.on('data', function(data) {
			var myObj = {"type":"phptty","content":data};
			ws.send(JSON.stringify(myObj));
		});
	}
}

// When the server sends a message
function onmessage(e) {
	console.log(e.data);
	var data = JSON.parse(e.data);
	switch(data['type']){
		case 'ping': // Server ping client
			ws.send('{"type":"pong"}');
			break;;
		case 'login': // Log in to update the user list
			//{"type":"login","client_id":xxx,"client_name":"xxx","client_list":"[...]","time":"xxx"}
			say(data['client_id'], data['client_name'],  data['client_name']+' Joined the chat room', data['time']);
			if(data['client_list']) {
				client_list = data['client_list'];
			} else {
				client_list[data['client_id']] = data['client_name'];
			}
			flush_client_list();
			console.log(data['client_name']+" login successful");
			break;
		case 'say': // speaking
			//{"type":"say","from_client_id":xxx,"to_client_id":"all/client_id","content":"xxx","time":"xxx"}
			say(data['from_client_id'], data['from_client_name'], data['content'], data['time']);
			break;
		case 'log':
			console.log(data['content'])
			say(0, 'Server', data['content'], data['time']);
			break;
		case 'phptty':
			term.write(data['content']);
			break;
		case 'vmstat':
			receiveStats(data['content']);
			break;
		// User exits to update user list
		case 'logout':
			//{"type":"logout","client_id":xxx,"time":"xxx"}
			say(data['from_client_id'], data['from_client_name'], data['from_client_name']+' Quit', data['time']);
			delete client_list[data['from_client_id']];
			flush_client_list();
	}
}

// enter your name
function show_prompt(){
	name = prompt('Enter your name:', '');
	if(!name || name=='null'){
		name = 'Guests';
	}
}

// submit the conversation
function onSubmit() {
	var input = document.getElementById("textarea");
	var to_client_id = $("#client_list option:selected").attr("value");
	var to_client_name = $("#client_list option:selected").text();
	ws.send('{"type":"say","to_client_id":"'+to_client_id+'","to_client_name":"'+to_client_name+'","content":"'+input.value.replace(/"/g, '\\"').replace(/\n/g,'\\n').replace(/\r/g, '\\r')+'"}');
	input.value = "";
	input.focus();
}

// Refresh user list box
function flush_client_list(){
	var userlist_window = $("#userlist");
	var client_list_slelect = $("#client_list");
	userlist_window.empty();
	client_list_slelect.empty();
	userlist_window.append('<h4>Online Users</h4><ul>');
	client_list_slelect.append('<option value="all" id="cli_all">Everyone</option>');
	for(var p in client_list){
		userlist_window.append('<li id="'+p+'" class="bounceInDown"><a href="#" class="clearfix"><img src="https://bootdey.com/img/Content/user_3.jpg" alt="" class="img-circle"><div class="friend-name"><strong>'+client_list[p]+'</strong></div><div class="last-message text-muted">Lorem ipsum dolor sit amet.</div><small class="time text-muted">Yesterday</small><small class="chat-alert text-muted"><i class="fa fa-reply"></i></small></a></li>');
		//userlist_window.append('<li id="'+p+'">'+client_list[p]+'</li>');
		client_list_slelect.append('<option value="'+p+'">'+client_list[p]+'</option>');
	}
	$("#client_list").val(select_client_id);
	userlist_window.append('</ul>');
}

// Speaking
function say(from_client_id, from_client_name, content, time){
	// Analysis of Sina microblogging picture
	content = content.replace(/(http|https):\/\/[\w]+.sinaimg.cn[\S]+(jpg|png|gif)/gi, function(img) {
		return "<a target='_blank' href='"+img+"'>"+"<img src='"+img+"'>"+"</a>";}
	);
	// resolve the url
	content = content.replace(/(http|https):\/\/[\S]+/gi, function(url) {
		if(url.indexOf(".sinaimg.cn/") < 0)
			return "<a target='_blank' href='"+url+"'>"+url+"</a>";
		else
			return url;
		}
	);
	$("#dialog").append('<li class="left clearfix"><span class="chat-img pull-left"><img src="https://bootdey.com/img/Content/user_3.jpg?'+from_client_id+'" alt="User Avatar"></span><div class="chat-body clearfix"><div class="header"><strong class="primary-font">'+from_client_name+'</strong><small class="pull-right text-muted"><i class="fa fa-clock-o"></i> '+time+'</small></div><p>'+content+'</p></div></li>').parseEmotion();
	//$("#dialog").append('<div class="speech_item"><img src="http://lorempixel.com/38/38/?'+from_client_id+'" class="user_icon" /> '+from_client_name+' <br> '+time+'<div style="clear:both;"></div><p class="triangle-isosceles top">'+content+'</p> </div>').parseEmotion();
}

$(function() {
	select_client_id = 'all';
	$("#client_list").change(function(){
		select_client_id = $("#client_list option:selected").attr("value");
	});
	$('.face').click(function(event){
		$(this).sinaEmotion();
		event.stopPropagation();
	});
});
	</script>
</head>
<body onload="connect();">
	<div class="container">
		<div class="row">
			<div class="col-sm-3 bg-white ">
				<div class=" row border-bottom padding-sm" style="height: 40px;">Member</div>
				<!-- member list -->
				<ul class="friend-list" id="userlist"></ul>
				<div>
					&nbsp;&nbsp;&nbsp;&nbsp;<b>Room List:</b>(Currently in &nbsp; room<?php echo isset($_GET['room_id'])&&intval($_GET['room_id'])>0 ? intval($_GET['room_id']):1; ?>）<br>
					&nbsp;&nbsp;&nbsp;&nbsp;<a href="/?room_id=1">Room 1</a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="/?room_id=2">Room 2</a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="/?room_id=3">Room 3</a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="/?room_id=vmstat">VMStat</a><br>
					<br>
				</div>
			</div>
			<!-- selected chat -->
			<div class="col-sm-9 bg-white ">
<?php if ($_GET['room_id'] == 'vmstat') {
	echo <<<EOF
				<main id="charts">
					<section class="chart template">
						<h4 class="title"></h4>
						<canvas style="width: 100%; height: 40px;"></canvas>
						<ul class="stats">
							<li class="stat template">
								<span class="stat-name"></span>
								<span class="stat-value"></span>
							</li>
						</ul>
					</section>
				</main>
EOF
;
} elseif ($_GET['room_id'] == 'phptty') {
	echo <<<EOF
				<div class="phptty"></div>
EOF
;
}
?>
				<div class="chat-message">
					<ul class="chat" id="dialog">
					</ul>
				</div>
				<form onsubmit="onSubmit(); return false;">
				<div class="chat-box bg-white">
					<select class="input-group" style="margin-bottom:8px" id="client_list">
						<option value="all">Everyone</option>
					</select>
					<div class="input-group">
						<input class="form-control border no-shadow no-rounded" placeholder="Type your message here" id="textarea">
						<span class="input-group-btn say-btn">
							<button class="btn btn-info face no-rounded" type="button">Emoji</button>
							<button class="btn btn-success no-rounded" type="submit">Send</button>
						</span>
					</div><!-- /input-group -->
				</div>
				</form>
			</div>
		</div>
	</div>
</body>
</html>
