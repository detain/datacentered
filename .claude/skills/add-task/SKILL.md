---
name: add-task
description: Creates a new PHP task function in `Tasks/` following the project's auto-load pattern. Each file exports one function named after the file (e.g. `Tasks/my_task.php` → `function my_task($args)`). Handles available globals: `$worker_db`, `$global`, `$influx_v2_database`, `$memcache`, `$redis`. Use when user says 'add task', 'new task function', 'create task', 'task worker function', or adds/modifies files in `Tasks/`. Do NOT use for modifying `Events.php`, adding Web endpoints, or creating Worker services.
---
# add-task

## Critical

- **File name = function name.** The file name must match the function name exactly (e.g., `my_task.php` must contain `function my_task($args)`). Any mismatch breaks auto-loading.
- **Never declare a namespace** inside a task file — auto-loader calls `call_user_func($task_data['type'], ...)` by bare function name.
- **Always return a value** (string, bool, or `null`). The dispatcher wraps it in `{'return': $result}` and sends it back to the caller.
- **Do not connect to databases yourself.** `$worker_db`, `$global`, `$memcache`, `$redis`, and `$influx_v2_database` are already initialised before your function runs.

## Instructions

1. **Choose a snake_case name** matching the action (e.g. `sync_dns_records`).  
   Verify no file with that name exists in the `Tasks/` directory before continuing.

2. **Create the task file** in the `Tasks/` directory with this exact skeleton:

   ```php
   <?php

   require_once '/home/my/include/functions.inc.php';

   function <name>($args)
   {
       global $worker_db, $global, $influx_v2_database, $memcache, $redis;

       // TODO: implement

       return true;
   }
   ```

   Remove `require_once` only if the function needs no PEAR DB / `$GLOBALS['tf']` access (rare — see `bandwidth.php`).

3. **Add database queries** using one of these two patterns — pick the one that matches your access needs:

   *Workerman fluent (preferred for simple SELECTs):*
   ```php
   $row = $worker_db->select('*')->from('vps')->where('id = :id')
       ->bindValues(['id' => $args['id']])->row();
   ```

   *PEAR DB via `$GLOBALS['tf']->db` (needed when other `$GLOBALS['tf']` state is required):*
   ```php
   $db = $GLOBALS['tf']->db;
   $db->query("SELECT * FROM vps WHERE id=".(int)$args['id']);
   $db->next_record(MYSQL_ASSOC);
   $row = $db->Record;
   ```

4. **Add a GlobalData CAS lock** if the task must not run concurrently per resource:

   ```php
   $lockVar = '<name>_' . $args['id'];
   if (!isset($global->$lockVar)) {
       $global->$lockVar = 0;
   }
   if (!$global->cas($lockVar, 0, time())) {
       return 'locked';
   }
   // ... do work ...
   $global->$lockVar = 0; // always release
   ```

5. **Write to InfluxDB** only when the task collects metrics:

   ```php
   if (INFLUX_V2 === true) {
       $influx_v2_database->write(
           '<measurement>,tag1=val1 field1=' . (int)$value
       );
       $influx_v2_database->close();
   }
   ```

6. **Wrap the body in try/catch** for any external I/O:

   ```php
   try {
       // work
   } catch (\Exception $e) {
       error_log('<name> Got Exception ' . $e->getCode() . ': ' . $e->getMessage());
       \Workerman\Worker::safeEcho('<name> Got Exception ' . $e->getCode() . ': ' . $e->getMessage() . "\n");
       return false;
   }
   ```

7. **Dispatch the task** from `Applications/Chat/Events.php` or another task using the async pattern:

   ```php
   $conn = new \Workerman\Connection\AsyncTcpConnection('Text://127.0.0.1:2208');
   $conn->send(json_encode(['type' => '<name>', 'args' => $args]));
   $conn->onMessage = function ($c, $result) use ($conn) { $conn->close(); };
   $conn->connect();
   ```

   Verify the task name string matches the file/function name exactly.

8. **Run a style check** before finishing:
   ```bash
   php vendor/bin/php-cs-fixer fix --dry-run Tasks/
   php vendor/bin/php-cs-fixer fix Tasks/
   ```

## Examples

**User says:** "Add a task that clears expired sessions from the `sessions` table"

**Actions taken:**
1. Create `Tasks/clear_expired_sessions.php`
2. Function name matches: `function clear_expired_sessions($args)`
3. Use `$worker_db` fluent query; no metrics → skip InfluxDB
4. Return row count as confirmation string

**Result:**
```php
<?php

require_once '/home/my/include/functions.inc.php';

function clear_expired_sessions($args)
{
    global $worker_db;

    try {
        $cutoff = $args['cutoff'] ?? time();
        $worker_db->query(
            'DELETE FROM sessions WHERE expires < ' . (int)$cutoff
        );
        return 'cleared';
    } catch (\Exception $e) {
        error_log('clear_expired_sessions Got Exception ' . $e->getCode() . ': ' . $e->getMessage());
        \Workerman\Worker::safeEcho('clear_expired_sessions Got Exception ' . $e->getCode() . ': ' . $e->getMessage() . "\n");
        return false;
    }
}
```

Dispatch from `Applications/Chat/Events.php`:
```php
$conn = new \Workerman\Connection\AsyncTcpConnection('Text://127.0.0.1:2208');
$conn->send(json_encode(['type' => 'clear_expired_sessions', 'args' => ['cutoff' => time()]]));
$conn->onMessage = function ($c, $r) use ($conn) { $conn->close(); };
$conn->connect();
```

## Common Issues

- **`call_user_func(): function not found` in task worker log** — file name does not match function name, or file is in a subdirectory. Check `basename` matches exactly: `Tasks/clear_expired_sessions.php` → `function clear_expired_sessions`.
- **`$worker_db` is null / method call on non-object** — you declared `global $worker_db` but the task is running in a process where the DB connection dropped. Add a reconnect check or wrap in try/catch and log the PDOException code.
- **Task silently does nothing** — task was dispatched but `onMessage` never fired. Confirm the TaskWorker is running: `php start.php status` and check `/home/my/logs/billingd.log` for startup errors.
- **InfluxDB write hangs** — forgot `$influx_v2_database->close()` after the write loop. Always call `close()` once per task invocation.
- **`cas` never returns true (lock stuck)** — a previous run crashed before releasing. Manually reset: `$global->$lockVar = 0;` via a temporary task or debug script, then investigate the crash in the log.
