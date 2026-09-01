---
name: async-task-dispatch
description: Dispatches work to the TaskWorker at `Text://127.0.0.1:2208` using `AsyncTcpConnection`, sending JSON `{type: 'function_name', args: {}}`. Also covers periodic task scheduling via core `Timer::add()` (from `Workerman\Timer`) in `onWorkerStart`. Use when user says 'dispatch task', 'run async', 'call task worker', 'schedule periodic', 'async dispatch', or needs to call a `Tasks/*.php` function from a Worker context. Do NOT use for direct synchronous function calls within the same process.
---
# async-task-dispatch

## Critical

- **Never** call `AsyncTcpConnection` outside of an async context (i.e., only inside Workerman event callbacks or timer callbacks — not in a bare script).
- **Always** call `$task_connection->connect()` **after** setting `onMessage` — setting handlers after `connect()` causes missed messages.
- Task functions are auto-loaded by filename in `Tasks/*.php`. The filename (without `.php`) **must** match the function name exactly, and it **must** match the `type` key in the JSON payload.
- Acquire a `SharedState` cross-process lock before dispatching if the task must not run concurrently (see the `redis-shared-state` skill). Acquire the lock **before** creating the `AsyncTcpConnection`.
- Periodic timers must be registered only in **worker process 0**: `if ($worker->id == 0)`.
- The task response is always JSON: `{"return": <value>}`. Decode with `json_decode($task_result, true)`.

## Instructions

### Step 1 — Create the Task file

Create a new PHP file in the `Tasks/` directory. The filename (without `.php`) and function name must match exactly.

```php
<?php

use Workerman\Worker;

function my_task_function($args)
{
    global $worker_db, $influx_v2_database, $memcache, $redis;
    // $worker_db → \Workerman\MySQL\Connection
    // $memcache  → \Memcached connected to localhost:11211
    // $redis     → \Redis (when USE_REDIS === true); the SharedState facade
    //              resolves its client from this global automatically

    $result = [];
    // ... your logic ...
    return $result; // serializable value; becomes {"return": ...}
}
```

Verify: `in_array('my_task_function', $functions)` will be true after TaskWorker restarts.

### Step 2 — Dispatch from a Worker event callback

Use this exact pattern:

```php
use Workerman\Connection\AsyncTcpConnection;

$task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
$task_connection->send(json_encode(['type' => 'my_task_function', 'args' => $args]));
$task_connection->onMessage = function ($connection, $task_result) use ($task_connection) {
    $task_connection->close();
    // Optionally: $data = json_decode($task_result, true); // ['return' => ...]
};
$task_connection->connect();
```

Verify: `$args` is JSON-serializable (no circular refs, no resource types).

### Step 3 — Add a SharedState lock (if task must not run concurrently)

Use the `SharedState` Redis facade — see the `redis-shared-state` skill for the full acquire / renew-check / finally-unlock discipline and the lock-name TTL table. Based on `Tasks/vps_queue_task.php`, acquire in the dispatcher **before** connecting and release when the task result returns:

```php
$lockName = 'my_task_lock';                  // bare name; lock() prepends dc:lock:
$token = SharedState::lock($lockName, 900);  // null == already running (or Redis down)
if ($token === null) {
    return; // someone else holds it this cycle — skip
}

$task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
$task_connection->send(json_encode(['type' => 'my_task_function', 'args' => $args]));
$task_connection->onMessage = function ($connection, $task_result) use ($task_connection, $lockName, $token) {
    $task_connection->close();
    SharedState::unlock($lockName, $token);  // owner-checked release on completion
};
$task_connection->onClose = function ($connection) use ($lockName, $token) {
    SharedState::unlock($lockName, $token);  // connection-drop safety; no-op if already released
};
$task_connection->connect();
```

Verify: The lock releases in both `onMessage` and `onClose` (a token-checked `unlock` is idempotent on the second call). For a long-running task, the task function itself must `SharedState::renew($lockName, $token, 900)` before each blocking call and abort on `false` — never continue while holding nothing.

### Step 4 — Register a periodic timer (if scheduling recurring work)

In `Applications/Chat/Events.php::onWorkerStart` (or equivalent), inside the `$worker->id == 0` block. Use core Workerman `Timer::add()` — the `$worker->id == 0` guard is the real mechanism that makes each timer fire exactly once across the 5 BusinessWorker processes (the removed `workerman/global-timer` was a thin wrapper around `Timer::add()` and provided no cross-process semantics of its own):

```php
use Workerman\Timer;

public static function onWorkerStart($worker)
{
    if ($worker->id == 0) {
        $args = [];
        // Optional hostname gate: only schedule on the host that owns this work
        if (gethostname() == 'myadmin1.interserver.net') {
            Timer::add(30, ['Events', 'my_queue_timer'], $args);
            // First invocation on startup (optional):
            Events::my_queue_timer();
        }
    }
}

public static function my_queue_timer()
{
    $task_connection = new AsyncTcpConnection('Text://127.0.0.1:2208');
    $task_connection->send(json_encode(['type' => 'my_task_function', 'args' => []]));
    $task_connection->onMessage = function ($connection, $task_result) use ($task_connection) {
        $task_connection->close();
    };
    $task_connection->connect();
}
```

Verify: Timer interval matches business need (30s for queues, 60s for maps, 3600s for bulk list ops).

### Step 5 — Restart and verify

```bash
php start.php restart
# Watch for TaskWorker boot errors:
tail -f /home/my/logs/billingd.log | grep -i 'task\|error\|fatal'
```

Verify: No parse errors in the task file; function appears in loaded `$functions` array.

## Examples

**User says:** "Add a task that pulls pending DNS records from the DB every 60 seconds"

**Actions taken:**
1. Create `Tasks/dns_queue_task.php` with `function dns_queue_task($args) { ... return $output; }`
2. Add timer in `Applications/Chat/Events.php::onWorkerStart` under `$worker->id == 0` (with `use Workerman\Timer;`):
   ```php
   Timer::add(60, ['Events', 'dns_queue_timer'], []);
   ```
3. Add `Events::dns_queue_timer()` static method that dispatches via `AsyncTcpConnection`
4. Restart: `php start.php restart`

**Result:** Every 60 s, worker 0 fires `dns_queue_timer()`, which opens a non-blocking TCP connection to port 2208, TaskWorker calls `dns_queue_task([])`, returns result JSON, connection closes.

## Common Issues

**"Function not found" / task silently returns `false`**
The `type` key doesn't match a loaded function name. Run:
```bash
grep -r 'function ' Tasks/ | grep -v '//' | awk -F'function ' '{print $2}' | cut -d'(' -f1
```
Ensure the function name, filename (without `.php`), and `type` value are identical.

**Task fires but the lock is never released — subsequent runs skip**
A holder returned or threw before releasing. With `SharedState` a crashed holder self-expires at the lock TTL (no manual reset, unlike the old CAS flag), but still release on every path: wrap synchronous work and, for async dispatch, release in both `onMessage` and `onClose`. For a synchronous body use try/finally:
```php
try { /* work */ } finally { SharedState::unlock($lockName, $token); }
```

**`onMessage` never fires — connection hangs**
Handlers set after `connect()`. Always set `onMessage` **before** calling `$task_connection->connect()`.

**`Connection refused on 127.0.0.1:2208`**
TaskWorker is not running. Check:
```bash
php start.php status | grep TaskWorker
tail -50 /home/my/logs/billingd.log
```
If absent, run `php start.php start -d` and check for PHP syntax errors in `Tasks/`.

**Timer fires on every worker process, not just once**
Missing `if ($worker->id == 0)` guard. All 5 BusinessWorker processes share `onWorkerStart`; without the guard, 5 parallel timers fire.
