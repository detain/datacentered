var ChOpper = (function (app) { //contacts
	function Contact (name,img,online) {
		this.id = contactList.length;
		this.name = name;
		this.img = img;
		this.online = online;
		this.messages = new Array();
		this.newmsg = 0;
		this.groups = new Array();
		contactList.push(this);
		name2Image[name] = img;
	}
	Contact.prototype.addMessage = function (msg) {
		this.messages.push(msg);
	}
	Contact.prototype.addGroup = function (group) {
		this.groups.push(group);
	}
	appContacts =  Contact;
	return appContacts;
})(ChOpper || {});
var ChOpper = (function (app) { //groups

	function Group (name,img) {
		this.id = roomList.length;
		this.name = name;
		this.img = img;
		this.members = new Array();
		this.messages = new Array();
		this.newmsg = 0;
		roomList.push(this);
		//contactList.push(this);
	}
	Group.prototype.addMember = function (contact) {
		this.members.push(contact);
	}
	Group.prototype.addMessage = function (msg) {
		this.messages.push(msg);
	}
	appGroups = Group;
	return appGroups;
})(ChOpper || {});
var ChOpper = (function (app) { //messages
	function Message (text,name,time,type,group,img) {
		this.text = text;
		this.name = name;
		this.time = time;
		this.type = type;
		this.group = group;
		if (typeof img == "undefined") {
			this.img = name2Image[name];
		} else {
			this.img = img;
		}
	}
	appMessages =  Message;
	return appMessages;
})(ChOpper || {});
var ChOpper = (function(app) { //subject
	function Subject() {
		this.observers = [];
	};
	Subject.prototype.subscribe = function(item) {
		this.observers.push(item);
	};
	Subject.prototype.unsubscribeAll = function() {
		this.observers.length = 0;
	};
	Subject.prototype.notifyObservers = function() {
		for (var x = 0;  x< this.observers.length; x++)
			this.observers[x].notify();
	};
	app.Subject = Subject;
	return app;
})(ChOpper || {});
var currentChat;
var contactList = new Array();
var roomList = new Array();
var name2Image = new Array();
var ChOpper = (function ToDoModel (app) { //model
	var subject = new app.Subject();
	var Model = {
		start : function() {
			$.getJSON("group_messages.json", function (data) {
				for (var i=0; i<data.elements.length; i++) {
					var e = data.elements[i];
					if (e.online == undefined) {
						var group = new appGroups(e.name,e.img);
						for (var j = 0; j < e.members.length; j++) {
							group.addMember(contactList[e.members[j].contact]);
							contactList[e.members[j].contact].addGroup(group);
						}
						for (var j = 0; j < e.messages.length; j++) {
							var m = e.messages[j];
							var message = new appMessages(m.text, m.name, m.time, m.type, true);
							group.addMessage(message);
						}
					} else {
						var contact = new appContacts(e.name, e.img, e.online);
						for (var j = 0; j < e.messages.length; j++) {
							var m = e.messages[j];
							var message = new appMessages(m.text, m.name, m.time, m.type, false, e.img);
							contact.addMessage(message);
						}
					}
				}
				subject.notifyObservers();
			});
		},
		writeMessage : function() {
			var msg = new appMessages($(".input-message").val(),"",new Date().getHours() + ":" + new Date().getMinutes(),true);
			ChOpper.View.printMessage(msg);
			currentChat.addMessage(msg);
			$(".input-message").val("");
			$("#" + currentChat.id).addClass("active-contact");
			subject.notifyObservers();
		},
		getMessage : function (text,id,name) {
			if (name == undefined) {
				var msg = new appMessages(text, contactList[id].name, new Date().getHours() + ":" + new Date().getMinutes(), false, false, contactList[id].img);
			}
			else {
				var msg = new appMessages(text, name, new Date().getHours() + ":" + new Date().getMinutes(), false, true, contactList[id].img);
			}
			contactList[id].addMessage(msg);
			contactList[id].online = new Date().getHours() + ":" + new Date().getMinutes();
			if(contactList[id] == currentChat) {
				ChOpper.View.printMessage(msg);
				ChOpper.View.printContact(contactList[id]);
			}
			else {
				contactList[id].newmsg ++;
				ChOpper.View.printContact(contactList[id]);
			}
		},
		register : function() {
			subject.unsubscribeAll();
			for (var x = 0 ; x < arguments.length; x++) {
				subject.subscribe(arguments[x]);
			}
		}
	};
	app.Model = Model;
	return app;
})(ChOpper || {});
var first = true;
var ChOpper = (function ToDoView(app) { //view
	var view = {
		printRoom : function (c) {
			$("#" + c.id).remove();
			var lastmsg = c.messages[c.messages.length - 1];
			if (c.newmsg == 0) {
				var html = $("<div class='room' id='" + c.id + "'><img src='" + c.img + "' alt='profilpicture'><div class='contact-preview'><div class='contact-text'><h1 class='font-name'>" + c.name + "</h1><p class='font-preview'>" + lastmsg.text + "</p></div></div><div class='contact-time'><p>" + lastmsg.time + "</p></div></div>");
			}
			else {
				var html = $("<div class='room new-message-contact' id='" + c.id + "'><img src='" + c.img + "' alt='profilpicture'><div class='contact-preview'><div class='contact-text'><h1 class='font-name'>" + c.name + "</h1><p class='font-preview'>" + lastmsg.text + "</p></div></div><div class='contact-time'><p>" + lastmsg.time + "</p><div class='new-message' id='nm" + c.id + "'><p>" + c.newmsg + "</p></div></div></div>");
			}
			var that = c;
			$(".room-list").prepend(html);
			ChOpper.Ctrl.addClick(html, that);
		} ,
		printContact : function (c) {
			$("#" + c.id).remove();
			var lastmsg = c.messages[c.messages.length - 1];
			if (c.newmsg == 0) {
				var html = $("<div class='contact' id='" + c.id + "'><img src='" + c.img + "' alt='profilpicture'><div class='contact-preview'><div class='contact-text'><h1 class='font-name'>" + c.name + "</h1><p class='font-preview'>" + lastmsg.text + "</p></div></div><div class='contact-time'><p>" + lastmsg.time + "</p></div></div>");
			}
			else {
				var html = $("<div class='contact new-message-contact' id='" + c.id + "'><img src='" + c.img + "' alt='profilpicture'><div class='contact-preview'><div class='contact-text'><h1 class='font-name'>" + c.name + "</h1><p class='font-preview'>" + lastmsg.text + "</p></div></div><div class='contact-time'><p>" + lastmsg.time + "</p><div class='new-message' id='nm" + c.id + "'><p>" + c.newmsg + "</p></div></div></div>");
			}
			var that = c;
			$(".contact-list").prepend(html);
			ChOpper.Ctrl.addClick(html, that);
		} ,
		printHost : function (c) {
			$("#" + c.id).remove();
			var lastmsg = c.messages[c.messages.length - 1];
			if (c.newmsg == 0) {
				var html = $("<div class='contact' id='" + c.id + "'><img src='" + c.img + "' alt='profilpicture'><div class='contact-preview'><div class='contact-text'><h1 class='font-name'>" + c.name + "</h1><p class='font-preview'>" + lastmsg.text + "</p></div></div><div class='contact-time'><p>" + lastmsg.time + "</p></div></div>");
			}
			else {
				var html = $("<div class='contact new-message-contact' id='" + c.id + "'><img src='" + c.img + "' alt='profilpicture'><div class='contact-preview'><div class='contact-text'><h1 class='font-name'>" + c.name + "</h1><p class='font-preview'>" + lastmsg.text + "</p></div></div><div class='contact-time'><p>" + lastmsg.time + "</p><div class='new-message' id='nm" + c.id + "'><p>" + c.newmsg + "</p></div></div></div>");
			}
			var that = c;
			$(".contact-list").prepend(html);
			ChOpper.Ctrl.addClick(html, that);
		} ,
		printChat : function (cg) {
			ChOpper.View.closeContactInformation();
			$(".chat-head #chat-target-picture").css('display', 'block').attr("src",cg.img);
			$(".chat-name h1").text(cg.name);
			$(".chat").html();
			if(cg.members == undefined) {
					$(".chat-name p").text("Last Online" + cg.online);
				$(".chat-bubble").remove(); // configure messages
				for (var i=0; i<cg.messages.length; i++) {
					ChOpper.View.printMessage(cg.messages[i]);
				}
				currentChat = cg;
			}
			else {
				var listMembers = "";
				for (var i=0; i<cg.members.length; i++) {
					listMembers += cg.members[i].name;
					if (i < cg.members.length - 1) {
						listMembers  += ", ";
					}
				}
				$(".chat-name p").text(listMembers);
				$(".chat-bubble").remove(); // configure message
				for (var i=0; i<cg.messages.length; i++) {
					ChOpper.View.printMessage(cg.messages[i]);
				}
				currentChat = cg;
			}
		},
		printMessage : function (gc) {
			if (gc.group) {
				if (gc.type) {
					$(".chat").append('<div class="me rightchat clearfix"><span class="chat-img pull-right"><img src="'+gc.img+'" alt="User Avatar"></span><div class="chat-body clearfix"><div class="header"><strong class="primary-font">'+gc.name+'</strong><small class="pull-right text-muted"><i class="fa fa-clock-o"></i> '+gc.time+'</small></div><p>'+gc.text+'</p></div></div>');
					//$(".chat").append('<div class="chat-bubble me"><div class="rightchat clearfix"><span class="chat-img pull-left"><img src="'+gc.img+'" alt="User Avatar"></span><div class="chat-body clearfix"><div class="header"><strong class="primary-font">'+gc.name+'</strong><small class="pull-right text-muted"><i class="fa fa-clock-o"></i> '+gc.time+'</small></div><p>'+gc.text+'</p></div></div>');
					//$(".chat").append("<div class='chat-bubble me'><div class='my-mouth'></div><div class='content'>" + gc.text + "</div><div class='time'>" + gc.time + "</div></div>");
				}else {
					$(".chat").append('<div class="you leftchat clearfix"><span class="chat-img pull-left"><img src="'+gc.img+'" alt="User Avatar"></span><div class="chat-body clearfix"><div class="header"><strong class="primary-font">'+gc.name+'</strong><small class="pull-right text-muted"><i class="fa fa-clock-o"></i> '+gc.time+'</small></div><p>'+gc.text+'</p></div></div>');
					//$(".chat").append('<div class="chat-bubble you"><div class="leftchat clearfix"><span class="chat-img pull-left"><img src="'+gc.img+'" alt="User Avatar"></span><div class="chat-body clearfix"><div class="header"><strong class="primary-font">'+gc.name+'</strong><small class="pull-right text-muted"><i class="fa fa-clock-o"></i> '+gc.time+'</small></div><p>'+gc.text+'</p></div></div>');
					//$(".chat").append("<div class='chat-bubble you'><div class='your-mouth'></div><h4>" + gc.name + "</h4><div class='content'>" + gc.text + "</div><div class='time'>" + gc.time + "</div></div>");
				}
			}
			else {
				if (gc.type) {
					$(".chat").append('<div class="me rightchat clearfix"><span class="chat-img pull-left"><img src="'+gc.img+'" alt="User Avatar"></span><div class="chat-body clearfix"><div class="header"><strong class="primary-font">'+gc.name+'</strong><small class="pull-right text-muted"><i class="fa fa-clock-o"></i> '+gc.time+'</small></div><p>'+gc.text+'</p></div></div>');
					//$(".chat").append('<div class="chat-bubble me"><div class="rightchat clearfix"><span class="chat-img pull-left"><img src="'+gc.img+'" alt="User Avatar"></span><div class="chat-body clearfix"><div class="header"><strong class="primary-font">'+gc.name+'</strong><small class="pull-right text-muted"><i class="fa fa-clock-o"></i> '+gc.time+'</small></div><p>'+gc.text+'</p></div></div>');
					//$(".chat").append("<div class='chat-bubble me'><div class='my-mouth'></div><div class='content'>" + gc.text + "</div><div class='time'>" + gc.time + "</div></div>");
				} else {
					$(".chat").append('<div class="you leftchat clearfix"><span class="chat-img pull-left"><img src="'+gc.img+'" alt="User Avatar"></span><div class="chat-body clearfix"><div class="header"><strong class="primary-font">'+gc.name+'</strong><small class="pull-right text-muted"><i class="fa fa-clock-o"></i> '+gc.time+'</small></div><p>'+gc.text+'</p></div></div>');
					//$(".chat").append('<div class="chat-bubble you"><div class="leftchat clearfix"><span class="chat-img pull-left"><img src="'+gc.img+'" alt="User Avatar"></span><div class="chat-body clearfix"><div class="header"><strong class="primary-font">'+gc.name+'</strong><small class="pull-right text-muted"><i class="fa fa-clock-o"></i> '+gc.time+'</small></div><p>'+gc.text+'</p></div></div>');
					//$(".chat").append("<div class='chat-bubble you'><div class='your-mouth'></div><div class='content'>" + gc.text + "</div><div class='time'>" + gc.time + "</div></div>");
				}
			}
		},
		showContactInformation : function () {
			$(".chat-head i").hide();
			$(".information").css("display", "flex");
			$("#close-contact-information").show();
			if (currentChat.members == undefined) {
				$(".information").append("<img src='" + currentChat.img + "'><div><h1>Name:</h1><p>" + currentChat.name + "</p></div><div id='listGroups'><h1>Gemeinsame Gruppen:</h1></div>");
				for (var i = 0; i < currentChat.groups.length; i++) {
					html = $("<div class='listGroups'><img src='" + currentChat.groups[i].img + "'><p>" + currentChat.groups[i].name + "</p></div>");
					$("#listGroups").append(html);
					$(html).click(function (e) {
						for (var i = 0; i < contactList.length; i++) {
							if (($(currentChat).find("p").text()) == contactList[i].name) {
								$(".active-contact").removeClass("active-contact");
								$("#" + contactList[i].id).addClass("active-contact");
								ChOpper.Groups.printChat(contactList[i]);
							}
						}
					});
				}
			} else {
				$(".information").append("<img src='" + currentChat.img + "'><div><h1>Name:</h1><p>" + currentChat.name + "</p></div><div id='listGroups'><h1>Mitglieder:</h1></div>");
				for (var i = 0; i < currentChat.members.length; i++) {
					html = $("<div class='listGroups'><img src='" + currentChat.members[i].img + "'><p>" + currentChat.members[i].name + "</p></div>");
					$("#listGroups").append(html);
					$(html).click(function (e) {
						for (var i = 0; i < contactList.length; i++) {
							if (($(currentChat).find("p").text()) == contactList[i].name) {
								$(".active-contact").removeClass("active-contact");
								$("#" + contactList[i].id).addClass("active-contact");
								ChOpper.Contacts.printChat(contactList[i]);
							}
						}
					});
				}
			}
		},
		closeContactInformation : function () {
			$(".chat-head i").show();
			$("#close-contact-information").hide();
			$(".information >").remove();
			$(".information").hide();
		},
		growRoomList : function () {
			$('.chat-left').addClass('active');
			/*
			$('.chat-center').css({
			  marginLeft: $(".chat-left").outerWidth() + "px"
			});
			$('.chat-left').css({
			  right: "0px",
			  opacity: "1"
			});*/
		},
		shrinkRoomList : function () {
			$('.chat-left').removeClass('active');
		},
		growContactList : function () {
			$('.chat-right').addClass('active');
			/*
			$('.chat-center').css({
			  marginLeft: $(".chat-left").outerWidth() + "px"
			});
			$('.chat-right').css({
			  right: "0px",
			  opacity: "1"
			});*/
		},
		shrinkContactList : function () {
			$('.chat-right').removeClass('active');
		},
		//Observer-Methode
		notify: function () {
			if (first) {
				first = false;
				for (var i = 0; i < contactList.length; i++) {
					ChOpper.View.printContact(contactList[i]);
					currentChat = contactList[i];
				}
				first = false;
				for (var i = 0; i < roomList.length; i++) {
					ChOpper.View.printRoom(roomList[i]);
					currentChat = roomList[i];
				}
				first = false;
			}
			else {
				ChOpper.View.printContact(currentChat);
				ChOpper.View.printRoom(currentChat);
			}
		}
	}
	app.View = view;
	return app;
})(ChOpper);
var start = true;
var ChOpper = (function ToDoCtrl(app) { //controller
	$(document).ready(function () {
		app.Model.start();
	});
	var Ctrl = {
		addClick : function (html, that) {
			$(html).click(function(e) {
				$(".active-contact").removeClass("active-contact");
				$(this).addClass("active-contact");
				$(this).removeClass("new-message-contact");
				$("#nm" + that.id).remove();
				that.newmsg = 0;
				ChOpper.View.printChat(that);
			});
		},
		//Observer-Methode
		notify : function() {
			if (start) {
				$(".input-message").keyup(function(ev) {
					if(ev.which == 13 || ev.keyCode == 13) {
						app.Model.writeMessage();
					}
				});
				$("#show-contact-information").on("click",function(){
					ChOpper.View.showContactInformation();
				});
				$("#close-contact-information").on("click",function(){
					ChOpper.View.closeContactInformation();
				});
				$("#grow-left-list").on("click",function(){
					ChOpper.View.growRoomList();
				});
				$("#shrink-left-list").on("click",function(){
					ChOpper.View.shrinkRoomList();
				});
				$("#grow-right-list").on("click",function(){
					ChOpper.View.growContactList();
				});
				$("#shrink-right-list").on("click",function(){
					ChOpper.View.shrinkContactList();
				});
				start = false;
			}
		}
	};
	app.Ctrl = Ctrl;
	return app;
})(ChOpper);
ChOpper.Model.register(ChOpper.View, ChOpper.Ctrl);
$(document).ready(function() {
	$("#loginModal").modal('show');
});

function login_to_server() {
	$("#loginModal").modal('hide');
	connect();
	return false;
}

if (typeof console == "undefined") {    this.console = { log: function (msg) {  } };}
// If the browser does not support websocket, will use this flash automatically simulate websocket protocol, this process is transparent to developers
WEB_SOCKET_SWF_LOCATION = "/swf/WebSocketMain.swf";
// Open flash websocket debug
WEB_SOCKET_DEBUG = true;
var ws, name, client_list={}, roomId;

// Connect to the server
function connect() {
	//ws = new ReconnectingWebSocket("wss://"+document.domain+":7272"); // create websocket
	ws = new WebSocket("wss://"+document.domain+":7272");
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
	var login_data = {
		"type": "login",
		"ima": "admin",
		"username": document.getElementById('email').value,
		"password": document.getElementById('password').value,
		"room_id": 1
	}
	console.log(login_data);
	login_data = JSON.stringify(login_data);
	console.log("websocket handshake successfully, send login data: "+JSON.stringify(login_data));
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
/*
Source,Destination,Command,Arguments
Hub,Client,ping
Hub,Client,error
Hub,Client,login
Hub,Client,say
Hub,Client,log
Hub,Client,phptty
Hub,Client,vmstat
Hub,Client,logout
Hub,Client,running
Hub,Client,ran
Client,Hub,pong
Client,Hub,clients
Client,Hub,hosts
Client,Hub,run
Client,Hub,groups
Client,Hub,say
Client,Hub,running
Host,Hub,bandwidth
Host,Hub,pong
Host,Hub,run
Host,Hub,running
Host,Hub,ran
Hub,Host,ping
Hub,Host,timers
Hub,Host,self-update
Hub,Host,run
Hub,Host,running
*/
// When the server sends a message
function onmessage(e) {
	console.log(e.data);
	var data = JSON.parse(e.data);
	switch(data['type']){
		case 'ping': // Server ping client
			ws.send('{"type":"pong"}');
			break;
		case 'error':
			console.log("There Was An Error:"+data['content']);
			break;
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
			flush_room_list();
			flush_client_list();
	}
}

// enter your name
function show_prompt(){
	$('#loginModal').modal('show');
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

// Refresh chat room list box
function flush_room_list(){
	var userlist_window = $("#roomlist");
	var client_list_slelect = $("#room_list");
	userlist_window.empty();
	client_list_slelect.empty();
	userlist_window.append('<h4>Online Users</h4><ul>');
	client_list_slelect.append('<option value="all" id="cli_all">Everyone</option>');
	for(var p in client_list){
		userlist_window.append('<li id="'+p+'" class="bounceInDown"><a href="#" class="clearfix"><img src="https://bootdey.com/img/Content/user_3.jpg" alt="" class="img-circle"><div class="friend-name"><strong>'+client_list[p]+'</strong></div><div class="last-message text-muted">Lorem ipsum dolor sit amet.</div><small class="time text-muted">Yesterday</small><small class="chat-alert text-muted"><i class="fa fa-reply"></i></small></a></li>');
		//userlist_window.append('<li id="'+p+'">'+client_list[p]+'</li>');
		client_list_slelect.append('<option value="'+p+'">'+client_list[p]+'</option>');
	}
	$("#room_list").val(select_client_id);
	userlist_window.append('</ul>');
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
	/*
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
	*/
	var img = $('.profile img').attr('src');
	$('.chat').append('<div class="rightchat clearfix"><span class="chat-img pull-right"><img src="'+img+'" alt="User Avatar"></span><div class="chat-body clearfix"><div class="header"><strong class="primary-font">'+from_client_name+'</strong><small class="pull-right text-muted"><i class="fa fa-clock-o"></i> '+time+'</small></div><p>'+content+'</p></div></div>');
	//$("#dialog").append('<li class="left clearfix"><span class="chat-img pull-left"><img src="https://bootdey.com/img/Content/user_3.jpg?'+from_client_id+'" alt="User Avatar"></span><div class="chat-body clearfix"><div class="header"><strong class="primary-font">'+from_client_name+'</strong><small class="pull-right text-muted"><i class="fa fa-clock-o"></i> '+time+'</small></div><p>'+content+'</p></div></li>').parseEmotion();
	//$("#dialog").append('<div class="speech_item"><img src="http://lorempixel.com/38/38/?'+from_client_id+'" class="user_icon" /> '+from_client_name+' <br> '+time+'<div style="clear:both;"></div><p class="triangle-isosceles top">'+content+'</p> </div>').parseEmotion();

}

$(function() {
	select_client_id = 'all';
	$("#client_list").change(function(){
		select_client_id = $("#client_list option:selected").attr("value");
	});
	$('.face').click(function(event){
		event.stopPropagation();
	});
});

