var WhatsApp = (function (app) { //contacts
	function Contact (name,img,online) {
		this.id = contactList.length;
		this.name = name;
		this.img = img;
		this.online = online;
		this.messages = new Array();
		this.newmsg = 0;
		this.groups = new Array();
		contactList.push(this);
	}
	Contact.prototype.addMessage = function (msg) {
		this.messages.push(msg);
	}
	Contact.prototype.addGroup = function (group) {
		this.groups.push(group);
	}
	appContacts =  Contact;
	return appContacts;
})(WhatsApp || {});
var WhatsApp = (function (app) { //groups

	function Group (name,img) {
		this.id = contactList.length;
		this.name = name;
		this.img = img;
		this.members = new Array();
		this.messages = new Array();
		this.newmsg = 0;
		contactList.push(this);
	}
	Group.prototype.addMember = function (contact) {
		this.members.push(contact);
	}
	Group.prototype.addMessage = function (msg) {
		this.messages.push(msg);
	}
	appGroups = Group;
	return appGroups;
})(WhatsApp || {});
var WhatsApp = (function (app) { //messages
	function Message (text,name,time,type,group) {
		this.text = text;
		this.name = name,
		this.time = time;
		this.type = type;
		this.group = group;
	}
	appMessages =  Message;
	return appMessages;
})(WhatsApp || {});
var WhatsApp = (function(app) { //subject
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
})(WhatsApp || {});
var currentChat;
var contactList = new Array();
var WhatsApp = (function ToDoModel (app) { //model
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
							var message = new appMessages(m.text, m.name, m.time, m.type, false);
							contact.addMessage(message);
						}
					}
				}
				subject.notifyObservers();
			});
		},
		writeMessage : function() {
			var msg = new appMessages($(".input-message").val(),"",new Date().getHours() + ":" + new Date().getMinutes(),true);
			WhatsApp.View.printMessage(msg);
			currentChat.addMessage(msg);
			$(".input-message").val("");
			$("#" + currentChat.id).addClass("active-contact");
			subject.notifyObservers();
		},
		getMessage : function (text,id,name) {
			if (name == undefined) {
				var msg = new appMessages(text, contactList[id].name, new Date().getHours() + ":" + new Date().getMinutes(), false, false);
			}
			else {
				var msg = new appMessages(text, name, new Date().getHours() + ":" + new Date().getMinutes(), false, true);
			}
			contactList[id].addMessage(msg);
			contactList[id].online = new Date().getHours() + ":" + new Date().getMinutes();
			if(contactList[id] == currentChat) {
				WhatsApp.View.printMessage(msg);
				WhatsApp.View.printContact(contactList[id]);
			}
			else {
				contactList[id].newmsg ++;
				WhatsApp.View.printContact(contactList[id]);
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
})(WhatsApp || {});
var first = true;
var WhatsApp = (function ToDoView(app) { //view
	var view = {
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
			WhatsApp.Ctrl.addClick(html, that);
		} ,
		printChat : function (cg) {
			WhatsApp.View.closeContactInformation();
			$(".chat-head #chat-target-picture").css('display', 'block').attr("src",cg.img);
			$(".chat-name h1").text(cg.name);
			if(cg.members == undefined) {
				$(".chat-name p").text("zuletzt online um " + cg.online);
				// Nachrichten konfigurieren
				$(".chat-bubble").remove();
				for (var i=0; i<cg.messages.length; i++) {
					WhatsApp.View.printMessage(cg.messages[i]);
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
				// Nachrichten konfigurieren
				$(".chat-bubble").remove();
				for (var i=0; i<cg.messages.length; i++) {
					WhatsApp.View.printMessage(cg.messages[i]);
				}
				currentChat = cg;
			}
		},
		printMessage : function (gc) {
			if (gc.group) {
				if (gc.type) {
					$(".chat").append("<div class='chat-bubble me'><div class='my-mouth'></div><div class='content'>" + gc.text + "</div><div class='time'>" + gc.time + "</div></div>");
				}
				else {
					$(".chat").append("<div class='chat-bubble you'><div class='your-mouth'></div><h4>" + gc.name + "</h4><div class='content'>" + gc.text + "</div><div class='time'>" + gc.time + "</div></div>");
				}
			}
			else {
				if (gc.type) {
					$(".chat").append("<div class='chat-bubble me'><div class='my-mouth'></div><div class='content'>" + gc.text + "</div><div class='time'>" + gc.time + "</div></div>");
				}
				else {
					$(".chat").append("<div class='chat-bubble you'><div class='your-mouth'></div><div class='content'>" + gc.text + "</div><div class='time'>" + gc.time + "</div></div>");
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
								WhatsApp.Groups.printChat(contactList[i]);
							}
						}
					});
				}
			}
			else {
				$(".information").append("<img src='" + currentChat.img + "'><div><h1>Name:</h1><p>" + currentChat.name + "</p></div><div id='listGroups'><h1>Mitglieder:</h1></div>");
				for (var i = 0; i < currentChat.members.length; i++) {
					html = $("<div class='listGroups'><img src='" + currentChat.members[i].img + "'><p>" + currentChat.members[i].name + "</p></div>");
					$("#listGroups").append(html);
					$(html).click(function (e) {
						for (var i = 0; i < contactList.length; i++) {
							if (($(currentChat).find("p").text()) == contactList[i].name) {
								$(".active-contact").removeClass("active-contact");
								$("#" + contactList[i].id).addClass("active-contact");
								WhatsApp.Contacts.printChat(contactList[i]);
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
		growContactList : function () {
			$('.left').addClass('active');
			/*
			$('.right').css({
			  marginLeft: $(".left").outerWidth() + "px"
			});
			$('.left').css({
			  right: "0px",
			  opacity: "1"
			});*/
		},
		shrinkContactList : function () {
			$('.left').removeClass('active');
		},
		//Observer-Methode
		notify: function () {
			if (first) {
				first = false;
				for (var i = 0; i < contactList.length; i++) {
					WhatsApp.View.printContact(contactList[i]);
					currentChat = contactList[i];
				}
				first = false;
			}
			else {
				WhatsApp.View.printContact(currentChat);
			}
		}
	}
	app.View = view;
	return app;
})(WhatsApp);
var start = true;
var WhatsApp = (function ToDoCtrl(app) { //controller
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
				WhatsApp.View.printChat(that);
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
					WhatsApp.View.showContactInformation();
				});
				$("#close-contact-information").on("click",function(){
					WhatsApp.View.closeContactInformation();
				});
				$("#grow-contact-list").on("click",function(){
					WhatsApp.View.growContactList();
				});
				$("#shrink-contact-list").on("click",function(){
					WhatsApp.View.shrinkContactList();
				});
				start = false;
			}
		}
	};
	app.Ctrl = Ctrl;
	return app;
})(WhatsApp);
WhatsApp.Model.register(WhatsApp.View, WhatsApp.Ctrl);
