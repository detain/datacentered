---
name: gateway-event
description: Adds or modifies GatewayWorker event handling in Applications/Chat/Events.php. Use when user says 'handle message', 'on connect', 'gateway event', 'client message handler', 'broadcast to client', or needs to modify Events.php. Covers onConnect/onClose lifecycle hooks, msg* handler methods, Gateway::send* routing, session read/write, bindUid, joinGroup. Do NOT use for new Worker services, task dispatch to port 2208, or GlobalData timer setup.
---
# Gateway Event

## Critical

- All new message handlers MUST follow the naming convention `msgFooBar` — derived automatically from the `type` field: `'msg' . ucwords(str_replace(['-','_'], ' ', $type))` with spaces removed. The router in `onMessage` (line 150) calls `call_user_func(['Events', $method], ...)` — no registration needed, just add the method.
- NEVER access `$_SESSION` before verifying `$_SESSION['login'] == true` inside a handler (unless the handler IS the login handler). Unauthenticated clients must get an error and be closed.
- NEVER call `Gateway::setSession` without immediately following it with `Gateway::bindUid` and (if applicable) `Gateway::joinGroup` — session, UID binding, and group membership must be set together.
- All outbound messages MUST be `json_encode`d arrays with a `type` key.

## Instructions

1. **Open the file** `Applications/Chat/Events.php`. Confirm the existing method list and the imports at the top (lines 13–17):
   ```php
   use GatewayWorker\Lib\Gateway;
   use Workerman\Worker;
   use Workerman\Connection\AsyncTcpConnection;
   ```
   Verify these are present before adding any handler.

2. **Add a `msg*` handler method** inside the `Events` class. Use this exact skeleton:
   ```php
   /**
    * handler for when receiving a {type} message.
    *
    * @param int $client_id
    * @param array $message_data
    */
   public static function msgMyType($client_id, $message_data)
   {
       if ($_SESSION['login'] == true) {
           // validate required fields
           if (!isset($message_data['field'])) {
               throw new \Exception("\$message_data['field'] not set. client_ip:{$_SERVER['REMOTE_ADDR']}");
           }
           // business logic here
           Gateway::sendToCurrentClient(json_encode(['type' => 'my_type_response', 'content' => $result]));
           return;
       }
       return;
   }
   ```
   The method name maps directly to `type` in the client JSON: `type: 'my_type'` → `msgMyType`.

3. **Choose the correct send method**:
   | Scenario | Method |
   |---|---|
   | Reply to the sender only | `Gateway::sendToCurrentClient(json_encode($msg))` |
   | Reply to a known `$client_id` | `Gateway::sendToClient($client_id, json_encode($msg))` |
   | Send to all sessions of a UID | `Gateway::sendToUid($uid, json_encode($msg))` |
   | Broadcast to a group (e.g. `'admins'`, `'hosts'`, `'room_1'`) | `Gateway::sendToGroup($group_id, json_encode($msg))` |

4. **Read session data** using the superglobal `$_SESSION` (automatically populated by GatewayWorker). Write back with `Gateway::setSession($client_id, $_SESSION)`. Only call `setSession` during login/auth flows:
   ```php
   $_SESSION['uid'] = $uid;
   $_SESSION['login'] = true;
   $_SESSION['ima'] = 'admin'; // or 'host'
   Gateway::setSession($client_id, $_SESSION);
   Gateway::bindUid($client_id, $uid);
   Gateway::joinGroup($client_id, 'admins'); // or 'hosts'
   ```

5. **Check UID online before sending** (for host-targeted commands):
   ```php
   if (Gateway::isUidOnline($uid)) {
       Gateway::sendToUid($uid, json_encode($json));
   }
   ```

6. **Close a client** (with optional final payload):
   ```php
   Gateway::closeClient($client_id); // clean close
   Gateway::closeClient($client_id, json_encode('ok')); // close with final frame
   ```

7. **Log debug output** with `Worker::safeEcho` (not `echo`/`print`):
   ```php
   Worker::safeEcho("[{$client_id}] description {$_SERVER['REMOTE_ADDR']}\n");
   ```

8. **Run the style fixer** and verify no errors before considering the change done:
   ```bash
   php vendor/bin/php-cs-fixer fix --dry-run
   php vendor/bin/php-cs-fixer fix
   php start.php restart
   ```

## Examples

**User says:** "Add a `get_status` message handler that returns the session's uid and ima back to the sender."

**Actions taken:**
1. Add to `Events` class in `Applications/Chat/Events.php`:
   ```php
   public static function msgGetStatus($client_id, $message_data)
   {
       if ($_SESSION['login'] == true) {
           Gateway::sendToCurrentClient(json_encode([
               'type' => 'status',
               'uid'  => $_SESSION['uid'],
               'ima'  => $_SESSION['ima'],
           ]));
           return;
       }
       return;
   }
   ```
2. Client sends `{"type": "get_status"}` → server replies `{"type": "status", "uid": "...", "ima": "admin"}`.

## Common Issues

- **Handler never called / "Wanted to call method msgFoo but it doesnt exist"**: The `type` value from the client doesn't map to the method name. Check: `'msg' . str_replace(' ', '', ucwords(str_replace(['-','_'], ' ', $type)))`. `type: 'get-status'` → `msgGetStatus`; `type: 'get_status'` → `msgGetStatus`.
- **`$_SESSION` is empty in handler**: The client hasn't authenticated yet. Ensure `msgLogin` has been called and `Gateway::setSession` was invoked. You cannot read session in `onConnect` — it's only available after the first `onMessage`.
- **`Gateway::sendToUid` silently does nothing**: The UID is not online. Wrap with `Gateway::isUidOnline($uid)` check first.
- **`Call to undefined method Gateway::...`**: Missing `use GatewayWorker\Lib\Gateway;` at the top of `Events.php` (line 14).
- **Changes not taking effect after edit**: Run `php start.php restart` — the BusinessWorker processes must be reloaded. Check `/home/my/logs/billingd.log` for boot errors.