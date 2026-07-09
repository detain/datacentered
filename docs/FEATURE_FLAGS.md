# WebSocket-revamp feature flags

Runtime feature-flag mechanism for the WebSocket-revamp program's **dormant-by-default**
rollout. Implemented in [`Applications/Chat/FeatureFlags.php`](../Applications/Chat/FeatureFlags.php).

This is the "single helper every new code path calls to decide legacy-vs-new"
required by plan step **0.5** and section **B8**. It is the foundation everything
in phases P1–P6 ships behind.

> **Dormant scaffolding.** As of Phase 0, **nothing calls `FeatureFlags` yet.**
> The class exists so that later phases can land new behavior guarded by it.
> Deploying the class today is a runtime no-op: with defaults, the system is
> byte-identical to today.

See also:
- [`ws_revamp_plan.md`](../ws_revamp_plan.md) section **B8** — rollout & feature-flag lifecycle (the source of truth for the design).
- [`ws_progress.md`](../ws_progress.md) step **0.5** — this step's status and record.
- [`docs/AUTH_DESIGN.md`](AUTH_DESIGN.md) — the token-auth design (step 0.4) which is itself gated by Flag A.

---

## The two flags

| Flag | Constant / helper | Default | Scope | Meaning |
|---|---|---|---|---|
| **A** | `WS_NEW_HANDLING` — `useNewHandling($hostId)` | **OFF** | Global default **and** per web/datacentered server or per VPS/QS host | When OFF, the new code is present but dormant/passive; the legacy path is active. When ON, that server/host **utilizes** the new handling. |
| **B** | `LEGACY_COMPAT` — `legacyCompatEnabled()` | **ON** | Global only | While ON, the legacy handling remains fully functional. Turned OFF only after Flag A is ON everywhere; this disables backward compatibility. |

### Why these defaults

The defaults encode "behaves exactly like today." Flag A defaults OFF so that
deploying dormant new code across every web/datacentered server and the
~thousands of VPS/QS hosts changes nothing at runtime. Flag B defaults ON so the
legacy path stays present and primary throughout the entire program and
indefinitely afterward. **No implementation phase flips a flag** — only an
operator does, on their own schedule (P7).

### The three-state lifecycle (B8)

The two independent flags give three operator-driven states:

| State | Flag A | Flag B | Behavior | When |
|---|---|---|---|---|
| **1 — Dormant** (default, indefinite) | OFF | ON | Exactly today's behavior. New code deployed everywhere but inert. | Throughout implementation and after all deploys, for as long as the operator wants. |
| **2 — Adoption** (gradual) | ON (rolling) | ON | New handling active where A is on; legacy still works everywhere; freely reversible per host. | Operator-initiated, long after P0–P6 are done and deployed. |
| **3 — New-only** | ON (everywhere) | OFF | Backward compatibility disabled; new handling only. | Operator-initiated, after adoption is complete and soaked. |

Flag A is per-server/per-host and reversible at any time. Flag B is the global
"no going back" switch, flipped last. Physical deletion of legacy code (P7) only
happens after Flag B has been OFF everywhere and stable.

---

## Storage mechanism

Flags are stored as **GlobalData variables** at `GLOBALDATA_IP:2207` (the
`\GlobalData\Client` shared-variable service). This makes them
runtime-readable and toggleable across every worker process **without a redeploy
or restart**.

| GlobalData variable | Type | Meaning |
|---|---|---|
| `ws_new_handling` | int `0`/`1` | Flag A global default. **Missing = `0` (OFF).** |
| `ws_new_handling_host_<id>` | int `0`/`1` | Flag A per-host override. **Missing = inherit the global default.** |
| `ws_legacy_compat` | int `0`/`1` | Flag B. **Missing = `1` (ON).** |

The `<id>` in the per-host variable is the host id normalized to a safe variable
name via `FeatureFlags::hostVar($hostId)`: any character outside `[A-Za-z0-9_]`
is replaced with `_`. So host `10.0.0.5` → `ws_new_handling_host_10_0_0_5`, and
host `web-node-a` → `ws_new_handling_host_web_node_a`.

> **Collision note.** Two host ids that normalize to the same safe name (e.g.
> `10.0.0.5` and `10-0-0-5`) share one override slot. This is intended and
> documented behavior; in practice host ids come from a controlled set.

The constants are exposed on the class for callers/tooling:
`FeatureFlags::VAR_NEW_HANDLING`, `FeatureFlags::VAR_NEW_HANDLING_HOST_PREFIX`,
`FeatureFlags::VAR_LEGACY_COMPAT`.

### Fail-safe to legacy when GlobalData is unreachable

The whole point is that a missing, fresh, or **unreachable** GlobalData server
yields today's behavior: **A = OFF, B = ON**. Concretely, every read and write is
wrapped in a `try/catch (\Throwable)` and resolves a client via `globalData()`:

- In a worker process, it reuses the process-wide `$global` client.
- Outside workers (web/CLI), it lazily connects using `GLOBALDATA_IP` (loaded
  from `/home/my/include/config/config.settings.php` if not already defined).
- If no client can be created, or any property access throws (e.g. the real
  client raising *"Timer can only be used in workerman running environment"*
  under the CLI SAPI), the reads return their **fail-safe** value:
  - `useNewHandling()` → **`false`** (legacy path stays active).
  - `legacyCompatEnabled()` → **`true`** (legacy compat stays available).
- Writers (`setNewHandling`, `setLegacyCompat`, `clearNewHandlingOverride`) **fail
  closed**: they return `false` rather than throw when GlobalData is unavailable.

Errors are logged via a process-safe helper (`Worker::safeEcho()` inside workers,
`error_log()` elsewhere) and swallowed — a GlobalData outage can never break a
call site or flip behavior on.

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
```

`useNewHandling()` checks the per-host override first (when a `$hostId` is given),
then the global default.

**How a future P1–P6 call site will branch** (illustrative — **no such call site
exists yet**):

```php
if (FeatureFlags::useNewHandling($hostId)) {
    // New WS handling for this host/server (Flag A is ON here).
    handle_via_new_path($hostId, $payload);
} elseif (FeatureFlags::legacyCompatEnabled()) {
    // Unchanged legacy path — the active default today.
    handle_via_legacy_path($hostId, $payload);
} else {
    // State 3 (new-only) and this host somehow lacks the new path:
    // an operator has turned legacy off, so refuse rather than fall back.
}
```

With defaults (A=OFF, B=ON) `useNewHandling()` is `false` and
`legacyCompatEnabled()` is `true`, so the legacy branch always runs — identical
to today.

### Writes (operator tooling — future work)

```php
// Set Flag A. $hostId = null/'' sets the GLOBAL default; otherwise a per-host override.
FeatureFlags::setNewHandling($hostId, bool $on): bool

// Remove a per-host Flag A override so the host inherits the global default again.
FeatureFlags::clearNewHandlingOverride($hostId): bool

// Set Flag B (global legacy-compat switch).
FeatureFlags::setLegacyCompat(bool $on): bool
```

Each returns `true` on a successful write, `false` if GlobalData was unavailable
(fail-closed, never throws). `clearNewHandlingOverride` is idempotent — clearing
an absent override still returns `true`.

### Helper

```php
// Build the GlobalData variable name for a per-host Flag A override.
FeatureFlags::hostVar($hostId): string   // e.g. 'ws_new_handling_host_10_0_0_5'
```

---

## Operating the flags today

There is **no CLI or UI tool for toggling these flags yet** — that operator
tooling is future work (likely **P7**, when the flag lifecycle is actually
exercised). Until then, an operator toggles flags by calling the static writers
from a context that can reach GlobalData (e.g. a one-off script run on `myadmin1`
where the GlobalData service lives, or a worker/REPL with `$global` available):

```php
require_once '/home/sites/datacentered/Applications/Chat/FeatureFlags.php';

// --- Adoption: turn Flag A ON for a single host (State 1 -> State 2, per host) ---
FeatureFlags::setNewHandling('host123', true);

// --- Roll a host back (reversible, no redeploy) ---
FeatureFlags::setNewHandling('host123', false);
// ...or drop the override entirely so it inherits the global default again:
FeatureFlags::clearNewHandlingOverride('host123');

// --- Fleet-wide adoption: flip the global default ON ---
FeatureFlags::setNewHandling(null, true);
// (hosts with an explicit override keep their override; others inherit ON)

// --- New-only: after A is ON everywhere and soaked, disable legacy (State 3) ---
FeatureFlags::setLegacyCompat(false);
```

Because the values live in GlobalData, changes take effect immediately across all
worker processes — no restart. Reverting is the same call with the opposite value.

---

## Testing

Unit tests live in
[`tests/FeatureFlagsTest.php`](../tests/FeatureFlagsTest.php) (PHPUnit; config in
`phpunit.xml.dist`). Run them with:

```bash
php vendor/bin/phpunit tests/FeatureFlagsTest.php
```

The suite has two layers:

1. **Fail-safe layer (the ship-dormant guarantee).** With **no usable GlobalData
   client** — which is the genuine state in a CLI/PHPUnit run where GlobalData is
   unreachable — the tests assert Flag A reads OFF and Flag B reads ON, and that
   writers fail closed (return `false`) rather than throw. This fail-safe path is
   exercised **for real, not mocked**: the tests rely on the actual
   GlobalData-unreachable behavior of this environment (plus one test that injects
   a deliberately-throwing `\GlobalData\Client` subclass to prove the
   exception path is swallowed to the fail-safe defaults).
2. **Logic layer.** An in-memory `\GlobalData\Client` subclass is injected via the
   process-global `$global` to prove global toggling, per-host override precedence
   (including forcing a host OFF while the global is ON), override clearing/revert,
   Flag B toggling, and `hostVar` normalization/collision behavior.

As of step 0.5 the suite is **13/13 passing**.
