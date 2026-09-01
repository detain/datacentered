# WebSocket-revamp feature flags

Runtime feature-flag mechanism for the WebSocket-revamp program's rollout.
Implemented in [`Applications/Chat/FeatureFlags.php`](../Applications/Chat/FeatureFlags.php).

This is the "single helper every new code path calls to decide legacy-vs-new"
required by plan step **0.5** and section **B8**.

> **Live gates.** The flags are read on real paths today: `Events::dispatchV1()`
> and `Web/trigger_payment.php` consult Flag A, and Flag C gates the datacenter
> bot's spawn/move/cleanup in `Events.php`. Flag B has no call-site reader yet
> (it will gate legacy-path retirement at P7). All three flags default **ON
> when unset** — the shipped code is enabled-by-default (Flag A has been
> enabled-by-default since commit 9eabb50); "dormant" is an explicit operator
> setting, or the fail-safe state while Redis is down.

See also:
- [`ws_revamp_plan.md`](../ws_revamp_plan.md) section **B8** — rollout & feature-flag lifecycle (the source of truth for the design).
- [`ws_progress.md`](../ws_progress.md) step **0.5** — this step's status and record.
- [`docs/AUTH_DESIGN.md`](AUTH_DESIGN.md) — the token-auth design (step 0.4) which is itself gated by Flag A.

---

## The three flags

| Flag | Constant / helper | Default | Scope | Meaning |
|---|---|---|---|---|
| **A** | `ws_new_handling` — `useNewHandling($hostId)` | **ON** (unset ⇒ ON) | Global default **and** per web/datacentered server or per VPS/QS host (per-host override falls back to the global default) | When ON, that server/host **utilizes** the new WS handling. When OFF, the new code is present but dormant/passive and the legacy path is active. |
| **B** | `ws_legacy_compat` — `legacyCompatEnabled()` | **ON** (unset ⇒ ON) | Global only | While ON, the legacy handling remains fully functional. Turned OFF only after Flag A is ON everywhere; this disables backward compatibility. |
| **C** | `dc_bot_presence` — `dcBotPresenceEnabled()` | **ON** (unset ⇒ ON) | Global only | Gates bot spawn/move/cleanup for the datacenter 3D presence system. |

### Why these defaults

The unset defaults are the **shipped defaults**: A, B and C all read ON when
their keys are absent. An earlier revision of the source header claimed a
missing `ws_new_handling` meant OFF — the code never did that, and the header
(now the contract) matches the implementation. The fail-safe direction is
deliberately asymmetric: a Redis outage flips only Flag A OFF (deploying
dormant code stays a runtime no-op — B8 "ship dormant" — and callers
transparently take the legacy path), while B and C stay ON because *disabling*
legacy compatibility or bot presence during an outage would be the destructive
direction. **No implementation phase flips a flag** — only an operator does, on
their own schedule (P7).

### The three-state lifecycle (B8)

The flags give three operator-driven states:

| State | Flag A | Flag B | Behavior | When |
|---|---|---|---|---|
| **1 — Dormant** | OFF | ON | Exactly the pre-revamp behavior. New code deployed everywhere but inert. | Explicit operator setting, or the fail-safe state while Redis is down. |
| **2 — Adoption** | ON | ON | New handling active where A is on; legacy still works everywhere; freely reversible per host. | **The unset default today.** |
| **3 — New-only** | ON (everywhere) | OFF | Backward compatibility disabled; new handling only. | Operator-initiated, after adoption is complete and soaked. |

Flag A is per-server/per-host and reversible at any time. Flag B is the global
"no going back" switch, flipped last. Physical deletion of legacy code (P7) only
happens after Flag B has been OFF everywhere and stable.

### UNSET vs UNREACHABLE

These two are deliberately different, and the shipped code behavior is the
contract:

| Redis state | Flag A | Flag B | Flag C | Writers |
|---|---|---|---|---|
| Reachable, flag key ABSENT | **ON** | **ON** | **ON** | work normally |
| Reachable, flag explicitly `0`/`1` | stored value | stored value | stored value | work normally |
| Unreachable / commands throw | **OFF** | **ON** | **ON** | return **`false`** |

Redis reachable + unset is the shipped default (everything ON). Unreachable is
routed through each accessor's own `catch (\Throwable)` fail-safe branch:
A falls back OFF so callers take the legacy path, B and C fall back ON because
disabling them is the destructive direction.

> **"Unreachable" covers both facade failure modes.** No resolvable client
> (`USE_REDIS` off / no `$redis` global / connect failed) **and** a
> live-but-dead handle (the command itself throws, e.g. phpredis raising after
> a Redis restart) are the same state for flags: `SharedState` wraps every
> command into fail-safe returns, and `FeatureFlags` re-raises via
> `SharedState::transportFailed()` so the row above still applies instead of a
> swallowed throw being mistaken for an unset flag. The facade re-probes every
> 30s (`REPROBE_INTERVAL`) and self-heals once the handle answers PING, so
> this state ends automatically after Redis returns — no restart.

---

## Storage mechanism

Flags are stored in **Redis through the SharedState facade**
([`Applications/Chat/SharedState.php`](../Applications/Chat/SharedState.php),
`dc:flag:` namespace, **written with no TTL** — flags persist until an operator
toggles them), so they are runtime-readable and toggleable across every worker
process **without a redeploy or restart**. This replaced the retired GlobalData
variables of the GlobalData→Redis migration.

| Redis key (full) | Type | Meaning |
|---|---|---|
| `dc:flag:ws_new_handling` | int `0`/`1` (JSON) | Flag A global default. **Absent = 1 (ON).** |
| `dc:flag:ws_new_handling_host_<id>` | int `0`/`1` (JSON) | Flag A per-host override. **Absent = inherit the global default.** |
| `dc:flag:ws_legacy_compat` | int `0`/`1` (JSON) | Flag B. **Absent = 1 (ON).** |
| `dc:flag:dc_bot_presence` | int `0`/`1` (JSON) | Flag C. **Absent = 1 (ON).** |

Values are written by `SharedState::set()` as JSON ints; `SharedState` enforces
the `dc:*` namespace on every key it touches.

The `<id>` in the per-host key is the host id normalized to a safe key suffix
via `FeatureFlags::hostVar($hostId)`: any character outside `[A-Za-z0-9_]`
is replaced with `_`. So host `10.0.0.5` → `dc:flag:ws_new_handling_host_10_0_0_5`,
and host `web-node-a` → `dc:flag:ws_new_handling_host_web_node_a`.

> **Collision note.** Two host ids that normalize to the same safe suffix (e.g.
> `10.0.0.5` and `10-0-0-5`) share one override slot. This is intended and
> documented behavior; in practice host ids come from a controlled set.

The constants are exposed on the class for callers/tooling:
`FeatureFlags::VAR_NEW_HANDLING`, `FeatureFlags::VAR_NEW_HANDLING_HOST_PREFIX`,
`FeatureFlags::VAR_LEGACY_COMPAT`, `FeatureFlags::VAR_DC_BOT_PRESENCE` (each is
already the full `dc:flag:`-namespaced key).

### Fail-safe to legacy when Redis is unreachable

Reads go through `FeatureFlags::readFlag()`, which **throws on purpose** when
`SharedState::client()` resolves no Redis client — that routes "unreachable"
through each accessor's `catch (\Throwable)` fail-safe branch, the single
documented place the defaults live (the facade's own null return could not
distinguish "Redis down" from "flag unset", and those have **opposite** Flag A
defaults). Because `SharedState` now swallows transport throws into that same
null, `readFlag()` also throws when `SharedState::transportFailed()` reports
the facade distrusts its live-but-dead handle — a swallowed throw is never
read as "unset". Concretely:

- The Redis client is resolved by `SharedState`: the process-wide `$redis`
  global when it is a live `\Redis` (shared, never replaced), else a lazy
  connect using `USE_REDIS`/`REDIS_HOST`/`REDIS_PORT` (2s timeout, no auth —
  trusted LAN), matching the existing `start_task.php` / `start_web.php` idiom.
- Fail-safe values on unreachability: `useNewHandling()` → **`false`** (legacy
  path stays active); `legacyCompatEnabled()` → **`true`**;
  `dcBotPresenceEnabled()` → **`true`**.
- Writers (`setNewHandling`, `setLegacyCompat`, `setDcBotPresence`,
  `clearNewHandlingOverride`) **fail closed**: they return `false` rather than
  throw when Redis is unavailable.
- Failures are logged via `logFailSafeOnce()` — **once per accessor/writer per
  process** (readers and writers each keep their own guard, so one accessor
  falling over never silences another's first report) — using a process-safe
  helper (`Worker::safeEcho()` inside workers, `error_log()` elsewhere) and
  swallowed. A Redis outage can never break a call site or flip behavior on.

---

## API

All methods are `public static` on `FeatureFlags`.

### Reads (what call sites use)

```php
// Flag A — should this server/host use the new WS handling?
// $hostId is optional; null/'' consults only the global default.
FeatureFlags::useNewHandling($hostId = null): bool

// Flag B — is the legacy handling still enabled? (global)
FeatureFlags::legacyCompatEnabled(): bool

// Flag C — is the DC bot presence system enabled? (global)
FeatureFlags::dcBotPresenceEnabled(): bool
```

`useNewHandling()` checks the per-host override first (when a `$hostId` is given),
then the global default. An unset flag means ON; a Redis error means OFF.

**The branch pattern live call sites use:**

```php
if (FeatureFlags::useNewHandling($hostId)) {
    // New WS handling for this host/server (Flag A is ON here).
    handle_via_new_path($hostId, $payload);
} elseif (FeatureFlags::legacyCompatEnabled()) {
    // Unchanged legacy path.
    handle_via_legacy_path($hostId, $payload);
} else {
    // State 3 (new-only) and this host somehow lacks the new path:
    // an operator has turned legacy off, so refuse rather than fall back.
}
```

### Writes (operator tooling)

```php
// Set Flag A. $hostId = null/'' sets the GLOBAL default; otherwise a per-host override.
FeatureFlags::setNewHandling($hostId, bool $on): bool

// Remove a per-host Flag A override so the host inherits the global default again.
FeatureFlags::clearNewHandlingOverride($hostId): bool

// Set Flag B (global legacy-compat switch).
FeatureFlags::setLegacyCompat(bool $on): bool

// Set Flag C (DC bot presence).
FeatureFlags::setDcBotPresence(bool $on): bool
```

Each returns `true` on a successful write, `false` if Redis was unavailable or
errored (fail-closed, never throws). `clearNewHandlingOverride` is idempotent —
clearing an absent override still returns `true` (it first checks
`SharedState::client()` and returns `false` only when Redis is absent).

### Helper

```php
// Build the full dc:flag:-namespaced Redis key for a per-host Flag A override.
FeatureFlags::hostVar($hostId): string   // e.g. 'dc:flag:ws_new_handling_host_10_0_0_5'
```

---

## Operating the flags

There is **no CLI or UI tool for toggling these flags yet** — that operator
tooling is future work (likely **P7**, when the flag lifecycle is actually
exercised). Until then, an operator toggles flags by calling the static writers
from any context that can reach Redis (a worker process, or a one-off script
with the same `USE_REDIS`/`REDIS_HOST`/`REDIS_PORT` configuration the workers
use — the SharedState facade resolves the client itself):

```php
require_once '/home/sites/datacentered/Applications/Chat/FeatureFlags.php';

// --- Dormant a single host (State 2 -> State 1, per host; A is ON by default) ---
FeatureFlags::setNewHandling('host123', false);

// --- Roll that host back to the default (ON) ---
FeatureFlags::setNewHandling('host123', true);
// ...or drop the override entirely so it inherits the global default again:
FeatureFlags::clearNewHandlingOverride('host123');

// --- Global default OFF fleet-wide (hosts with an override keep it) ---
FeatureFlags::setNewHandling(null, false);

// --- New-only: after A is ON everywhere and soaked, disable legacy (State 3) ---
FeatureFlags::setLegacyCompat(false);

// --- Silence the datacenter bots (Flag C) ---
FeatureFlags::setDcBotPresence(false);
```

Because the values live in Redis, changes take effect immediately across all
worker processes — no restart. Reverting is the same call with the opposite
value, or deleting the key to fall back to the unset default (ON).

---

## Testing

Unit tests live in
[`tests/FeatureFlagsTest.php`](../tests/FeatureFlagsTest.php) (PHPUnit; config in
`phpunit.xml.dist`). Run them with:

```bash
php vendor/bin/phpunit tests/FeatureFlagsTest.php
```

The suite has two layers:

1. **Fail-safe layer (the ship-dormant guarantee).** With **no resolvable Redis
   client** — the genuine state in a CLI/PHPUnit run without `USE_REDIS` — the
   tests assert Flag A reads OFF, Flags B and C read ON, and that writers fail
   closed (return `false`) rather than throw. The deliberately-throwing-client
   path is covered for real (a double whose commands raise), pinning that the
   exception is swallowed to the same fail-safe defaults, and that fail-safe
   logging is once per accessor/writer per process.
2. **Logic layer.** An in-memory Redis double (`InMemoryRedis`, declared in
   `tests/TestBootstrap.php`) is injected via `SharedState::setClient()`. It
   pins: unset Flag A reads **ON** when Redis is usable; global toggling;
   per-host override precedence (including forcing a host OFF while the global
   is ON); override clearing/revert (including clearing a nonexistent override
   succeeding); Flag B and Flag C toggling; `hostVar` normalization/collision
   behavior; and that every flag write lands under `dc:flag:` and persists with
   **no TTL**.

As of the Redis migration the suite is **18 tests passing**, and the flag accessors are
pinned to never construct a `\GlobalData\Client` or load production settings
(retired-store regression guard).
