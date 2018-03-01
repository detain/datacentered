if (typeof console == "undefined") { this.console = { log: function (msg) { } };}
WEB_SOCKET_SWF_LOCATION = "/swf/WebSocketMain.swf"; // If the browser does not support websocket, will use this flash automatically simulate websocket protocol, this process is transparent to developers
WEB_SOCKET_DEBUG = true; // Open flash websocket debug
var ws, name, roomId;

var ChOpper = (function (app) { //contacts
	function Contact (id,ima,name,img,online) {
		this.id = id;
		this.name = name;
		this.ima = ima;
		this.img = img;
		this.online = online;
		this.messages = new Array();
		this.newmsg = 0;
		this.rooms = new Array();
		contactList[this.id] = this;
		name2Image[name] = img;
	}
	Contact.prototype.addMessage = function (msg) {
		this.messages.push(msg);
	}
	appContacts =  Contact;
	return appContacts;
})(ChOpper || {});

var ChOpper = (function (app) { //rooms
	function Room (name,img) {
		this.id = roomList.length;
		this.name = name;
		this.img = img;
		this.members = new Array();
		this.messages = new Array();
		this.newmsg = 0;
		roomList[this.id] = this;
		//contactList.push(this);
	}
	Room.prototype.addMember = function (contact) {
		this.members.push(contact);
	}
	Room.prototype.addMessage = function (msg) {
		this.messages.push(msg);
	}
	appRooms = Room;
	return appRooms;
})(ChOpper || {});

var ChOpper = (function (app) { //messages
	function Message (text,name,time,type,room,img) {
		this.text = text;
		this.name = name;
		this.time = time;
		this.type = type;
		this.room = room;
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
var hostList = new Array();
var roomList = new Array();
var name2Image = new Array();

var ChOpper = (function ChOpperModel (app) { //model
	var subject = new app.Subject();
	var Model = {
		start : function() {
			/*$.getJSON("group_messages.json", function (data) {
				app.Model.load(data.elements);
			});*/
		},
		load : function(data) {
			for (var i=0; i<data.length; i++) {
				var e = data[i];
				if (e.online == undefined) {
					var room = new appRooms(e.name,e.img);
					for (var j = 0; j < e.members.length; j++) {
						room.addMember(contactList[e.members[j].contact]);
					}
					for (var j = 0; j < e.messages.length; j++) {
						var m = e.messages[j];
						var message = new appMessages(m.text, m.name, m.time, m.type, true);
						room.addMessage(message);
					}
				} else {
					var contact = new appContacts(e.id, e.name, e.ima, e.img, e.online);
					for (var j = 0; j < e.messages.length; j++) {
						var m = e.messages[j];
						var message = new appMessages(m.text, m.name, m.time, m.type, false, e.img);
						contact.addMessage(message);
					}
				}
			}
			subject.notifyObservers();
		},
		writeMessage : function() {
			var msg = new appMessages($(".input-message").val(),"",new Date().getHours() + ":" + new Date().getMinutes(),true);
			ChOpper.View.printMessage(msg);
			currentChat.addMessage(msg);
			$(".input-message").val("");
			$("#" + currentChat.id).addClass("active-contact");
			subject.notifyObservers();
		},
		getMessage : function (type,text,id) {
			var msg = new appMessages(text, contactList[id].name, new Date().getHours() + ":" + new Date().getMinutes(), false, false, contactList[id].img);
			if (type == "room") {
				roomList[0].addMessage(msg);
			} else {
				contactList[id].addMessage(msg);
				contactList[id].online = new Date().getHours() + ":" + new Date().getMinutes();
			}
			if(contactList[id] == currentChat) {
				ChOpper.View.printMessage(msg);
				ChOpper.View.printContact(contactList[id]);
			} else {
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

var ChOpper = (function ChOpperView(app) { //view
	var view = {
		printRoom : function (c) {
			$("#" + c.id).remove();
			var lastmsg = c.messages[c.messages.length - 1];
			if (typeof lastmsg == "undefined") {
				if (c.newmsg == 0) {
					var html = $("<div class='room' id='" + c.id + "'><img src='" + c.img + "' alt='profilpicture'><div class='contact-preview'><div class='contact-text'><h1 class='font-name'>" + c.name + "</h1></div></div><div class='contact-time'></div></div>");
				} else {
					var html = $("<div class='room new-message-contact' id='" + c.id + "'><img src='" + c.img + "' alt='profilpicture'><div class='contact-preview'><div class='contact-text'><h1 class='font-name'>" + c.name + "</h1></div></div><div class='contact-time'><div class='new-message' id='nm" + c.id + "'><p>" + c.newmsg + "</p></div></div></div>");
				}
			} else {
				if (c.newmsg == 0) {
					var html = $("<div class='room' id='" + c.id + "'><img src='" + c.img + "' alt='profilpicture'><div class='contact-preview'><div class='contact-text'><h1 class='font-name'>" + c.name + "</h1><p class='font-preview'>" + lastmsg.text + "</p></div></div><div class='contact-time'><p>" + lastmsg.time + "</p></div></div>");
				} else {
					var html = $("<div class='room new-message-contact' id='" + c.id + "'><img src='" + c.img + "' alt='profilpicture'><div class='contact-preview'><div class='contact-text'><h1 class='font-name'>" + c.name + "</h1><p class='font-preview'>" + lastmsg.text + "</p></div></div><div class='contact-time'><p>" + lastmsg.time + "</p><div class='new-message' id='nm" + c.id + "'><p>" + c.newmsg + "</p></div></div></div>");
				}
			}
			var that = c;
			$(".room-list").prepend(html);
			ChOpper.Ctrl.addClick(html, that);
		} ,
		printContact : function (c) {
			//console.log(c);
			$("#" + c.id).remove();
			var lastmsg = c.messages[c.messages.length - 1];
			if (typeof lastmsg == "undefined") {
				if (c.newmsg == 0) {
					var html = $("<div class='contact' id='" + c.id + "'><img src='" + c.img + "' alt='profilpicture'><div class='contact-preview'><div class='contact-text'><h1 class='font-name'>" + c.name + "</h1></div></div><div class='contact-time'></div></div>");
				} else {
					var html = $("<div class='contact new-message-contact' id='" + c.id + "'><img src='" + c.img + "' alt='profilpicture'><div class='contact-preview'><div class='contact-text'><h1 class='font-name'>" + c.name + "</h1></div></div><div class='contact-time'><div class='new-message' id='nm" + c.id + "'><p>" + c.newmsg + "</p></div></div></div>");
				}
			} else {
				if (c.newmsg == 0) {
					var html = $("<div class='contact' id='" + c.id + "'><img src='" + c.img + "' alt='profilpicture'><div class='contact-preview'><div class='contact-text'><h1 class='font-name'>" + c.name + "</h1><p class='font-preview'>" + lastmsg.text + "</p></div></div><div class='contact-time'><p>" + lastmsg.time + "</p></div></div>");
				} else {
					var html = $("<div class='contact new-message-contact' id='" + c.id + "'><img src='" + c.img + "' alt='profilpicture'><div class='contact-preview'><div class='contact-text'><h1 class='font-name'>" + c.name + "</h1><p class='font-preview'>" + lastmsg.text + "</p></div></div><div class='contact-time'><p>" + lastmsg.time + "</p><div class='new-message' id='nm" + c.id + "'><p>" + c.newmsg + "</p></div></div></div>");
				}
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
			} else {
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
			if (gc.room) {
				if (gc.type) {
					$(".chat").append('<div class="me rightchat clearfix"><span class="chat-img pull-right"><img src="'+gc.img+'" alt="User Avatar"></span><div class="chat-body clearfix"><div class="header"><strong class="primary-font">'+gc.name+'</strong><small class="pull-right text-muted"><i class="fa fa-clock-o"></i> '+gc.time+'</small></div><p>'+gc.text+'</p></div></div>');
					//$(".chat").append('<div class="chat-bubble me"><div class="rightchat clearfix"><span class="chat-img pull-left"><img src="'+gc.img+'" alt="User Avatar"></span><div class="chat-body clearfix"><div class="header"><strong class="primary-font">'+gc.name+'</strong><small class="pull-right text-muted"><i class="fa fa-clock-o"></i> '+gc.time+'</small></div><p>'+gc.text+'</p></div></div>');
					//$(".chat").append("<div class='chat-bubble me'><div class='my-mouth'></div><div class='content'>" + gc.text + "</div><div class='time'>" + gc.time + "</div></div>");
				}else {
					$(".chat").append('<div class="you leftchat clearfix"><span class="chat-img pull-left"><img src="'+gc.img+'" alt="User Avatar"></span><div class="chat-body clearfix"><div class="header"><strong class="primary-font">'+gc.name+'</strong><small class="pull-right text-muted"><i class="fa fa-clock-o"></i> '+gc.time+'</small></div><p>'+gc.text+'</p></div></div>');
					//$(".chat").append('<div class="chat-bubble you"><div class="leftchat clearfix"><span class="chat-img pull-left"><img src="'+gc.img+'" alt="User Avatar"></span><div class="chat-body clearfix"><div class="header"><strong class="primary-font">'+gc.name+'</strong><small class="pull-right text-muted"><i class="fa fa-clock-o"></i> '+gc.time+'</small></div><p>'+gc.text+'</p></div></div>');
					//$(".chat").append("<div class='chat-bubble you'><div class='your-mouth'></div><h4>" + gc.name + "</h4><div class='content'>" + gc.text + "</div><div class='time'>" + gc.time + "</div></div>");
				}
			} else {
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
				$(".information").append("<img src='" + currentChat.img + "'><div><h1>Name:</h1><p>" + currentChat.name + "</p></div><div id='listRooms'><h1>Gemeinsame Gruppen:</h1></div>");
				for (var i = 0; i < currentChat.rooms.length; i++) {
					html = $("<div class='listRooms'><img src='" + currentChat.rooms[i].img + "'><p>" + currentChat.rooms[i].name + "</p></div>");
					$("#listRooms").append(html);
					$(html).click(function (e) {
						for (var i = 0; i < contactList.length; i++) {
							if (($(currentChat).find("p").text()) == contactList[i].name) {
								$(".active-contact").removeClass("active-contact");
								$("#" + contactList[i].id).addClass("active-contact");
								ChOpper.Rooms.printChat(contactList[i]);
							}
						}
					});
				}
			} else {
				$(".information").append("<img src='" + currentChat.img + "'><div><h1>Name:</h1><p>" + currentChat.name + "</p></div><div id='listRooms'><h1>Mitglieder:</h1></div>");
				for (var i = 0; i < currentChat.members.length; i++) {
					html = $("<div class='listRooms'><img src='" + currentChat.members[i].img + "'><p>" + currentChat.members[i].name + "</p></div>");
					$("#listRooms").append(html);
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
				for (var id in contactList) {
					//console.log("contact "+id);
					//console.log(contactList[id]);
					ChOpper.View.printContact(contactList[id]);
					currentChat = contactList[id];
				}
				first = false;
				for (var id in roomList) {
					//console.log("room "+id);
					//console.log(roomList[id]);
					ChOpper.View.printRoom(roomList[id]);
					currentChat = roomList[id];
				}
				first = false;
			} else {
				console.log("current contact"+currentChat);
				ChOpper.View.printContact(currentChat);
				console.log("current room"+currentChat);
				ChOpper.View.printRoom(currentChat);
			}
		}
	}
	app.View = view;
	return app;
})(ChOpper);

var start = true;

var ChOpper = (function ChOpperCtrl(app) { //controller
	$(document).ready(function () {
		$("#loginModal").modal('show');
		$("#login-submit").on('click', function() {
			$("#loginModal").modal('hide');
			app.Ctrl.connect();
		});
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
						ChOpper.Ctrl.writeMessage();
					}
				});
				$("#input-submit").on('click', function() {
					var input = document.getElementById("textarea");
					var to_client_id = $("#client_list option:selected").attr("value");
					var to_client_name = $("#client_list option:selected").text();
					ws.send('{"type":"say","to_client_id":"'+to_client_id+'","to_client_name":"'+to_client_name+'","content":"'+input.value.replace(/"/g, '\\"').replace(/\n/g,'\\n').replace(/\r/g, '\\r')+'"}');
					input.value = "";
					input.focus();
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
		},
		onClose : function() {
			console.log("Connection is closed, timing reconnection");
			//connect();
		},
		onError : function() {
			console.log("An error occurred");
		},
		onOpen : function() { // When the socket connection is open, enter the user name
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
		},
		onMessage : function(e) { // When there is a message according to the type of message shows different information
/*
Source,Destination,Command,Arguments
Hub,Client,ping
Hub,Client,clients
Hub,Client,hosts
Hub,Client,groups
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
			//console.log("got message");
			var data = JSON.parse(e.data);
			switch(data['type']){
				case 'ping': // Server ping client
					ws.send('{"type":"pong"}');
					break;
				case 'clients':
					console.log('processing clients');
					//console.log(data['content']);
					app.Model.load(data['content']);
					break;
				case 'error':
					console.log("There Was An Error:"+data['content']);
					break;
				case 'login': // Log in to update the user list
					//{"type":"login","id":xxx,"name":"xxx","ima":"client|host","email":"","ip":"","time":"xxx"}
					if (data['ima'] == 'admin' && (document.getElementById('email').value == data['email'] || document.getElementById('email').value == data['name'])) {
						console.log("getting clients list");
						ws.send('{"type":"clients"}');
					} else {
						var contact = new appContacts(data.id, data.name, data.ima, data.img, data.online);
					}
					ChOpper.View.printMessage(data['name']+' Logged in');
					if(contactList[data.id] == currentChat) {

						//ChOpper.View.printContact(contactList[data.id]);
					} else {
						contactList[data.id].newmsg ++;
						//ChOpper.View.printContact(contactList[data.id]);
					}
					var message = new appMessages('Joined the chat room', data['name'], data['time'], data['type'], true);
					//roomList[0].addMessage(message);
					console.log(data['name']+" login successful");
					break;
				case 'say': // speaking
					//{"type":"say","from_id":xxx,"to_client_id":"all/client_id","content":"xxx","time":"xxx"}
					say(data['from_id'], data['from_name'], data['content'], data['time']);
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
					say(data['from_id'], data['from_name'], data['from_name']+' Quit', data['time']);
					delete contactList[data['from_id']];
					flush_room_list();
					flush_client_list();
					break;
				default:
					console.log("unknown message");
					console.log(e.data);
					break;
			}
		},
		connect : function() { // Connect to the server
			//ws = new ReconnectingWebSocket("wss://"+document.domain+":7272"); // create websocket
			ws = new WebSocket("wss://"+document.domain+":7272");
			ws.onopen = app.Ctrl.onOpen;
			ws.onmessage = app.Ctrl.onMessage;
			ws.onclose = app.Ctrl.onClose;
			ws.onerror = app.Ctrl.onError;
		}
	};
	app.Ctrl = Ctrl;
	return app;
})(ChOpper);

ChOpper.Model.register(ChOpper.View, ChOpper.Ctrl);
