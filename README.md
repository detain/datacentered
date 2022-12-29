# DataCentered

A Server to handle and basically manage other servers and providing several interfaces and many advanced featuers and functionality including asynchronous support, websocket server and secure websocket server, events, timers, tasks, global data mapping between threadsa and processes and services, subscriptionn and publishing based service, child process handling, timers, and much more.

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
* [Workerman Manual](http://doc.workerman.net/) [Alternate Workerman Manual](http://doc3.workerman.net/)
* [react / child-process](http://doc3.workerman.net/component/reactchild-process.html)
* [GitHub clue/php-soap-react: Simple, async SOAP webservice client, built on top of ReactPHP](https://github.com/clue/php-soap-react)
* [GitHub clue/php-buzz-react: Simple, async PSR-7 HTTP client for concurrently processing any number of HTTP requests, built on top of ReactPHP](https://github.com/clue/php-buzz-react)


## Channel

Subscription-based multi-process communication components for workerman process communication or server cluster communications, similar to the redis subscription publishing mechanism.

* [GitHub walkor/Channel: Interprocess communication component for workerman](https://github.com/walkor/Channel)
* [Channel Distributed Communication Components](http://doc3.workerman.net/component/channel.html)

## Child Process handling with React

Integrates Program Execution with the EventLoop. Child processes launched may be signaled and will emit an exit event upon termination. Additionally, process I/O streams (i.e. STDIN, STDOUT, STDERR) are exposed as Streams.

* [GitHub react/child-process](https://github.com/reactphp)
* [REACT / Child-Process](http://doc3.workerman.net/component/reactchild-process.html)
* [StdOut/StdErr Example](https://github.com/reactphp/child-process/blob/master/examples/03-stdout-stderr.php)
* [ChildProcess: Child Process Component - ReactPHP](https://reactphp.org/child-process/)

## GlobalData component

Using PHP __set __get __isset __unsetmagic method to trigger communication with GlobalData server, the actual variable is stored in GlobalData server. For example, when setting a non-existent property to a client class, a __setmagic method is triggered . The client class __setsends a request to the GlobalData server in the method and saves it in a variable. When accessing a non-existent variable in the __getclient class, the method of the class is triggered. The client initiates a request to the GlobalData server to read this value, thereby completing the process of variable sharing between processes.

* [GitHub walkor/GlobalData: Inter-process variable sharing component for distributed data sharing](https://github.com/walkor/GlobalData)

## Additional WorkerMan Manual Links

  * [Introduction](http://doc3.workerman.net/)

## GatewayWorker

GatewayWorker is based on a project framework developed by Workerman for rapid development of TCP long-connect applications such as app push server, instant IM server, game server, IoT, smart home, etc.
GatewayWorker uses the classic Gateway and Worker process models. The Gateway process is responsible for maintaining client connections and forwarding client data to the BusinessWorker process. The BusinessWorker process is responsible for processing the actual business logic (calling Events.php by default) and pushing the result to the corresponding client. Gateway service and BusinessWorker service can be deployed separately on different servers for distributed clustering.
GatewayWorker provides a handy API for broadcasting data globally, broadcasting data to a group, and pushing data to a specific client. With Workerman's timer, you can also push the data regularly.

* [GitHub walkor/GatewayWorker: Distributed realtime messaging framework based on workerman.](https://github.com/walkor/GatewayWorker)
  * [GatewayWorker manual](http://doc2.workerman.net/)
  * [Alternate GatewayWorker manual](http://doc4.workerman.net/)


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


## Choose **Workerman** or **GatewayWorker**

If your project is a long connection and requires communication between the client and the client, it is recommended to use GatewayWorker.
Workerman is recommended for short connections or projects that do not require communication between the client and the client.
GatewayWorker does not support UDP listening, so choose UDP service Workerman.
If you are a multi-process socket programming experience and like to customize your own process model, you can choose Workerman.


### **[Workerman3.x version manual](http://doc.workerman.net/)**

Workerman is a high-performance socket server communication framework for rapid development of a variety of network applications, including tcp, udp, long connection, and short connection applications.

[Online Manual »](http://doc.workerman.net/)
[Alternate Online Manual »](http://doc3.workerman.net/)


### **[GatewayWorker Manual](http://doc2.workerman.net/)**

GatewayWorker is a set of **TCP long connection** application framework developed by Workerman . It
implements single-issue, group-sending, broadcast and other interfaces. It has built-in mysql class library.
GatewayWorker is divided into Gateway process and Worker process. It supports distributed deployment naturally and can support huge The number of connections (millions or even millions of connection level applications).
Can be used to develop IM chat applications, mobile communications, game backends, the Internet of Things, smart home background and more.

[GatewayWorker Manual](http://doc2.workerman.net/)
[Backup Online Manual 2 »](http://doc4.workerman.net/)

## **Workerman related third party projects**

### **thinkworker**

High-performance website/API development framework based on Workerman 
Project homepage: [http://thinkworker.cn/project](http://thinkworker.cn/)
documentation: [http://docs.thinkworker.cn/](http://docs.thinkworker.cn/)

### **Beanbun**

Beanbun is a multi-process web crawler framework written in PHP with good openness and high scalability, based on Workerman. 
Project home page: [http://www.beanbun.org](http://www.beanbun.org/)
project documentation: [http://www.beanbun.org](http://www.beanbun.org/)

### **think-worker**

ThinkPHP's official developer extension for ThinkPHP5 
Project home page: [https://github.com/top-think/think-worker](https://github.com/top-think/think-worker)
project documentation: [https://www.kancloud.cn/manual/thinkphp5/235128](https://www.kancloud.cn/manual/thinkphp5/235128)

### **SlightPHP**

SlightPHP's efficient PHP agile development framework 
Project home page: [https://github.com/hetao29/slightphp](https://github.com/hetao29/slightphp)
project documentation: [https://github.com/hetao29/slightphp](https://github.com/hetao29/slightphp)

### **SimplerWorker**

High-performance, timely deployment of high-performance, timely communication framework based on Thinkphp and workerman, gateway 
Project home page: [https://gitee.com/SimplerWorker/SimplerWorker](https://gitee.com/SimplerWorker/SimplerWorker)
project documentation: [https://gitee.com/SimplerWorker/SimplerWorker](https://gitee.com/SimplerWorker/SimplerWorker)

### **hide2 / api**

High-performance API service based on Workerman 
Project home page: [https://github.com/hide2/api](https://github.com/hide2/api)
project documentation: [https://github.com/hide2/api](https://github.com/hide2/api)

### **think-workerman**

A simple asynchronous development framework based on workerman, syntax similar to thinkphp5 development model, pure composer package + common class composition 
Project home page: [https://github.com/Zsoner/think-workerman](https://github.com/Zsoner/think-workerman)
project documentation: [https://github.com/Zsoner /think-workerman](https://github.com/Zsoner/think-workerman)

### **webworker**

Web development framework with http server based on Workerman 
Project homepage: [http://ask.webworker.xtgxiso.com/project](http://ask.webworker.xtgxiso.com/)
documentation: [http://doc.webworker.xtgxiso.com/](http://doc.webworker.xtgxiso.com/)

### **Workerman_cor_ape**

Workerman_cor_ape is an enhanced version of the php framework Workerman that adds asynchronous task components without affecting any usage, stability, or performance. 
Project home page: [https://github.com/zyfei/workerman_cor_ape](https://github.com/zyfei/workerman_cor_ape)
project documentation: [https://github.com/zyfei/workerman_cor_ape](https://github.com/zyfei/workerman_cor_ape)

### **WorkermanYii2**

For those familiar with workerman and yii2 ActiveRecod, using ActiveRecord in workerman is a problem. This project mainly solves the problem of using ActiveRecord in yii2 in workerman. 
Project homepage: [https://github.com/victorruan/WorkermanYii2](https://github.com/victorruan/WorkermanYii2)
project Documentation: [https://github.com/victorruan/WorkermanYii2](https://github.com/victorruan/WorkermanYii2)

### **WorkerA**

a http framework for workerman 
project home page: [https://github.com/wazsmwazsm/WorkerA](https://github.com/wazsmwazsm/WorkerA)
project documentation: [https://www.kancloud.cn/wazsmwazsm/workera](https://www.kancloud.cn/wazsmwazsm/workera/691859)

### **Workerman-Amp**

This project is used to apply Amp's event-loop to Workerman, so you can use Amp-based high-performance components in Workerman, such as asynchronous MySQL, asynchronous Redis, asynchronous HTTP client, and so on. 
Project home page: [https://github.com/CismonX/Workerman-Amp](https://github.com/CismonX/Workerman-Amp)
project documentation: [https://github.com/CismonX/Workerman-Amp](https://github.com/CismonX/Workerman-Amp)

### **socket-service**

A high-performance socket push service that supports distributed deployment based on the workerman-chat's GatewayWorker framework. 
Project home page: [https://github.com/cryhac/socket-service](https://github.com/cryhac/socket-service)
project documentation: [https://github.com/cryhac/socket-service](https://github.com/cryhac/socket-service)

### **scheduledTask-workerman**

Scheduled Task System based on workerman and yaf 
Project Homepage: [https://github.com/moxiaobai/scheduledTask-workerman](https://github.com/moxiaobai/scheduledTask-workerman)
Project Documentation: [https://github.com/moxiaobai/scheduledTask-workerman](https://github.com/moxiaobai/scheduledTask-workerman)

### **Iyov http proxy**

Web proxy for http(s) for developers to analyze data between client and servers based on workerman, especailly for app developers.
项目主页：[https://github.com/nicecp/iyov](https://github.com/nicecp/iyov)
项目文档：[https://github.com/nicecp/iyov](https://github.com/nicecp/iyov)

### **laravel_worker**

Best practices for bidirectional instant messaging for websocket based on laravel&workerman, php 
Project home page: [https://github.com/shellus/laravel_worker](https://github.com/shellus/laravel_worker)
Project documentation: [https://github.com/shellus/laravel_worker](https://github.com/shellus/laravel_worker)

### **workerman-thrift-resque**

Thrift RPC and Resque based on workerman. 
Project home page: [https://github.com/vtumi/workerman-thrift-resque](https://github.com/vtumi/workerman-thrift-resque)
project documentation: [https://github.com/vtumi/workerman-thrift-resque](https://github.com/vtumi/workerman-thrift-resque)

### **workerman-statistics-java**

A distributed statistical monitoring system includes JAVA client and server 
project home page: [https://github.com/shuiguang/workerman-statistics-java](https://github.com/shuiguang/workerman-statistics-java)
project documentation: [https://github.com/shuiguang/workerman-statistics-java](https://github.com/shuiguang/workerman-statistics-java)

### **CI-worker**

CI framework written by workerman 
Project home page: [https://github.com/tmtbe/CI-worker](https://github.com/tmtbe/CI-worker)
project documentation: [https://github.com/tmtbe/CI-worker](https://github.com/tmtbe/CI-worker)

### **Logger Ever**

The worker server-based log server uses udp to upload logs, which has no impact on application performance. The log server supports multiple processes and conforms to the psr-3 log specification. 
Project home page: [https://github.com/tmtbe/LoggerSever](https://github.com/tmtbe/LoggerSever)
project documentation: [https://github.com/tmtbe/LoggerSever](https://github.com/tmtbe/LoggerSever)

### **workerman-crontab**

Dynamic crontab for php, power by workerman 
project home page: [https://github.com/shuiguang/workerman-crontab](https://github.com/shuiguang/workerman-crontab)
project documentation: [https://github.com/shuiguang/workerman-crontab](https://github.com/shuiguang/workerman-crontab)

### **Workerman-ThinkPHP-Redis**

Workerman-chat+ThinkPHP+Redis 
project home page: [https://github.com/happyliu2014/Workerman-ThinkPHP-Redis](https://github.com/happyliu2014/Workerman-ThinkPHP-Redis)
project documentation: [https://github.com/happyliu2014/Workerman-ThinkPHP-Redis](https://github.com/happyliu2014/Workerman-ThinkPHP-Redis)

### **Quasar**

a Push Server for Nova Framework 
project home page: [https://github.com/nova-framework/quasar](https://github.com/nova-framework/quasar)
project documentation: [https://github.com/nova-framework/quasar](https://github.com/nova-framework/quasar)

### **workermvc**

Workermvc is a workerv-based mvc framework, using thinkphp5 composer package, using the habits to try to make the original formula, the original taste 
Project home page: [https://github.com/lobtao/workermvc](https://github.com/lobtao/workermvc)
project documentation: [https://github. Com/lobtao/workermvc_demo](https://github.com/lobtao/workermvc_demo)


## Other Links

[ask.webworker.xtgxiso.com](http://ask.webworker.xtgxiso.com/)
[Channel Distributed Communication Components | Manual for WorkerMan 3.x](http://doc3.workerman.net/component/channel.html)
[Client Connection Failure Causes · workerman manuals look at the cloud](http://doc.workerman.net/327807)
[CloudXNS / mss: Asynchronous High-Performance Mailman-based Serviceman Short Message Delivery Service](https://github.com/CloudXNS/mss)
[Development Manual | workerman PHP Socket Server Framework](https://www.workerman.net/doc)
[Download Installation workerman manual look at the cloud](http://doc.workerman.net/315116)
[Editing en.workerman.net/bench.tpl.php at master · walkor/en.workerman.net](https://github.com/walkor/en.workerman.net/edit/master/Views/bench.tpl.php)
[forest2087 / docker-workerman: Docker For PHP workerman environment](https://github.com/forest2087/docker-workerman)
[garveen / laravoole: Laravel && Swoole || Workerman to get 10x faster than php-fpm](https://github.com/garveen/laravoole)
[Getting Started | WorkerMan 3.x manual](http://doc3.workerman.net/getting-started/README.html)
[GlobalData Variable Sharing Components | Manual for WorkerMan 3.x.](http://doc3.workerman.net/component/global-data.html)
[Home - Beanbun - a simple and open PHP crawler framework](http://www.beanbun.org/#/)
[Home - workerman question and answer community](http://wenda.workerman.net/#all)
[hprose/hprose-workerman: A PHP class that enables you to use Hprose with Workerman. Includes custom protocol, bridge and interface. Enjoy Hprose at its finest with multi-process powers!](https://github.com/hprose/hprose-workerman)
[Introduction | GatewayWorker2.x 3.x Brochure](http://www.workerman.net/gatewaydoc/)
[Introduction · GitBook](http://doc.webworker.xtgxiso.com/)
[kiddyuchina / Beanbun: Beanbun is written in PHP multi-process web crawler framework, has a good open, high scalability, based on the Workerman. ](https://github.com/kiddyuchina/Beanbun)
[liliuwli / spider-with-workerman: workerman-based crawler currently only supported](https://github.com/liliuwli/spider-with-workerman)
[LiveCamera | PHP + Websocket + HTML5 Call the camera for live video](https://www.workerman.net/camera)
[moxiaobai / scheduledTask-workerman: Scheduled Tasks System Developed Based on workerman and yaf](https://github.com/moxiaobai/scheduledTask-workerman)
[phpsocket.io/docs/zh at master walker / phpsocket.io](https://github.com/walkor/phpsocket.io/tree/master/docs/en)
[phpsocket.io php version socket.io](https://www.workerman.net/phpsocket_io)
[popyelove / knowask: a problem publishing platform based on the workerman socket framework, mongodb](https://github.com/popyelove/knowask)
[Preface workerman manual, look at the cloud](http://doc.workerman.net/315110)
[Push Messages on Other Projects | GatewayWorker2.x 3.x Brochure](http://www.workerman.net/gatewaydoc/advanced/push.html)
[shuiguang / workerman-crontab: Dynamic crontab for php, power by workerman](https://github.com/shuiguang/workerman-crontab)
[TcpConnection class workerman manual look at the cloud](http://doc.workerman.net/315157)
[tmtbe / CI-worker: CI framework written by workerman](https://github.com/tmtbe/CI-worker)
[tmtbe / LoggerSever: based on the workerman log server, using udp upload log, no impact on application performance, log server supports multiple processes, in line with the psr-3 log book. ](https://github.com/tmtbe/LoggerSever)
[tmtbe / ServerFrame: Frame Extension Based on the wokerman Engine Framework](https://github.com/tmtbe/ServerFrame)
[top-think / think-worker: ThinkPHP5 Workerman extend](https://github.com/top-think/think-worker)
[walkor / Channel: Interprocess communication component for workerman](https://github.com/walkor/Channel)
[walkor / GlobalData: Inter-process variable sharing component for distributed data sharing](https://github.com/walkor/GlobalData)
[walkor / laychat: layim + Workerman chat rooms, support group chat, chat, facial expressions, send pictures, send files](https://github.com/walkor/laychat)
[walkor / live-ascii-camera: Use HTML5 to convert camera video to ascii characters and send them to other pages in real time via websocket. Serverman]](https://github.com/walkor/live-ascii-camera)
[walkor / php-http-proxy: HTTP proxy written in PHP based on workerman.](https://github.com/walkor/php-http-proxy)
[walkor / php-socks5: socks5 proxy written in PHP based on workerman.](https://github.com/walkor/php-socks5)
[walkor / phptty: Share your terminal as a web application. PHP terminal emulator based on workerman.](https://github.com/walkor/phptty)
[walkor/Workerman: An asynchronous event driven PHP framework for easily building fast, scalable network applications. Supports HTTP, Websocket, SSL and other custom protocols. Supports libevent, HHVM, ReactPHP.](https://github.com/walkor/Workerman)
[walkor / workerman-flappy-bird: flappy bird Multiplayer Online](https://github.com/walkor/workerman-flappy-bird)
[walkor / workerman-JsonRpc: workerman as process manager, json as a framework for remote service invocation](https://github.com/walkor/workerman-jsonrpc/)
[walkor/workerman-JsonRpc: workerman作为进程管理器，json作为协议的远程服务调用的框架](https://github.com/walkor/workerman-jsonrpc/)
[walkor / workerman-queue: workerman message queue](https://github.com/walkor/workerman-queue)
[walkor / workerman-statistics: A distributed statistical monitoring system that includes PHP clients, servers](https://github.com/walkor/workerman-statistics/)
[walkor / workerman-thrift: Thrift RPC for php based on workerman.](https://github.com/walkor/workerman-thrift/)
[workerman-flappy-bird | flappy bird multiplayer online source code PHP + Websocket + HTML5](https://www.workerman.net/workerman-flappybird)
[workerman how to use the worker process to deal with the heavy business? - workerman question and answer community](http://wenda.workerman.net/?/question/358)
[workerman-json-rpc | A high-performance PHP Json Rpc framework](https://www.workerman.net/workerman-jsonrpc)
[workerman-manual / SUMMARY.md at master walker / workerman-manual](https://github.com/walkor/workerman-manual/blob/master/english/src/SUMMARY.md)
[workerman-statistics | a high-performance PHP monitoring and accounting system](https://www.workerman.net/workerman-statistics)
[workerman-thrift-rpc | A high-performance PHP Thrift Rpc framework](https://www.workerman.net/workerman-thrift)
[workerman windows version download and install](https://www.workerman.net/windows)
[Workerman workflow, such as process, child process, socket and other relations, workerman is how it works? - workerman question and answer community](http://wenda.workerman.net/?/question/29)
[xpader / Navigation: A Workerman-based PHP web development framework. ](https://github.com/xpader/Navigation)
[xtgxiso / WebWorker-benchmark: WebWorker-benchmark](https://github.com/xtgxiso/WebWorker-benchmark)
[xtgxiso / WebWorker-example: WebWorker-example](https://github.com/xtgxiso/WebWorker-example)
[xtgxiso / WebWorker: Web Development Framework With Http Server Based on Workerman (http://www.workerman.net/)](https://github.com/xtgxiso/WebWorker)


##### Queue Info

* Memcached Queue Task [30s]
  * Set $global->cas('queuein', 0, 1)
  * Iterate $memcache->get('queuein'.$suffix) combining into $processQueue and empting queuein<id>
  * Process $processQueue
  * Set $memcache->cas($response['cas'], 'queueout, [])
  * Set $global->queuein = 0;
* /queue.php URL Endpoint
  * if post action=queue
    * $memcache->get('queue')
    * if data in $queue[ip] display it
    * empty queue with $memcache->cas($response['cas'], 'queue',
  * else
    * $memcache->get('queuehosts')
    * if data not in queuehosts[ip] add it
    * update wit $memcache->cas($response['cas'], 'queuehosts'
    * get $memcache->get('queuein<id>' data
    * Add data to queuein<id>
    * set with $memcache->cas($response['cas'], 'queuein<id>'
* vps_cron
  * cpu-usage -> :55151/queue.php
  * host-info -> :55151/queue.php
  * get_new_vps -> mynew/vps_queue.php
  * bw-info -> :55151/queue.php
  * map -> :55151/queue.php
  * get_queue -> mynew/vps_queue.php
  * vps-info -> :55151/queue.php


