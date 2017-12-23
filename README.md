# WorkerMan WSS+Task+GlobalData+Channel+Timer+GatewayWorker+React Service

Building up an awesome client/server setup that will basically serve to replace our current webhositng and vps cron tasks.

## OverAll Features

* Asynchronous support througfhout
* Server running on VPS hosts provides remote API type access to cmds similar to HyperV
* Central Server on my that utilises GlobalData to share informatoin
* Central Server peeridocially triggers ...
* VPS Host server periodicsally updates its own basic list of services
* Central Server has as WSS server clients can connect to for various information
* Central Server can register new Timers both on itselfand with the VPS Host

Lots more just didnt feel like typing more right now

## Topology

### my server

* WSS Server
* Task Server
* GlobalData Server
* Channel Server+Client
* Timer component
* React/Child-Process component

### VPS Host Node

* WSS Server
* Task Server
* GlobalData Client
* Channel Server+Client
* Timer component
* React/Child-Process component

### Client

* WSS Client

## Workerman Main Links

* [GitHub walkor/Workerman: An asynchronous event driven PHP framework for easily building fast, scalable network applications. Supports HTTP, Websocket, SSL and other custom protocols. Supports libevent, HHVM, ReactPHP.](https://github.com/walkor/workerman/)
* [workerman a high-performance PHP Socket server framework](http://www.workerman.net/)
* [Workerman Wiki Documentation](http://wiki.workerman.net/Workerman_documentation)
* [Workerman Manual](http://doc3.workerman.net/)
* [react / child-process](http://doc3.workerman.net/component/reactchild-process.html)
* [GitHub clue/php-soap-react: Simple, async SOAP webservice client, built on top of ReactPHP](https://github.com/clue/php-soap-react)
* [GitHub clue/php-buzz-react: Simple, async PSR-7 HTTP client for concurrently processing any number of HTTP requests, built on top of ReactPHP](https://github.com/clue/php-buzz-react)


## Channel

Subscription-based multi-process communication components for workerman process communication or server cluster communications, similar to the redis subscription publishing mechanism.

* [GitHub walkor/Channel: Interprocess communication component for workerman](https://github.com/walkor/Channel)
* [Channel Distributed Communication Components](http://doc3.workerman.net/component/channel.html)
  * [ChannelServer](http://doc3.workerman.net/component/channel-server.html)
  * [ChannelClient](http://doc3.workerman.net/component/channel-client.html)
	  * [Connect](http://doc3.workerman.net/component/connect.html)
	  * [On](http://doc3.workerman.net/component/on.html)
	  * [Publish](http://doc3.workerman.net/component/publish.html)
	  * [Unsubscribe](http://doc3.workerman.net/component/unsubscribe.html)
  * [Example - Cluster push](http://doc3.workerman.net/component/channel-examples.html)

## Child Process handling with React

Integrates Program Execution with the EventLoop. Child processes launched may be signaled and will emit an exit event upon termination. Additionally, process I/O streams (i.e. STDIN, STDOUT, STDERR) are exposed as Streams.

* [GitHub react/child-process](https://github.com/reactphp)
* [REACT / Child-Process](http://doc3.workerman.net/component/reactchild-process.html)
* [StdOut/StdErr Example](https://github.com/reactphp/child-process/blob/master/examples/03-stdout-stderr.php)
* [ChildProcess: Child Process Component - ReactPHP](https://reactphp.org/child-process/)

## GlobalData component

Using PHP __set __get __isset __unsetmagic method to trigger communication with GlobalData server, the actual variable is stored in GlobalData server. For example, when setting a non-existent property to a client class, a __setmagic method is triggered . The client class __setsends a request to the GlobalData server in the method and saves it in a variable. When accessing a non-existent variable in the __getclient class, the method of the class is triggered. The client initiates a request to the GlobalData server to read this value, thereby completing the process of variable sharing between processes.

* [GitHub walkor/GlobalData: Inter-process variable sharing component for distributed data sharing](https://github.com/walkor/GlobalData)
* [GlobalData Variable Sharing Components](http://doc3.workerman.net/component/global-data.html)
* [GlobalData Server](http://doc3.workerman.net/component/global-data-server.html)
* [GlobalData Client](http://doc3.workerman.net/component/global-data-client.html)
  * [Add](http://doc3.workerman.net/component/global-data-add.html)
  * [Cas](http://doc3.workerman.net/component/global-data-cas.html)
  * [Increment](http://doc3.workerman.net/component/global-data-increment.html)

## Additional WorkerMan Manual Links

  * [Introduction](http://doc3.workerman.net/)
  * [Copyright Information](http://doc3.workerman.net/license/README.html)
  * [Principle](http://doc3.workerman.net/principle/README.html)
  * [Preamble](http://doc3.workerman.net/preface/README.html)
  * [Getting Started Guide](http://doc3.workerman.net/getting-started/README.html)
	* [Features](http://doc3.workerman.net/getting-started/feature.html)
	* [Simple development example](http://doc3.workerman.net/getting-started/simple-example.html)
  * [Install the configuration](http://doc3.workerman.net/install/README.html)
	* [Environmental Requirements](http://doc3.workerman.net/install/requirement.html)
	* [Download and install](http://doc3.workerman.net/install/install.html)
	* [Start and stop](http://doc3.workerman.net/install/start-and-stop.html)
  * [Development process](http://doc3.workerman.net/development/README.html)
	* [Read Before Developing](http://doc3.workerman.net/development/before-development.html)
	* [Directory Structure](http://doc3.workerman.net/development/directory-structure.html)
	* [Development Specification](http://doc3.workerman.net/development/standard.html)
	* [Basic process](http://doc3.workerman.net/development/process.html)
  * [Custom communication protocol](http://doc3.workerman.net/protocols/README.html)
	* [The role of communication protocols](http://doc3.workerman.net/protocols/why-protocols.html)
	* [How to customize the agreement](http://doc3.workerman.net/protocols/how-protocols.html)
	* [Some examples](http://doc3.workerman.net/protocols/example.html)
  * [The the Worker class](http://doc3.workerman.net/worker-development/worker-functions.html)
	* [Constructor](http://doc3.workerman.net/worker-development/worker-construct.html)
	* [Properties](http://doc3.workerman.net/worker-development/property.html)
	* [Id](http://doc3.workerman.net/worker-development/workerid.html)
	* [Count](http://doc3.workerman.net/worker-development/count.html)
	* [Name](http://doc3.workerman.net/worker-development/name.html)
	* [User](http://doc3.workerman.net/worker-development/user.html)
	* [Reloadable](http://doc3.workerman.net/worker-development/reloadable.html)
	* [Transport](http://doc3.workerman.net/worker-development/transport.html)
	* [Connections](http://doc3.workerman.net/worker-development/connections.html)
	* [Daemonize](http://doc3.workerman.net/worker-development/daemonize.html)
	* [StdoutFile](http://doc3.workerman.net/worker-development/stdout_file.html)
	* [PidFile](http://doc3.workerman.net/worker-development/pid_file.html)
	* [LogFile](http://doc3.workerman.net/worker-development/log-file.html)
	* [GlobalEvent](http://doc3.workerman.net/worker-development/global-event.html)
	* [ReusePort](http://doc3.workerman.net/worker-development/reuse-port.html)
	* [Protocol](http://doc3.workerman.net/worker-development/worker-protocol.html)
	* [Callback properties](http://doc3.workerman.net/worker-development/callback.html)
	* [OnWorkerStart](http://doc3.workerman.net/worker-development/on_worker_start.html)
	* [OnWorkerReload](http://doc3.workerman.net/worker-development/on-worker-reload.html)
	* [OnWorkerStop](http://doc3.workerman.net/worker-development/on-worker-stop.html)
	* [OnConnect](http://doc3.workerman.net/worker-development/on-connect.html)
	* [OnMessage](http://doc3.workerman.net/worker-development/on-message.html)
	* [OnClose](http://doc3.workerman.net/worker-development/on-close.html)
	* [OnBufferFull](http://doc3.workerman.net/worker-development/on-buffer-full.html)
	* [OnBufferDrain](http://doc3.workerman.net/worker-development/on-buffer-drain.html)
	* [OnError](http://doc3.workerman.net/worker-development/on-error.html)
	* [Interfaces](http://doc3.workerman.net/worker-development/method.html)
	* [RunAll](http://doc3.workerman.net/worker-development/run-all.html)
	* [StopAll](http://doc3.workerman.net/worker-development/stop-all.html)
	* [Listen](http://doc3.workerman.net/worker-development/listen.html)
  * [The TcpConnection class](http://doc3.workerman.net/worker-development/connection-functions.html)
	* [Properties](http://doc3.workerman.net/worker-development/connection-property.html)
	* [Id](http://doc3.workerman.net/worker-development/id.html)
	* [Protocol](http://doc3.workerman.net/worker-development/protocol.html)
	* [Worker](http://doc3.workerman.net/worker-development/worker.html)
	* [MaxSendBufferSize](http://doc3.workerman.net/worker-development/max-send-buffer-size.html)
	* [DefaultMaxSendBufferSize](http://doc3.workerman.net/worker-development/default-max-send-buffer-size.html)
	* [MaxPackageSize](http://doc3.workerman.net/worker-development/max-package-size.html)
	* [Callback properties](http://doc3.workerman.net/worker-development/connection-callback.html)
	* [OnMessage](http://doc3.workerman.net/worker-development/connection-on-message.html)
	* [OnClose](http://doc3.workerman.net/worker-development/connection-on-close.html)
	* [OnBufferFull](http://doc3.workerman.net/worker-development/connection-on-buffer-full.html)
	* [OnBufferDrain](http://doc3.workerman.net/worker-development/connection-on-buffer-drain.html)
	* [OnError](http://doc3.workerman.net/worker-development/connection-on-error.html)
	* [Interface](http://doc3.workerman.net/worker-development/connection-method.html)
	* [Send](http://doc3.workerman.net/worker-development/send.html)
	* [GetRemoteIp](http://doc3.workerman.net/worker-development/get-remote-ip.html)
	* [GetRemotePort](http://doc3.workerman.net/worker-development/get-remote-port.html)
	* [Close](http://doc3.workerman.net/worker-development/close.html)
	* [Destroy](http://doc3.workerman.net/worker-development/destroy.html)
	* [PauseRecv](http://doc3.workerman.net/worker-development/pause-recv.html)
	* [ResumeRecv](http://doc3.workerman.net/worker-development/resume-recv.html)
	* [Pipe](http://doc3.workerman.net/worker-development/pipe.html)
  * [The AsyncTcpConnection class](http://doc3.workerman.net/worker-development/async-tcp-connection.html)
	* [__construct](http://doc3.workerman.net/worker-development/__construct.html)
	* [Connect](http://doc3.workerman.net/worker-development/connect.html)
	* [Reconnect](http://doc3.workerman.net/worker-development/reconnect.html)
	* [Transport attribute](http://doc3.workerman.net/worker-development/async-tcp-connection-transport.html)
  * [Timer Timer class](http://doc3.workerman.net/worker-development/timer-functions.html)
	* [Add](http://doc3.workerman.net/worker-development/add.html)
	* [Del](http://doc3.workerman.net/worker-development/del.html)
	* [Precautions](http://doc3.workerman.net/worker-development/timer-notice.html)
  * [WebServer](http://doc3.workerman.net/advanced/webserver.html)
  * [Debug](http://doc3.workerman.net/debug/README.html)
	* [Basic Commissioning](http://doc3.workerman.net/debug/base.html)
	* [Check the operating status](http://doc3.workerman.net/advanced/status.html)
	* [Network capture package](http://doc3.workerman.net/debug/tcpdump.html)
	* [Tracking System Calls](http://doc3.workerman.net/debug/strace.html)
  * [Common components](http://doc3.workerman.net/component/README.html)
	* [FileMonitor File Monitoring Components](http://doc3.workerman.net/component/file-monitor.html)
	* [MySQL Components](http://doc3.workerman.net/component/mysql.html)
	* [Workerman / mysql](http://doc3.workerman.net/component/workerman-mysql.html)
	* [Asynchronous react / mysql](http://doc3.workerman.net/component/reactmysql.html)
	* [Asynchronous redis components](http://doc3.workerman.net/component/async-redis.html)
	* [Clue / redis-react](http://doc3.workerman.net/component/clueredis-react.html)
	* [Asynchronous dns components](http://doc3.workerman.net/component/dns-components.html)
	* [REACT / dns](http://doc3.workerman.net/component/reactdns.html)
	* [Asynchronous HTTP Components](http://doc3.workerman.net/component/async-http-components.html)
	* [REACT / HTTP-Client](http://doc3.workerman.net/component/reacthttp-client.html)
	* [Asynchronous Message Queuing Components](http://doc3.workerman.net/component/async-message-queue-components.html)
	* [REACT / ZMQ](http://doc3.workerman.net/component/reactzmq.html)
	* [REACT / Stomp](http://doc3.workerman.net/component/reactstomp.html)
  * [Frequently Asked Questions](http://doc3.workerman.net/faq/README.html)
	* [Whether to support multithreading](http://doc3.workerman.net/faq/support-multi-thread.html)
	* [Integration with ThinkPHP and other frameworks](http://doc3.workerman.net/faq/work-with-other-framework.html)
	* [Running Multiple WorkerMan](http://doc3.workerman.net/faq/running-concurent.html)
	* [Which protocols are supported?](http://doc3.workerman.net/faq/protocols.html)
	* [How to set the number of processes](http://doc3.workerman.net/faq/processes-count.html)
	* [Check the current number of client connections](http://doc3.workerman.net/faq/connection-status.html)
	* [Persistence of objects and resources](http://doc3.workerman.net/faq/persistent-data-and-resources.html)
	* [Examples do not work](http://doc3.workerman.net/faq/demo-not-work.html)
	* [Failed to start](http://doc3.workerman.net/faq/workerman-start-fail.html)
	* [Stop failed](http://doc3.workerman.net/faq/stop-fail.html)
	* [How Many Concurrent Supports](http://doc3.workerman.net/faq/how-many-connections.html)
	* [The code does not take effect](http://doc3.workerman.net/faq/change-code-not-work.html)
	* [Send data to a client](http://doc3.workerman.net/faq/send-data-to-client.html)
	* [How to proactively push the message](http://doc3.workerman.net/faq/active-push.html)
	* [Pushed in other projects](http://doc3.workerman.net/faq/push-in-other-project.html)
	* [How to achieve asynchronous tasks](http://doc3.workerman.net/faq/async-task.html)
	* [Status send_fail reasons](http://doc3.workerman.net/faq/about-send-fail.html)
	* [Development of Linux deployment under Win](http://doc3.workerman.net/faq/windows-to-linux.html)
	* [Whether to support socket.IO](http://doc3.workerman.net/faq/socketio-support.html)
	* [Terminal shutdown causes service shutdown](http://doc3.workerman.net/faq/ssh-close-and-workerman-stop.html)
	* [Relation to Apache / Nginx](http://doc3.workerman.net/faq/relationship-with-apache-nginx.html)
	* [Use mysql, redis](http://doc3.workerman.net/faq/how-to-use-mysql-redis.html)
	* [Disable function checking](http://doc3.workerman.net/faq/disable-function-check.html)
	* [Smooth restart principle](http://doc3.workerman.net/faq/reload-principle.html)
	* [Open 843 port for Flash](http://doc3.workerman.net/faq/843-port-for-flash-socket-policy-file.html)
	* [How to broadcast data](http://doc3.workerman.net/faq/how-to-broadcast.html)
	* [Heartbeat](http://doc3.workerman.net/faq/heartbeat.html)
	* [How to set up udp service](http://doc3.workerman.net/faq/how-to-create-udp-service.html)
	* [Listen to ipv6 address](http://doc3.workerman.net/faq/ipv6.html)
	* [Turn off the unauthenticated connection](http://doc3.workerman.net/faq/close-unauthed-connections.html)
	* [Transfer Encryption - ssl / tsl](http://doc3.workerman.net/faq/ssl-support.html)
	* [Create wss service](http://doc3.workerman.net/faq/secure-websocket-server.html)
	* [Create https service](http://doc3.workerman.net/faq/secure-http-server.html)
	* [Workerman as client](http://doc3.workerman.net/faq/use-workerman-as-client-side.html)
	* [As a ws / wss client](http://doc3.workerman.net/faqas-wss-client.html)
  * [Appendix](http://doc3.workerman.net/appendices/README.html)
	* [Linux kernel tuning](http://doc3.workerman.net/appendices/kernel-optimization.html)
	* [Stress Test](http://doc3.workerman.net/appendices/stress-test.html)
	* [Installation Extension](http://doc3.workerman.net/appendices/install-extension.html)
	* [Websocket protocol](http://doc3.workerman.net/appendices/about-websocket.html)
	* [Ws agreement](http://doc3.workerman.net/appendices/about-ws.html)
	* [Text agreement](http://doc3.workerman.net/appendices/about-text.html)
	* [Frame agreement](http://doc3.workerman.net/appendices/about-frame.html)

## GatewayWorker

GatewayWorker is based on a project framework developed by Workerman for rapid development of TCP long-connect applications such as app push server, instant IM server, game server, IoT, smart home, etc.
GatewayWorker uses the classic Gateway and Worker process models. The Gateway process is responsible for maintaining client connections and forwarding client data to the BusinessWorker process. The BusinessWorker process is responsible for processing the actual business logic (calling Events.php by default) and pushing the result to the corresponding client. Gateway service and BusinessWorker service can be deployed separately on different servers for distributed clustering.
GatewayWorker provides a handy API for broadcasting data globally, broadcasting data to a group, and pushing data to a specific client. With Workerman's timer, you can also push the data regularly.

* [GitHub walkor/GatewayWorker: Distributed realtime messaging framework based on workerman.](https://github.com/walkor/GatewayWorker)
  * [GatewayWorker manual](http://www.workerman.net/gatewaydoc/)
  * [Preface](http://www.workerman.net/gatewaydoc/preface/README.html)
  * [Features](http://www.workerman.net/gatewaydoc/feature/README.html)
  * [Working Principle](http://www.workerman.net/gatewaydoc/process-of-communication/README.html)
  * [Getting Started](http://www.workerman.net/gatewaydoc/getting-started/README.html)
  * [Start and Stop](http://www.workerman.net/gatewaydoc/start-and-stop/README.html)
  * [Gateway/Worker Process Model](http://www.workerman.net/gatewaydoc/gateway-worker-development/gateway-worker-development.html)
  * [Gateway Class Use](http://www.workerman.net/gatewaydoc/gateway-worker-development/gateway.html)
	* [BusinessWorker Class Usage](http://www.workerman.net/gatewaydoc/gateway-worker-development/business-worker.html)
	* [eventHandler](http://www.workerman.net/gatewaydoc/gateway-worker-development/event-handler.html)
	* [processTimeout](http://www.workerman.net/gatewaydoc/gateway-worker-development/process-timeout.html)
	* [processTimeoutHandler](http://www.workerman.net/gatewaydoc/gateway-worker-development/process-timeout-handler.html)
  * [Register Class Use](http://www.workerman.net/gatewaydoc/gateway-worker-development/register.html)
  * [Events Class Use](http://www.workerman.net/gatewaydoc/gateway-worker-development/event-functions.html)
	* [onWorkerStart](http://www.workerman.net/gatewaydoc/gateway-worker-development/onworkerstart.html)
	* [onConnect](http://www.workerman.net/gatewaydoc/gateway-worker-development/onconnect.html)
	* [onMessage](http://www.workerman.net/gatewaydoc/gateway-worker-development/onmessage.html)
	* [onClose](http://www.workerman.net/gatewaydoc/gateway-worker-development/onclose.html)
	* [onWorkerStop](http://www.workerman.net/gatewaydoc/gateway-worker-development/onworkerstop.html)
  * [Lib\Gateway Interface](http://www.workerman.net/gatewaydoc/gateway-worker-development/lib-gateway-functions.html)
	* [sendToAll](http://www.workerman.net/gatewaydoc/gateway-worker-development/send-to-all.html)
	* [sendToClient](http://www.workerman.net/gatewaydoc/gateway-worker-development/send-to-client.html)
	* [sendToCurrentClient](http://www.workerman.net/gatewaydoc/gateway-worker-development/send-to-current-client.html)
	* [closeClient](http://www.workerman.net/gatewaydoc/gateway-worker-development/close-client.html)
	* [closeCurrentClient](http://www.workerman.net/gatewaydoc/gateway-worker-development/close-current-client.html)
	* [isOnline](http://www.workerman.net/gatewaydoc/gateway-worker-development/is-online.html)
	* [bindUid](http://www.workerman.net/gatewaydoc/gateway-worker-development/bind-uid.html)
	* [isUidOnline](http://www.workerman.net/gatewaydoc/gateway-worker-development/is-uid-online.html)
	* [getClientIdByUid](http://www.workerman.net/gatewaydoc/gateway-worker-development/get-client-id-by-uid.html)
	* [getUidByClientId](http://www.workerman.net/gatewaydoc/gateway-worker-development/get-uid-by-client-id.html)
	* [unbindUid](http://www.workerman.net/gatewaydoc/gateway-worker-development/unbind-uid.html)
	* [sendToUid](http://www.workerman.net/gatewaydoc/gateway-worker-development/send-to-uid.html)
	* [joinGroup](http://www.workerman.net/gatewaydoc/gateway-worker-development/join-group.html)
	* [leaveGroup](http://www.workerman.net/gatewaydoc/gateway-worker-development/leave-group.html)
	* [sendToGroup](http://www.workerman.net/gatewaydoc/gateway-worker-development/send-to-group.html)
	* [getClientCountByGroup](http://www.workerman.net/gatewaydoc/gateway-worker-development/get-client-count-by-group.html)
	* [getClientSessionsByGroup](http://www.workerman.net/gatewaydoc/gateway-worker-development/get-client-sessions-by-group.html)
	* [getAllClientCount](http://www.workerman.net/gatewaydoc/gateway-worker-development/get-all-client-count.html)
	* [getAllClientSessions](http://www.workerman.net/gatewaydoc/gateway-worker-development/get-all-client-sessions.html)
	* [setSession](http://www.workerman.net/gatewaydoc/gateway-worker-development/set-session.html)
	* [updateSession](http://www.workerman.net/gatewaydoc/gateway-worker-development/update-session.html)
	* [getSession](http://www.workerman.net/gatewaydoc/gateway-worker-development/get-session.html)
  * [Timer](http://www.workerman.net/gatewaydoc/timer/README.html)
  * [Heasrtbeat test](http://www.workerman.net/gatewaydoc/gateway-worker-development/heartbeat.html)
  * [Set router router](http://www.workerman.net/gatewaydoc/gateway-worker-development/router.html)
  * [SuperGlobal $_SESSION](http://www.workerman.net/gatewaydoc/gateway-worker-development/session.html)
  * [SuperGlobal $_SERVER](http://www.workerman.net/gatewaydoc/gateway-worker-development/server.html)
  * [Common Components](http://www.workerman.net/gatewaydoc/component/README.html)
  * [Distributed Deployment](http://www.workerman.net/gatewaydoc/gateway-worker-development/distributed.html)
	* [Why Distributed Deployment](http://www.workerman.net/gatewaydoc/gateway-worker-development/why-distributed.html)
	* [How to Deploy Distributedly](http://www.workerman.net/gatewaydoc/gateway-worker-development/how-distributed.html)
	* [Gateway Worker Seperatre Deployment](http://www.workerman.net/gatewaydoc/gateway-worker-development/gateway-worker-separation.html)
  * [Commnon Problems](http://www.workerman.net/gatewaydoc/faq/README.html)
	* [Push Messages in other Projects](http://www.workerman.net/gatewaydoc/advanced/push.html)
	* [How much to open the process is appropriatre](http://www.workerman.net/gatewaydoc/faq/process-count-seting.html)
	* [Multi-Protocol Support](http://www.workerman.net/gatewaydoc/advanced/multi-protocols.html)
	* [How to distinguish multi-protocol port](http://www.workerman.net/gatewaydoc/faq/get-gateway-port.html)
	* [Custom Communication Protocol](http://www.workerman.net/gatewaydoc/protocols/README.html)
	* [Run Multiple GatewayWorker Instances](http://www.workerman.net/gatewaydoc/advanced/multi-gatewayworker-instance.html)
	* [Use MySQL DataBase](http://www.workerman.net/gatewaydoc/appendices/mysql.html)
	* [Monitor File Updates](http://www.workerman.net/gatewaydoc/advanced/file-monitor.html)
	* [How to get the client ip](http://www.workerman.net/gatewaydoc/faq/get-ip.html)
	* [Turn off unauthenticated connections](http://www.workerman.net/gatewaydoc/faq/close-unauthed-connections.html)
	* [View GatewayWorker version](http://www.workerman.net/gatewaydoc/faq/get-gateway-version.html)
	* [Create wss service](http://www.workerman.net/gatewaydoc/faq/secure-websocket-server.html)
	* [See More...](http://www.workerman.net/gatewaydoc/faq/more-faq.html)


### GatewayWorker and Workerman relationship

Workerman can be seen as a pure socket class library, you can develop almost all network applications, whether it is TCP or UDP, long connection or short connection. Workerman code is streamlined, powerful and flexible to quickly develop a variety of web applications. At the same time, Workerman is also lower than GatewayWorker, which requires developers to have some experience in multi-process programming.
Because the goal of the vast majority of developers is to develop TCP long-connect applications based on Workerman, long-connect application server has much in common, for example, they have the same process model and interface requirements such as single issue, bulk issue and broadcast. So there is a GatewayWorker framework, GatewayWorker is based on the Workerman developed a TCP connection framework, to achieve a single hair, group sending, broadcast connection will be necessary for long connections, and built MySql class library. The GatewayWorker framework implements the Gateway Worker process model and natively supports distributed, multi-server deployments. Capacity expansion and scaling are easy and can handle massive concurrent connections. It can be said that GatewayWorker is based on the Workerman to achieve a more complete project framework for the realization of TCP long connection.

### GatewayWorker or Workerman?

If your project is a long connection and requires client-client communication, GatewayWorker is recommended. 
Shorter connections or projects that do not require client-client communication suggest the use of Workerman. 
GatewayWorker does not support UDP listening, so please select Workerman UDP service. 
If you are a multi-process socket programming experience, like to customize their own process model, you can choose Workerman.

### Linux system starts quickly (starting with a streamlined chat demo)

1 [download demo](http://www.workerman.net/download/GatewayWorker.zip)
2 run the command line `unzip GatewayWorker.zip`unzip GatewayWorker.zip
3 command line to run `cd GatewayWorker`into the GatewayWorker directory
4 run the command line `php start.php start`to start GatewayWorker
5 a few new command line window to run `telnet 127.0.0.1 8282`, enter any character you can chat (non-native test Please replace 127.0.0.1 with the actual ip).

### Windows system starts quickly (starting with a streamlined chat demo)

1 [download demo](http://www.workerman.net/download/GatewayWorker-for-win.zip)
2 extract to any location
3 into the GatewayWorker directory
4 double-click start_for_win.bat start. (If there is an error, please set [here](http://www.workerman.net/windows) php environment variables)
5 a few new cmd command-line window to run `telnet 127.0.0.1 8282`, enter any character you can chat (non-native test Please replace 127.0.0.1 with the actual ip).

**Note:**
windows system telnet may need to be installed, the installation method can baidu 
Windows system telnet is sent by character, can not send the whole sentence, please do not be surprised 

### GatewayWorker source address

Contains only the GatewayWorker kernel

[https://github.com/walkor/GatewayWorker](https://github.com/walkor/GatewayWorker)

### Projects developed using GatewayWorker

#### [tadpole](http://kedou.workerman.net/)

[Live demo](http://kedou.workerman.net/)
[Source code](https://github.com/walkor/workerman)

#### [chat room](http://chat.workerman.net/)

[Live demo](http://chat.workerman.net/)
[Source code](https://github.com/walkor/workerman-chat)
