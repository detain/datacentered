workerman
workerman is a high-performance PHP socket service framework, developers can develop a variety of network applications in this framework, such as Rpc services, chat rooms, games and so on. workerman has the following features

multi-Progress
Support TCP / UDP
Support a variety of application layer protocols
Use libevent event polling library to support high concurrency
Support file update detection and automatic loading
Support service smooth reboot
Support telnet remote control and monitoring
Support abnormal monitoring and alarm
Support long connection
Support for running the worker process on the specified user
Visit www.workerman.net for more

The required environment
workerman need PHP version of not less than 5.3, you only need to install PHP Cli, without having to install PHP-FPM, nginx, apache workerman can not run on the Window platform

installation
1, download or git clone https://github.com/walkor/workerman-JsonRpc

2, run composer install

start stop
start up
php start.php start -d

Restart to start
php start.php restart

Smooth restart / reload configuration
php start.php reload

Check the service status
php start.php status

stop
php start.php stop

Rpc application using method
### Client synchronization call:


<?php
include_once 'yourClientDir/RpcClient.php';

$address_array = array(
          'tcp://127.0.0.1:2015',
          'tcp://127.0.0.1:2015'
          );
// Configure server list
RpcClient::config($address_array);

$uid = 567;

// User corresponding User class in applications / JsonRpc / Services / User.php
$user_client = RpcClient::instance('User');

// getInfoByUid corresponds to the getInfoByUid method in the User class
$ret_sync = $user_client->getInfoByUid($uid);
### Client Asynchronous Calling: RpcClient supports asynchronous remote calls

<?php
include_once 'yourClientDir/RpcClient.php';
// server list
$address_array = array(
  'tcp://127.0.0.1:2015',
  'tcp://127.0.0.1:2015'
  );
// Configure server list
RpcClient::config($address_array);

$uid = 567;
$user_client = RpcClient::instance('User');

// Asynchronously call the User :: getInfoByUid method
$user_client->asend_getInfoByUid($uid);
// Asynchronously call the User :: getEmail method
$user_client->asend_getEmail($uid);

Here are other business codes
....................
....................

// Receive data asynchronously when needed
$ret_async1 = $user_client->arecv_getEmail($uid);
$ret_async2 = $user_client->arecv_getInfoByUid($uid);

Here are other business logic
###Server:
Server Each class provides a set of services, class files by default on the Applications / JsonRpc / Services directory.
Clients are actually static methods that call these classes remotely. E.g:
<?php
RpcClient::instance('User')->getInfoByUid($uid);
The call is the getInfoByUid method of the User class in Applications / JsonRpc / Services / User.php.
User.php file looks like this

<?php
class User
{
       public static function getInfoByUid($uid)
        {
            // ....
        }
   
        public static function getEmail($uid)
        {
            // ...
        }
}
f you want to add a group of services, you can add a class file in this directory.

rpc monitoring page
rpc monitoring page address http://ip:55757
