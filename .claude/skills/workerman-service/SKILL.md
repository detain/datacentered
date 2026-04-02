---
name: workerman-service
description: Creates a new Workerman Worker service file in `Applications/Chat/start_*.php`. Use when user says 'add service', 'new worker', 'add process', 'new workerman component'. Handles boilerplate: use statements, buffer sizes (100MB), onConnect/onBufferFull/onBufferDrain/onError callbacks, GLOBAL_START guard, and start.php $services registration. Do NOT use for task functions in Tasks/*.php or gateway event handlers in Events.php.
---
# workerman-service

## Critical

- Every service file **must** end with the `GLOBAL_START` guard — without it, running the file directly will fail to boot.
- Buffer sizes must be set inside `onConnect`, not at the class level, to match the per-connection pattern used by existing service files.
- After creating the file, you **must** add the service name to `$services` in `start.php` or the worker will never load.
- Services that must only run on the admin host (e.g., globaldata, channel, register) go in the `array_merge(...)` branch in `start.php`. All other services go in the base `$services` array.

## Instructions

1. **Name the file** using the pattern `start_` followed by your service name (lowercase, underscores) and place it in the `Applications/Chat/` directory. The service name becomes the key added to `$services` in `start.php`.

2. **Write the file** using this exact boilerplate:
   ```php
   <?php

   use \Workerman\Worker;
   use \Workerman\Connection\TcpConnection;

   if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1) {
       ini_set('default_socket_timeout', 1200);
   }

   if (!defined('GLOBALDATA_IP')) {
       require_once '/home/my/include/config/config.settings.php';
   }

   $worker = new Worker('<protocol>://0.0.0.0:<port>');
   $worker->name  = '<ServiceName>';
   $worker->count = 5;

   $worker->onConnect = function ($connection) {
       $connection->maxSendBufferSize = 100*1024*1024; // 100MB send buffer
       $connection->maxPackageSize    = 100*1024*1024; // 100MB receive buffer
   };
   $worker->onBufferFull = function ($connection) {
       Worker::safeEcho("<ServiceName> bufferFull and do not send again\n");
   };
   $worker->onBufferDrain = function ($connection) {
       Worker::safeEcho("<ServiceName> buffer drain and continue send\n");
   };
   $worker->onError = function ($connection, $code, $msg) {
       Worker::safeEcho("<ServiceName> error {$code} {$msg}\n");
   };

   if (!defined('GLOBAL_START')) {
       Worker::runAll();
   }
   ```
   Replace `<protocol>` (`websocket`, `text`, `http`), `<port>`, and `<ServiceName>` for the new service.
   
   Verify the file exists in `Applications/Chat/` before proceeding.

3. **Add extra use-statements** only if needed (e.g., `use \GatewayWorker\BusinessWorker;` for GatewayWorker types). Follow the import order in existing files: Workerman core first, then GatewayWorker, then GlobalData.

4. **Add `onWorkerStart`** if the service needs initialization (DB connections, loading configs, registering with GlobalData). Model after `Applications/Chat/start_task.php` `onWorkerStart` for resource setup.

5. **Register in `start.php`** — open `start.php` and add `'<service_name>'` to `$services`:
   - Standard service (runs on all hosts): add to the base array on line 33.
     ```php
     $services = ['task', 'gateway', 'gateway_ssl', 'businessworker', 'web', '<service_name>'];
     ```
   - myadmin1-only service: add to the `array_merge` branch on line 35.
     ```php
     $services = array_merge(['globaldata', 'channel', 'register', '<service_name>'], $services);
     ```
   Verify `start.php` line 33–35 reflects the change before proceeding.

6. **Validate** the new service loads without errors:
   ```bash
   php start.php start -d
   ```
   Look for `<ServiceName>` in the process list and confirm no PHP parse errors in `/home/my/logs/billingd.log`.

## Examples

**User says:** "Add a new worker called `metrics` that listens on port 2210 with a text protocol"

**Actions:**
1. Create `Applications/Chat/start_metrics.php`:
   ```php
   <?php

   use \Workerman\Worker;
   use \Workerman\Connection\TcpConnection;

   if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1) {
       ini_set('default_socket_timeout', 1200);
   }

   if (!defined('GLOBALDATA_IP')) {
       require_once '/home/my/include/config/config.settings.php';
   }

   $worker = new Worker('text://0.0.0.0:2210');
   $worker->name  = 'Metrics';
   $worker->count = 5;

   $worker->onConnect = function ($connection) {
       $connection->maxSendBufferSize = 100*1024*1024;
       $connection->maxPackageSize    = 100*1024*1024;
   };
   $worker->onBufferFull = function ($connection) {
       Worker::safeEcho("Metrics bufferFull and do not send again\n");
   };
   $worker->onBufferDrain = function ($connection) {
       Worker::safeEcho("Metrics buffer drain and continue send\n");
   };
   $worker->onError = function ($connection, $code, $msg) {
       Worker::safeEcho("Metrics error {$code} {$msg}\n");
   };

   if (!defined('GLOBAL_START')) {
       Worker::runAll();
   }
   ```
2. Edit `start.php` line 33: `$services = ['task', 'gateway', 'gateway_ssl', 'businessworker', 'web', 'metrics'];`

**Result:** Running `php start.php start -d` shows a `Metrics` worker process listening on port 2210.

## Common Issues

- **`PHP Fatal error: Class 'Workerman\Worker' not found`** — the file was run directly without `vendor/autoload.php`. The autoloader is loaded by `start.php`, so either run via `php start.php start` or add `require_once __DIR__ . '/../../vendor/autoload.php';` for standalone testing.

- **Worker starts but is missing from process list** — `'<service_name>'` was not added to `$services` in `start.php`. Check lines 33–35 of `start.php`.

- **`Address already in use` on chosen port** — the port conflicts with an existing service. Current port assignments: 1236 (register), 2207 (globaldata), 2208 (task), 3333 (channel), 7271 (gateway), 7272 (gateway SSL), 55151 (web). Choose a port outside this list.

- **Service runs standalone but crashes under `start.php`** — missing `GLOBAL_START` guard at the bottom. Ensure the file ends with `if (!defined('GLOBAL_START')) { Worker::runAll(); }`.

- **`GLOBALDATA_IP` undefined** — config was not loaded. Ensure the guard block `if (!defined('GLOBALDATA_IP')) { require_once '/home/my/include/config/config.settings.php'; }` appears before any use of the constant.
