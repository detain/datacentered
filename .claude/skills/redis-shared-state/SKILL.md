---
name: redis-shared-state
description: Implements cross-process coordination on Redis through the SharedState facade (Applications/Chat/SharedState.php), which replaced the retired GlobalData CAS client. Pattern: `$token = SharedState::lock($name, $ttl)` to acquire (null means someone else holds it, so skip), `SharedState::renew($name, $token, $ttl)` before each blocking op and ABORT on false, `SharedState::unlock($name, $token)` in `finally`. Also covers shared registries (dc:state: HASH vs per-key EX), feature flags and presence keys under the enforced `dc:*` namespace. Use when user says 'redis lock', 'shared state', 'lock between processes', 'cross-process coordination', 'SET NX', 'prevent concurrent', 'share variable', or coordinates work across Workerman/task processes. Do NOT use for local single-process state, and NEVER route the raw `queuein:<ip>` queue LIST through this facade.
---
# redis-shared-state

`SharedState` is a `final` static facade over phpredis (`\Redis`). It is the hub's shared memory: distributed locks, cross-process registries, feature flags and presence/session state. It keeps that state on Redis with real TTLs everywhere (the shared-state client it replaced had none, which is why stale-lock reapers used to exist). Values are always JSON-encoded/decoded by the facade; callers pass and receive plain PHP arrays/scalars. Written for PHP 7.4 syntax (typed properties via docblocks, `?string`, variadics, `finally`) — no PHP 8-only constructs.

## Critical

- **`lock()`/`renew()`/`unlock()` take a bare NAME; every other method takes a FULL key.** `lock()` prepends `dc:lock:` for you — pass `'vps_host_123'`, not `'dc:lock:vps_host_123'`. But `get`/`set`/`hSet`/`hGetAll`/etc. require the complete key INCLUDING a `dc:*` prefix, or `guardKey()` throws `InvalidArgumentException`.
- **A `null` token from `lock()` means "someone else holds it (or Redis is down)" — skip, never proceed.** This is the same contract the old `cas()` callers branched on.
- **Renew BEFORE each blocking/long operation and CHECK the return.** `renew()` returns `false` when you no longer own the lock (expired or taken). `false` ⇒ loud abort (`Worker::safeEcho` + `continue`/`return`/`break`) — never continue an operation while holding nothing.
- **Release in `finally` with the token.** `unlock($name, $token)` is owner-checked via Lua, so it is a safe no-op when the lock was already stolen — you never delete another holder's lock.
- **Lock tokens are stored RAW, never JSON.** `lock()` writes a raw `host:pid:hex` string because the Lua compare-and-delete/renew scripts `GET` it and string-compare against `ARGV`. Therefore you MUST NOT inspect or seed a lock's value with `get()`/`set()` — that path JSON-encodes and would corrupt the token. (See the RAW-token exception below.)
- **Every key must live under `dc:*`.** The facade guards this on all methods; the shared Redis DB0 also holds MyAdmin caches, so an unguarded write could clobber another subsystem's namespace.
- **Never route the raw `queuein:<ip>` DATA LIST through SharedState.** Only the drain LOCK `dc:lock:queuein:<ip>` goes through the facade; the Redis LIST / Memcached key `queuein:<ip>` that actually holds queue items is un-prefixed and lives outside `dc:*` by design.

## Instructions

### 1. Lock family TTLs (match old durations or run LONGER, never shorter)

Ops rule: timeouts and lock TTLs must match the retired shared-state windows or be longer. `GetVMList` and other host-side HyperV SOAP calls can take 10+ minutes under load, so a lock must never lapse before the operation it guards finishes. Concurrency rule: exactly ONE command per VPS host at a time (the per-host lock is the serialization point); parallelism is ACROSS hosts, never within one.

| Lock name | TTL | Where |
|---|---|---|
| `processing_queue` | 900 | `Events::processing_queue_timer()` |
| `vps_host_<id>` | 900 | `Tasks/vps_queue_task.php`, `Tasks/async_hyperv_*` (per-host) + `Events::vps_queue_timer()` |
| `queuein:<ip>` (LOCK key only) | 900 | `Tasks/memcached_queue_task.php` (raw data LIST stays un-prefixed) |
| `boardctl_asset_<id>` | 22200 | `Events::boardctl_queue_timer()` (6h job cap + 10min buffer) |
| `bot_owner:<loc>` | 10 | `Events` bot presence (state TTL 30 > lock, so a dead owner's ghost state lingers into the takeover window) |
| `startup_reap` | 60 | `Events::onWorkerStart` (`STARTUP_REAP_LOCK_TTL`) |

Reference the constants (`\Events::BOT_OWNER_LOCK_TTL`, `\Events::STARTUP_REAP_LOCK_TTL`, ...) rather than hard-coding where they exist.

### 2. Acquire → renew-check → finally-unlock (the full idiom)

Model after `Tasks/vps_queue_task.php`. Absence now means free, so the old "seed the key if unset" step and the "reset the value to 0" release are GONE — a crashed holder simply self-expires at the TTL.

```php
use Workerman\Worker;

$lockName = 'vps_host_' . $service_id;   // bare name; dc:lock: is prepended by lock()
$token = SharedState::lock($lockName, 900);
if ($token !== null) {
    try {
        // ... first handler ...
        // Renew between phases: false == the lock expired or was taken. Never run
        // another handler while holding nothing — loud abort, then move on.
        if (!SharedState::renew($lockName, $token, 900)) {
            Worker::safeEcho("lost lock {$lockName} — skipping remaining handlers\n");
            continue; // or return/break, depending on the loop
        }
        // ... next handler ...
    } finally {
        SharedState::unlock($lockName, $token); // owner-checked; no-op if stolen
    }
} else {
    // Held by another process (or Redis unavailable): skip this cycle, retry next tick.
}
```

For a plain acquire-and-go (no phases), you can still renew once mid-run for a long call and always release in `finally`.

### 3. Contention retries with backoff + per-item renew

When draining many items under one lock (e.g. `Tasks/memcached_queue_task.php`), retry acquisition a bounded number of times with random backoff, then renew between items so a full backlog can't outlive the TTL:

```php
$lockName = 'queuein:' . $hostIp;
$token = null;
$attempts = 0;
while ($attempts < 3) {
    $token = SharedState::lock($lockName, 900);
    if ($token !== null) {
        break;
    }
    $attempts++;
    usleep(rand(10000, 50000) * $attempts); // 10-50ms, then 20-100ms
}
if ($token === null) {
    continue; // another worker owns this host's drain this cycle
}
try {
    foreach ($batchItems as $item) {
        if (!SharedState::renew($lockName, $token, 900)) {
            Worker::safeEcho("Lost {$lockName} mid-drain — stopping this host this cycle\n");
            break; // finally below no-ops the unlock since ownership is gone
        }
        // ... process $item ...
    }
} finally {
    SharedState::unlock($lockName, $token);
}
```

### 4. Shared registries — choose HASH vs per-key EX

Two storage shapes map the old whole-variable shared arrays onto Redis:

- **Redis HASH (one key, many fields)** — for registries you enumerate or mutate as a set, where each entry is keyed by an id. Field = entry id, value = JSON. This is what the legacy `hosts`/`rooms`/`timers`/`ptys`-style arrays became.
  ```php
  SharedState::hSet(\Events::HOSTS_REGISTRY_KEY, (string) $row['vps_id'], $row); // dc:state:hosts
  $hosts = SharedState::hGetAll(\Events::HOSTS_REGISTRY_KEY);                    // array, [] when absent
  $one   = SharedState::hGet(\Events::HOSTS_REGISTRY_KEY, (string) $id);
  SharedState::hDel(\Events::HOSTS_REGISTRY_KEY, (string) $id);
  ```
  For "register exactly once / collision guard", use `hSetNx()` (atomic HSETNX) instead of a read-then-write CAS loop:
  ```php
  if (!SharedState::hSetNx(\Events::PTYS_REGISTRY_KEY, $pty_id, $entry)) {
      // pty_id already in use by an open pty — reject
  }
  ```

- **Per-key STRING with EX (TTL)** — for entries that must independently expire, or where the whole-map round-trip would be wasteful. The `running` command registry pairs one TTL'd STRING per run id with a `dc:state:running_ids` SET index for enumeration.
  ```php
  $runningKey = \Events::RUNNING_KEY_PREFIX . $run_id; // dc:state:running:<id>
  if (!SharedState::add($runningKey, $entry, \Events::RUNNING_ENTRY_TTL)) {
      // entry already present (SET NX) — treat as duplicate
  }
  SharedState::sAdd(\Events::RUNNING_INDEX_KEY, $run_id);
  // read: $run = SharedState::get($runningKey);
  // done: SharedState::del($runningKey); SharedState::sRem(\Events::RUNNING_INDEX_KEY, $run_id);
  ```

Rule of thumb: HASH when membership is the unit (list/mutate the whole registry); per-key EX when each item carries its own lifetime or you want to touch one item without round-tripping the set. `add()` (SET NX) is the direct replacement for the old "seed only if absent" pattern; Redis existence semantics have no NULL-vs-empty trap.

### 5. Simple values, flags and presence

Scalar/JSON state, feature flags and presence records use the plain value methods with a full `dc:*` key:
```php
SharedState::set(SharedState::PREFIX_STATE . 'processing_queue_last', time()); // persists (ttl 0)
$last = SharedState::get(SharedState::PREFIX_STATE . 'processing_queue_last');  // null when absent
SharedState::exists($key);          // false when absent OR Redis down
SharedState::del($key, $otherKey);  // guarded before any removal

// Presence record with a TTL (dc:presence: STRING JSON):
SharedState::set(\Events::DC_ROOM_BOUNDS_KEY_PREFIX . $location, $bounds, \Events::PRESENCE_SESSION_TTL);
```

### 6. Fail-safe posture — reads degrade, writes/locks report, guards throw

When the facade cannot serve an authoritative answer — either **no client** (no `$GLOBALS['redis']`, `USE_REDIS` off, or the connect failed) or a **live-but-dead handle** (the command itself THROWS, e.g. phpredis `RedisException` after a Redis restart drops the shared socket) — it returns a fail-safe value and logs ONCE per process. Every command dispatch is wrapped, so a transport exception NEVER escapes into timers/Tasks; both modes degrade to the SAME values below. You therefore need NO `try/catch` around Redis availability; a bad result just means "someone else holds it (or Redis is down)". The argument guards, however, throw on programmer error regardless of client state — even while the transport is dead.

| Situation | Behavior |
|---|---|
| `get` / `hGet` — no client OR transport throw | returns `null` |
| `hGetAll` / `lRange` / `sMembers` / `zRange` / `zRangeByScore` — no client OR transport throw | returns `[]` |
| `set` / `add` / `exists` / `unlock` / `renew` / `hSetNx` / `zAdd` — no client OR transport throw | returns `false` (and `zAdd`/`hSetNx` report `false`) |
| `lock` — no client OR transport throw | returns `null` (treat as "skip" — NEVER as `false`; a null token must never reach `unlock()`, whose null token means unconditional force-delete) |
| `hIncr` / `sAdd` / `sRem` / `zRem` / `zRemRangeByScore` — no client OR transport throw | returns `0` |
| `del` / `hSet` / `hDel` — no client OR transport throw | void no-op |
| `guardKey` — key outside `dc:*` | THROWS `InvalidArgumentException` (before touching Redis, even when down or transport-dead) |
| `guardLockArgs` — empty name or TTL `< 1` | THROWS `InvalidArgumentException` |
| `rPushLtrim` — `max < 1` | THROWS `InvalidArgumentException` |

Design note: `lock()` returning `null` on Redis-down is intentional — the caller skips, which is safe. If the return value of a counter is decision-critical, take a `lock()` first (per the `hIncr` docblock: `0` is indistinguishable from "counter at zero after failure").

### 6a. Transport death is timed, not sticky — bounded self-heal (no restart)

A transport throw (or connect failure) marks the facade **transport-dead** for `SharedState::REPROBE_INTERVAL` (30s): inside the window `client()` answers null without touching the broken handle (one failed probe per window, not per call); the first call after the window clears the mark and re-probes. Consecutive shared-handle PING failures are counted. A shared `$redis` PING that answers (phpredis re-handshakes internally once the server returns — observed on phpredis 5.3.7) resumes that same handle immediately and clears the streak; it is never closed or replaced. After `SharedState::SHARED_HANDLE_GRACE_PROBES` (2) consecutive failed re-probe PINGs the facade **deprioritizes** the global for the rest of the process — never closed, replaced, or re-PINGed, just skipped — and heals through its OWN connection: the existing fallback handle is PING-verified (a dead one dropped, not closed), then one PING-guarded lazy `USE_REDIS` connect per elapsed window. A failing probe re-marks for another window. **Recovery is bounded on every path: ~1 window when the shared handle self-heals, ~2 windows before the fresh internal handle takes over — no restart needed.**

The swallow loses one distinction, and `SharedState::transportFailed()` restores it: true from a transport throw / connect failure until the next command's re-probe succeeds. While true, NO facade value is authoritative — a `null` lock is "transport dead", not "held elsewhere"; a `null` get is "unreadable", not "unset". Almost every caller should ignore it and take the same skip/abort branch as contention. The two that must not: `FeatureFlags` (unset vs unreachable have OPPOSITE Flag A defaults — it re-raises so its documented fail-safe branch runs) and `Events::processing_queue_timer()` (escalates ONLY transport death as a `RuntimeException` so a `trigger_payment` nudge answers `unavailable`, never a silent `ok`). That escalation is contained by each of the timer's THREE consumers: the 30s periodic tick (Workerman's `Timer::tick` safeCall absorbs and logs it), `Web/trigger_payment.php` (catches `\Throwable` → `unavailable`), and the legacy WS handler `Events::msgPaymentprocess()` (catches locally with a `Worker::safeEcho` line so the adjacent `boardctl_queue_timer()` nudge still runs — never call the timer bare from an onMessage path).

### 7. Test seam

Tests never open a socket. Inject the in-memory double (`InMemoryRedis`, declared in `tests/TestBootstrap.php`, a duck-type that does NOT extend `\Redis`) through `setClient()` — but first clear `$GLOBALS['redis']`, because `client()` prefers a live global `$redis` over any injected client:

```php
protected function setUp(): void
{
    unset($GLOBALS['redis']);
    $this->redis = new \InMemoryRedis();
    \SharedState::setClient($this->redis);
}

protected function tearDown(): void
{
    \SharedState::setClient(null);
    unset($GLOBALS['redis']);
    \SharedState::reset(); // drops the facade's client + failure/log memos
}
```

- Seed a rival/foreign lock RAW — never via `SharedState::set()` (that JSON-wraps the value and breaks the token compare):
  ```php
  $this->redis->set(\SharedState::PREFIX_LOCK . $name, $token, ['nx', 'ex' => $ttl]);
  ```
- TTLs run against a controllable clock: `$this->redis->fastForward($ttl + 1)` expires a key so you can test the dead-owner takeover window.
- Transport-recovery timelines (dead mark → re-probe window → self-heal) are driven with `SharedState::setTestClock($epoch)` — a test-only facade clock (null/`reset()` restores `time()`); never sleep 30s in a test, and `reset()` in `tearDown` clears the pin along with the other memos. `SharedState::setConnectFactory($fn)` is the companion test-only seam for the deprioritized-global path: the factory stands in for the lazy fallback connect (return a duck-typed client that answers `ping()`, or `null` for a refused connect), so a test can prove the fresh internal handle takes over after two failed shared PINGs without defining `USE_REDIS` or opening a socket.
- Assert the whole keyspace is clean after a negative path with `$this->redis->allKeys()` (expect `[]` or a documented set).
- **Integral-float JSON round-trip gotcha:** whole-valued floats (`400.0`, `-48.0`) come back as `int` through `set()`/`get()` (serialize_precision = -1). Assert coordinates/bounds via `assertEquals`/`assertEqualsWithDelta`, NOT `assertSame`. Non-integral floats survive as `float`.

## Examples

**User says:** "Add a lock to `Tasks/sync_widget_task.php` so it doesn't run concurrently per service."

**Actions taken:**
- `$lockName = 'widget_host_' . $service_id;` (bare name)
- `SharedState::lock($lockName, 900)`; on a non-null token, run the work in `try`, `renew` before each sub-step and abort on `false`, `unlock($lockName, $token)` in `finally`.

**Result:**
```php
function sync_widget_task($args)
{
    $service_id = $args['id'];
    $lockName   = 'widget_host_' . $service_id;
    $token      = SharedState::lock($lockName, 900);
    if ($token === null) {
        return; // another worker holds it (or Redis unavailable) — retry next tick
    }
    try {
        // ... fetch data ...
        if (!SharedState::renew($lockName, $token, 900)) {
            \Workerman\Worker::safeEcho("sync_widget_task lost {$lockName} before write\n");
            return false;
        }
        // ... write results ...
    } finally {
        SharedState::unlock($lockName, $token);
    }
    return true;
}
```

## Common Issues

- **`SharedState key 'foo' is outside the dc:* namespace`** — you passed a full-key method (`get`/`set`/`hSet`/…) an un-prefixed name. Use a `dc:*` key (e.g. `SharedState::PREFIX_STATE . 'hosts'`), or use `lock()` which takes a bare name and adds `dc:lock:` itself.
- **Lock never releases / next run skips** — you released without the token, or forgot `finally`. Always `unlock($name, $token)` (token-checked) inside `finally`; a plain `unlock($name)` is an UNCONDITIONAL delete meant only for admin/stale-cleanup overrides, never a normal completion path.
- **Operation ran while another process also held the lock** — you ignored a `renew()` `false`. Renew before each blocking step and abort loudly on `false`; do not assume the lock you took at the top is still yours.
- **A seeded lock in tests "doesn't take" or corrupts a token compare** — you wrote it with `SharedState::set()`. Lock values are RAW; seed rivals with `$redis->set(SharedState::PREFIX_LOCK.$name, $token, ['nx','ex'=>TTL])`.
- **Injected double is ignored, or a real socket is attempted** — you forgot `unset($GLOBALS['redis'])` in `setUp()`. `client()` resolves the global `$redis` FIRST; clear it, then `setClient()`.
- **`assertSame` fails on a stored `100.0`** — the JSON round-trip returned `int 100`. Use `assertEquals`/`WithDelta` for numeric values that could be whole.
- **`lock()` throws about a positive TTL** — TTL `< 1` is rejected (a no-TTL lock is the SPOF this facade replaces). Pass a real TTL from the family table above.
