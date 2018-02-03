# WorkerMan Manual

## Summary

* [License](license/README.md)
* [Preface](preface/README.md)
* [Getting Started](getting-started/README.md)
   * [Features](getting-started/feature.md)
   * [A simple tutorial](getting-started/simple-example.md)
* [Installation and Configuration](install/README.md)
   * [Environment](install/requirement.md)
   * [Installlation](install/install.md)
   * [Start and stop](install/start-and-stop.md)
* [API](worker-development/README.md)
   * [Worker](worker-development/worker-functions.md)
       * [Properties](worker-development/property.md)
           * [count](worker-development/count.md)
           * [name](worker-development/name.md)
           * [user](worker-development/user.md)
           * [reloadable](worker-development/reloadable.md)
           * [transport](worker-development/transport.md)
           * [connections](worker-development/connections.md)
           * [daemonize](worker-development/daemonize.md)
           * [stdoutFile ](worker-development/stdout_file.md)
           * [pidFile](worker-development/pid_file.md)
           * [globalEvent](worker-development/global-event.md)
       * [Callbacks](worker-development/callback.md)
           * [onWorkerStart](worker-development/on_worker_start.md)
           * [onWorkerStop](worker-development/on-worker-stop.md)
           * [onConnect](worker-development/on-connect.md)
           * [onMessage](worker-development/on-message.md)
           * [onClose](worker-development/on-close.md)
           * [onBufferFull](worker-development/on-buffer-full.md)
           * [onBufferDrain](worker-development/on-buffer-drain.md)
           * [onError](worker-development/on-error.md)
   * [TcpConnection](worker-development/connection-functions.md)
       * [Properties](worker-development/connection-property.md)
           * [id](worker-development/id.md)
           * [protocol](worker-development/protocol.md)
           * [worker](worker-development/worker.md)
           * [maxSendBufferSize](worker-development/max-send-buffer-size.md)
           * [maxPackageSize](worker-development/max-package-size.md)
       * [Callbacks](worker-development/connection-callback.md)
           * [onMessage](worker-development/connection-on-message.md)
           * [onClose](worker-development/connection-on-close.md)
           * [onBufferFull](worker-development/connection-on-buffer-full.md)
           * [onBufferDrain](worker-development/connection-on-buffer-drain.md)
           * [onError](worker-development/connection-on-error.md)
       * [Methods](worker-development/connection-method.md)
           * [send](worker-development/send.md)
           * [getRemoteIp](worker-development/get-remote-ip.md)
           * [getRemotePort](worker-development/get-remote-port.md)
           * [close](worker-development/close.md)
           * [destroy](worker-development/destroy.md)
           * [pauseRecv](worker-development/pause-recv.md)
           * [resumeRecv](worker-development/resume-recv.md)
   * [AsyncTcpConnection](worker-development/async-tcp-connection.md)
       * [__construct](worker-development/__construct.md)
       * [connect](worker-development/connect.md)
   * [Timer](worker-development/timer-functions.md)
       * [add](worker-development/add.md)
       * [del](worker-development/del.md)
   * [WebServer](worker-development/web-server.md)
       * [__construct](worker-development/webserver-construct.md)
       * [addRoot](worker-development/webserver-add-root.md)




## license

Copyright © 2013 - 2015 by workerman.net

MIT




## Preface

[PHP](http://www.php.net) is a widely-used open source general-purpose scripting language that is especially suited for web development.The main goal of the language is to allow web developers to write dynamically generated web pages quickly, but you can do much more with PHP.


WorkerMan is an asynchronous event driven framework for easily building fast, scalable network applications. Its core is an event loop and each worker can handle many connections concurrently. WorkerMan uses an event-driven, non-blocking I/O model that makes it lightweight and efficient, perfect for data-intensive real-time applications.







## Getting Started



## Features

#### 1、Written in PHP

#### 2、Multiprocess support

#### 3、TCP/UDP support

#### 4、Persistent connection support

#### 5、Support a variety of protocols HTTP Websocket, including custom protocols

#### 6、High concurrency

#### 7、Graceful restart support

#### 8、Set the User of the worker process

#### 9、Object and Resources can be kept persistently

#### 10、High performance

#### 11、Support HHVM

#### 12、Daemonize support

#### 13、Supports standard input and output redirection




## A simple tutorial

### Example 1 : A HTTP Service
**Create http_test.php**
```php
<?php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

// Create a Worker and listens 2345 port，use HTTP Protocol
$http_worker = new Worker("http://0.0.0.0:2345");

// 4 processes
$http_worker->count = 4;

// Emitted when data is received
$http_worker->onMessage = function($connection, $data)
{
    var_dump($_GET, $_POST, $_COOKIE, $_SESSION, $_SERVER, $_FILE);
    // Send hello world to client
    $connection->send('hello world');
};

// Run all workers
Worker::runAll();
```

**Run with**
```shell
php http_test.php start

```

**test**


Visit url http://127.0.0.1:2345


### Example 2 : A Websocket Service
**create ws_test.php**
```php
<?php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

// Create A Worker and Listens 2346 port, use Websocket protocol
$ws_worker = new Worker("websocket://0.0.0.0:2346");

// 4 processes
$ws_worker->count = 4;

// Emitted when new connection come
$ws_worker->onConnect = function($connection)
{
    // Emitted when websocket handshake done
    $connection->onWebSocketConnect = function($connection)
    {
        echo "New connection\n";
    };
};

// Emitted when data is received
$ws_worker->onMessage = function($connection, $data)
{
    // Send hello $data
    $connection->send('hello ' . $data);
};

// Emitted when connection closed
$ws_worker->onClose = function($connection)
{
    echo "Connection closed";
};

// Run worker
Worker::runAll();
```

**Run with**
```shell
php ws_test.php start

```

**Test**

Javascript

```javascript
ws = new WebSocket("ws://127.0.0.1:2346");
ws.onopen = function() {
    alert("connection success");
    ws.send('tom');
};
ws.onmessage = function(e) {
    alert("recv message from server：" + e.data);
};
```

### Example 3 ： A TCP Server
**create tcp_test.php**

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

// Creae A Worker and listen 2347 port，not specified protocol
$tcp_worker = new Worker("tcp://0.0.0.0:2347");

// 4 processes
$tcp_worker->count = 4;

// Emitted when new connection come
$tcp_worker->onConnect = function($connection)
{
    echo "New connection\n";
};

// Emitted when data is received
$tcp_worker->onMessage = function($connection, $data)
{
    // Send hello $data
    $connection->send('hello ' . $data);
};

// Emitted when connection closed
$tcp_worker->onClose = function($connection)
{
    echo "Connection closed\n";
};

// Run worker
Worker::runAll();
```

**Run with**

```shell
php tcp_test.php start

```

**Test**
```shell
telnet 127.0.0.1 2347
Trying 127.0.0.1...
Connected to 127.0.0.1.
Escape character is '^]'.
tom
hello tom
```



## install



### Environment

1、Linux（centos、RedHat、Ubuntu、debian、mac os etc）

2、PHP-CLI(>=5.3.3) with pcntl and posix extension

3、Libevent extension recommended, but not required



## Installation

```shell
git clone https://github.com/walkor/workerman
```


```shell
cd workerman
php start.php start
```




## Start and stop

#### Start

Run as debug mode

```php start.php start```

Run as daemon mode

```php start.php start -d```

#### Stop
```php start.php stop```

#### Restart
```php start.php restart```

#### Graceful restart
```php start.php reload```

#### Status
```php start.php status```







## API





## Worker

Worker{

public int [count](./count.md);

public string [name](./name.md);

public string [user](./user.md);

public bool [reloadable](./reloadable.md);

public string [transport](./transport.md);

public array [connections](./connections.md);

public static bool [deamonize](./daemonize.md);

public static string [stdoutFile](./stdout_file.md);

public satic string [pidFile](./pid_file.md);

public static EventInterface [globalEvent](./global-event.md);

// callbacks

public callback [onWorkerStart](./on_worker_start.md);

public callback [onWorkerStop](./on-worker-stop.md);

public callback [onConnect](./on-connect.md);

public callback [onMessage](./on-message.md);

public callback [onClose](./on-close.md);

public callback [onBufferFull](./on-buffer-full.md);

public callback [onbufferDrain](./on-buffer-drain.md);

public callback [onError](./on-error.md);

}



## Properties




## count

### Description :
```php
int Worker::$count
```

Set the process count of the worker instance. Default value is ```1```.


### Examples


```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
// 8 porcesses
$worker->count = 8;
$worker->onWorkerStart = function($worker)
{
    echo "Worker starting...\n";
};

// Run all workers
Worker::runAll();
```



## name

### Description:
```php
string Worker::$name
```

Set the name of worker, useful for status command.


### Examples
yourfile.php

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8485');
// set the worker name
$worker->name = 'MyWebsocketWorker';
$worker->count = 6;
$worker->onWorkerStart = function($worker)
{
    echo "Worker starting...\n";
};

// Run all workers
Worker::runAll();
```

```php yourfile.php start -d``` and ```php yourfile.php status``` you will see

```shell
php start.php status
Workerman[start.php] status
---------------------------------------GLOBAL STATUS--------------------------------------------
Workerman version:3.1.4          PHP version:5.4.37
start time:2015-05-03 15:03:59   run 0 days 0 hours
load average: 0, 0, 0
1 workers       6 processes
worker_name       exit_status     exit_count
MyWebsocketWorker 0                0
---------------------------------------PROCESS STATUS-------------------------------------------
pid     memory  listening                worker_name       connections total_request send_fail throw_exception
14773   0.56M   websocket://0.0.0.0:8485 MyWebsocketWorker 0           0              0         0
14774   0.56M   websocket://0.0.0.0:8485 MyWebsocketWorker 0           0              0         0
14775   0.56M   websocket://0.0.0.0:8485 MyWebsocketWorker 0           0              0         0
14776   0.56M   websocket://0.0.0.0:8485 MyWebsocketWorker 0           0              0         0
14777   0.56M   websocket://0.0.0.0:8485 MyWebsocketWorker 0           0              0         0
14778   0.56M   websocket://0.0.0.0:8485 MyWebsocketWorker 0           0              0         0
```



## user

### Description:
```php
string Worker::$user
```

Set the user of the worker processes. This needs appropriate privileges (usually root) on the system to be able to perform this.

Recommend ```www-data```, ```apache```, ```nobody``` and so on.


### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
// Set the user of worker processes
$worker->user = 'www-data';
$worker->onWorkerStart = function($worker)
{
    echo "Worker starting...\n";
};

// Run all workers
Worker::runAll();
```



## reloadable
### Description:
```php
string Worker::$reloadable
```

If the worker processes can be reloaded. Default value is ```true```. If ```$worker->reloadable = true```, the worker processes will restart when get reload signal(```SIGUSR1```).


### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
// reloadable
$worker->reloadable = false;
$worker->onWorkerStart = function($worker)
{
    echo "Worker starting...\n";
};

// Run all workers
Worker::runAll();
```



## transport
### Description:
```php
string Worker::$transport
```

Set the protocol of transport layer, tcp or udp.


### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('Text://0.0.0.0:8484');
// udp
$worker->transport = 'udp';
$worker->onMessage = function($connection, $data)
{
    $connection->send('Hello');
};

// Run all workers
Worker::runAll();
```



## connections
### Description:
```php
array Worker::$connections
```

It contains all of the connections of the worker.


### Examples

```php
use Workerman\Worker;
use Workerman\Lib\Timer;
require_once './Workerman/Autoloader.php';

$worker = new Worker('Text://0.0.0.0:8484');
$worker->count = 6;

// Add a Timer to Every worker process when the worker process start
$worker->onWorkerStart = function($worker)
{
    // Timer every 10 seconds
    Timer::add(10, function()use($worker)
    {
        // Iterate over connections and send the time
        foreach($worker->connections as $connection)
        {
            $connection->send(time());
        }
    });
};

// Run all workers
Worker::runAll();
```

**Test**

```shell
telnet 127.0.0.1 8484
Trying 127.0.0.1...
Connected to 127.0.0.1.
Escape character is '^]'.
1430638160
1430638170
```



## daemonize
### Description:
```php
static bool Worker::$daemonize
```

This is a static property. Workerman will run as daemon mode when  ```Worker::$daemonize=true``` , otherwise run as debug mode by default. Use ```-d``` option start workerman will run as daemon mode.


### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

Worker::$daemonize = true;
$worker = new Worker('Text://0.0.0.0:8484');
$worker->onWorkerStart = function($worker)
{
    echo "Worker start\n";
};

// Run all workers
Worker::runAll();
```



## stdoutFile
### Description:
```php
static string Worker::$stdoutFile
```

This is a static property. All output (echo var_dump, etc.) to the terminal  will be redirected to the specified file when workerman run as **daemon** mode. The default value is ```/dev/null```.

```Worker::$stdoutFile``` only work in daemon mode. All output will redirected to terminal when in debug mode .


### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

Worker::$daemonize = true;

// All output will be redirected to /tmp/stdout.log
Worker::$stdoutFile = '/tmp/stdout.log';
$worker = new Worker('Text://0.0.0.0:8484');
$worker->onWorkerStart = function($worker)
{
    echo "Worker start\n";
};

// Run all workers
Worker::runAll();
```



## pidFile
### Description:
```php
static Event Worker::$pidFile
```

This is a static property. ```Worker::$pidFile``` is a path which can be set to store the pid of the master process.


### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

Worker::$pidFile = '/var/run/workerman.pid';

$worker = new Worker('Text://0.0.0.0:8484');
$worker->onWorkerStart = function($worker)
{
    echo "Worker start";
};

// Run all workers
Worker::runAll();
```



## globalEvent

### Description:
```php
static Event Worker::$globalEvent
```

This is a static property. ```Worker::$globalEvent``` is the global eventloop.


### Examples

```php
use Workerman\Worker;
use Workerman\Events\EventInterface;
require_once './Workerman/Autoloader.php';

$worker = new Worker('Text://0.0.0.0:8484');
$worker->onWorkerStart = function($worker)
{
    //  Install SIGALRM handler
    Worker::$globalEvent->add(SIGALRM, EventInterface::EV_SIGNAL, function()
    {
        echo "Get signal SIGALRM\n";
    });
};

// Run all workers
Worker::runAll();
```



## 回调接口



## onWorkerStart
### Description:
```php
callback Worker::$onWorkerStart
```

Emitted when a Woker process start.


### Parameters

``` $worker ```

The worker.



### Examples


```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onWorkerStart = function($worker)
{
    echo "Worker starting...\n";
};

// Run all workers
Worker::runAll();
```



## onWorkerStop
### Description:
```php
callback Worker::$onWorkerStop
```

Emitted when a Woker process stop.

### Parameters

``` $worker ```

The worker.

### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onWorkerStop = function($worker)
{
    echo "Worker stopping...\n";
};

// Run all workers
Worker::runAll();
```



## onConnect
### Description:
```php
callback Worker::$onConnect
```

Emitted when a new connection is made.

### Parameters

``` $connection ```

The instance of Connection.


### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onConnect = function($connection)
{
    echo "new connection from ip " . $connection->getRemoteIp() . "\n";
};

// Run all workers
Worker::runAll();
```



## onMessage
### Description:
```php
callback Worker::$onMessage
```

Emitted when data is received.

### Parameters

``` $connection ```

The instance of Connection.

``` $data ```

The data received.

If the Protocol of Worker is setted, the data will be the result of ```Protocol::decode($recv_buffer)```.


### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onMessage = function($connection, $data)
{
    // $data is the result of Websocket::decode($recv_buffer)
    var_dump($data);
    $connection->send('receive success');
};

// Run all workers
Worker::runAll();
```



## onClose
### Description:
```php
callback Worker::$onClose
```

Emitted once the connection is closed.

### Parameters

``` $connection ```

The instance of Connection.


### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onClose = function($connection)
{
    echo "connection closed\n";
};

// Run all workers
Worker::runAll();
```



## onBufferFull
### Description:
```php
callback Worker::$onBufferFull
```

Emitted when the send buffer becomes full.

Each connection has a send write which can be set by ```$connection->maxSendBufferSize```. Default size is the value of ```TcpConnection::$defaultMaxSendBufferSize``` which is ```1MB```.


### Parameters

``` $connection ```

The instance of Connection.


### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onBufferFull = function($connection)
{
    echo "bufferFull and do not send again\n";
};

// Run all workers
Worker::runAll();
```




## onBufferDrain
### Description:
```php
callback Worker::$onBufferDrain
```

Emitted when the send buffer becomes empty.


### Parameters

``` $connection ```

The instance of Connection.


### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onBufferFull = function($connection)
{
    echo "bufferFull and do not send again\n";
};
$worker->onBufferDrain = function($connection)
{
    echo "buffer drain and continue send\n";
};

// Run all workers
Worker::runAll();
```



## onError
### Description:
```php
callback Worker::$onError
```

Emitted when an error occurs.


### Parameters

``` $connection ```

The instance of Connection.

``` $code ```

Error code

``` $msg ```

Error message


### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onError = function($connection, $code, $msg)
{
    echo "error $code $msg\n";
};

// Run all workers
Worker::runAll();
```



## TcpConnection




## Properties



## id

### Description:
```php
int Connection::$id
```

In one worker process each new connection is given its own unique id, this id is stored in the id.


### Examples


```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('tcp://0.0.0.0:8484');
$worker->onConnect = function($connection)
{
    echo $connection->id;
};

// Run all workers
Worker::runAll();
```



## protocol

### Description:
```php
string Connection::$protocol
```

The protocol of the connection.


### Examples


```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('tcp://0.0.0.0:8484');
$worker->onConnect = function($connection)
{
    $connection->protocol = 'Workerman\\Protocols\\Http';
};
$worker->onMessage = function($connection, $data)
{
    var_dump($_GET, $_POST);
    $connection->send("hello");
};

// Run all workers
Worker::runAll();
```



## worker
### Description:
```php
Worker Connection::$worker
```

Read only. The owner of the connection.


### Examples


```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('Websocket://0.0.0.0:8484');

// When received message，forwarded to all connections of the worker
$worker->onMessage = function($connection, $data)
{
    foreach($connection->worker->connections as $con)
    {
        $con->send($data);
    }
};

// Run all workers
Worker::runAll();
```



## maxSendBufferSize
### Description:
```php
int Connection::$maxSendBufferSize
```

Set the send buffer size of the connection.


### Examples


```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('Websocket://0.0.0.0:8484');
$worker->onConnect = function($connection)
{
    $connection->maxSendBufferSize = 2*1024*1024;
};

// Run all workers
Worker::runAll();
```



## maxPackageSize

### Description:
```php
static int Connection::$maxPackageSize
```

This is a static property. Set the max package size can be received .Default value is 10MB.


### Examples


```php
use Workerman\Worker;
use Workerman\Protocols\TcpConnection;
require_once './Workerman/Autoloader.php';

// 设置每个连接接收的数据包最大为1024000字节
TcpConnection::$maxPackageSize = 1024000;

$worker = new Worker('Websocket://0.0.0.0:8484');
$worker->onMessage = function($connection, $data)
{
    $connection->send('hello');
};

// Run all workers
Worker::runAll();
```



## Callbacks



## onMessage
### Description:
```php
callback Connection::$onMessage
```


Is the same as ```$worker->onMessage```, but only for the current connection.


### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onConnect = function($connection)
{
    $connection->onMessage = function($connection, $data)
    {
        var_dump($data);
        $connection->send('receive success');
    };
};

// Run all workers
Worker::runAll();
```

Is the same as

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onMessage = function($connection, $data)
{
    var_dump($data);
    $connection->send('receive success');
};

// Run all workers
Worker::runAll();
```



## onClose
### Description:
```php
callback Connection::$onClose
```

Is the same as ```$worker->onClose```, but only for the current connection.

### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onConnect = function($connection)
{
    $connection->onClose = function($connection)
    {
        echo "connection closed\n";
    };
};

// Run all workers
Worker::runAll();
```

Is the same as

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onClose = function($connection)
{
    echo "connection closed\n";
};

// Run all workers
Worker::runAll();
```



## onBufferFull
### Description:
```php
callback Connection::$onBufferFull
```

Is the same as ```$worker->onBufferFull```, but only for the current connection.



## onBufferDrain
### Description:
```php
callback Connection::$onBufferDrain
```

Is the same as ```$worker->onBufferDrain```, but only for the current connection.



## onError
### Description:
```php
callback Connection::$onError
```

Is the same as ```$worker->onError```, but only for the current connection.



## Methods



## send
### Description:
```php
mixed Connection::send(mixed $data [,$raw = false])
```

Sends data on the connection.

If the protocol is setted, ```$data``` will be encoded with ```Protocol::encode($data)``` before send.

### Parameters

``` $data ```

The data to be sent.

``` $raw ```
Whether send raw data.  ```Protocol::encode($data)``` will not be called When  ```$raw=true```.

### Return Values

```true```: success

```null```: Join to send buffer and waiting to be sent asynchronously.

```false```: Connection is closed by remote or send buffer is full.


### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onMessage = function($connection, $data)
{
    // hello\n Will be encode by \Workerman\Protocols\Websocket::encode before to be sent
    $connection->send("hello\n");
};

// Run all workers
Worker::runAll();
```



## getRemoteIp
### Description:
```php
string Connection::getRemoteIp()
```

get remote ip

### Parameters

This function has no parameters.

### Return Values
The remote ip. For Example ```111.26.36.12```.

### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onConnect = function($connection)
{
    echo "new connection from ip " . $connection->getRemoteIp() . "\n";
};

// Run all workers
Worker::runAll();
```



## getRemotePort
### Description:
```php
int Connection::getRemotePort()
```

Get remote port

### Parameters

This function has no parameters.


### Return Values

The remote port. Example ```5698```.

### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onConnect = function($connection)
{
    echo "new connection from address " .
    $connection->getRemoteIp() . ":". $connection->getRemotePort() ."\n";
};

// Run all workers
Worker::runAll();
```



## close
### Description:
```php
void Connection::close(mixed $data = '')
```

Sends a FIN packet.

```$connection->onClose``` will be called when send buffer is mepty.

### Parameters

``` $data ```

Optional data.

If the protocol is setted, ```$data``` will be encoded with ```Protocol::encode($data)``` before send.

### Return Values


### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onMessage = function($connection, $data)
{
    // hello\n Will be encode by \Workerman\Protocols\Websocket::encode before to be sent
    $connection->close("hello\n");
};

// Run all workers
Worker::runAll();
```



## destroy
### Description:
```php
void Connection::destroy()
```

Destroy the connection.

```$connection->onClose``` event will be called directly following this event

### Parameters

This function has no parameters.

### Return Values

No value is returned.

### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onMessage = function($connection, $data)
{
    // if something wrong
    $connection->destroy();
};

// Run all workers
Worker::runAll();
```



## pauseRecv
### Description:
```php
void Connection::pauseRecv(void)
```

Pauses the reading of data. That is, 'data' events will not be emitted. Useful to throttle back an upload.

### Parameters

This function has no parameters.

### Return Values
No value is returned.


### Examples

```php
use Workerman\Worker;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onConnect = function($connection)
{
    $connection->messageCount = 0;
};
$worker->onMessage = function($connection, $data)
{
    // Stop receive data when 100 package received
    $limit = 100;
    if(++$connection->messageCount > $limit)
    {
        $connection->pauseRecv();
    }
};

// Run all workers
Worker::runAll();
```



## resumeRecv
### Description:
```php
void Connection::resumeRecv(void)
```

Resumes reading after a call to pause().

### Parameters

This function has no parameters.

### Return Values

No value is returned.

### Examples

```php
use Workerman\Worker;
use Workerman\Lib\Timer;
require_once './Workerman/Autoloader.php';

$worker = new Worker('websocket://0.0.0.0:8484');
$worker->onConnect = function($connection)
{
    $connection->messageCount = 0;
};
$worker->onMessage = function($connection, $data)
{
    // Stop receive data when 100 package received
    $limit = 100;
    if(++$connection->messageCount > $limit)
    {
        $connection->pauseRecv();
        // Resumes reading after 30s
        Timer::add(30, function($connection){
            $connection->resumeRecv();
        }, array($connection), false);
    }
};

// Run all workers
Worker::runAll();
```



## AsyncTcpConnection

AsyncTcpConnection Extends TcpConnection




## __construct
### Description:
```php
void \Workerman\Connection\AsyncTcpConnection::__construct(string $remote_address)
```
Create an async connection.

#### Parameters
``` remote_address ```

Address，such as ```tcp://www.google.com:80```


#### Examples
```php
use \Workerman\Worker;
use \Workerman\Connection\AsyncTcpConnection;
require_once './Workerman/Autoloader.php';

$task = new Worker();
$task->onWorkerStart = function($task)
{
    $connection_to_google = new AsyncTcpConnection('tcp://www.google.com:80');

    $connection_to_google->onConnect = function($connection_to_google)
    {
        echo "connect success\n";
        $connection_to_google->send("GET / HTTP/1.1\r\nHost: www.google.com\r\nConnection: keep-alive\r\n\r\n");
    };

    $connection_to_google->onMessage = function($connection_to_google, $http_buffer)
    {
        echo $http_buffer;
    };

    $connection_to_google->onClose = function($connection_to_google)
    {
        echo "connection closed\n";
    };

    $connection_to_google->onError = function($connection_to_google, $code, $msg)
    {
        echo "Error code:$code msg:$msg\n";
    };

    $connection_to_google->connect();
};

// Run all workers
Worker::runAll();

```




## connect
### Description:
```php
void \Workerman\Connection\AsyncTcpConnection::connect()
```
Opens the connection.

#### Parameters
This function has no parameters.


#### Return Values
No value is returned.

#### Examples: Mysql proxy

```php
use \Workerman\Worker;
use \Workerman\Connection\AsyncTcpConnection;
require_once './Workerman/Autoloader.php';

$REAL_MYSQL_ADDRESS = 'tcp://127.0.0.1:3306';

$proxy = new Worker('tcp://0.0.0.0:4406');

$proxy->onConnect = function($connection)
{
    global $REAL_MYSQL_ADDRESS;

    $connection_to_mysql = new AsyncTcpConnection($REAL_MYSQL_ADDRESS);

    $connection_to_mysql->onMessage = function($connection_to_mysql, $buffer)use($connection)
    {
        $connection->send($buffer);
    };

    $connection_to_mysql->onClose = function($connection_to_mysql)use($connection)
    {
        $connection->close();
    };

    $connection_to_mysql->onError = function($connection_to_mysql)use($connection)
    {
        $connection->close();
    };

    $connection_to_mysql->connect();

    $connection->onMessage = function($connection, $buffer)use($connection_to_mysql)
    {
        $connection_to_mysql->send($buffer);
    };

    $connection->onClose = function($connection)use($connection_to_mysql)
    {
        $connection_to_mysql->close();
    };

    $connection->onError = function($connection)use($connection_to_mysql)
    {
        $connection_to_mysql->close();
    };

};

// Run all workers
Worker::runAll();
```

 **Test**

```shell
mysql -uroot -P4406 -h127.0.0.1 -p

Welcome to the MySQL monitor.  Commands end with ; or \g.
Your MySQL connection id is 25004
Server version: 5.5.31-1~dotdeb.0 (Debian)

Copyright (c) 2000, 2013, Oracle and/or its affiliates. All rights reserved.

Oracle is a registered trademark of Oracle Corporation and/or its
affiliates. Other names may be trademarks of their respective
owners.

Type 'help;' or '\h' for help. Type '\c' to clear the current input statement.

mysql>

 ```




## Timer



## add
### Description:
```php
int \Workerman\Lib\Timer::add(float $time_interval, callable $callback [,$args = array(), bool $persistent = true])
```
To schedule execution of a callback after/every ```$time_interval``` seconds. Returns a timerId for possible use with Timer::del(). Optionally you can also pass arguments to the callback.

### Notice
You should add Timer at ```on{.....}``` callbacks, otherwise the timer will have no effect


#### Parameters
``` time_interval ```

The delay time. For example 2.5, 1, 0.01


``` callback ```

Callback

``` args ```

Arguments

``` persistent ```

If the execution is one-time callback, ``` persistent ``` should be set ```false```, otherwise ``` persistent ``` should be set ```true```.

#### Return Values
Return a integer as timerid

#### Examples
```php
use \Workerman\Worker;
require_once './Workerman/Autoloader.php';

$task = new Worker();
$task->onWorkerStart = function($task)
{
    $time_interval = 2.5;
    \Workerman\Lib\Timer::add($time_interval, function()
    {
        echo "task run\n";
    });
};

// Run all workers
Worker::runAll();

```



## del
### Description:
```php
boolean \Workerman\Lib\Timer::del(int $timer_id)
```
Stops a Timer.

#### Parameters
``` timer_id ```

TimerId which returned by ```Timer::add```

#### Return Values
boolean


#### Examples

```php
use \Workerman\Worker;
require_once './Workerman/Autoloader.php';

$task = new Worker();
$task->onWorkerStart = function($task)
{
    $timer_id = \Workerman\Lib\Timer::add(2, function()
    {
        echo "task run\n";
    });
    Timer::add(20, function($timer_id)
    {
        Timer::del($timer_id);
    }, array($timer_id), false);
};

// Run all workers
Worker::runAll();
```



## WebServer
WebServer extends Worker



## __construct
### Description:
```php
void \Workerman\WebServer::__construct(string $address)
```
Create an WebServer instance

#### Parameters
``` address ```

Address，such as ```tcp://0.0.0.0:80```

#### Notice

These superglobal variables are available

``` $_SERVER、$_GET、$_POST、$_FILES、$_COOKIE、$_SESSION、$_REQUEST ```


**This WebServer's ```$_FILES``` is different from native php**

```php

var_export($_FILES);

// The above code will output like
array(
    0 => array(
        'file_name' => 'logo.png', // file name
        'file_size' => 23654,      // file size
        'file_data' => '*****',    // file data ,maybe binary
    ),
    1 => array(
        'file_name' => 'file.tar.gz',
        'file_size' => 128966,
        'file_data' => '*****',
    ),
    ...
);

```

Save upload files like
```php
foreach($_FILES as $file_info)
{
    file_put_contents('/tmp/'.$file_info['file_name'], $file_info['file_data']);
}
```



#### Examples
```php
use \Workerman\WebServer;
require_once './Workerman/Autoloader.php';

$webserver = new WebServer('http://0.0.0.0:80');
$webserver->addRoot('www.example.com', '/your/path/of/web/');
$sebserver->count = 4;

// Run all workers
Worker::runAll();
```




## addRoot

### Description:
```php
int \Workerman\WebServer::addRoot(string $domain, string $path)
```
Set the document root directory for the ```$domain```


#### Parameters
``` domain ```

Domain.


``` path ```

The document root directory for the ```$domain```


#### Return Values
No value is returned.

#### Examples
```php
use \Workerman\WebServer;
require_once './Workerman/Autoloader.php';

$webserver = new WebServer('http://0.0.0.0:80');
$webserver->addRoot('www.example.com', '/your/path/of/web/');
$sebserver->count = 4;

// Run all workers
Worker::runAll();
```



