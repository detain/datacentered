# DataCentered

Async PHP server platform managing VPS hosts via Workerman/GatewayWorker. Central hub; VPS host nodes connect via WSS. Business logic in `Applications/Chat/Events.php`, tasks in `Tasks/`, web in `Web/`.

## Commands

```bash
# Start all services
php start.php start

# Start in debug mode (foreground)
php start.php start -d

# Stop / graceful restart
php start.php stop
php start.php restart

# Code style check / fix (.php-cs-fixer.dist.php)
php vendor/bin/php-cs-fixer fix --dry-run
php vendor/bin/php-cs-fixer fix

# Install dependencies
composer install
```

## Architecture

**Entry**: `start.php` → loads `Applications/Chat/start_*.php` → `Worker::runAll()`

| Service | File | Port | Notes |
|---|---|---|---|
| Register | `start_register.php` | `1236` | GatewayWorker service registry (`text://`) |
| GlobalData | `start_globaldata.php` | `2207` | Cross-process shared variables |
| Channel | `start_channel.php` | `3333` | Pub/sub inter-process comms |
| Gateway | `start_gateway.php` | `7271` | WebSocket client connections |
| Gateway SSL | `start_gateway_ssl.php` | `7272` | WSS (certs at `/home/my/files/apache_setup/`) |
| BusinessWorker | `start_businessworker.php` | — | Calls `Events.php`; 5 processes |
| TaskWorker | `start_task.php` | `2208` | Text protocol; loads all `Tasks/*.php` |
| WebServer | `start_web.php` | `55151` | HTTP + HTTPS; serves `Web/` |

**myadmin1 only**: `globaldata`, `channel`, `register` (hostname check in `start.php`)
**GlobalData IP**: `GLOBALDATA_IP` constant from `/home/my/include/config/config.settings.php`
**Logs**: `Worker::$stdoutFile` → `/home/my/logs/billingd.log`

## Key Patterns

### Async Task Dispatch
```php
// Dispatch to TaskWorker at Text://127.0.0.1:2208
$task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
$task_connection->send(json_encode(['type' => 'my_task_function', 'args' => $args]));
$task_connection->onMessage = function ($connection, $task_result) use ($task_connection) {
    $task_connection->close();
};
$task_connection->connect();
```

### GlobalData CAS Lock
```php
global $global; // \GlobalData\Client instance at GLOBALDATA_IP:2207
$var = 'vps_host_' . $service_id;
if ($global->cas($var, 0, time())) {
    $global->$requestVar = 'working';
    // do work
    $global->$var = 0; // release lock
}
```

### Task Function Signature (`Tasks/*.php`)
Each file exports one `function filename($args)`. Auto-loaded from `Tasks/` on `onWorkerStart`. Available globals: `$worker_db` (Workerman MySQL), `$global` (GlobalData client), `$influx_v2_database`, `$memcache`, `$redis`.

## Task Functions (`Tasks/`)
- `bandwidth` — writes InfluxDB v2 bandwidth per VPS (in/out bytes)
- `map_queue_task` — builds IP/VNC/slice maps → Memcached or Redis per host IP
- `queue_queue_task` — pulls `queue_log` table entries → Memcached queues per host
- `processing_queue_task` — runs `process_payment` for billing queue entries
- `vps_queue_task` / `vps_get_list` / `vps_update_info` — VPS lifecycle ops via `vps_queue_handler`
- `async_hyperv_get_list` — async SOAP `GetVMList` via `clue/soap-react` + `React\Http\Browser`
- `sync_hyperv_queue` / `async_hyperv_queue_runner` — HyperV queue sync with CAS
- `hyperv_cleanupresources` — SOAP `CleanUpResources` call via `SoapClient`
- `get_map` — returns VPS IP/VNC/slice map for a host
- `memcached_queue_task` / `vps_update_info` — queue processing helpers

## Web Endpoints (`Web/`)
- `queue.php` — VPS/QS queue dispatch; actions: `map`, `get_queue`, `get_new_vps`, `queue`
- `logger.php` — ZoneMTA log ingestion → `mail_logentry` / `mail_messagestore` / `mail_senderdelivered`
- `prober.php` — JSON system stats (CPU, RAM, network, disk) via `SystemStats`/`NetworkStats`/`StorageStats`
- `systemstats_data.php` — live metrics for `Web/systemstats.html` dashboard (jQuery jqplot graphs)
- `Web/index.html` / `Web/lobby.html` — ChatOps UI (jQuery, Bootstrap, ReconnectingWebSocket)
- `Web/css/` — `chat.css`, `groups.css`, `lobby.css`, emotion picker CSS
- `Web/js/groups.js` — WebSocket group chat client

## Process & Utility Classes (`Applications/Chat/`)
- `Process.php` — PTY child process wrapper (`proc_open` + `TcpConnection` streams → `Gateway::sendToClient`)
- `stdObject.php` — callable-property bag (magic `__call` dispatch)
- `Events.php` — GatewayWorker business logic (onConnect, onMessage, onClose)

## Dependencies (`composer.json`)
- `workerman/workerman ^4.1`, `gateway-worker`, `globaldata`, `channel`, `global-timer`, `mysql`, `statistics`, `gatewayclient`
- `react/child-process`, `react/http 1.9.0`, `react/mysql`, `react/event-loop`
- `clue/soap-react` — async HyperV SOAP (`Tasks/async_hyperv_get_list.php`)
- `influxdata/influxdb-client-php` — InfluxDB v2 metrics (`Tasks/bandwidth.php`)
- `cache/memcached-adapter` — Memcached queue/map storage
- `corneltek/cliframework`, `guzzlehttp/guzzle >=6.0`

## Code Style
- PSR-2 + PHP 7.4 migrations (`@PSR2`, `@PHP74Migration`) — see `.php-cs-fixer.dist.php`
- Cache file: `.php-cs-fixer.cache`
- No trailing commas in multiline, no heredoc indentation, no method argument space changes
- All `Worker::safeEcho()` for process-safe output; never `echo` in workers without it

## Experiments (Reference Only)
- `experiments/amphp/` — amphp/Aerys/Artax/DNS async examples
- `experiments/swoole/` — Swoole server/client/chat/coroutine examples
- `experiments/swoole/install.sh` / `install_swoole.sh` — Swoole build scripts
- `experiments/swoole/chat/demo2/` — full Swoole WebSocket chat (CMD dispatch, Service locator, DB layer)

<!-- caliber:managed:pre-commit -->
## Before Committing

Run `caliber refresh` before creating git commits to keep docs in sync with code changes.
After it completes, stage any modified doc files before committing:

```bash
caliber refresh && git add CLAUDE.md .claude/ .cursor/ .github/copilot-instructions.md AGENTS.md CALIBER_LEARNINGS.md 2>/dev/null
```
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->
