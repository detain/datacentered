---
name: globaldata-cas
description: Implements GlobalData CAS (compare-and-swap) locking for distributed coordination across Workerman processes. Pattern: `$global->cas($var, 0, time())` to acquire, `$global->$var = 0` to release; uses request-state tracking variable (`$var.'_request'`) for debugging. `$global` is a `\GlobalData\Client` instance connecting to `GLOBALDATA_IP:2207`. Use when user says 'lock between processes', 'prevent concurrent', 'CAS lock', 'share variable', 'global state', or coordinates work across task workers. Do NOT use for local single-process state.
---
# globaldata-cas

## Critical

- **Always initialize before CAS**: Check `!isset($global->$var)` and set to `0` before the first `cas()` call. Skipping this causes a silent miss — CAS returns false when the key doesn't exist.
- **Always release on every exit path**: Every `return`, error branch, and async callback that exits early must call `$global->$var = 0`. A lock left set blocks all future runs silently.
- **Never use `$global->$var = 1` to acquire** — that bypasses atomicity. Only `$global->cas()` is atomic.
- `$global` is only available as a global inside task functions and worker event handler methods. Always declare `global $global;` with the `@var \GlobalData\Client` docblock.

## Instructions

1. **Declare the global with type hint** at the top of the function or method:
   ```php
   /**
    * @var \GlobalData\Client
    */
   global $global;
   ```
   Verify `$global` is a `\GlobalData\Client` connected to `GLOBALDATA_IP:2207` (set in `start_task.php:41`).

2. **Choose a lock key** using the pattern `'<noun>_<id>'` for per-service locks or a bare noun for global locks:
   - Per-service: `$var = 'vps_host_' . $service_id;` (see `async_hyperv_queue_runner.php:16`)
   - Global singleton: `$var = 'processsing_queue';` or `'queuein'` (see `memcached_queue_task.php:40`)

3. **Add a request-state tracking variable** for per-service locks (omit for simple boolean locks):
   ```php
   $requestVar = $var . '_request';
   ```
   This step uses the `$var` from Step 2.

4. **Initialize the lock key if not set**:
   ```php
   if (!isset($global->$var)) {
       $global->$var = 0;
   }
   ```
   Required before the first `cas()` in any code path that may run before the key exists.

5. **Acquire with CAS** — use `time()` as the held value for per-service locks (enables staleness detection); use `1` for simple boolean locks:
   ```php
   // Per-service (timestamp lock)
   if ($global->cas($var, 0, time())) {
       $global->$requestVar = 'initial_operation';
       // ... do work ...
       $global->$var = 0; // release
   } else {
       $delay = (int)time() - (int)$global->$var;
       Worker::safeEcho("couldnt get lock for {$var} (running {$global->$requestVar} for {$delay} seconds)\n");
   }

   // Global boolean lock
   if (!$global->cas('queuein', 0, 1)) {
       Worker::safeEcho('Cannot get global queuein lock, returning' . PHP_EOL);
       return;
   }
   // ... do work ...
   $global->queuein = 0;
   ```

6. **Update `$requestVar` at each sub-step** (per-service locks only) to track progress:
   ```php
   $global->$var = time();       // refresh timestamp so staleness check stays valid
   $global->$requestVar = 'next_operation';
   // ... next operation ...
   $global->$var = 0;            // release when fully done
   ```
   See `async_hyperv_queue_runner.php:26-35` for this multi-phase pattern.

7. **Release inside every async callback** that terminates the work chain:
   ```php
   $task_connection->onMessage = function ($connection, $task_result) use ($task_connection, $var) {
       $task_connection->close();
       global $global;
       $global->$var = 0;
   };
   ```
   Also add an `onClose` handler that releases the lock for connection-drop safety.

## Examples

**User says**: "Add a CAS lock to `Tasks/sync_widget_task.php` so it doesn't run concurrently per service."

**Actions taken**:
- `$var = 'widget_host_' . $service_id;`  
- `$requestVar = $var . '_request';`  
- Initialize, CAS-acquire with `time()`, set `$requestVar = 'fetch_data'`, do work, release.

**Result**:
```php
function sync_widget_task($args)
{
    /**
     * @var \GlobalData\Client
     */
    global $global;
    $service_id = $args['id'];
    $var = 'widget_host_' . $service_id;
    $requestVar = $var . '_request';
    if (!isset($global->$var)) {
        $global->$var = 0;
    }
    if ($global->cas($var, 0, time())) {
        $global->$requestVar = 'fetch_data';
        // ... do work ...
        $global->$var = 0;
    } else {
        $delay = (int)time() - (int)$global->$var;
        Worker::safeEcho("couldnt get lock for {$var} (running {$global->$requestVar} for {$delay} seconds)\n");
    }
}
```

## Common Issues

- **CAS always returns false on first run**: The key doesn't exist yet. Fix: add `if (!isset($global->$var)) { $global->$var = 0; }` before the `cas()` call.
- **Lock never releases after a crash/timeout**: The lock holds its timestamp value indefinitely. Add a staleness check: `if ((int)$global->$var > 0 && time() - (int)$global->$var > 300) { $global->$var = 0; }` before the `cas()` call.
- **`$global` is null inside a callback**: Re-declare `global $global;` at the top of every closure that needs it — PHP closures don't inherit globals automatically.
- **Key name collision across services**: Always prefix per-service keys with the module name (`vps_host_`, `qs_host_`) to avoid cross-module lock interference.
- **Lock held across an `AsyncTcpConnection` without `onClose`**: If the remote side drops the connection before `onMessage` fires, the lock is never released. Always pair an `onClose` handler that also calls `$global->$var = 0;`.
