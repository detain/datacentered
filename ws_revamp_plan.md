# WebSocket Revamp & Workerman Modernization Plan

Status: **DRAFT for review** · Author: investigation 2026-06-29 · Owner: Joe Huss

This plan covers four things as one program of work:
1. **Replace the host↔hub transport** (new versioned protocol) while keeping every operational feature.
2. **Rebuild the outdated `vps_host_server` agent** and modernize the Workerman stack across all repos.
3. **Modernize — not delete — two UX features:** interactive PTY/terminal streaming, and group messaging (rebuilt as a real channels system with a new client in `mystage`).
4. **Bring WS queue handling to full parity** with the HTTP `queue.php` action set.

It is written to be **executed by a nested-agent orchestration** (Part C) with progress tracked in `ws_progress.md` and kicked off via `ws_prompt.md`.

---

## ⛔ CRITICAL INVARIANT — queue processing & HyperV must never break

**The single most important rule of this entire program.** At every step, on every commit, the following must stay **fully working and 100% backward compatible** — no regressions, no downtime, no behavior change — from now until forever:

1. **`datacentered/Web/queue.php`** — the hub HTTP queue dispatch (`map`, `get_queue`, `get_new_vps`, `queue`).
2. **The TaskWorker / memcache queue processing** — `Tasks/memcached_queue_task.php`, `Tasks/processing_queue_task.php`, `Tasks/vps_queue_task.php`, the queue timers in `Events.php`, and the dual task pools (2208/2209). The "memtask queue thing."
3. **`mystage/public_html/queue.php`, `vps_queue.php`, `qs_queue.php`** — and their `ServiceQueueHandler`/`ResponseHandlers/*`/`Commands/*` machinery. All actions, all response shapes, byte-for-byte.
4. **All HyperV functionality** — `Tasks/async_hyperv_get_list.php`, `sync_hyperv_queue`, `async_hyperv_queue_runner`, `hyperv_cleanupresources`, and the HyperV timers.

**These are existing, working, production-critical paths. This program ADDS a parallel WS transport and modernizes internals AROUND them — it never replaces, degrades, or interrupts them.** Any step that touches code in these paths (notably P1.2, P1.3, P1.4, P4.x, P6.1, P6.5) is **change-in-place with proven equivalence**, not rewrite. Every such step's Test Agent (C3) and Phase Verify Agent (C4) **must** include an explicit regression check that these four areas still pass end-to-end before the step/phase is marked done. If equivalence cannot be proven, the change is reverted, not shipped.

**Backward compatibility is PERMANENT and operator-controlled — it is NOT removed when the plan's implementation is "done."** The old handling must keep working *exactly as it does today*, through the entire implementation, after all code is deployed to the many web/datacentered servers and the thousands of VPS/QS host servers, and indefinitely afterward — until the operator (a human) chooses otherwise via feature flags. See **B8** for the two-flag, three-state lifecycle. In short: all new code ships **dormant** behind a default-off flag; the legacy path stays the active path until the operator flips flag A to begin using the new handling (gradual, fleet-wide); and the legacy path is only disabled when the operator later flips flag B — long after adoption is complete. Implementation phases P0–P6 do **not** turn anything off; only operator flag-flips (and the final P7 cleanup, which happens only after flag B is off everywhere) ever retire the old behavior.

---
---

# PART A — Current state

## A1. The dependency reframing (important)

The first investigation assumed an ancient Workerman `^4.1`. **That is wrong / stale in `CLAUDE.md`.** Actual `composer.lock` audit:

| Project | Workerman | PHP req | Notes |
|---|---|---|---|
| `datacentered` (hub) | **v5.2.0** (coroutine-capable) | ≥8.2 | gateway-worker v4.0.1, react/http v1.11, `workerman/coroutine` v1.1.5 **installed but unused** |
| `mystage` | **v5.2.2** | ≥8.1 | 3 WS client stacks: native Workerman, ratchet/pawl+cboden (**stale**), amphp/websocket-client v2 (modern) |
| `vps_host_server/workerman` (agent) | **v4.1.10** | ≥5.3 *(ancient)* | the real laggard — to be rebuilt |
| `mystage/scripts/bots/*` (SshitBot, MonitorLizzy) | **v4.1.17** | ≥5.3 | rocketchat bot has no composer.json |

**So the work is adoption + consolidation, not a risky v4→v5 jump for the hub.** Specifics:
- **Coroutines installed, unused.** Platform still uses callback `dispatchTask()` + GlobalData CAS spin-locks. The Fiber driver (needs `revolt/event-loop`, *not installed*) does **not** auto-yield native blocking calls (`\SoapClient`, `workerman/mysql`, `file_get_contents`) — only the Swoole/Swow drivers do.
- **Dead/deprecated deps:** `workerman/mysql` (deprecated, blocking, `$worker_db`), `workerman/global-timer` (unmaintained since 2022, `GlobalTimer::add()`), `workerman/statistics` (abandoned, v5-incompatible, unresolved), `clue/soap-react` (dormant since 2021, **not even imported** — clean delete).
- **`react/promise` is `2.x-dev`** (target v3). The datacentered planner found **no promise chains** in hub code → low-risk bump; audit mystage too.
- **GlobalData is a SPOF with no lock TTL** — that's *why* the manual stale-lock reapers exist. Locks → Redis `SET NX EX` removes the SPOF and lets reaper code be deleted (`$redis` already a task global).
- **Composer pins are blanket `*@stable`/`dev-master`** → pin to caret ranges for reproducibility.
- **GatewayWorker v4 removed `Lib\Db`; `$_SESSION` is unusable under Swoole/Swow** — audit `App::session()` usage before enabling a coroutine driver under GatewayWorker.

## A2. The three overlapping transports

| Transport | Where | Carries | Disposition |
|---|---|---|---|
| **A. WebSocket (chat hub)** | `datacentered` ↔ agent | `run`/`running`/`ran`, `get_map`, `phpsysinfo`, presence, **+ dead chat** | new v1 protocol; rebuild chat+pty |
| **B. HTTP pull (`queue.php`)** | host cron / `provirted` → `mystage/public_html/{queue,vps_queue,qs_queue}.php` | **the complete protocol**: 30+ actions | WS reaches parity; HTTP kept as fallback |
| **C. queue_log + GlobalData + Redis/Influx** | shared DB backbone | source of truth | unchanged (locks → Redis) |

Key insight: **the HTTP `queue.php` action surface (B) is the de-facto complete protocol.** The WebSocket only adds streamed command output, instant push, presence, and (rebuilt) chat. `provirted` is the stateless CLI engine on each host and stays as the exec boundary.

## A3. Hub consumers (who connects)
- **Agent** `vps_host_server/workerman` — persistent client; the other half of `run`/`get_map`/`phpsysinfo`; IP-only auth; no reconnect.
- **`mystage`** (= `/home/my` live tree): `view_host_server.php` (vmstat stream + direct GlobalData read), `queue_process_payment.php` (Amp `paymentprocess` trigger), CLI tools `CronCommand/AddCommand` + `DcCommand/ListCommand` (ratchet/pawl → `timers`/`clients`), `datacentered_monitor.php` (GlobalData), `js/phptty.js` (dead PTY).
- **Bots** `SshitBot`, `MonitorLizzy` (persistent AsyncTcpConnection + local GlobalData busy flag + POST to `vps_queue.php`), `rocketchat` (RocketChat DDP, reads remote GlobalData).
- **Cron fallback** `vps_cron.sh`/`qs_cron.sh` → `queue.php:55151`; **designed to run when the agent is down** — must stay working through the whole migration.

---
---

# PART B — Target design

## B1. Architecture
```
   NEW mystage clients ─wss→ ┌─────────────────────────────────────┐
   • terminal (xterm/pty.*)   │  HUB (datacentered, Workerman v5)    │
   • chat/channels            │  Gateway WSS :7272 (only)            │
   • dashboards/admin.*        │   → BusinessWorker → v1 router       │
   Host agents       ─wss→     │   cmd/pty/queue/telemetry/config     │
   (new v5 agent)              │   /vps/channel/chat/admin            │
   Bots (v5)         ─wss→     │   queue_log │ GlobalData→Redis locks │
   Internal services ─HTTP→    │   ServiceQueueHandler (reused by WS) │
   (payment trigger, CLI)      │   HTTP triggers + queue.php fallback │
                              └─────────────────────────────────────┘
                                          │ shells out / reads registry / allocs pty
                                          ▼
                              provirted.phar  +  *.sh helpers  (host)
```
Principles: one persistent protocol over WSS:7272 for agents + browsers; hub stays thin transport+router (business logic stays in `queue_log`/`ServiceQueueHandler`/TaskWorker); `provirted` boundary unchanged; HTTP `queue.php` kept as fallback until cutover; triggers become HTTP not WS; PTY and channels are first-class.

## B2. Protocol v1 (envelope)
```json
{ "v":1, "id":"<uuid>", "op":"<namespace.verb>", "ts":1719700000, "data":{} }
```
Replies set `re` to the request `id`: `{ "v":1, "re":"<id>", "ok":true, "data":{} }` or `{ "ok":false, "error":{"code","message"} }`. Large payloads MAY set `"enc":"gzip"` (base64 `data`) — explicit, never implicit. `v` bumps on breaking change; hub supports N and N-1.

## B3. Ops (host agent ↔ hub)
```
auth.hello   host→hub  { role:"host|bot|admin", host_id, token, agent_version, virt_type }
auth.welcome hub→host  { session, host_id, hub_time, timers:{…} }
ping / pong  both      { }

cmd.exec     hub→host  { run_id, command, interact, rows, cols, update_after }
cmd.stdin    hub→host  { run_id, data }
cmd.output   host→hub  { run_id, stream:"stdout|stderr", data }
cmd.exit     host→hub  { run_id, code, term }
cmd.kill     hub→host  { run_id }

pty.open     admin→hub→host  { pty_id, command?, cols, rows, env? }   # empty cmd = login shell
pty.data     both           { pty_id, data }                          # raw bytes (base64), full duplex
pty.resize   admin→hub→host  { pty_id, cols, rows }
pty.close    both           { pty_id, code? }

# Queue — FULL parity with queue.php. Generic dispatch + named aliases.
queue.pull       host→hub  { module }              → { jobs:[{history_id, command, args}] }
queue.ack        host→hub  { history_id, status:"done|failed", output }
queue.provision  host→hub  { module }              → { script | jobs }
queue.action     host→hub  { module, action, args} → { result }   # dispatches ANY ServiceQueueHandler action

telemetry.host        host→hub { load, hd, ram, kernel, raid }
telemetry.host_extra  host→hub { cpu_flags, cpu_speed }
telemetry.cpu         host→hub { per_vps:[{vps_id, cpu}] }
telemetry.bandwidth   host→hub { per_vps:[{vps_id, in, out}] }
telemetry.inventory   host→hub { vps:[{vzid, status, ip, mac, hostname, mem, …}] }  # = vps_list + server_list
telemetry.sysinfo     host→hub { params }                                            # phpsysinfo (verify still used)

config.maps      hub→host { slices, vnc, ips, mainips }   # host writes /root/cpaneldirect/vps.* byte-compatibly
config.topology  hub→host { vlans, vlans6, vps:[…] }
config.template  hub→host { ref, userdata_yaml }

vps.lock / vps.unlock / vps.finished / vps.progress   host→hub { vps_id, module, … }
agent.update     hub→host { url?, restart:true }
```

## B4. Dual-transport parity (DECIDED: both HTTP and WS are first-class, permanently)
**Both transports fully support the operational/queue/telemetry/config op set, in both directions.** HTTP `queue.php` stays primary initially; WS endpoints roll out **gradually, per host** — not a forced cutover. The HTTP path is a permanent peer, not a deprecated fallback.
- `queue.*` (WS) MUST cover the entire `ServiceQueueHandler` action set (get_queue/get_new_vps/qs, server_info(+extra), server_list, cpu_usage, bandwidth, get_map, get_ip/slice/vnc/main_ips maps, get_info, get_template, lock/unlock, finished, install_progress, + the ~25 `Commands/*`).
- Implement as the generic `queue.action{action,args}` bridge → existing `ResponseHandlers/*` + `Commands/*` reused unchanged (same code path HTTP uses), with named aliases (`queue.pull`/`provision`) for hot paths. **Anything addable to `ServiceQueueHandler` is automatically reachable over BOTH transports → they never drift.**
- PTY and channels/chat are inherently WS-only (no HTTP equivalent needed). Everything queue/telemetry/config-shaped must work over either.

## B5. Auth
- **Host/bot:** per-identity bearer token, **distributed by hub config push** (hub generates tokens, stores them in `vps_masters` / a bot registry, and pushes them to known hosts over the existing channel — enables centralized rotation). Agent sends `auth.hello{token}`; hub validates token + source IP (defence in depth). Replaces pure-IP trust and MD5.
- **Admin/browser:** `auth.hello{role:"admin", session}` validated against the existing `mystage` session; drop the MD5 password path.
- WSS terminates TLS at the gateway (certs at `/etc/letsencrypt/live/mynew.interserver.net/`). Retire plain `7271`.

## B6. Chat / channels / log streaming (rebuilt feature)
One channel abstraction `type:name` serves human chat **and** machine log streaming: `chat:noc`, `host:vps12`, `job:boardctl:4567`, `provision:vps1001`.
```
channel.list     client→hub { }                      → { channels:[{id,type,topic,members}] }
channel.join     client→hub { channel }              → { history:[…last N…] }
channel.leave    client→hub { channel }
channel.create   client→hub { name, topic? }
channel.publish  any→hub    { channel, body, level? }     # human msg OR a log line
channel.message  hub→subs   { channel, from, body, level, ts }
channel.presence hub→subs   { channel, members }
chat.send/chat.message/chat.presence — convenience wrappers
```
- **Log-channel streaming** reuses the PTY/`cmd.output` fan-out: a running job's stdout mirrors into `job:<type>:<id>` for live tailing; persisted via existing `queue_log`/Influx paths (boardctl already does this).
- **Auth:** channel access gated by role; hosts may only publish to their own `host:*`/`job:*` channels.

## B7. New mystage clients (detailed)
Live in the `mystage` tree, connect `wss://<hub>:7272`, authenticate with the admin's existing session (`auth.hello{role:"admin", session}`).

**Terminal client** — `public_html/js/ws_terminal.js` + template: xterm.js + fit addon over `pty.*` (open/data/resize/close). Replaces `phptty.js` and the `view_host_server.php` vmstat-via-`run` hack with a real terminal.

**Chat/channels client** — `public_html/js/ws_channels.js` + `templates/admin/ws_channels.tpl` + page `include/admin/view_channels.php`. **Thorough 3-pane UI:**
- **Left — channel sidebar:** list of channels (chat + `host:*`/`job:*` log channels), unread badges, a **"New Channel"** button (→ `channel.create`), join/leave.
- **Center — message pane:** scrollback thread (loaded via `channel.join` history), live append on `channel.message`, a message composer (textarea + send), distinct rendering for log lines vs chat (level coloring), auto-scroll with "jump to latest".
- **Right — members panel:** live member/presence list for the active channel (`channel.presence`), role badges (admin/host/bot), online indicators.
- Reusable module so it can also embed in the admin area (e.g. a per-host/per-job live log tail inside `view_host_server.php`).

## B8. Rollout & feature-flag lifecycle (DECIDED)

The new handling is gated by **two independent feature flags** giving **three states**. The default everywhere is "behaves exactly like today." Operators move between states by hand, fleet-wide, on their own timeline — the implementation never advances these on its own.

**Flag A — `WS_NEW_HANDLING` (default OFF):** when OFF, the new code is present but **dormant/passive** — the legacy handling is the active path and behavior is byte-identical to today. When ON (set gradually, per web/datacentered server and per VPS/QS host), that server/host starts **utilizing** the new handling.

**Flag B — `LEGACY_COMPAT` (default ON):** while ON, the legacy handling remains fully functional (so anything not yet on Flag A, or rolled back, still works). Turned OFF only after Flag A is on everywhere — this disables backward compatibility.

| State | Flag A | Flag B | Behavior | When |
|---|---|---|---|---|
| **1 — Dormant (default, indefinite)** | OFF | ON | Exactly today's behavior. New code deployed everywhere but inert. | Throughout implementation + after all deploys, for as long as the operator wants |
| **2 — Adoption (gradual)** | ON (rolling) | ON | New handling active where A is on; legacy still works everywhere; freely reversible per host | Operator-initiated, "long after the plan changes are done and deployed" |
| **3 — New-only** | ON (everywhere) | OFF | Backward compatibility disabled; new handling only | Operator-initiated, after adoption is complete and soaked |

**Design requirements this imposes on every phase:**
- **Ship dormant.** Every new path (v1 protocol use, new agent behavior, Redis locks, ORM/Pool, coroutine drivers, new clients' use of new ops) is **behind Flag A, default OFF**. Deploying P0–P6 to all servers and all ~thousands of hosts must be a **no-op at runtime** until an operator flips a flag.
- **Legacy is the active default.** The old code paths (`Web/queue.php`, the memtask/queue tasks + timers, `mystage/public_html/*queue.php`, HyperV, IP-auth, old `msg*` handlers, cron fallback) remain present and primary, guarded by Flag B (ON by default), for the entire program and indefinitely after.
- **Reversible.** Flag A is per-server/per-host and can be toggled back at any time without redeploy. Flag B is the global "no going back" switch and is flipped last.
- **No implementation phase flips a flag.** P0–P6 deliver dormant capability. The flips in **state 2 and 3 are operator actions** captured in P7, performed on the operator's schedule — not triggered by "plan complete."
- **Physical code deletion is final and gated.** Legacy code is only *removed from the source tree* after Flag B has been OFF everywhere and stable (P7.3) — never before.

---
---

# PART C — Execution model (how this plan is run)

This program is executed by a **nested-agent orchestration**. Read this part before starting any phase.

## C1. Roles
- **Conductor** (the top session, started by `ws_prompt.md`): reads `ws_revamp_plan.md` + `ws_progress.md`, determines the next incomplete phase, spawns **one Phase Agent** for it, waits, records the result in `ws_progress.md`, repeats. The Conductor **never reads source files or edits code itself** — it only orchestrates and updates progress. **Model: Sonnet 5.**
- **Phase Agent** (one per major phase, Part D): owns a phase. **Does not investigate or edit code itself.** It spawns **Step Agents** in dependency order (parallel only where steps are independent and touch disjoint files), and drives the per-step cycle (C3). It updates `ws_progress.md` after every step transition. At phase end it spawns a **Phase Verify Agent**. **Model: Sonnet 5.**
- **Step Agent**: implements exactly **one** step from the catalog (Part D). Small, focused, returns a precise summary of what changed (files + symbols). **Model: Fable 5.**
- **Review Agent / Fix Agent / Test Agent / Docs Agent / Verify Agent**: single-responsibility leaf agents (C3). Each is **freshly spawned** per use (trust-but-verify: the reviewer/verifier is never the same agent that wrote the code). **Models: Review + Fix → Fable 5; Test + Docs + (Phase) Verify → Opus 4.8.**

**Hard rule:** orchestration agents (Conductor, Phase Agent) delegate *everything* — investigation, edits, reviews, tests, docs, verification — to freshly-spawned leaf subagents. If an orchestration agent is tempted to open a file, it spawns an agent instead.

### C1.1 Model assignments (per role)
Every spawned agent is pinned to a specific model tier so cost/latency/quality match the task. **When spawning a subagent, set its model to the one below** (pass the `model` field); do not inherit the parent's model by default.

| Role | Model | Model ID | Why this tier |
|---|---|---|---|
| **Conductor** | **Sonnet 5** | `claude-sonnet-5` | Orchestration + progress bookkeeping — strong reasoning, no code edits; long-running top loop |
| **Phase Agent** | **Sonnet 5** | `claude-sonnet-5` | Owns a phase, sequences steps, delegates — coordination reasoning, no direct edits |
| **Step Agent** | **Fable 5** | `claude-fable-5` | Focused single-step code changes — fast, cheap, high-throughput implementation |
| **Review Agent** | **Fable 5** | `claude-fable-5` | Fast trust-but-verify pass over a small diff |
| **Fix Agent** | **Fable 5** | `claude-fable-5` | Applies targeted fixes from review/test findings — fast iteration |
| **Test Agent** | **Opus 4.8** | `claude-opus-4-8` | Authors/runs tests incl. the ⛔ queue & HyperV regression checks — highest rigor, must not miss regressions |
| **Docs Agent** | **Opus 4.8** | `claude-opus-4-8` | Authors prose docs + PHPDoc/JSDoc — quality and accuracy matter for lasting artifacts |
| **Phase Verify Agent** | **Opus 4.8** | `claude-opus-4-8` | Independent end-to-end verification of phase success criteria — the last gate before a phase is marked done |

Rationale for the split: the **orchestration tier (Sonnet 5)** reasons about sequencing and records state but writes no code; the **implementation tier (Fable 5)** does the high-volume, tightly-scoped write/review/fix churn quickly and cheaply; the **assurance tier (Opus 4.8)** owns the correctness-critical gates (tests — especially the queue/HyperV invariant regression checks — durable documentation, and final phase verification), where a miss is most expensive.

## C2. The macro loop (Conductor)
```
loop:
  read ws_progress.md → find next phase with status != done
  if none: program complete
  spawn Phase Agent(phase)         # one phase at a time unless progress marks two independent
  on completion: append phase result + verify verdict to ws_progress.md
```

## C3. The micro loop (Phase Agent, per step)
For each step in the phase (model per agent in brackets — see C1.1):
```
1. IMPLEMENT   → spawn Step Agent [Fable 5]. It makes the step's change only.
2. REVIEW↔FIX  → spawn Review Agent [Fable 5] (fresh). If it finds issues:
                   spawn Fix Agent [Fable 5] → spawn Review Agent [Fable 5] again. Repeat until REVIEW returns clean.
3. TEST        → spawn Test Agent [Opus 4.8]: create/extend tests for the new/changed code, run them,
                 fix-until-green (may loop with a Fix Agent [Fable 5]). Tests must actually pass.
4. DOCUMENT    → spawn Docs Agent [Opus 4.8]: update/author .md or README.md for the change AND add/curate
                 PHPDoc docblocks (or JSDoc for JS) on new/changed symbols.
5. RECORD      → Phase Agent [Sonnet 5] updates ws_progress.md: mark step done, note files touched, test status,
                 doc artifacts, and any follow-ups/blockers.
```
Notes:
- **Review→fix repeats until the review is clean** — no step is "done" with open review findings.
- **Testing scope:** only the new/updated code from this program. Do **not** add tests for unrelated existing code.
- **Docs scope:** every new/updated public symbol gets a docblock; every feature/step gets prose in the relevant `.md`/`README.md`.
- A step is **done** only after IMPLEMENT + clean REVIEW + green TEST + DOCS are all complete and recorded.

## C4. Phase Verify (trust-but-verify, per phase)
After all steps, the Phase Agent spawns a **Phase Verify Agent** (fresh, independent) **[Opus 4.8]** that checks the phase's success criteria end-to-end (build/boot, the relevant flows, tests green, docs present) and returns a verdict. If it fails, the Phase Agent spawns Fix Agents **[Fable 5]** and re-verifies. The Conductor only advances when verify passes.

## C5. Progress & recovery (`ws_progress.md`)
- `ws_progress.md` is the single source of truth for "where are we." It is updated after **every** step transition and phase verdict.
- It must let a brand-new session resume with zero prior context: it records the current phase/step cursor, per-step status, files touched, test/doc status, open blockers, and decisions made (esp. the Open Questions in Part E as they're resolved).
- On startup the Conductor reads it; if a step is `in-progress` (interrupted), it spawns a fresh Review Agent to assess actual on-disk state before continuing (never assume the interrupted step finished).

## C6. Global gates (apply to every phase)
- **⛔ Queue & HyperV invariant (see top of doc):** `Web/queue.php`, the TaskWorker/memcache queue processing (`memcached_queue_task`/`processing_queue_task`/`vps_queue_task` + timers + pools), `mystage/public_html/*queue.php` (+ `ServiceQueueHandler` machinery), and **all HyperV tasks** must stay fully working and byte-for-byte backward compatible at **every** step — and **permanently** afterward (until the operator flips Flag B per B8). Every step touching these paths carries a mandatory regression check in its Test + Verify agents. This gate outranks all others — if a change risks these, it does not ship. **Scope:** this invariant covers the **VPS/operational** paths above. The old WebSocket **chat/lobby** layer is *exempt* — it is dead cruft, replaced by the new channels client, and may be dropped (P7.1) without compat treatment.
- **Ship dormant (B8):** all new VPS/operational behavior lands **behind Flag A (`WS_NEW_HANDLING`), default OFF.** Deploying P0–P6 to every server and every host must be a runtime no-op until an operator flips a flag. The new handling is built as an **alternative** path alongside the unchanged legacy one — **VPS behavior does not change** during implementation.
- **Dual-running, permanently:** the hub speaks old + v1 protocols simultaneously, and **HTTP + WS are permanent first-class peers** (B4). HTTP `queue.php` and the cron fallback are **never** removed; WS adoption is operator-gated and gradual (B8). No implementation phase turns anything off — only the operator does (P7).
- **Registry byte-compat:** `config.maps` must write `/root/cpaneldirect/vps.*` identically to today (provirted reads them). Verify in tests.
- **Rollout safety:** anything touching hypervisors (agent, bots) rolls dev → canary(<5%) → 50% → 100%, with rollback per batch.
- **Tests + docs are gates, not afterthoughts** (C3 steps 3–4).

---
---

# PART D — Phase & step catalog

Each step uses: **Change · Files · Why · Needs · Success.** The Review→Fix and Test+Docs cycles (C3) run between/after every step and are not repeated per entry. Effort is relative.

## PHASE 0 — Foundations & decisions
*Goal: lock the spec and the cross-cutting decisions so all later phases build against a fixed contract.*
- **0.1 Resolve Open Questions** — produce decisions for Part E (token distribution, transport, phpsysinfo keep/drop, chat persistence, client placement, PTY scope, coroutine driver). *Files:* `ws_progress.md` decisions block. *Success:* every OQ has a recorded decision.
- **0.2 Freeze protocol v1** — finalize Part B field lists by diffing what both ends read today (agent `onMessage.php`, `queue.php` handlers, `Events.php`). *Files:* this doc §B2–B6, new `datacentered/docs/PROTOCOL_V1.md`. *Success:* every KEEP op has exact fields; reviewers sign off.
- **0.3 Fix `CLAUDE.md` reality** — correct the stale Workerman `^4.1`/react `1.9` claims to v5.2/v1.11 across affected repos. *Files:* `datacentered/CLAUDE.md` (+ mystage). *Success:* docs match `composer.lock`.
- **0.4 Token-auth design** — design host/bot token issuance + storage (`vps_masters.token`/bot registry) + validation flow. *Success:* a written auth design + migration approach.
- **0.5 Feature-flag infrastructure (B8)** — implement the two-flag mechanism: **Flag A `WS_NEW_HANDLING`** (default OFF, per web/datacentered server AND per VPS/QS host) and **Flag B `LEGACY_COMPAT`** (default ON, global), with config plumbing on hub, agent, bots, and mystage, plus a single helper every new code path calls to decide legacy-vs-new. Flags must be runtime-readable and toggleable without redeploy; Flag A reversible per host. *Why:* everything in P1–P6 ships dormant behind these. *Success:* with defaults (A=off, B=on) the system is byte-identical to today; toggling A on a test node activates the new path there only; toggling B off disables legacy there only; a regression check confirms default state = today.

## PHASE 1 — Hub dependency stabilization (datacentered)
*Goal: remove dead deps, pin versions, de-risk — no behavior change. Independent of protocol work.*
- **1.1 Pin composer versions** — `*@stable`/`dev-master` → caret ranges; declare `workerman/coroutine`. *Files:* `datacentered/composer.json`. *Success:* `composer update` clean; lock reproducible.
- **1.2 Remove `clue/soap-react`** — unused/dormant; HyperV already uses native `\SoapClient`. *Files:* `composer.json`, confirm `Tasks/async_hyperv_get_list.php`. *Success:* removed; **all HyperV tasks still run identically (mandatory regression check — ⛔ invariant).**
- **1.3 Remove `workerman/global-timer`** → core `Timer::add()` guarded by `$worker->id===0` (or `workerman/crontab`). *Files:* `Events.php` (6 timers at ~152–171), `composer.json`. *Success:* **every queue timer (processing/boardctl/vps/memcache/map/hyperv) fires exactly once on schedule — full queue-processing regression check (⛔ invariant); no missed or doubled runs.**
- **1.4 Remove `workerman/statistics`** → push call/timing/error metrics to existing InfluxDB v2. *Files:* `Tasks/async_hyperv_get_list.php`, `composer.json`. *Success:* metrics land in Influx; no StatisticClient refs; **HyperV get-list still works end-to-end (⛔ invariant).**
- **1.5 `react/promise` v2→v3** — audit `done()/otherwise()/always()`/3-arg `then`/non-Throwable `reject` (hub planner found none). *Files:* repo-wide. *Success:* bumped; suite green.
- **1.6 react/mysql hardening** — set `?idle=10.0` + explicit charset. *Files:* react/mysql connection setup. *Success:* no reconnect churn in always-on workers.

## PHASE 2 — Hub v1 protocol & feature parity (datacentered)
*Goal: hub speaks v1 alongside old; full queue parity; pty + channels + admin server-side; HTTP triggers. Zero downtime (dual-running).*
- **2.1 v1 envelope router** — add `op` dispatch in `Events.php` beside existing `msg*`. *Success:* hub round-trips a v1 `ping`/`pong` while old protocol still works.
- **2.2 Token auth handshake** — `auth.hello`/`auth.welcome`; token migration populating `vps_masters`; keep IP check. *Success:* a token client authenticates; bad token rejected with clear error.
- **2.3 `cmd.*` exec relay** — port `run`/`running`/`ran`/`stop_run` to `cmd.exec/stdin/output/exit/kill`. *Success:* command streams end-to-end to a v1 test client.
- **2.4 `pty.*` server-side** — terminal alloc + duplex relay through the gateway. *Success:* a v1 client opens a pty, runs `htop`, resizes, closes.
- **2.5 `queue.action` parity bridge** — generic dispatch → `ServiceQueueHandler`; named aliases `queue.pull`/`provision`/`ack`. *Success:* every `queue.php` action reachable over WS with identical output (parity test matrix).
- **2.6 `telemetry.*` + `config.*` + `vps.*`** — ingestion + map push (byte-compat) + lock/finished/progress over v1. *Success:* maps written identically; metrics/locks land.
- **2.7 `channel.*`/`chat.*` fan-out** — channels in GlobalData (or `chat_messages` table per OQ#5); log channels mirror `cmd.output`/boardctl output. *Success:* two clients exchange messages; a job streams into `job:*`.
- **2.8 `admin.*` ops** — `admin.hosts`/`admin.timers`/`admin.running` (replaces chat `clients`/`timers`). *Success:* returns live data from GlobalData.
- **2.9 HTTP trigger endpoint(s)** — `POST /trigger/payment` (and friends) → nudge timers; replaces `paymentprocess`. *Success:* posting triggers the queue; authenticated.

## PHASE 3 — New host agent on Workerman v5 (vps_host_server)
*Goal: rebuild the agent; speak v1; PTY; reconnect; keep provirted + *.sh + cron fallback. Rollout-safe.*
- **3.1 Verify baseline** — confirm installed Workerman/PHP from `composer.lock` (resolve the v4.1.10-vs-v5.2 discrepancy). *Success:* documented truth.
- **3.2 composer/runtime modernize** — PHP ≥8.2, Workerman ^5.2, pinned; React deps current. *Success:* `composer update` clean on PHP 8.2.
- **3.3 Architecture: `Agent` + handler registry** — replace `stdObject` closure dispatch with `Agent` state + `Handlers/*` + `MessageHandler`. *Success:* no `stdObject`; explicit routing; unit-testable.
- **3.4 `ReconnectManager` + v5 heartbeat** — replace `onClose→Worker::stopAll()` with backoff + built-in heartbeat. *Success:* survives hub bounce, reconnects with backoff, no systemd needed.
- **3.5 Map handlers to v1** — `cmd.*`, `telemetry.*`, `config.maps`, `agent.update`, `queue.*`. *Success:* parity with old handlers against a v1 mock hub.
- **3.6 `PTYSession`/`PTYPool` + `PTYHandler`** — real terminals via `pty.*`. *Success:* allocate/write/resize/kill round-trips.
- **3.7 Keep boundaries** — provirted + `*.sh` invocation unchanged; HTTP cron fallback intact. *Success:* fallback still works when ws disabled.
- **3.8 Side-by-side + staged rollout** — dev → canary → 50% → 100%, old agent coexists. *Success:* fleet on new agent, telemetry/queues/commands all flowing.

## PHASE 4 — mystage clients & connectors
*Goal: consolidate on amphp; build the new terminal + channels clients; migrate connectors.*
- **4.1 Amphp `WsClient` wrapper** — `include/Amphp/WsClient.php`. *Success:* connects/sends/receives over wss with v1 envelope.
- **4.2 Migrate CLI tools off ratchet/pawl** — `AddCommand`/`ListCommand` → amphp + `admin.*`; then remove `ratchet/pawl`+`cboden/ratchet` from composer + `.phan`. *Success:* CLI returns timers/clients via v1; no ratchet refs.
- **4.3 Payment trigger → HTTP** — replace Amp `paymentprocess` with `POST /trigger/payment`; keep AJAX/queue_log fallback. *Success:* payment still triggers; billing-critical path tested hard. **⛔ invariant: the underlying payment queue processing (`processing_queue_task` + queue_log) is unchanged and must keep working; only the trigger mechanism changes, and the AJAX/queue_log fallback stays.** *(Note: the `mystage/public_html/*queue.php` endpoints themselves are NOT modified by this program — they remain the primary, fully-supported path.)*
- **4.4 `GlobalDataQuery` (optional)** — async GlobalData read for `view_host_server.php`, or keep direct read (harmless). *Success:* host presence still resolves.
- **4.5 New terminal client** — `public_html/js/ws_terminal.js` (xterm/`pty.*`) + template; replace `phptty.js`. *Success:* interactive terminal works in browser over wss.
- **4.6 New chat/channels client** — `ws_channels.js` + `ws_channels.tpl` + `view_channels.php`: the 3-pane UI (B7) with channel sidebar, message pane, members panel, create-room, join/leave, live log tail. *Success:* list/join/create/send/receive + presence all work.
- **4.7 Migrate `view_host_server.php` vmstat** — to `cmd.exec`/`cmd.output` or a `host:*` log channel; v1 auth. *Success:* live charts via v1.
- **4.8 mystage composer/promise hardening** — pin amphp; audit react/promise v3. *Success:* pins set; suite green.

## PHASE 5 — Bots modernization (mystage/scripts/bots)
*Goal: SshitBot/MonitorLizzy/rocketchat on PHP 8.2 + Workerman v5 + v1.*
- **5.1 Inventory + dead-bot check** — verify versions, enumerate function, decide if SshitBot/MonitorLizzy are redundant or per-host; check rocketchat staleness. *Success:* keep/retire decision recorded.
- **5.2 SshitBot/MonitorLizzy → PHP 8.2 + v5** — floor bump, Workerman ^5, react deps, fix v4→v5 API. *Success:* boots on v5; existing behavior intact.
- **5.3 → v1 protocol + token auth** — `auth.hello{role:"bot"}`, map handlers to ops, `queue.*` instead of local task service. *Success:* telemetry/queue/cmd over v1 against the dual-running hub.
- **5.4 Reconnect/backoff + drop GlobalData busy-flag** — v5 heartbeat; `Locker`/Redis for mutual exclusion. *Success:* auto-reconnect; no 55553 dependency.
- **5.5 rocketchat: composer.json + v5 + reconnect** — proper deps, PHP 8.2, backoff. *Success:* boots, reconnects; commands work.

## PHASE 6 — Hub deeper modernization (datacentered)
*Goal: kill the SPOF locks and the deprecated DB client; adopt coroutines where they pay off.*
- **6.1 GlobalData locks → Redis `SET NX EX`** — migrate `hosts`/`running`/`vps_host_*`/`boardctl_asset_*`/`processing_queue`; **delete the stale-lock reapers**. *Files:* `Events.php` (~337, ~353–356, ~1113–1117, reapers). *Success:* locks auto-expire; reapers gone; concurrency preserved (per-asset serialize, multi-asset parallel). **⛔ invariant: payment/boardctl/vps queue processing must run uninterrupted through the lock swap — regression check + a soak under concurrent load before done. Migrate behind a flag with GlobalData fallback so it can be reverted live.**
- **6.2 GlobalData unix socket** — co-located on myadmin1. *Success:* switched; lower latency.
- **6.3 Coroutine driver abstraction (DECIDED: support both, configurable + fallback)** — build a driver-selection layer: a **configurable preferred driver** (Swoole/Swow auto-yield, or Fiber+revolt) with **capability detection** that **falls back to whichever driver is available** when the preferred one isn't installed. Both paths must work; under Fiber the blocking calls (SOAP/MySQL) must be migrated/guarded (P6.5), under Swoole/Swow they auto-yield. Enable per-worker. *Files:* a `Runtime/Driver` selector + `start_*.php` worker config. *Success:* preferred driver honored when present; clean automatic fallback when absent; one worker running each driver without regressions.
- **6.4 `$_SESSION`/GatewayWorker coroutine audit** — fix `App::session()` usages incompatible with the driver. *Success:* no session breakage under the driver.
- **6.5 `workerman/mysql` → ORM + `Pool`** — replace `$worker_db`/`createDbConnection()` with illuminate/think-orm behind v5 `Pool`. *Files:* `Events.php` (~41–66), `start_task.php` (~33,107–111), `Tasks/memcached_queue_task.php`. *Success:* queries via pool; deprecated client removed; retries handled by pool. **⛔ HIGHEST-RISK to the queue invariant: `memcached_queue_task` and all queue tasks read/write through `$worker_db`. Migrate incrementally (task-by-task, behind a flag, old client retained until proven), with a full queue-processing regression check (cpu_usage/bandwidth/server_info ingestion, payment, boardctl, vps queue) green before each task is switched. Never a big-bang swap.**
- **6.6 Convert callback hotspots to coroutines** — `dispatchTask` + queue timers + run/relay → linear coroutine code; intra-process CAS → `Locker`. *Success:* fewer nested callbacks; behavior identical; load test OK.

## PHASE 7 — Operator-gated cutover & retirement
*Goal: operate the flag lifecycle (B8) and do gated cleanup. **These are operator actions performed long after P0–P6 are done & deployed — not automatic on "plan complete."** Two tracks: chat-drop (free, anytime) and operational-legacy retirement (strictly gated by Flag B off everywhere).*

**Track 1 — Drop the dead WebSocket chat layer (NOT compat-gated; safe to drop):** the old chat/lobby is dead cruft and is *replaced* by the new channels client (P4.6) — it is **not** subject to the permanent VPS backward-compat invariant.
- **7.1 Remove old chat UI + chat handlers** — `Web/index.html`, `Web/lobby.html`, `Web/js/groups.js`, chat CSS, ReconnectingWebSocket, old `phptty.js`; chat-only handlers `say`/`clients`/`timers`(chat)/rooms/`self_update`(broadcast)/`vmstat`; hub-local `Process.php`/`run_local`. *Needs:* new channels + terminal clients live (P4.5/4.6) and the CLI tools moved to `admin.*` (P4.2). *Success:* old chat surface gone; new clients fully cover messaging + terminal; **VPS/queue/HyperV paths untouched.**

**Track 2 — Flag lifecycle for the VPS/operational handling (B8):**
- **7.2 Enable adoption (Flag A → ON, gradual)** — operator turns on `WS_NEW_HANDLING` per web/datacentered server and across the VPS/QS fleet, in waves, monitoring each wave. Legacy stays active (Flag B ON) and per-host rollback is available. *Success:* new handling carrying real traffic where enabled; zero regression vs legacy; queue & HyperV invariant intact.
- **7.3 Disable backward compat (Flag B → OFF)** — only after Flag A is ON everywhere and soaked: operator turns off `LEGACY_COMPAT`, disabling the old path at runtime. *Success:* new-only operation stable fleet-wide; clean rollback path (flip B back on) verified before committing.
- **7.4 Final code removal (gated)** — only after Flag B has been OFF everywhere and stable: physically remove the now-dead legacy operational code (old IP-auth, old operational `msg*` handlers), retire plain gateway **7271** (WSS-only), and decommission `vps_host_server/workerman`. *Success:* legacy operational code removed; only the new path remains.
- **7.5 HTTP `queue.php` stays (DECIDED)** — `Web/queue.php` and `mystage/public_html/*queue.php` are **never** removed; HTTP + WS are permanent peers (B4). Even in new-only state the HTTP transport remains first-class. *Success:* HTTP+WS parity matrix green; no HTTP removal, ever.

---
---

# PART E — Risks, open questions, file map

## E1. Risks / coupling
- **⛔ Queue & HyperV invariant (top of doc, gate C6):** `Web/queue.php`, the TaskWorker/memcache queue processing, `mystage/public_html/*queue.php`, and all HyperV tasks must stay fully working + backward compatible at every step. Highest-risk steps: **P6.5** (`$worker_db`→ORM, touches every queue task), **P6.1** (locks→Redis, touches payment/boardctl/vps queues), **P1.3** (timer migration), **P1.2/1.4** (HyperV deps). All carry mandatory regression checks; migrate behind flags with the old path retained until equivalence is proven.
- **Registry byte-compat** (`vps.{mainips,ipmap,vncmap,slicemap}`) — provirted reads them; `config.maps` must match the current `echo … > vps.*` format. *(tested gate)*
- **VNC emission** — preserve `provirted.phar vnc setup $vps $ip` exactly.
- **Exit-code semantics** — `queue_log` completion depends on provirted 0/1; `cmd.exit`/`queue.ack` must preserve.
- **Cron fallback must stay working** until P7.
- **QS vs VPS** — every op carries `module`; QS reuses VPS handlers (RAM ×0.90, table prefixes, Influx measurement names).
- **Coroutine driver** — Fiber does NOT auto-yield blocking `\SoapClient`/`workerman/mysql`; choose Swoole/Swow or migrate those first (P6.3 gates P6.6).
- **Billing-critical** payment path (P4.3) and **admin-facing** view_host_server (P4.5/4.7) — extra test + canary.

## E2. Open questions
**Resolved (2026-06-29):**
1. ✅ **Host/bot token distribution** — **hub config push** (hub generates, stores in `vps_masters`/bot registry, pushes to hosts; centralized rotation). → B5.
2. ✅ **Transport** — **both HTTP and WS are permanent first-class peers**, full parity both directions. HTTP stays primary initially; WS rolls out gradually per host (no forced cutover). → B4, P7.4.
8. ✅ **Coroutine driver** — **support both**: configurable preferred driver (Swoole/Swow vs Fiber+revolt) with capability detection + automatic fallback to whichever is available. → P6.3.

**Still open (resolve in P0.1):**
3. **phpsysinfo** — is `telemetry.sysinfo` still used by any dashboard, or droppable?
4. **Agent runtime** — stay PHP/Workerman v5 (recommended) or other?
5. **Chat history durability** — ephemeral (GlobalData last-N) vs a `chat_messages` table with scrollback/search?
6. **mystage client placement** — standalone admin page(s) vs embedded in existing admin views?
7. **PTY scope** — full login shells vs scoped/audited command terminals (recommend scoped-by-default, full shell role-gated)?
8. **Coroutine driver** — Swoole vs Swow vs Fiber+migrate (P6.3).
9. **SshitBot/MonitorLizzy** — redundant twins or per-host? (P5.1)

## E3. File-touch map (high level)
- **Hub (datacentered):** `Applications/Chat/Events.php` (v1 router, `cmd/pty/queue/channel/chat/admin/telemetry/config/vps`, Redis locks, Timer migration, ORM/Pool), `start_task.php`, `Tasks/{async_hyperv_get_list,memcached_queue_task}.php`, new `Web/trigger_*.php`, `composer.json`, new `docs/PROTOCOL_V1.md`, `CLAUDE.md`; delete chat UI + `Process.php` (P7).
- **New agent:** rebuild `vps_host_server/workerman/` (`Agent`, `Handlers/*`, `ProcessPool`, `PTYPool/PTYSession`, `ReconnectManager`, `MessageValidator`, tests, `docs/*`); keep sibling `*.sh` + provirted.
- **mystage:** `include/Amphp/{WsClient,GlobalDataQuery}.php`; `scripts/cli/.../{AddCommand,ListCommand}.php`; `include/billing/payments/queue_process_payment.php`; `include/admin/{view_host_server,view_channels}.php`; new `public_html/js/{ws_terminal,ws_channels}.js` + templates; `composer.json`+`.phan`; delete `public_html/js/phptty.js` (P7); tests under `tests/phpunit/unit/Amphp/`.
- **Bots:** `scripts/bots/{SshitBot,MonitorLizzy,rocketchat}/*` (composer, v5, v1, reconnect).
- **Untouched:** `Tasks/*` queue logic, `provirted/*`, `vps_host_server/*.sh` + cloud-init harness, `queue_log` schema, `ServiceQueueHandler`/`ResponseHandlers/*`/`Commands/*` (reused via the WS bridge).

## E4. Detailed sub-plans (reference)
The four per-repo deep-dive plans (datacentered, agent, mystage, bots) and the Workerman library research informed this document; their full step-by-step detail and code sketches can be regenerated per phase by the Step Agents from the catalog above. Conductor: capture any that you want persisted under `datacentered/docs/` during P0.2.
