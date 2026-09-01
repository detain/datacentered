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

# Run unit tests (phpunit.xml.dist)
php vendor/bin/phpunit
```

**CI** (`.github/workflows/ci.yml`, on push to `master`, PRs, and `workflow_dispatch`): `syntax` runs `php -l` over every tracked `*.php` on PHP 8.2/8.3/8.4 (the only check that verifies the declared `>=8.2` floor); `tests` runs `php vendor/bin/phpunit --colors=always --display-deprecations` on 8.3/8.4 (`--display-deprecations` because PHP 8.4 raises 5 deprecations in the code under test that 8.3 does not, and PHPUnit otherwise prints only the count; 8.2 excluded — phpunit `^12.5` needs `>=8.3`; the suite is fully offline via the `tests/TestBootstrap.php` doubles, no service containers; `setup-php` installs `ext-redis`, currently phpredis 6.x while dev hosts run 5.3.7 — see `.claude/rules/redis-test-doubles.md`); `style` runs `php vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no`; `composer-health` runs `composer validate --strict --no-check-publish` and `composer audit --locked`. All jobs check out with `actions/checkout@v7` and cache Composer with `actions/cache@v6`.

## Architecture

**Entry**: `start.php` → loads `Applications/Chat/start_*.php` → `Worker::runAll()`

| Service | File | Port | Notes |
|---|---|---|---|
| Register | `start_register.php` | `1236` | GatewayWorker service registry (`text://`) |
| Channel | `start_channel.php` | `3333` | Pub/sub inter-process comms |
| Gateway | `start_gateway.php` | `7271` | WebSocket client connections |
| Gateway SSL | `start_gateway_ssl.php` | `7272` | WSS (certs at `/home/my/files/apache_setup/`) |
| BusinessWorker | `start_businessworker.php` | — | Calls `Events.php`; 5 processes |
| TaskWorker | `start_task.php` | `2208` | Text protocol; loads all `Tasks/*.php` |
| WebServer | `start_web.php` | `55151` | HTTP + HTTPS; serves `Web/` |

**myadmin1 only**: `channel`, `register` (hostname check in `start.php`)
**Register host**: `GLOBALDATA_IP` constant from `/home/my/include/config/config.settings.php` — the host running `register`; gateway/BusinessWorkers set `registerAddress` to `GLOBALDATA_IP:1236`. The name predates the RETIRED GlobalData shared-variable service; the constant itself stays.
**Shared state**: Redis (external service) via the `Applications/Chat/SharedState.php` facade — replaced the retired GlobalData server.
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
// From Events.php context, prefer Events::dispatchTask($type, $args, $onResult, $onError)
// which adds onClose/onError handling automatically.
```

### SharedState (Redis) Lock & Shared State
Redis is the platform's shared-state layer: the static `SharedState` facade in
`Applications/Chat/SharedState.php` (phpredis; resolves the process-wide `$redis`
global or `USE_REDIS`/`REDIS_HOST`/`REDIS_PORT`) replaced the retired GlobalData
service — with real TTLs everywhere GlobalData had none.
```php
// Acquire: SET NX EX with a MANDATORY positive TTL. null token == held elsewhere OR Redis down (fail-safe).
$lockName = 'vps_host_' . $service_id;
$token = SharedState::lock($lockName, 900);
if ($token !== null) {
    try {
        // Long work: renew before the TTL lapses. false == ownership lost (expired/stolen) => ABORT, don't touch the work.
        if (!SharedState::renew($lockName, $token, 900)) { return; }
        // ... do work ...
    } finally {
        SharedState::unlock($lockName, $token); // Lua token-compared DEL: no-op if the lock was re-acquired by someone else
    }
}
```
- **Token discipline:** `lock()` returns a `host:pid:random` token; always pass it to `renew()`/`unlock()`. `unlock($name)` with a null token is an UNCONDITIONAL admin/stale-cleanup delete — never on a normal completion path.
- **Fail-safe posture:** BOTH failure modes degrade identically — no Redis client (reads return null/`[]`, writes `false`, `lock()` returns `null`) AND a live-but-dead handle (every command dispatch is wrapped, so transport throws never escape into timers/Tasks; same fail-safe returns). Reason logged once per OUTAGE (the memo re-arms on a verified recovery, so a later outage is not silent) — call sites need no try/catch; a lost lock takes the same branch a lost CAS used to. Death is timed, not sticky: `client()` short-circuits for `REPROBE_INTERVAL` (30s), then re-probes — PINGs the shared `$redis` handle and a PING that answers resumes it (phpredis re-handshakes on current builds); after 2 consecutive failed re-probe PINGs (`SHARED_HANDLE_GRACE_PROBES`) the shared handle is deprioritized (never closed/replaced/re-PINGed) and the facade heals through its OWN fresh PING-guarded lazy connect — so self-heal is bounded (shared handle when it recovers, else fresh internal handle within ~2 windows) with no restart. `SharedState::transportFailed()` distinguishes "transport dead" from contention/unset for the few callers that must (FeatureFlags' fail-safe defaults; `processing_queue_timer` escalating a dead-transport nudge as `unavailable`, contained by its three consumers: the 30s tick's `Events::safeTimerCallback()` wrapper at the `Timer::add()` site, trigger_payment's `\Throwable` catch, and `msgPaymentprocess`'s local try/catch that keeps the boardctl nudge running). **Workerman does NOT contain a throwing timer callback** — `Events\Select::tick()` calls `safeCall()`, which forwards the Throwable to the loop errorHandler that `Worker::run()` installs as `stopAll(250, $e)`, i.e. the worker EXITS. Every `Timer::add()` must go through `safeTimerCallback()`.
- **Namespaces (every key guarded into `dc:*`):** `dc:lock:` (raw lock tokens ONLY — `guardKey()` refuses every WRITE primitive here; reads and `del()` are allowed, `del()` being the documented stale/admin cleanup path. The observability sibling moved OUT to `SharedState::requestKey()` = `dc:state:<lock>:request`), `dc:state:` (registry HASHes `hosts`/`rooms`/`channel_meta`/`timers`/`ptys`; per-key `running:<id>` STRING EX3600 + `running_ids` SET index; `sysinfo:<id>` EX300; `admin_hosts_cache` EX5), `dc:chat:` (`msgs:<channel>` LIST RPUSH+LTRIM keeping the newest 100, with an idle EXPIRE refreshed on every append so reclamation does not depend on anyone calling `channel.list`; `activity` ZSET swept at 3600s both on read and on a write-path throttle), `dc:flag:` (feature flags, no TTL), `dc:presence:` (records EX `PRESENCE_RECORD_TTL` 270 + `index`/`active` ZSETs swept on the SAME 270s window; the missed-keepalive DROP threshold is the separate `PRESENCE_STALE_TTL` 90 — retention must outlive it, or the sweep evicts a silent client before the watchdog can close its socket).
- **Lock TTLs:** `processing_queue` 900s (renewed mid-chain; renew-fail ⇒ ABORT the chain), `vps_host_<id>` `SharedState::VPS_HOST_LOCK_TTL` = **1300s** (Events acquires and hands the token down to `vps_queue_task` via `args['lock_token']`; the Task renews and only releases a lock it took itself), `queuein:<ip>` 900s (per-host drain), `startup_reap` 60s, `boardctl_asset_<id>` 22200s (token handed to the detached runner **via the `BOARDCTL_LOCK_TOKEN` env var**, not argv), `bot_owner:<loc>` 30s (renewed every move tick = the ownership heartbeat; `bot_state` TTL 90s outlives the lock). Ops rules: (1) TTLs match or exceed the old GlobalData reap windows, never shorter; (2) a lock TTL must EXCEED the longest single blocking call it guards — `vps_host_*` is 1300 > `default_socket_timeout` 1200 because `GetVMList` can run 10+ minutes and `renew()` only fires BETWEEN blocking calls. If `default_socket_timeout` is raised, raise `VPS_HOST_LOCK_TTL` with it.
- **Not namespaced:** the raw queue DATA LIST `queuein:<ip>` (and its Memcached fallback) lives OUTSIDE `dc:*` and is never touched by the facade — only the same-named drain lock goes through it.

### Task Function Signature (`Tasks/*.php`)
Each file exports one `function filename($args)`. Auto-loaded from `Tasks/` on `onWorkerStart`. Available globals: `$worker_db` (Workerman MySQL), `$influx_v2_database`, `$memcache`, `$redis` (no `$global` — cross-process state goes through the `SharedState` Redis facade, which `require_once`s from `Applications/Chat/SharedState.php`). Use `App::db()` for MyAdmin database/session access; `$GLOBALS['tf']` has been removed.

## Task Functions (`Tasks/`)
- `bandwidth` — writes InfluxDB v2 bandwidth per VPS (in/out bytes)
- `map_queue_task` — builds IP/VNC/slice maps → Memcached or Redis per host IP
- `queue_queue_task` — pulls `queue_log` table entries → Memcached queues per host
- `processing_queue_task` — runs `process_payment` for billing queue entries
- `vps_queue_task` / `vps_get_list` / `vps_update_info` — VPS lifecycle ops via `vps_queue_handler`
- `async_hyperv_get_list` — SOAP `GetVMList` via native ext-soap `\SoapClient` (WSDL mode, `SOAP_1_2`); call outcomes (duration/success/code/msg) are recorded to InfluxDB v2 via the `$influx_v2_database` global (same client used by `bandwidth.php`) through the local `async_hyperv_report_metric()` helper — this replaced the removed `workerman/statistics`/`StatisticClient::report()` mechanism
- `sync_hyperv_queue` / `async_hyperv_queue_runner` — HyperV queue sync under the `vps_host_<id>` SharedState (Redis) lock (900s TTL; renew-fail ⇒ abort)
- `hyperv_cleanupresources` — SOAP `CleanUpResources` call via `SoapClient`
- `get_map` — returns VPS IP/VNC/slice map for a host
- `queue_action` — executes a queued `queue.php` action inside the TaskWorker via a superglobal (`$_POST`/`$_REQUEST`) shim so legacy action handlers run unchanged
- `chat_message` — persists V1 chat messages (`migrations/2026_07_phase2_chat_messages.sql`)
- `memcached_queue_task` — processes `cpu_usage`/`bandwidth`/`server_info` queue entries from Memcached/Redis for `vps` and `quickservers`; drains each host under the `queuein:<ip>` SharedState lock (900s TTL, renewed between items, renew-fail ⇒ stop that host); the raw `queuein:<ip>` queue LIST stays OUTSIDE the `dc:*` facade; InnoDB cluster retry with exponential backoff; writes CPU + bandwidth metrics to InfluxDB v2
- `boardctl_task` — runs a single queued boardctl job via `boardctl_run_job($historyId)`; receives full `queue_log` row; sets `App::session()->account_id` from `history_owner`; 2hr timeout (`set_time_limit(7500)`); on error calls `boardctl_append_output`/`boardctl_set_status`; dispatched by `boardctl_queue_timer` (15s) which parses `history_type` as `"<action>:<assetId>"`, holds the per-asset Redis lock `boardctl_asset_<id>` (22200s = 6h job cap + 10min buffer) and hands the ownership token to the detached runner (`scripts/boardctl_runner.php`), which releases it token-checked (with a fresh-connection fallback); allows concurrent multi-asset execution

## Web Endpoints (`Web/`)
- `queue.php` — VPS/QS queue dispatch; actions: `map`, `get_queue`, `get_new_vps`, `queue`
- `trigger_payment.php` — token-authenticated endpoint that triggers `process_payment` for a billing queue entry (token auth: `migrations/2026_07_phase2_token_auth.sql`, `docs/AUTH_DESIGN.md`)
- `logger.php` — ZoneMTA log ingestion → `mail_logentry` / `mail_messagestore` / `mail_senderdelivered`
- `prober.php` — JSON system stats (CPU, RAM, network, disk) via `SystemStats`/`NetworkStats`/`StorageStats`
- `systemstats_data.php` — live metrics for `Web/systemstats.html` dashboard (jQuery jqplot graphs)
- `Web/index.html` / `Web/lobby.html` — ChatOps UI (jQuery, Bootstrap, ReconnectingWebSocket)
- `Web/css/` — `chat.css`, `groups.css`, `lobby.css`, emotion picker CSS
- `Web/js/groups.js` — WebSocket group chat client

## Process & Utility Classes (`Applications/Chat/`)
- `Process.php` — PTY child process wrapper (`proc_open` + `TcpConnection` streams → `Gateway::sendToClient`)
- `stdObject.php` — callable-property bag (magic `__call` dispatch)
- `SharedState.php` — static Redis facade for all cross-process state: locks (SET NX EX + Lua token-checked renew/release), `dc:*`-namespaced registries/flags/presence, fail-safe when Redis is unavailable AND when a live-but-dead handle's commands throw (all wrapped; timed 30s re-probe self-heals — see Key Patterns)
- `FeatureFlags.php` — feature-flag gating for V1 protocol rollout (see `docs/FEATURE_FLAGS.md`)
- `Events.php` — GatewayWorker business logic (onConnect, onMessage, onClose); `dispatchTask($type, $args, $onResult, $onError)` wraps async dispatch with `onClose`/`onError` handling; `createDbConnection()` builds a retrying Workerman MySQL connection; V1 client protocol handlers (auth/hello, chat, cmd, pty, queue, config_vps, admin, telemetry) are documented in `docs/PROTOCOL_V1.md`

## Dependencies (`composer.json`)
- Version constraints are now caret-/branch-pinned to what `composer.lock` resolved (was mostly floating `*`); pure pins, no version drift
- `workerman/workerman v5.2.2`, `gateway-worker` (dev-master), `channel` (dev-master), `mysql` (dev-master), `gatewayclient` (dev-master)
- `workerman/globaldata` — REMOVED; the retired shared-variable client (and the `globaldata` entry in `start.php`'s `$services`) is replaced by the `SharedState` Redis facade
- `workerman/coroutine ^1.1.5` — now an explicitly-declared direct dependency (was previously an undeclared transitive dep of `workerman/workerman`); still functionally dormant/unused at runtime
- `react/child-process v0.6.7`, `react/http v1.11.0`, `react/event-loop v1.6.0`
- `react/mysql v0.6.0` — dead/unused dependency; runtime MySQL uses `workerman/mysql`'s `\Workerman\MySQL\Connection` (auto-reconnects on 2006/2013, persists `utf8mb4` charset)
- `react/promise` (require-dev) — constrained to `^3.0` (resolves cleanly at 3.x-dev; the interim `^3.0 || ^2.11` range was only needed while `clue/soap-react` forced v2.x, which has since been removed)
- HyperV SOAP uses PHP's native ext-soap `\SoapClient` (`Tasks/async_hyperv_get_list.php`, `Tasks/hyperv_cleanupresources.php`); the `clue/soap-react` dependency was dead/unused and has been removed
- `influxdata/influxdb-client-php` (dev-master) — InfluxDB v2 metrics (`Tasks/bandwidth.php`, and HyperV SOAP call metrics in `Tasks/async_hyperv_get_list.php`)
- `cache/memcached-adapter 1.2.0` — Memcached queue/map storage
- `guzzlehttp/guzzle ^7.13.1` (locked at 7.15.5)
- `friendsofphp/php-cs-fixer ^3.95` (require-dev) — the style gate documented under Commands was previously NOT a declared dependency, so it could never actually run
- REMOVED as dead/unused: `corneltek/cliframework` (zero references outside `vendor/`; it pinned `symfony/finder ^2.7` → `symfony/config 3.3.2` → `symfony/filesystem ~2.8|~3.0`, which capped php-cs-fixer at the 2021-era v2.19 and dragged in twig/pimple/doctrine-inflector/symfony-class-loader), plus `satooshi/php-coveralls` and `codacy/coverage` (both abandoned upstream, never wired to CI — `.github/workflows/ci.yml` runs with `coverage: none` and `phpunit.xml.dist` has no coverage config, so no clover report was ever produced for them to upload)

## Code Style
- PSR-2 + PHP 8.2 migrations (`@PSR2`, `@PHP82Migration`) — see `.php-cs-fixer.dist.php`. The ruleset targeted `@PHP74Migration` long after `composer.json` moved to `php >=8.2`, so 8.0–8.2 modernisations went unchecked; risky fixers stay disabled
- The reference-only `experiments/` tree (amphp/Swoole samples) has been DELETED from the repo; its `exclude` entry in `.php-cs-fixer.dist.php` is now vestigial
- Cache file: `.php-cs-fixer.cache`
- No trailing commas in multiline, no heredoc indentation, no method argument space changes
- All `Worker::safeEcho()` for process-safe output; never `echo` in workers without it

## Before Committing

Run `caliber refresh` before creating git commits to keep docs in sync with code changes.
After it completes, stage any modified doc files before committing:

```bash
caliber refresh && git add CLAUDE.md .claude/ .cursor/ .github/copilot-instructions.md AGENTS.md CALIBER_LEARNINGS.md 2>/dev/null
```
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort (stronger reasoning; higher cost and latency than smaller models).
Smaller/faster models trade quality for speed and cost — pick what fits the task.
Pin your choice (`/model` in Claude Code, or `CALIBER_MODEL` when using Caliber with an API provider) so upstream default changes do not silently change behavior.

## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `/home/my/.nvm/versions/node/v24.15.0/bin/caliber refresh`.
`.caliberignore` lists large binaries kept out of the doc-sync context window (currently `Web/img/phptty.png`); `.gitignore`d paths are already skipped and need no entry.
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.
