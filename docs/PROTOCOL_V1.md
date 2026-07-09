# PROTOCOL_V1 — Frozen WebSocket Protocol v1 Specification

Status: **FROZEN** (Phase 0 Step 0.2, 2026-07-01)
Rationale and program context: see `ws_revamp_plan.md` Part B (B1–B8).
Decisions referenced: `ws_progress.md` decisions log (OQ1, OQ3, OQ5, OQ6, OQ7).

This document freezes protocol v1 **exactly**: every KEEP op with its exact field
list and types. It was produced by diffing the plan's Part B sketch against what
both ends of the legacy transport actually read/write today:

- Hub: `datacentered/Applications/Chat/Events.php` (`msg*` handlers, `run_command()`,
  `say()`, `onClose`), `datacentered/Web/queue.php`, `datacentered/Tasks/{bandwidth,get_map,vps_get_list,vps_update_info}.php`
- Agent: `vps_host_server/workerman/src/Events/{onMessage,onConnect,phpsysinfo,get_map,vps_get_list,vps_update_info,vps_get_traffic,sendPing,sendPong}.php`
  and `src/Tasks/{vps_get_list,vps_update_info,vps_get_cpu}.php`
- mystage HTTP protocol: `/home/my/public_html/queue.php` →
  `include/Services/ServiceQueueHandler.php` → `include/vps/queue/ResponseHandlers/*`
  (+ `Commands/*` via `GetQueue`)

**Field-naming rule applied throughout:** where the current code already has a
field name/shape, v1 keeps it (to ease bridging); where the plan sketch and code
disagree, the resolution is stated inline in a *Diff note*.

Types used below: `str`, `int`, `float`, `bool`, `num` (int|float), `ts` (unix
seconds int), `obj`, `arr`, `map<K,V>` (JSON object used as dictionary),
`b64gz` (base64-encoded gzcompress/zlib payload — see `enc`). `?` = optional.

---

## 1. Envelope

From plan **B2**, verbatim:

```json
{ "v":1, "id":"<uuid>", "op":"<namespace.verb>", "ts":1719700000, "data":{} }
```

| Field | Type | Req | Meaning |
|---|---|---|---|
| `v` | int | yes | Protocol version. `1` for this spec. Bumps only on breaking change; the hub supports N and N−1 simultaneously. |
| `id` | str (uuid) | yes | Sender-unique message id. Used for reply correlation. |
| `op` | str | yes | `namespace.verb`, lower-case, dot-separated (e.g. `cmd.exec`). |
| `ts` | ts | yes | Sender unix time (seconds). |
| `data` | obj | yes | Op payload (may be `{}`). |
| `enc` | str | no | If present, only value is `"gzip"`: `data` is a base64 string of the zlib-compressed JSON payload. **Explicit, never implicit.** |

**Reply shape.** Replies set `re` to the request `id` (no `op` required on a reply;
receivers correlate by `re`):

```json
{ "v":1, "re":"<id>", "ok":true,  "data":{} }
{ "v":1, "re":"<id>", "ok":false, "error":{ "code":"<str>", "message":"<str>" } }
```

| Field | Type | Req | Meaning |
|---|---|---|---|
| `re` | str | yes | The `id` of the request being answered. |
| `ok` | bool | yes | Success flag. |
| `data` | obj | if ok | Result payload. |
| `error.code` | str | if !ok | Stable machine-readable code (e.g. `auth_failed`, `unknown_op`, `bad_request`, `forbidden`, `not_online`, `internal`). |
| `error.message` | str | if !ok | Human-readable detail. |

Unsolicited server→client events use the request shape (fresh `id`, an `op`,
no `re`). Ops documented as "no reply" get no `re` message unless an error
occurs, in which case an `ok:false` reply MUST be sent.

*Diff note:* the legacy protocol has no envelope at all — bare
`{"type":"...", ...}` objects with no version, no ids, no correlation
(e.g. `Events.php::onMessage()` dispatches on `type` only). The `run_id` (legacy
`id` = `md5($cmd)`) is the only correlation mechanism today, and it collides for
identical commands. v1's envelope `id` fixes this; op-level ids (`run_id`,
`pty_id`) remain for stream identity, not correlation.

---

## 2. Op catalog (exact frozen field lists)

Direction key: `H→A` hub→agent(host), `A→H` agent→hub, `C→H` client(admin
browser / bot / CLI)→hub, `H→C` hub→client, `any` either direction.

### 2.1 `auth.*`

#### `auth.hello` (connecting party → hub; MUST be first message)

| Field | Type | Req | Notes |
|---|---|---|---|
| `role` | str | yes | `"host"` \| `"bot"` \| `"admin"`. |
| `host_id` | int | host/bot | `vps_masters.vps_id` (host) or bot registry id. |
| `token` | str | host/bot | Bearer token (see §3). |
| `session` | str | admin | mystage session id, validated against `sessions` table (see §3). |
| `agent_version` | str | host/bot | Semver/build string of the agent. |
| `virt_type` | str? | host | e.g. `"kvm"`, `"openvz"`, `"virtuozzo"`, `"lxc"`, `"hyperv"` — replaces legacy numeric `vps_type` for capability hints. |
| `module` | str? | host | `"vps"` (default) or `"quickservers"`. Legacy login sent `module:"vps"`. |

*Diff note (current code):* the agent today sends
`{type:"login", name:<hostname>, module:"vps", room_id:1, ima:"host"}`
(`onConnect.php`) and the hub authenticates **by source IP only**
(`msgLogin` looks up `vps_masters` by `vps_ip = REMOTE_ADDR`). Admin login is
`{type:"login", ima:"admin", session_id}` **or** `{username, password}` with an
MD5 check. v1 drops the MD5 path, drops `room_id`/`name` (hub already knows the
host's name from the registry row), and makes auth explicit token/session per
plan B5. Source-IP match is retained as defence-in-depth, not as the credential.

#### `auth.welcome` (hub → connecting party; reply to `auth.hello`)

| Field | Type | Req | Notes |
|---|---|---|---|
| `session` | str | yes | Hub-assigned session token for this connection. |
| `host_id` | int | host/bot | Echo of authenticated identity (`vps_masters.vps_id`). |
| `uid` | str | yes | Bound uid, kept legacy-compatible: `"vps<host_id>"` for hosts, account id for admins. |
| `name` | str | yes | Display name (`vps_name` / `account_lid`). |
| `hub_time` | ts | yes | Hub unix time (clock-skew reference). |
| `timers` | obj | host | Timer schedule the agent should run, `map<str,int>` name→interval seconds (replaces agent-local hardcoded `setupTimers()` triggered by legacy `login` receipt). |

Auth failure is an `ok:false` reply with `error.code = "auth_failed"`, after
which the hub closes the connection. *(Legacy equivalent: `{type:"error",
content:...}` then `closeClient`.)*

#### `ping` / `pong` (`any`, ops `ping` and `pong`)

`data: {}` both ways. `pong` is the reply (`re` set) to a `ping`.
*Diff note:* identical to today (`{type:"ping"}`/`{type:"pong"}`); the hub's
legacy behavior of disconnecting unauthenticated clients on `pong`
(`msgPong`) is replaced by a hard rule: any op other than `auth.hello` before
successful auth ⇒ `error.code:"auth_required"` + close.

### 2.2 `cmd.*` — streamed command execution (replaces legacy `run`/`running`/`ran`/`stop_run`/`run_list`)

#### `cmd.exec` (H→A; originated by admin `C→H` and relayed)

| Field | Type | Req | Notes |
|---|---|---|---|
| `run_id` | str | yes | Unique per invocation (uuid). **Diff note:** legacy uses `id = md5($cmd)` (`Events::run_command`), which collides for repeated identical commands; v1 requires uniqueness. |
| `command` | str | yes | Shell command. Multi-line commands are still written to a temp file and run via `bash -l <file>` on the agent (current behavior preserved). |
| `interact` | bool | yes (default `false`) | Open stdin for interactive input. Legacy same field. |
| `rows` | int | yes (default `24`) | Terminal height → agent env `LINES`. |
| `cols` | int | yes (default `80`) | Terminal width → agent env `COLUMNS`. |
| `update_after` | bool | yes (default `false`) | After exit, agent refreshes inventory + maps (`vps_update_info` + `get_map_timer`). Legacy same field. |
| `for` | str? | no | Delivery target for output (uid or `#group`/channel). **Diff note:** legacy carries `for` and `host` inside the message; in v1 the hub keeps the routing table (`running` registry) itself, so `for`/`host` are hub-internal. `for` remains optional only for hub→hub bookkeeping and MUST NOT be trusted from clients. |

*Diff note (defaults):* legacy `run_command($host,$cmd,$interact=false,$for=null,$rows=80,$cols=24,...)`
has `rows`/`cols` **defaults swapped** relative to the agent's interpretation
(`COLUMNS=cols=80`, `LINES=rows=24` in `onMessage.php`). v1 freezes the agent's
semantics: `cols` = width (default 80), `rows` = height (default 24). Bridges
translating legacy defaults MUST emit `rows:24, cols:80`.

#### `cmd.stdin` (H→A, originated C→H)

| Field | Type | Req | Notes |
|---|---|---|---|
| `run_id` | str | yes | |
| `data` | str | yes | Raw stdin bytes (UTF-8 or base64 with `enc:"gzip"` — plain string for the common case). |

*Diff note:* legacy overloads the **same** `running` type for both stdin
(admin→host: `{type:"running", id, stdin}`) and output (host→admin:
`{type:"running", id, stdout|stderr}`), disambiguated only by the sender's
session role (`msgRunning`). v1 splits this into `cmd.stdin` / `cmd.output`.

#### `cmd.output` (A→H, relayed H→C; no reply)

| Field | Type | Req | Notes |
|---|---|---|---|
| `run_id` | str | yes | |
| `stream` | str | yes | `"stdout"` \| `"stderr"`. **Diff note:** legacy uses two alternative keys `stdout` / `stderr` on the `running` message; v1 normalizes to `stream`+`data` per plan B3. |
| `data` | str | yes | Output chunk. |

#### `cmd.exit` (A→H, relayed H→C; no reply)

| Field | Type | Req | Notes |
|---|---|---|---|
| `run_id` | str | yes | |
| `code` | int\|null | yes | Exit code; `null` if terminated by signal. |
| `term` | int\|null | yes | Terminating signal; `null` on normal exit. Exactly one of `code`/`term` is non-null (legacy `ran` semantics, `msgRan` checks `term === null`). |
| `stdout` | str? | no | Optional trailing/aggregate stdout. **Diff note:** not in the plan sketch, but `msgRan` reads optional `stdout`/`stderr` today — kept for byte-parity of the completion summary. |
| `stderr` | str? | no | Optional trailing/aggregate stderr. |

**Exit-code invariant (E1):** `queue_log` completion logic depends on provirted's
0/1 exit codes; `cmd.exit.code` MUST propagate the child's code unmodified.

#### `cmd.kill` (H→A)

| Field | Type | Req | Notes |
|---|---|---|---|
| `run_id` | str | yes | Legacy `{type:"stop_run", id}`; agent closes pipes and `terminate(SIGKILL)` — behavior preserved. |

*Dropped from v1:* legacy `run_list` (`{type:"run_list"} → {running:{...}}`) is
subsumed by `admin.running` (§2.9). Legacy hub-local `run_local`
(`msgRunLocal`, `Process.php`) is chat-layer cruft and is **not** carried into
v1 (P7.1 track).

### 2.3 `pty.*` — real interactive terminals (new; replaces the dead `phptty.js` path)

#### `pty.open` (C→H→A)

| Field | Type | Req | Notes |
|---|---|---|---|
| `pty_id` | str | yes | Unique (uuid). |
| `scope` | str | yes (default `"command"`) | `"command"` \| `"shell"`. **OQ7:** defaults to scoped; `"shell"` (full login shell, empty `command`) requires an elevated role check server-side. See §5. |
| `command` | str? | scope=command | Command to run in the PTY. Empty/absent + `scope:"shell"` = login shell. |
| `cols` | int | yes (default 80) | |
| `rows` | int | yes (default 24) | |
| `env` | map<str,str>? | no | Extra environment variables (allowlisted server-side). |

Reply: `{ ok:true, data:{ pty_id } }` once allocated on the host.

#### `pty.data` (any; no reply)

| Field | Type | Req | Notes |
|---|---|---|---|
| `pty_id` | str | yes | |
| `data` | str | yes | Base64-encoded raw bytes, full duplex. Always base64 (PTY streams are binary-unsafe in JSON). |

#### `pty.resize` (C→H→A; no reply)

| Field | Type | Req |
|---|---|---|
| `pty_id` | str | yes |
| `cols` | int | yes |
| `rows` | int | yes |

#### `pty.close` (any; no reply)

| Field | Type | Req | Notes |
|---|---|---|---|
| `pty_id` | str | yes | |
| `code` | int? | no | Exit code when the PTY child exited; absent when closed by the peer. |

*Diff note:* there is no working PTY in current code (agent `run` with
`interact:true` is a pipe, not a PTY; hub `Process.php` is local-only). Field
list follows plan B3 + OQ7 `scope` addition.

### 2.4 `queue.*` — full parity with HTTP `queue.php`

Every action reachable at `mystage/public_html/queue.php?action=<x>` (dispatched
via `ServiceQueueHandler::render()` → `ResponseHandlers\<CamelCase(action)>`,
and `Commands/*` via `GetQueue`) MUST be reachable over WS. The generic bridge
is `queue.action`; hot paths get named aliases. **Both transports are permanent
first-class peers (B4); response payloads must be byte-identical to the HTTP
output for the same action+args.**

#### `queue.action` (A→H or C→H) — generic dispatch

| Field | Type | Req | Notes |
|---|---|---|---|
| `module` | str | yes | `"vps"` \| `"quickservers"`. Legacy HTTP infers module from source IP (`queue.php` master lookup); v1 sends it explicitly, hub still validates the caller is that module's registered host. |
| `action` | str | yes | Any `ServiceQueueHandler` action, snake_case exactly as HTTP: `get_queue`, `get_new_vps`, `get_new_qs`, `server_info`, `server_info_extra`, `vps_info`, `vps_info_extra`, `server_list`, `cpu_usage`, `bandwidth`, `get_map`, `get_ip_map`, `get_slice_map`, `get_vnc_map`, `get_vps_main_ips`, `get_info`, `get_template`, `lock`, `unlock`, `finished`, `install_progress`, … (anything added to `ResponseHandlers/` is automatically available). |
| `args` | obj | yes | The fields the handler reads from `$_REQUEST` today, same names (see per-action notes below). May use `enc:"gzip"`. |

Reply: `{ ok:true, data:{ result:<str|obj> } }` — `result` is the handler's raw
`render()` output (bash script text, JSON string, or empty), unmodified.

Per-action `args` (frozen from actual `$_REQUEST` reads in `ResponseHandlers/*`):

| action | args fields |
|---|---|
| `get_queue` / `get_new_vps` / `get_new_qs` | *(none)* — identity comes from auth |
| `server_info` / `vps_info` | `servers`: **legacy-encoded str** — `base64(json(obj))` (obj shape = `telemetry.host` field table §2.5). See ⚠️ AMENDMENT 1 below. |
| `server_info_extra` / `vps_info_extra` | `servers`: `{cpu_flags:str, speed:num}` |
| `server_list` | `servers`: **legacy-encoded str** + `ips`: **legacy-encoded str** (arr/map shapes per `telemetry.inventory`). See ⚠️ AMENDMENT 1 below. |
| `cpu_usage` | `cpu_usage`: **legacy-encoded str** (html-entity-encoded serialized map, shape per `telemetry.cpu`). See ⚠️ AMENDMENT 1 below. |
| `bandwidth` | `bandwidth`: **legacy-encoded str** — `base64(gzcompress(stringify(map ip→{in:int,out:int})))`; `servers`: legacy-encoded str (map ip→veid). See ⚠️ AMENDMENT 1 below. |
| `get_map` / `get_ip_map` / `get_slice_map` / `get_vnc_map` / `get_vps_main_ips` / `get_info` | *(none)* |
| `get_template` | `template`: str (template file name) |
| `lock` / `unlock` | `id`: int (service id) |
| `finished` | `service`: int, `command`: str |
| `install_progress` | `progress`: str, `server`: str (vzid or numeric id with `linux`/`windows`/`qs` prefix stripped server-side) |

> **⚠️ IMPLEMENTATION AMENDMENT 1 (step 2.5) — verbatim-arg encoding for the
> telemetry-shaped queue actions (`server_info`/`vps_info`, `server_info_extra`,
> `server_list`, `cpu_usage`, `bandwidth`).**
>
> *Original §2.4 sketch (superseded for the `queue.action` path):* the rows above
> read "legacy HTTP sends base64(json); v1 sends **plain obj**."
>
> *Corrected reality:* over `queue.action` the bridge injects the caller's `args`
> **VERBATIM** into `$_REQUEST`/`$_POST` and invokes the **UNCHANGED** shared
> `vps_queue_handler`/`qs_queue_handler` callable (⛔ invariant: no queue logic is
> forked or reimplemented — see `Tasks/queue_action.php`). Those handlers'
> `ResponseHandlers/*` decode their inputs **unconditionally** in the legacy wire
> encoding: `ServerInfo` does `base64_decode`→`json_decode`; `Bandwidth` does
> `base64_decode`→`gzuncompress`→unstringify; `CpuUsage` does
> `html_entity_decode`→unstringify; `ServerList` likewise decodes `servers`/`ips`.
> A plain object handed to these handlers over `queue.action` would raise a decode
> `TypeError`. Therefore, **over `queue.action` these specific telemetry-shaped
> actions REQUIRE the legacy-encoded string form** (base64 / json / gzip / html-
> entity) exactly as the shared handlers expect. The "plain obj" ergonomics of the
> original sketch do **not** apply on the `queue.action` path.
>
> *Preferred v1 path for telemetry-shaped data:* use the dedicated `telemetry.*`
> ops (§2.5 — `telemetry.host`, `telemetry.host_extra`, `telemetry.cpu`,
> `telemetry.bandwidth`, `telemetry.inventory`; implemented in step 2.6). Those
> accept **plain objects** and perform the legacy encoding **hub-side** before
> reaching the same handlers. `queue.action` remains the generic byte-parity
> escape hatch that reuses the handlers unchanged; `telemetry.*` is where the
> plain-obj ergonomics live.
>
> *Rationale:* invariant preservation (the shared handler callable is reused
> unchanged) outranks the original plain-obj sketch on the `queue.action` path.
> The plain-obj contract is honored — just relocated to `telemetry.*` (§2.5),
> which is the correct v1 home for it.

#### `queue.pull` (A→H) — alias for `get_queue` + queued push items

| Field | Type | Req |
|---|---|---|
| `module` | str | yes |

Reply `data`:

| Field | Type | Notes |
|---|---|---|
| `jobs` | arr<obj> | **SINGLE-AGGREGATE shape** (see ⚠️ AMENDMENT 2). Either `[{history_id:0, command:"get_queue", args:{script:<raw aggregated script>}}]` (one entry — the whole pending-queue script) or `[]` when there is nothing pending. Marking `queue_log` rows `<module>queueold` happens hub-side (inside the reused `GetQueue::render()`) exactly as today. |

> **⚠️ IMPLEMENTATION AMENDMENT 2 (step 2.5) — `queue.pull` returns a SINGLE
> aggregate entry, not per-row jobs.**
>
> *Original §2.4 sketch (superseded):* `jobs:[{history_id:int, command:str,
> args:{script:str}}]` — one entry **per `queue_log` row**, with `command` = the
> row's `history_new_value` action and `args.script` = that row's own rendered
> `Commands/*` output.
>
> *Corrected reality (implemented):* `jobs` is a **single aggregate entry** —
> `[{history_id:0, command:"get_queue", args:{script:<raw aggregated script>}}]`
> (`history_id:0` is the sentinel meaning "aggregate, not a single row"), or
> `[]` when the aggregated output is empty.
>
> *Reason:* `queue.pull` reuses the unchanged `GetQueue` handler (⛔ invariant).
> `GetQueue::render()` produces **ONE concatenated script** for **all** pending
> rows AND performs the legacy optimistic `<module>queueold` row-flip **in the
> same DB pass**. Decomposing that output into per-row jobs would require forking
> `GetQueue`'s `queue_log` query + row-flip so each row could be rendered and
> marked individually — which the invariant forbids (no queue logic copied; the
> flip stays exactly where it is today). So the bridge returns the raw aggregated
> script byte-identical to the HTTP `get_queue` body, wrapped as the single entry
> above.
>
> *Downstream note:* the Phase-3 `queue.pull` consumer (host agent) MUST expect
> the **aggregate script** in `jobs[0].args.script` (or an empty `jobs` array),
> **not** per-row jobs. Per-row decomposition is a later refactor, unlocked only
> once `GetQueue` itself exposes per-row rendering without moving the row-flip.

#### `queue.provision` (A→H) — alias for `get_new_vps` / `get_new_qs`

| Field | Type | Req |
|---|---|---|
| `module` | str | yes |

Reply `data`: `{ script: str }` — the raw provisioning script text (may be `""`),
byte-identical to the HTTP response.

#### `queue.ack` (A→H)

| Field | Type | Req | Notes |
|---|---|---|---|
| `history_id` | int | yes | The `queue_log` row. |
| `status` | str | yes | `"done"` \| `"failed"`. |
| `output` | str | yes | Captured job output (may be `""`). |

*Diff note:* there is **no explicit ack today** — HTTP `get_queue` flips rows to
`<module>queueold` optimistically at fetch time, and completion is inferred via
`finished`/`install_progress` callbacks. `queue.ack` is new in v1 (plan B3);
during dual-running the hub treats it as additive telemetry and MUST NOT change
the legacy optimistic flip (⛔ invariant).

### 2.5 `telemetry.*` — host → hub metrics

All telemetry ops are fire-and-forget (no reply unless error). Large payloads
SHOULD set `enc:"gzip"`.

#### `telemetry.host` (A→H) — replaces WS `vps_info` and HTTP `server_info`

`data` is the flat server object currently built by the agent's
`Tasks/vps_update_info.php` and consumed by `ResponseHandlers/ServerInfo.php`:

| Field | Type | Req | Notes |
|---|---|---|---|
| `load` | float | yes | 1-min loadavg. |
| `cores` | int | yes | |
| `bits` | int | yes | 32/64. |
| `kernel` | str | yes | `uname -r`. |
| `ram` | int | yes | kB. QS note: the **hub** applies the ×0.90 adjustment for `quickservers` (`ServerInfo.php`), never the agent. |
| `cpu_model` | str | yes | |
| `cpu_mhz` | float | yes | |
| `hdsize` | num | yes | GB. |
| `hdfree` | num | yes | GB. |
| `iowait` | float | yes | |
| `ioping` | str | yes | |
| `mounts` | str | yes | Comma-joined mount list. |
| `drive_type` | str | yes | `"SSD"` \| `"SATA"`. |
| `raid_building` | bool | yes | |
| `raid_status` | str | yes | check_raid summary. |
| `mem_free` | int? | no | MemAvailable kB (legacy alias `ramfree` accepted by the hub). |
| `cpu_usage`,`cpu_iowait`,`cpu_steal`,`cpu_steal_norm`,`run_queue_norm`,`cpu_capacity`,`cpu_capacity_max`,`io_pressure`,`cpu_pressure`,`mem_pressure`,`total_pressure` | float? | no | Saturation/capacity metrics (nullable columns; skipped if absent — forward/backward compatible per `ServerInfo.php`). |

*Diff note:* plan sketch `{load, hd, ram, kernel, raid}` was a shorthand; the
frozen list above is the real field set both ends use today.

#### `telemetry.host_extra` (A→H) — replaces HTTP `server_info_extra`

| Field | Type | Req | Notes |
|---|---|---|---|
| `cpu_flags` | str | yes | Space-joined sorted flags. |
| `speed` | num | yes | NIC speed (Mbps). **Diff note:** plan sketch said `cpu_speed`; actual code (`ServerInfoExtra.php`) uses `speed` (and it is *link* speed, not CPU) — frozen as `speed`. |

#### `telemetry.cpu` (A→H) — replaces HTTP `cpu_usage`

| Field | Type | Req | Notes |
|---|---|---|---|
| `host` | obj | yes | Host-level usage object; MUST contain `cpu`: float (avg %). Legacy: the first (shifted) element of the `cpu_usage` map. |
| `per_vps` | map<str,obj> | yes | veid → usage object (arbitrary numeric keys, written to Influx `<prefix>_stats` as-is). |

*Diff note:* legacy HTTP sends one urlencoded serialized map with the host entry
mixed in at index 0 (`CpuUsage.php` `array_shift`s it). v1 separates `host` from
`per_vps` — the bridge reassembles the legacy shape.

#### `telemetry.bandwidth` (A→H) — replaces WS `bandwidth` and HTTP `bandwidth`

| Field | Type | Req | Notes |
|---|---|---|---|
| `per_ip` | map<str,obj> | yes | ip → `{ vps: str (veid), in: int (bytes), out: int (bytes) }`. |

*Diff note:* plan sketch said `per_vps:[{vps_id,in,out}]`; actual agent output
(`get_vps_iptables_traffic.php` → `{type:"bandwidth", content:{ip:{vps,in,out}}}`)
and both hub consumers (`Tasks/bandwidth.php`, `ResponseHandlers/Bandwidth.php`)
key by **IP** and identify the VPS by **veid string**, resolved to `vps_id`
hub-side. Frozen to the existing keyed-by-IP shape (`per_ip`).

#### `telemetry.inventory` (A→H) — replaces WS `vps_list` and HTTP `server_list`

| Field | Type | Req | Notes |
|---|---|---|---|
| `servers` | map<str,obj> | yes | veid → server object. Fields per virt type, exactly as `Tasks/vps_get_list.php` emits today: common `{type:"kvm"\|"openvz"\|"virtuozzo"\|"lxc", veid:str, status:str, hostname?:str, ip?:str, mac?:str, vnc?:int, kmemsize?:num, cpu_usage?:obj, diskused?:num, diskmax?:num, uuid?:str, vzid?:str}` plus, for openvz/virtuozzo, the full vzlist beancounter set (`numproc`, `vswap`, `layout`, `kmemsize_f`, `lockedpages(_f)`, `privvmpages(_f)`, `shmpages(_f)`, `physpages(_f)`, `vmguarpages(_f)`, `oomguarpages(_f)`, `numtcpsock(_f)`, `numflock(_f)`, `numpty(_f)`, `numsiginfo(_f)`, `tcpsndbuf(_f)`, `tcprcvbuf(_f)`, `othersockbuf(_f)`, `dgramrcvbuf(_f)`, `numothersock(_f)`, `dcachesize(_f)`, `numfile(_f)`, `numiptent(_f)`, `diskspace(_s,_h)`, `diskinodes(_s,_h)`, `laverage`). |
| `host` | obj | yes | Host-level pseudo-entry: `{ bw_usage?: obj, os_info?: {distro:str, version:str, speed:num, cpu_flags:str}, cpu_usage?: obj }`. **Diff note:** legacy smuggles this as `servers[0]` (`ServerList.php` special-cases index 0 then `unset`s it); v1 promotes it to a sibling key. `bw_usage` fields: `time:ts, bytes_in/out/total:int, packets_in/out/total:int, bytes_sec_in/out/total:float, packets_sec_in/out/total:float`. |
| `ips` | map<str,arr<str>> | yes | veid → list of assigned IPs (first = main IP). Legacy same. |

*Diff note:* plan sketch `vps:[{vzid,status,ip,mac,hostname,mem,…}]` was a
shorthand; frozen to the real `servers`+`ips` maps (renaming would break the
`ServerList.php` reuse that gives us parity for free).

#### `telemetry.sysinfo` (kept — OQ3 decision) 

Request (C→H→A), op `telemetry.sysinfo`:

| Field | Type | Req | Notes |
|---|---|---|---|
| `host` | int | yes | Target host id (admin-originated; hub rewrites routing exactly like `msgPhpsysinfo` does with `for`). |
| `params` | obj | yes | phpsysinfo request params (passed through to `workerman/phpsysinfo.php`). |

Reply (A→H→C):

| Field | Type | Req | Notes |
|---|---|---|---|
| `host` | int | yes | |
| `params` | obj | yes | Echo of the original params (legacy restores `orig_params`). |
| `data` | b64gz | yes | phpsysinfo result JSON, gzip+base64 — in v1 expressed as `enc:"gzip"` on the envelope instead of an inline encoded field. |

*Diff note:* legacy carries `for` (requesting admin uid) inside the message and
each side rewrites it (`msgPhpsysinfo`); in v1 the hub tracks the requester via
the envelope `id`/`re` correlation, so `for` disappears from the wire.

### 2.6 `config.*` — hub → host configuration

#### `config.maps` (H→A push, or reply to a host pull)

Host pull: op `config.maps` with `data:{}` A→H (legacy `{type:"get_map"}` from
`get_map_timer.php`); reply/push payload:

| Field | Type | Req | Notes |
|---|---|---|---|
| `slices` | str | yes | `vzid:slices` lines → `/root/cpaneldirect/vps.slicemap`. |
| `vnc` | str | yes | `vzid:vncport` lines → `vps.vncmap` (+ agent runs `provirted.phar vnc setup <vps> <ip>` for missing xinetd entries — unchanged). |
| `ips` | str | yes | `mainip:addonip` lines → `vps.ipmap` (+ `run_buildebtables.sh` on change — unchanged). |
| `mainips` | str | yes | `vzid:mainip` lines → `vps.mainips`. |

**Byte-compat invariant (E1/C6):** the four registry files MUST be written
byte-identically to today (agent `get_map.php` trims and compares before
writing; provirted reads these files).

*Diff note:* this is exactly the hub `GetMap.php`/`Tasks/get_map.php` output and
agent `Events/get_map.php` input — plan and code already agree. (The *other*
map shape, `Web/queue.php action=map`, uses Redis keys `slice/ip/vnc/main` and
emits a bash script; that HTTP path is unchanged and out of scope for v1.)

#### `config.topology` (H→A; reply to a host request with `data:{}`) — replaces HTTP `get_info`

| Field | Type | Req | Notes |
|---|---|---|---|
| `vlans` | map<str,obj> | yes | vlans_id → ipcalc result of the vlan network (from `GetInfo.php`). |
| `vlans6` | obj | yes | IPv6 network info (`get_server_network_info()['vlans6']`). |
| `vps` | arr<obj> | yes | Per-VPS rows with prefix stripped, exactly the `GetInfo.php` selection: `{id:int, hostname:str, vzid:str, mac:str, ip:str, ipv6:str, ipv6_range:str, status:str, server_status:str, vnc:str}` (null fields omitted). |

#### `config.template` (H→A; reply to a host request) — replaces HTTP `get_template`

Request: `{ ref: str }` — the template file name (legacy `template` request
param; renamed to `ref` per plan sketch since it also matches future non-file
refs; the bridge maps `ref`→`template`).
Reply: `{ template: obj }` — the full `vps_templates` row (as `GetTemplate.php`
returns), or `ok:false` `error.code:"not_found"` /`"bad_request"` (legacy
returned `{error:...}` JSON bodies).

### 2.7 `vps.*` — service lifecycle callbacks (host → hub)

All carry `module` explicitly (legacy infers it from source IP). No reply
unless error.

#### `vps.lock` / `vps.unlock` (A→H) — replaces HTTP `lock`/`unlock`

| Field | Type | Req | Notes |
|---|---|---|---|
| `module` | str | yes | |
| `vps_id` | int | yes | Legacy request field `id`; renamed to `vps_id` per plan (bridge maps to `id`). Sets/clears `extra['lock']` (unlock also clears `restore_status`/`backup_status`) — unchanged semantics. |

#### `vps.finished` (A→H) — replaces HTTP `finished`

| Field | Type | Req | Notes |
|---|---|---|---|
| `module` | str | yes | |
| `vps_id` | int | yes | Legacy `service`. |
| `command` | str | yes | Completed command; `delete`/`destroy` trigger repeat-invoice deletion (`Finished.php`) — unchanged. |

#### `vps.progress` (A→H) — replaces HTTP `install_progress`

| Field | Type | Req | Notes |
|---|---|---|---|
| `module` | str | yes | |
| `server` | str | yes | vzid or numeric id (legacy `server` param, matched against `_vzid` or stripped id) — kept as-is. |
| `progress` | str | yes | Free-form status written to `<prefix>_server_status`. |

### 2.8 `agent.update` (H→A) — replaces legacy `self-update` broadcast

| Field | Type | Req | Notes |
|---|---|---|---|
| `url` | str? | no | Optional package/script URL override; absent = agent runs its bundled `update.sh` (current behavior). |
| `restart` | bool | yes (default `true`) | Reload after update (legacy always reloads). |
| `jitter_max` | int? | no | Max random delay seconds before updating (legacy hardcodes two `sleep(rand(1,30))`; frozen as an explicit field, default 60). |

*Diff note:* legacy is `{type:"self-update"}` with **no fields**, broadcast to
the `hosts` group by `msgSelfUpdate` (admin-gated). v1 keeps the admin-only
gate and adds the explicit fields above.

### 2.9 `admin.*` — admin/CLI introspection (C→H, request/reply)

Role `admin` required (also used by the migrated CLI tools, P4.2).

#### `admin.hosts` — replaces chat `clients`

Request `data:{}`. Reply `data`:

| Field | Type | Notes |
|---|---|---|
| `hosts` | arr<obj> | `[{id:str (uid), host_id:int, name:str, ima:str, type:int\|str, ip:str, online:str (Y-m-d H:i:s), module:str}]` — from Gateway sessions + `$global->hosts`, same source data as `msgClients` minus the chat-room noise and minus the mandatory `gzcompress` (use `enc:"gzip"` if large). |
| `admins` | arr<obj> | `[{id:str, name:str, ima:"admin", img:str, online:str}]`. |

#### `admin.timers` — replaces chat `timers`

Request `data:{}`. Reply `data`: `{ timers: map<str,obj> }` — timer name →
`{interval:int, last_run:ts?, timer_id:int}` from `$global->timers`.
*Diff note:* legacy `msgTimers` replies with an **empty** `{type:"timers"}`
(the status calls are commented out); v1 specifies the real payload the CLI
`ListCommand` wants.

#### `admin.running` — replaces chat/agent `run_list`

Request `data:{}`. Reply `data`: `{ running: arr<obj> }` where each entry is the
hub's registry record: `{run_id:str, host:str (uid), command:str, interact:bool,
update_after:bool, for:str\|null, rows:int, cols:int, started:ts}` (legacy
`$global->running` entries carry `type/command/id/interact/update_after/host/rows/cols/for`;
`type` dropped, `started` added).

### 2.10 `channel.*` / `chat.*` — channels & messaging (rebuilt; plan B6)

One channel abstraction `type:name` serves human chat and machine log
streaming: `chat:noc`, `host:vps12`, `job:boardctl:4567`, `provision:vps1001`.
Access is role-gated; hosts may only publish to their own `host:*`/`job:*`
channels. Log channels mirror `cmd.output` fan-out (a running job's stdout is
republished as `channel.message` with `level:"log"`).

#### `channel.list` (C→H)
Request `data:{}`. Reply: `{ channels: [{id:str, type:str, topic:str, members:int}] }`.

#### `channel.join` (C→H)
Request: `{ channel: str }`. Reply: `{ history: [<message obj, see channel.message>] }`
— last N messages (N=100) from the hot cache / `chat_messages` (§4).

#### `channel.leave` (C→H)
Request: `{ channel: str }`. Reply: `{ }`.

#### `channel.create` (C→H)
Request: `{ name: str, topic?: str }` (type is always `chat:` for user-created
channels). Reply: `{ channel: str }` (the full `type:name` id).

#### `channel.publish` (any→H)
| Field | Type | Req | Notes |
|---|---|---|---|
| `channel` | str | yes | |
| `body` | str | yes | Message text or log line. Hub HTML-escapes on render, **not** on store. *Diff note:* legacy `say()` stores `nl2br(htmlspecialchars(...))` — pre-rendered HTML in the data store; v1 stores raw text (`chat_messages.body`) and leaves rendering to clients. |
| `level` | str? | no | `"chat"` (default) \| `"log"` \| `"info"` \| `"warn"` \| `"error"`. |

#### `channel.message` (H→subscribers; no reply)
| Field | Type | Req | Notes |
|---|---|---|---|
| `channel` | str | yes | |
| `from` | str | yes | Sender uid (`vps<id>`, account id, or `"system"`). |
| `from_name` | str | yes | Display name — kept from legacy `say` shape (`from_name`). |
| `body` | str | yes | Raw text. |
| `level` | str | yes | |
| `ts` | ts | yes | *Diff note:* legacy uses `time` as `"Y-m-d H:i:s"` string; frozen to unix `ts` (envelope already carries `ts`; duplicated here so persisted/history objects are self-contained). |
| `msg_id` | int | yes | `chat_messages.id` (for scrollback pagination). |

#### `channel.presence` (H→subscribers; no reply)
| Field | Type | Req | Notes |
|---|---|---|---|
| `channel` | str | yes | |
| `members` | arr<obj> | yes | `[{id:str, name:str, ima:str, online:bool}]`. Replaces legacy `login`/`logout` broadcast messages (`{type:"login", id, self, ip/email, img, name, ima, online}` / `{type:"logout", id, time}`). |

#### `chat.send` / `chat.message` / `chat.presence`
Convenience wrappers with **identical field lists** to `channel.publish` /
`channel.message` / `channel.presence`, plus for `chat.send` a direct-message
form: `{ to: str (uid), body: str }` (replaces legacy `say` with
`is:"client"`). Direct messages are persisted to `chat_messages` with
`channel = "dm:<uidA>:<uidB>"` (sorted) — fixing the legacy gap where DMs were
never persisted (see §4).

*Diff note (legacy `say`):* `{type:"say", from, is:"room"|"client", to, content,
time}` — `is`/`to` are replaced by the channel id or `to` uid; `content` →
`body`; the hardcoded single `rooms[0]` becomes real channels.

---

## 3. Auth (per plan B5 + OQ1 decision)

- **Host/bot: bearer token, distributed by hub config push.** The hub
  generates per-identity tokens, stores them in `vps_masters` (new `vps_token`
  column; bots in a bot registry table), and **pushes** them to known hosts over
  the existing (legacy) channel during migration — enabling centralized
  rotation without touching hosts by hand. The agent presents the token in
  `auth.hello{token}`; the hub validates **token AND source IP** (IP is
  defence-in-depth, not the credential). This replaces today's pure-IP trust
  (`msgLogin` host path) — detailed issuance/rotation design is Step 0.4.
- **Admin/browser:** `auth.hello{role:"admin", session}` validated against the
  existing mystage `sessions` table (`session_owner` → `accounts` with
  `account_ima='admin'`), exactly the query `msgLogin` uses for its
  `session_id` path today. The MD5 `username`/`password` path is **dropped**
  in v1 (legacy keeps it until Flag B off).
- **Transport:** WSS only for v1 clients — TLS terminates at the gateway
  (`:7272`, certs at `/etc/letsencrypt/live/mynew.interserver.net/`). Plain
  `:7271` remains for legacy traffic until P7.4 retirement.
- **Ordering rule:** `auth.hello` MUST be the first message; any other op first
  ⇒ `error.code:"auth_required"` + close. Per-op authorization: `cmd.*`
  origination, `pty.open`, `agent.update`, `admin.*` require role `admin`;
  `queue.*`, `telemetry.*`, `vps.*`, `config.*` pulls require role `host`/`bot`
  bound to the matching `host_id`; channel ACLs per §2.10.

---

## 4. Chat persistence (OQ5 decision impact)

Per the OQ5 decision (`ws_progress.md`, 2026-07-01): all `chat.*` /
`channel.*` messages are persisted to a **new `chat_messages` table**, plus a
**bounded last-N hot cache** (GlobalData/Redis, N=100 per channel) serving
`channel.join` history and live tails.

Schema sketch (final DDL in Phase 2.7):

```sql
CREATE TABLE chat_messages (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    channel   VARCHAR(191)    NOT NULL,          -- 'type:name' id, e.g. 'chat:noc', 'job:boardctl:4567', 'dm:a:b'
    `from`    VARCHAR(64)     NOT NULL,          -- sender uid ('vps12', account id, 'system')
    body      TEXT            NOT NULL,          -- raw text (NOT pre-escaped HTML)
    level     VARCHAR(16)     NOT NULL DEFAULT 'chat',
    ts        INT UNSIGNED    NOT NULL,          -- unix seconds
    KEY idx_channel_ts (channel, ts),
    KEY idx_channel_id (channel, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Why (from the OQ5 verification): today's `say()` writes to an **unbounded**
`GlobalData rooms[0]['messages']` array — never trimmed, always room index `0`
regardless of the `$to` target, and direct messages aren't persisted at all.
v1 fixes this rather than preserving it: DB = durability/scrollback/search,
hot cache = live tail; the cache is bounded and evicts, the table does not.
High-volume `log`-level channel traffic MAY be sampled/skipped for DB writes
(log channels already persist via `queue_log`/Influx); `chat`-level messages
are always persisted.

---

## 5. PTY scope (OQ7 decision impact)

Per the OQ7 decision (`ws_progress.md`, 2026-07-01): `pty.open` carries a
**`scope`** field — `"command"` (default) or `"shell"`.

- **Scoped-by-default:** `scope:"command"` runs exactly the supplied `command`
  in a PTY; no login shell. This is the default for all sessions and requires
  the standard `admin` role.
- **Full shell is role-gated:** `scope:"shell"` (login shell; `command` absent)
  requires an **elevated role check server-side** (a distinct privilege beyond
  `ima='admin'` — exact role/flag defined with the auth design, Step 0.4). The
  hub enforces this before relaying to the agent; the agent additionally
  refuses `scope:"shell"` from a hub message lacking the elevation marker in
  its session.
- **Audit:** every `pty.open`/`pty.close` is logged with session attribution
  (who, which host, scope, command, timestamps), and scoped sessions record
  the command line — a real structured audit trail, not just
  `Worker::safeEcho` to `billingd.log`. Must not regress below current
  capability (today's admin-gated unrestricted shell path), but new sessions
  default to scoped/audited.

---

## 6. Implementation status

> This section tracks **what is actually wired up** against the frozen spec above.
> It is bookkeeping, not spec — the field lists/tables in §1–§2 are FROZEN and
> unchanged by anything here. Each phase appends its own note; nothing is
> retroactively rewritten.

### Phase 2, step 2.1 — v1 envelope router skeleton

**Delivered.** A v1 envelope router in `Applications/Chat/Events.php`, gated by
Flag A (`WS_NEW_HANDLING`, see `docs/FEATURE_FLAGS.md`):

- `Events::isV1Envelope()` — detects a v1-shaped frame per §1 (requires all of
  `v==1`, non-empty `id`, non-empty `op`, int `ts`, and a `data` array). Legacy
  `{"type":...}` messages never match, so the legacy dispatch path is byte-unchanged.
- `Events::dispatchV1($client_id, $envelope)` — the router. With **Flag A OFF
  (the default) it is fully dormant**: the frame is parsed but no logic runs and
  no reply is sent, so deploying it is a runtime no-op (State 1, per B8). With
  **Flag A ON**, only `ping` is functional end-to-end — it replies with the frozen
  pong `{"v":1,"re":"<id>","ok":true,"data":{}}` (§2.1). **Every other op replies
  `{ok:false,error:{code:"not_implemented"}}`** so the dispatch skeleton
  round-trips without touching any legacy state.
- `onMessage()` routes a detected v1 envelope to `dispatchV1()` and returns
  before the legacy `type`-dispatch.

Verified by `tests/EventsV1RouterTest.php` (legacy shapes never detected as v1;
dormant-by-default; ping→pong byte-exact when Flag A on; unimplemented ops →
`not_implemented`).

**Non-blocking notes from review (carried forward, not fixed in 2.1):**

1. **Auth-gating deferred to step 2.2 (MUST retrofit).** §2.1 requires that any
   op other than `auth.hello` before successful auth reply
   `error.code:"auth_required"` and close. The 2.1 router does **not** enforce
   this — with Flag A ON, pre-auth `ping` is answered and other pre-auth ops
   return `not_implemented` rather than erroring+closing. This is a known,
   deliberate gap to be closed in **2.2** (tracked by a `@todo` on `dispatchV1()`).
2. **Per-frame GlobalData round-trip when Flag A is OFF (perf).** Every
   v1-shaped frame consults `FeatureFlags::useNewHandling()`, which reads
   GlobalData, even in the dormant default state. Acceptable for this phase
   (no action needed); revisit if v1 frame volume grows before adoption.
3. **Silent drop of v1-shaped input when Flag A is OFF (behavior delta).** A
   dormant v1 frame produces **no reply and no log line** — it is silently
   ignored, not answered with an error. This is intentional (dormant == inert)
   and differs from an explicit rejection; documented so it is not read as a bug.
4. **`data` accepts JSON arrays, not just objects.** Detection uses `is_array()`,
   so `"data":[]` / `"data":[1,2]` pass as well as `"data":{}`. Harmless for
   ping/pong (which ignore `data`); **future per-op handlers MUST validate their
   own `data` shape** rather than assume an associative object.

### Phase 2, step 2.2 — v1 token-auth handshake (`auth.hello` / `auth.welcome` + auth gate)

**Delivered.** The v1 auth handshake in `Applications/Chat/Events.php`, still gated
by Flag A (`WS_NEW_HANDLING`) and dormant when it is OFF:

- `Events::handleAuthHello()` — implements `auth.hello` (§2.1) for all three roles:
  **host** (token vs `vps_masters`/`qs_masters` by `module`), **bot** (token vs
  `ws_bots` by id/name), and **admin** (mystage `session` validated with the exact
  legacy `msgLogin` session query). Host/bot validation is a primary-key row fetch →
  constant-time `hash_equals` token compare (honoring the rotation prev-hash within
  its grace window) → source-IP defense-in-depth **hard fail**. On success it
  populates the same session shape legacy `msgLogin` sets (`uid`/`module`/`name`/
  `ima`/`ip`/`type`/`online`/`login`), sets `v1_authed`, `bindUid`s, joins the role
  group, CAS-updates `$global->hosts` for vps hosts, and replies `auth.welcome`
  ({ `session`, `host_id`?, `uid`, `name`, `hub_time`, `timers`? } per §2.1 — no `img`).
- **Auth gate now enforced (closes the step 2.1 gap).** `dispatchV1()` enforces the
  §2.1 ordering rule: with Flag A ON, any op other than `auth.hello` received before
  successful v1 auth is answered `{ok:false,error:{code:"auth_required"}}` and the
  connection is closed (`ping` included). The step 2.1 `@todo` is resolved.
- **Migration.** `migrations/2026_07_phase2_token_auth.sql` adds the nullable
  `*_token_*` columns to `vps_masters`/`qs_masters` and creates the empty `ws_bots`
  table (AUTH_DESIGN §2). Behavior-neutral, operator-applied manually.
- Still dormant behind Flag A: with the flag OFF (the default), `dispatchV1()`
  returns before any auth logic, so this is a fleet-wide runtime no-op.

**Concrete auth-failure codes (clarifies §2.1's example).** §2.1 shows `auth_failed`
only as an *illustrative generic* reply code. The concrete, machine-readable codes the
hub actually emits on the auth path are the **closed set defined in `docs/AUTH_DESIGN.md`
§4**: `unknown_host`, `bad_token`, `ip_mismatch`, `no_token_issued`, `bot_disabled`,
`unsupported_credential`, `bad_session`, `bad_request`, `auth_disabled` (plus the
non-auth-decision `internal`). This is a status-area clarification only; the frozen
§2.1/§3 field tables are unchanged.

**Deferred follow-up.** `auth.hello` failed-attempt rate limiting: deferred
(AUTH_DESIGN §4) — not yet implemented as of step 2.2. The hub rejects+closes on each
bad attempt but does not throttle repeat offenders per source IP.

### Phase 2, step 2.3 — v1 `cmd.*` exec-relay handlers

**Delivered.** The full `cmd.*` streamed-command family (§2.2) in
`Applications/Chat/Events.php`, still gated by Flag A (`WS_NEW_HANDLING`) and
dormant when it is OFF. Reachable only via `dispatchV1()` (Flag A on + v1-authed;
a pre-auth `cmd.*` op is answered `auth_required` + close, per step 2.2):

- `Events::handleCmdExec()` — `cmd.exec` (admin `C→H`, relayed `H→A`). Role
  **admin**. Validates the frozen §2.2 fields; `run_id` is **required** (unique
  uuid — v1 forbids the legacy `md5($cmd)` scheme) and a **collision guard**
  rejects reuse of an in-flight `run_id` with `bad_request` before any relay or
  registry write. Uses the **corrected §2.2 defaults** `rows=24` (height→LINES)
  / `cols=80` (width→COLUMNS), deliberately NOT the swapped legacy
  `run_command` defaults. `for` is taken from the originating admin's session,
  never trusted from the client. Replies `not_online` when the target host uid
  is offline. *(QS limitation: the target uid is built as `vps<id>`, so QS hosts
  authing as `qs<id>` cannot be targeted — same limitation as legacy
  `run_command`, parity not regression.)*
- `Events::handleCmdStdin()` — `cmd.stdin` (admin `C→H`, relayed `H→A`). Role
  **admin**; unknown `run_id` silently dropped (race with a finished run).
- `Events::handleCmdOutput()` — `cmd.output` (host `A→H`, relayed `H→C`; no
  reply). Role **host** AND sender must own the run (sender uid == registry
  `host`). Relays to the run's `for` target (uid or `#group`).
- `Events::handleCmdExit()` — `cmd.exit` (host `A→H`, relayed `H→C`; no reply).
  Role **host** + ownership. **⛔ Exit-code invariant (E1):** `code`/`term` are
  forwarded to the admin **verbatim** — no casting/defaulting/remapping —
  because `queue_log` completion depends on provirted's 0/1 codes. Removes the
  finished run from the registry via a whole-map CAS loop.
- `Events::handleCmdKill()` — `cmd.kill` (admin `C→H`, relayed `H→A`). Role
  **admin**; the registry entry is intentionally left in place — the agent's
  ensuing `cmd.exit` performs cleanup (mirrors legacy `stop_run`→`ran`).

All five relay through the **same shared `$global->running` GlobalData
registry** the legacy path uses, via the identical whole-map CAS
read-modify-write loop, so legacy md5-keyed entries and v1 uuid-keyed entries
**coexist without collision** (registration, exit-removal, and onClose sweep all
CAS-safe). Registry entries also carry the legacy `id` field aliased to
`run_id` so pre-existing consumers read them without notices. Still dormant
behind Flag A: with the flag OFF (the default) `cmd.*` produces no reply, no
relay and no registry write — a fleet-wide runtime no-op.

Verified by `tests/EventsV1CmdTest.php` (happy paths + explicit-field carry;
role/ownership rejections; run_id-collision non-overwrite; host-offline
`not_online`; exit-code 0/1/signal verbatim + optional stdout/stderr; kill keeps
the entry; dormancy when Flag A off; unauthed→`auth_required`+close; and
legacy-entry coexistence across both exec registration and exit removal).

**Non-blocking notes from review (carried forward, not fixed in 2.3):**

1. **Any-admin stdin/kill (no per-run ownership).** `cmd.stdin` and `cmd.kill`
   authorize on role only — ANY admin may inject stdin into / kill ANY run,
   regardless of who originated it. This matches legacy `msgRunning` and the §3
   role-only auth model; deliberate, revisit-later.
2. **QS hosts unreachable for v1 cmd runs.** As above, the `vps<id>` uid
   construction excludes `qs<id>` hosts — parity with legacy `run_command`, not
   a regression.

### Phase 2, step 2.4 — v1 `pty.*` interactive-terminal relay handlers

**Delivered.** The full `pty.*` interactive-terminal family (§2.3) in
`Applications/Chat/Events.php`, hub-side relay only, still gated by Flag A
(`WS_NEW_HANDLING`) and dormant when it is OFF. Reachable only via `dispatchV1()`
(Flag A on + v1-authed; a pre-auth `pty.*` op is answered `auth_required` +
close, per step 2.2). The hub validates/authorizes and relays; actual PTY
allocation is a Phase-3 host-agent concern:

- `Events::handlePtyOpen()` — `pty.open` (admin `C→H`, relayed `H→A`). Role
  **admin**. Validates the frozen §2.3 fields; `pty_id` is **required** (unique
  uuid) and a **collision guard** rejects reuse of an in-flight `pty_id` with
  `bad_request` before any relay or registry write. **Scope gating (§5/OQ7):**
  `scope:"command"` (the default) runs the supplied `command` and needs only the
  standard admin role; `scope:"shell"` is **conservative-denied** — it requires
  an elevated session marker `$_SESSION['pty_shell'] === true` that no current
  auth path sets, so shell is `forbidden` for every admin today (spec-consistent
  with §5's "distinct privilege beyond `ima='admin'`"; command-scope terminals
  work for all admins, so this is not a regression). **Env dropped:** client
  `data.env` is **never** read or relayed — arbitrary attacker-controlled
  environment (`LD_PRELOAD`/`PATH`/`BASH_ENV`/…) cannot reach the host. Replies
  `not_online` when the target host uid is offline. Every open emits a structured
  §5 `pty_audit` line (`open` / `open_denied` for the shell deny).
- `Events::handlePtyData()` — `pty.data` (full-duplex any→hub→peer; no reply).
  **Party-gated:** the sender must be the owning admin (registry `for`) or the
  allocated host (registry `host`); anyone else gets `forbidden`. The
  base64-encoded `data.data` is relayed **byte-identical** — never
  decoded/re-encoded hub-side (binary-safe). Unknown `pty_id` silently dropped
  (data racing a close).
- `Events::handlePtyResize()` — `pty.resize` (admin `C→H`, relayed `H→A`; no
  reply). Role **admin** AND **owner-only** (sender uid == registry `for`) —
  resize is origination-side only, unlike duplex `pty.data`. Relays
  `{pty_id,cols,rows}` and CAS-updates the registry geometry. Unknown `pty_id`
  silently dropped.
- `Events::handlePtyClose()` — `pty.close` (either party any→hub→peer; no reply).
  Either the owning admin or the allocated host may close; anyone else gets
  `forbidden`. Relays the close (with the optional exit `code` verbatim) to the
  **other** party, removes the entry from the registry via a whole-map CAS loop,
  and emits a structured §5 `pty_audit` `close` line (`pty_id` / who closed /
  `code` / timestamp).

Pty session state lives in a **separate `$global->ptys` GlobalData registry**,
fully decoupled from the cmd `$global->running` registry (verified: a full pty
open/resize/close lifecycle leaves `$global->running` byte-identical, and vice
versa), lazily created via `$global->add('ptys', [])` so no legacy method is
touched. Env dropping, scope gating, and the audit stream all improve on the
legacy admin-gated `Process.php` shell (which has no structured pty audit at
all). Still dormant behind Flag A: with the flag OFF (the default) `pty.*`
produces no reply, no relay and no registry write — a fleet-wide runtime no-op.

Verified by `tests/EventsV1PtyTest.php` (command-scope happy path with default
`cols=80`/`rows=24` and client `env` proven not relayed; structured audit-line
emission; shell scope forbidden-by-default AND allowed-with-elevation-marker;
non-admin / missing-`pty_id` / missing-command / `pty_id`-collision non-overwrite
/ host-offline rejections; `pty.data` duplex both directions with byte-identical
base64 pass-through, third-party `forbidden`, unknown-id silent drop; `pty.resize`
owner-only + registry geometry update; `pty.close` either-party relay + removal +
verbatim `code=0`; dormancy when Flag A off; unauthed→`auth_required`+close; and
registry isolation from `$global->running`).

**Known Phase-3 refinements (not blocking; carried forward):**

1. **Reply-on-dispatch, not alloc-ack.** §2.3 words the `pty.open` reply as sent
   "once allocated on the host", but this hub-side step replies `{ok:true,
   data:{pty_id}}` on **relay dispatch** (exactly like `handleCmdExec`) because
   the host agent does not exist until Phase 3. Deferring the reply to a real
   agent alloc-ack is a Phase-3 refinement.
2. **No pty reaper yet.** Registry entries are removed only on an explicit
   `pty.close`. There is no disconnect-driven or cold-start sweep of orphaned
   `$global->ptys` entries yet — a Phase-3 (or dedicated pty-reaper step)
   follow-up, mirroring the cmd path's onClose/cold-start cleanup.
3. **Shell-scope elevation grant is unwired.** Shell scope stays denied for all
   admins until the auth design defines the concrete `pty_shell` elevation
   privilege (who gets it, and how it is set on the session). Granting that
   elevation is a follow-up for the auth design.
4. **Env allowlist deferred.** §2.3 says env is "allowlisted server-side", but no
   allowlist policy is defined yet, so env is dropped entirely for now. Defining
   the policy and relaying only the whitelisted subset is an auth/agent-design
   follow-up.

### Phase 2, step 2.5 — v1 `queue.*` HTTP-parity bridge (highest-risk step)

**Delivered.** The full `queue.*` family (§2.4) in `Applications/Chat/Events.php`,
backed by a new TaskWorker executor `Tasks/queue_action.php`, still gated by Flag A
(`WS_NEW_HANDLING`) and dormant when it is OFF. Reachable only via `dispatchV1()`
(Flag A on + v1-authed; a pre-auth `queue.*` op is answered `auth_required` + close,
per step 2.2):

- `Events::handleQueueAction()` — `queue.action` (§2.4), generic
  `ServiceQueueHandler` dispatch. Validates the frozen fields (`module` required +
  session-matched, `action` required snake_case, `args` object → `{}` default) then
  dispatches to the TaskWorker.
- `Events::handleQueuePull()` — `queue.pull`, alias forcing `action=get_queue`;
  reply uses the **single-aggregate `jobs` shape** (see §2.4 AMENDMENT 2).
- `Events::handleQueueProvision()` — `queue.provision`, alias forcing `get_new_vps`
  (vps) / `get_new_qs` (quickservers); reply `{script:str}`.
- `Events::handleQueueAck()` — `queue.ack`, **NEW in v1, ADDITIVE-ONLY**: validates
  the frozen fields (`history_id` positive int, `status` `"done"|"failed"`, `output`
  str) and emits ONE structured `queue_ack {…}` `safeEcho` line (history_id / status
  / module / host_id / who / output_len / ts). **Zero DB writes** — never touches the
  legacy `<module>queueold` optimistic flip or any `queue_log` completion logic
  (⛔ invariant); it dispatches **no** task at all, so no DB-writing path is even
  reachable. Reply `{ok:true, data:{}}`.
- Shared plumbing: `queueBindIdentity()` resolves and authorizes the identity from
  the **authed session only** (role host/bot, `module` must equal the session module,
  `host_id` parsed from the session uid `"vps<id>"`/`"qs<id>"` — never client-supplied;
  bots have no host binding and are conservatively denied). `dispatchQueueTask()`
  marshals the call to `Events::dispatchTask('queue_action', …)` and shapes the reply.
- `Tasks/queue_action.php` (`queue_action($args)`) — the executor. Re-resolves the
  `<prefix>_masters LEFT JOIN <prefix>_master_details` row from the authed `host_id`
  (mystage keys it by `REMOTE_ADDR`; the bridge keys it by the token-authed id) and
  invokes the **IDENTICAL** reusable `vps_queue_handler()`/`qs_queue_handler()`
  callable both HTTP transports use (→ `ServiceQueueHandler::render()` →
  `ResponseHandlers/*`), unchanged (⛔ invariant — no queue logic copied). Establishes
  the same execution context mystage `public_html/queue.php` uses (sessionid /
  account_id 160308 / `ima='services'`), and restores the task-pool convention
  (account_id 160307, empty accounts data) in `finally`. **Superglobal shim:** because
  the TaskWorker is long-lived and several `ResponseHandlers` read `$_REQUEST`
  directly, `args` is injected VERBATIM into `$_REQUEST`/`$_POST` for the handler call,
  with prior values saved before injection and restored in `finally` (even on throw)
  so no request state leaks across dispatches or into the legacy tasks sharing the
  2208 pool. Raw `render()` output is returned UNMODIFIED (null/false → `""`), keeping
  the WS reply byte-identical to the HTTP body.
- A new additive `Events::$taskDispatcher` static is a **test seam only** — strict
  null-guarded, so it is production-inert (null ⇒ the real `AsyncTcpConnection`
  dispatch runs, zero behavior change). It lets the bridge be unit-tested without a
  running event loop / TaskWorker.

**Two §2.4 amendments (this step).** The frozen §2.4 sketch had two spec-vs-reality
contract errors that the reuse invariant forces; both are now corrected inline in
§2.4 as clearly-marked IMPLEMENTATION AMENDMENTs (not silent edits):
1. **AMENDMENT 1 — verbatim-arg encoding.** For the telemetry-shaped queue actions
   (`server_info`/`vps_info`, `server_info_extra`, `server_list`, `cpu_usage`,
   `bandwidth`), `queue.action` passes `args` VERBATIM to the unchanged handlers,
   which decode unconditionally — so these actions REQUIRE the legacy-encoded string
   form (base64/json/gzip/html-entity), NOT the "plain obj" the original sketch said.
   The plain-obj ergonomics live on the dedicated `telemetry.*` ops (§2.5, step 2.6).
2. **AMENDMENT 2 — `queue.pull` single-aggregate shape.** `jobs` is a single
   aggregate entry `[{history_id:0, command:"get_queue", args:{script:<raw>}}]`
   (or `[]`), not per-row jobs — because `GetQueue::render()` produces one
   concatenated script AND does the `<module>queueold` row-flip in the same pass,
   which the invariant forbids decomposing.

Verified by `tests/EventsV1QueueTest.php` (identity from session not client; args
injected verbatim; module/prefix mapping; alias forcing `get_queue`/`get_new_vps`/
`get_new_qs`; aggregate `jobs` wrap + empty→`[]` + whitespace-only-included;
byte-identical result passthrough incl. hostile payloads; role/module rejections;
`queue.ack` zero-dispatch + structured-log + empty-`{}` reply + bad-field rejections;
task-failure/dispatch-failure → `internal`; dormancy when Flag A off; unauthed →
`auth_required` + close) and `tests/QueueActionSuperglobalShimTest.php` (the
`$_REQUEST`/`$_POST` save/inject/restore contract proven directly, including restore
after a throwing handler). 124 tests green; reviewed CLEAN. Still dormant behind
Flag A: with the flag OFF (the default) `queue.*` produces no reply, no task dispatch
and no state change — a fleet-wide runtime no-op.

**Deferred follow-up (documented, not faked green).** The live end-to-end
"WS reply === direct `vps_queue_handler()` output" byte-parity test requires the
mystage/TaskWorker runtime (the real `vps_queue_handler`/`ServiceQueueHandler`/
`ResponseHandlers` are not loadable in the datacentered PHPUnit harness). What is
proven here is STRUCTURAL parity (verbatim arg injection, session-derived identity,
unmodified passthrough, superglobal shim); the live-parity integration test is
deferred to a mystage-runtime harness and tracked in `ws_progress.md`.

### Phase 2, step 2.6 — v1 `telemetry.*` / `config.maps` / `vps.*` handlers + §1 `enc:"gzip"` decode

**Delivered.** Eleven new op handlers in `Applications/Chat/Events.php`
(`telemetry.host`, `telemetry.host_extra`, `telemetry.cpu`, `telemetry.bandwidth`,
`telemetry.inventory`, `telemetry.sysinfo`, `config.maps`, `vps.lock`, `vps.unlock`,
`vps.finished`, `vps.progress`), plus real §1 `enc:"gzip"` envelope decoding
(previously implemented nowhere). Still gated by Flag A (`WS_NEW_HANDLING`) and
dormant when it is OFF. Reachable only via `dispatchV1()` (Flag A on + v1-authed;
a pre-auth op is answered `auth_required` + close, per step 2.2):

- **Zero forked business logic (⛔ invariant).** Every op routes to the SAME
  unchanged consumers the legacy transports use — either a plain-obj
  `Events::dispatchTask()` to an unchanged `Tasks/*` file
  (`telemetry.host`→`vps_update_info`, `telemetry.bandwidth`→`bandwidth`,
  `telemetry.inventory`→`vps_get_list`, `config.maps`→`get_map`) or the step-2.5
  `dispatchQueueTask()`→`Tasks/queue_action.php` `$_REQUEST`-injection path to the
  unchanged `ResponseHandlers/*` (`telemetry.host_extra`→`ServerInfoExtra`,
  `telemetry.cpu`→`CpuUsage`, `vps.lock`/`vps.unlock`→`Lock`/`Unlock`,
  `vps.finished`→`Finished`, `vps.progress`→`InstallProgress`), with any required
  legacy wire encoding applied HUB-side per §2.4 AMENDMENT 1 (the plain-obj
  ergonomics promised there now exist on these ops).
- **Identity from the authed session only.** `telemetryBindIdentity()` /
  `queueBindIdentity()` derive `module`/`host_id` from `$_SESSION` (`uid`
  `"vps<id>"`/`"qs<id>"`) — never from the client payload; a client-supplied
  `module` is only accepted when it matches the session. Ops whose reused Task is
  `vps_masters`-only carry a vps-module gate (legacy-WS parity, not a new
  restriction).
- **`telemetry.cpu` host-at-index-0 reassembly.** `CpuUsage.php` `array_shift()`s
  the FIRST element of the decoded map as the host entry, so the bridge rebuilds
  the legacy shape as `[0 => host] + per_vps` — the array-union operator keeps the
  host slot first AND preserves `per_vps`'s numeric veid keys (which `array_merge`
  would renumber) — then `json_encode()`s it for the handler's
  `html_entity_decode → myadmin_unstringify` path. (`telemetry.inventory` performs
  the mirror-image demotion of its promoted `host` obj back into `servers[0]`.)
- **`config.maps` byte-compat (⛔ C6 registry gate).** The four map strings from
  the unchanged `Tasks/get_map.php`/`GetMap.php` (`slices`/`vnc`/`ips`/`mainips`,
  `"\n"`-joined `k:v` line blocks WITH the per-line trailing `"\n"`) pass through
  the hub UNTRIMMED/untouched, so `trim(wire)` on the host writes the exact bytes
  today's files contain (provirted reads `/root/cpaneldirect/vps.{slicemap,vncmap,
  ipmap,mainips}`). Proven by comparing the reply values against the raw
  `GetMap.php` json output — no hub-side transformation exists to diverge.
- **`telemetry.sysinfo` thin relay.** Modeled on legacy `msgPhpsysinfo`: the admin
  request is relayed to the host as a fresh envelope and correlated via a
  CAS-maintained GlobalData `sysinfos` registry (relay id → requesting admin uid +
  original envelope id); the host's `re`-correlated response is forwarded to the
  recorded admin with `data.host` overwritten from the authed host session (never
  trusted from the payload). Reply leg requires the sender BE the addressed host.

**`enc:"gzip"` (§1) — IMPLEMENTED (inbound decode, hub-wide).** Before this step a
gzip-encoded envelope (`data` = base64 string) failed `isV1Envelope()`'s
`is_array(data)` check and fell through to the legacy dispatcher — silently
dropped, which concretely broke the `telemetry.sysinfo` reply leg (§2.5 words the
reply `data` as b64gz "expressed as `enc:"gzip"` on the envelope"). Now:

- `isV1Envelope()` also accepts string `data` when `enc === "gzip"` is present
  (still a pure shape detector — no decoding there); plain envelopes are matched
  exactly as before.
- `dispatchV1()` calls the new `Events::v1DecodeEnvelopeData()` BEFORE any handler
  reads `$envelope['data']`: `base64_decode` (strict) → `gzuncompress` (the same
  gzcompress/zlib pairing the §0 `b64gz` type, legacy `msgClients`, and
  `Tasks/memcached_queue_task.php` use) → `json_decode` into the plain data array,
  with `enc` removed. Handlers are therefore encoding-agnostic; this works for
  EVERY op (including `auth.hello`), not just sysinfo. Any malformed input
  (non-"gzip" `enc` value, bad base64, bad zlib stream, non-JSON plaintext) gets a
  clean `{ok:false,error:{code:"bad_request"}}` reply — never a crash, never a
  silent drop. Purely additive: unencoded envelopes are byte-identically handled
  as before, and the auth gate still fires before decode for non-`auth.hello` ops.
- **Outbound hub gzip NOT implemented (deliberate).** The hub always SENDS plain
  (unencoded) `data` — including the sysinfo reply it forwards to the admin after
  decoding the host's gzip'd response. §1 makes `enc` explicit-never-implicit and
  §2.5's "SHOULD set `enc:"gzip"`" is advisory for senders of large payloads, so
  plain hub output is spec-compliant; hub-side compression of large outbound
  envelopes is a follow-up optimization, not a correctness gap.

Still dormant behind Flag A: with the flag OFF (the default) all eleven ops — and
the gzip decode path — produce no reply, no task dispatch and no state change
(a v1-shaped frame, gzip'd or not, is parsed and discarded), a fleet-wide runtime
no-op. Legacy handlers byte-unchanged; `php -l` clean.

**Non-blocking notes from review (carried forward, not fixed in 2.6):**

1. **No `sysinfos` registry reaper.** The GlobalData `sysinfos` relay registry
   (relay id → admin) has no expiry/reaper — a host that never answers leaks its
   entry forever, and the waiting admin gets no timeout error. Mirrors the pty
   registry's known 2.4 gap; a dedicated reaper step (or fold into the pty reaper)
   is the follow-up. Flagged inline in `handleTelemetrySysinfo()`.
2. **Hub does not gzip outbound envelopes.** As above — spec-compliant, deferred
   as an optimization.

Verified by `tests/EventsV1TelemetryTest.php`, `tests/EventsV1ConfigVpsTest.php`
(+2 gzip decode tests in `tests/EventsV1AuthHelloTest.php`, including
`testAuthHelloMalformedGzipRepliesBadRequestButDoesNotClose` pinning the
malformed-gzip-does-not-close asymmetry); 190 tests / 986 assertions total,
full suite green.

### Phase 2, step 2.7 — v1 `channel.*` / `chat.*` fan-out (channels & messaging)

**Delivered.** The full `channel.*` / `chat.*` family (§2.10) in
`Applications/Chat/Events.php` — six new op handlers plus a durable/hot-cache
dual-write store backed by a new TaskWorker writer `Tasks/chat_message.php` and a
new `chat_messages` table (`migrations/2026_07_phase2_chat_messages.sql`,
operator-applied, byte-neutral). This is a parallel rebuild of the legacy
`say()`/`rooms` chat layer — legacy `say`/`msgSay`/`rooms[0]['messages']` and the
`login`/`logout` broadcasts are **byte-unchanged** (⛔ retirement is P7.1). Still
gated by Flag A (`WS_NEW_HANDLING`) and dormant when it is OFF. Reachable only via
`dispatchV1()` (Flag A on + v1-authed; a pre-auth op is answered `auth_required` +
close, per step 2.2):

- `Events::handleChannelList()` — `channel.list` (§2.10). Derives the list from
  the union of the `$global->channel_meta` registry (explicit `channel.create`d
  channels) and every id with traffic in the `$global->channels` hot cache, then
  **filters by the caller's ACL** so hosts see only their own channels and bots
  only `chat:*`. Reply `{channels:[{id,type,topic,members}]}`.
- `Events::handleChannelJoin()` — `channel.join`. Validates id shape + role ACL,
  `Gateway::joinGroup()`s the client (the same group idiom legacy room broadcasts
  use), and replies `{history:[…]}` — the last N≤100 messages from the **hot cache
  ONLY** (never a DB query on join, per §4); a best-effort `channel.presence`
  follows. Deep scrollback via `msg_id` pagination against `chat_messages` is a
  later client-driven step.
- `Events::handleChannelLeave()` — `channel.leave`, the symmetric
  `Gateway::leaveGroup()`. No ACL check on the way out (leaving is a harmless
  no-op); reply `{}`, best-effort presence follows.
- `Events::handleChannelCreate()` — `channel.create`, **ADMIN-GATED** (plan B6/B7:
  the admin UI "New Channel" button). Name constrained to a sane slug; writes
  `{type:'chat',topic,created_by,created_at}` into `$global->channel_meta` via a
  CAS whole-map loop. A **duplicate id is rejected with `bad_request` INSIDE the
  CAS loop** (two racing creates cannot both win; no silent overwrite of an
  existing channel's metadata). Reply `{channel:"chat:<name>"}`.
- `Events::handleChannelPublish()` — `channel.publish` (`any→H`), the v1
  counterpart of the legacy `say()` room path: raw-text storage, durable
  persistence, bounded hot cache, real channels. Validates frozen fields + ACL,
  then runs the shared publish pipeline. Reply `{ok:true,data:{msg_id}}`.
- `Events::handleChatSend()` — `chat.send` (`C→H`) convenience wrapper with two
  forms: the **channel form** (no `to`) delegates verbatim to
  `handleChannelPublish` (emitting a `channel.message` so subscribers get one
  event type regardless of wrapper); the **DM form** (`{to,body}`, replacing
  legacy `say is:"client"`) persists to `chat_messages` with a **sorted**
  `dm:<uidA>:<uidB>` channel (order-independent thread id) and routes a
  `chat.message` push to **exactly the two participant uids** via
  `Gateway::sendToUid` (sender included; never broadcast) — fixing the legacy gap
  where DMs were never persisted.

**Dual-write store (DB + bounded hot cache).** The shared `chatPublishMessage()` /
`chatFinishPublish()` pipeline builds the §2.10 message object — `from`/`from_name`
**always from the authed session, never client-supplied** (spoofing is pinned
against) — and both persists and caches it:

- **Durability** is dispatched to the TaskWorker (`Tasks/chat_message.php` via
  `Events::dispatchTask('chat_message', …)`), NOT written inline — keeping
  `Events.php` thin and the BusinessWorker event loop unblocked (the step 2.5/2.6
  `queue_action`/`boardctl_task` precedent). The task returns the
  `chat_messages.id` AUTO_INCREMENT, which becomes §2.10's required `msg_id` on the
  fanned-out event and the cached entry. `body` is stored **RAW** (no
  `nl2br`/`htmlspecialchars` at store time — rendering is a client concern, the
  OQ5 fix vs legacy `say()`).
- **Hot cache** is the `$global->channels` map (channel id → last
  `CHAT_HISTORY_MAX`=100 message objects), maintained with the CAS whole-map loop
  convention of `$global->ptys`/`sysinfos`. Bounded and evicting, unlike legacy
  `rooms[0]['messages']`; it serves `channel.join` history and the live tail
  without a DB round-trip.
- **`level:"log"` SKIPS the DB write** (cache + fan-out only, `msg_id` 0) per §4 —
  log channels already persist via `queue_log`/Influx; `"chat"`/`"info"`/`"warn"`/
  `"error"` levels are always persisted. On a persist failure or dispatch failure
  the message still fans out live with `msg_id` 0 (availability over durability for
  the live tail; the failure is operator-logged).

**ACL (§3 / plan B6), identity from session only.** `chatChannelAllowed()` gates
join/publish by role, using the uid from the authed session (never client data):
hosts may access **only their own `host:*` channels** (`host:<uid>` / `host:<uid>:…`)
and their own `job:*` channels; bots are restricted to `chat:*` only; admins may
access any non-DM channel. Two conservative interpretations are flagged as
**documented follow-ups** (no runtime dependency yet exists): `job:*` ownership is
inferred by a **uid-segment heuristic** (a `:`-segment of the job id must equal the
host uid) because the hub has no job→host registry; and the bot restriction is a
blanket `chat:*` because the `ws_bots.bot_channels` JSON allow-list column (from the
token-auth migration) is not yet threaded into the auth session.

**Two beyond-frozen-spec interpretation calls (reviewed safe, non-contradictory):**
1. **`dm:*` is participant-only for EVERY role, admins included.** The frozen §2.10
   text puts no channel restriction on admins, but DM ids surface in
   `channel.list`/`channel.join` via the hot cache — so without this an admin could
   read anyone's DM history. Restricting `dm:*` to the two sorted participants is a
   deliberate, safe extension of the spec (it removes an access, never grants one).
   Pinned by `testDmThirdPartyEvenAdminCannotJoin` /
   `testDmChannelHiddenFromNonParticipantInList`.
2. **`channel.publish`'s ack reply shape.** §2.10 does not spell out
   `channel.publish`'s reply; the hub answers the minimal
   `{ok:true,data:{msg_id:<int>}}` (the persisted id, 0 when skipped/failed) so the
   sender can correlate scrollback. Unspecified-but-harmless — additive only.

**⚠️ KNOWN SCALABILITY FOLLOW-UP (flagged prominently — more substantive than a
routine LOW note).** The per-channel *message* list is capped and evicts, but the
`$global->channels` map has **no cap on the NUMBER of channel keys and no idle
eviction**, and two growth vectors compound. (a) `chat.send`'s DM form does **not
validate the `to` uid for existence/format**, so any authed user can mint an
unlimited number of distinct `dm:<me>:<random>` keys — each becoming a permanent map
entry (and a `chat_messages` row). (b) Every `channel.publish`/`chat.send` append
**CAS round-trips the ENTIRE all-channels map**, not just the one channel, so the
per-op GlobalData payload grows linearly with the total fleet-wide channel count.
The already-solved per-channel 100-message cap bounds neither. Suggested follow-up:
move to **per-channel GlobalData keys** (an append touches only its own channel)
instead of one giant map, and/or a **channel-count cap + idle-eviction policy**, and
validate the DM `to` uid so junk `dm:*` keys cannot be minted. Harmless at current
channel counts; flagged inline in `chatCacheAppend()` and `handleChatSend()`.

**Other non-blocking notes from review (carried forward, not fixed in 2.7):**

1. **DM `to` not validated for existence/format.** As above — permits junk hot-cache
   keys / malformed dm ids in `chat_messages`. Low severity (a client can only spam
   its own dm threads); fixed together with the per-channel-key rework. Flagged in
   `handleChatSend()`.
2. **No presence broadcast on disconnect.** `onClose` is byte-unchanged legacy this
   step (touching it would modify forbidden legacy code), so `channel.presence`
   fires only on explicit `channel.join`/`channel.leave`; subscribers see the
   corrected member list on the next join/leave. Self-documented scope limitation,
   flagged in `chatBroadcastPresence()`.
3. **`channel.list`'s `members` is a connection count, not a unique-uid count.**
   `getClientIdCountByGroup` counts connections, so a uid with two open tabs counts
   twice — a documented approximation (`channel.presence`'s member *array* is
   deduped by uid, pinned by `testPresenceMembersDedupedByUid`). Flagged in
   `handleChannelList()`/`chatBroadcastPresence()`.

Still dormant behind Flag A: with the flag OFF (the default) all six ops produce no
reply, no task dispatch, no cache write and no group op — a fleet-wide runtime
no-op. Legacy `say`/`rooms`/`onClose` byte-unchanged; `php -l` clean on both
`Events.php` and `Tasks/chat_message.php`.

Verified by `tests/EventsV1ChatTest.php` (dormancy when Flag A off; list reflects
created+cached channels and ACL-filters per role; join returns cached history +
subscribes; leave replies empty `{}`; admin-gated create + full-id reply + bad-name
rejection + **duplicate-create rejection**; publish persists+caches+fans-out;
identity cannot be spoofed on publish or DM; empty-body / invalid-level rejections;
**log-level skips the DB write but still fans out** while info-level still persists;
persist-failure still fans out with `msg_id` 0; chat.send channel form emits
`channel.message`; chat.send DM persists a **sorted** channel + delivers to
participants only + symmetric sorting + spoof-proof + empty-`to` rejection; hot
cache **caps at `CHAT_HISTORY_MAX` and evicts oldest**; host cannot publish to a
foreign `host:*`/`job:*` channel but can to its own; bot cannot join/publish a
non-`chat:*` channel; **third-party (even admin) cannot join a `dm:*`**; dm hidden
from non-participant in list; presence members deduped by uid); 222 tests / 1140
assertions total, full suite green; reviewed CLEAN.

### Phase 2, step 2.8 — `admin.*` ops (`admin.hosts` / `admin.timers` / `admin.running`)

**Delivered.** The three `admin.*` introspection ops (§2.9) in
`Applications/Chat/Events.php` — `admin.hosts`, `admin.timers`, `admin.running`
— replacing the legacy chat `clients`/`timers`/`run_list` (which are
**byte-unchanged** and keep serving legacy clients; retirement is P7.1). All
three require role **admin** (§2.9/§3), are read-only introspection (no state
mutation), and are still gated by Flag A (`WS_NEW_HANDLING`) — dormant when it
is OFF. Reachable only via `dispatchV1()` (Flag A on + v1-authed; a pre-auth
`admin.*` op is answered `auth_required` + close, per step 2.2):

- `Events::handleAdminHosts()` — `admin.hosts`, replaces chat `clients`
  (`msgClients`). Same source data as `msgClients` — iterate
  `Gateway::getAllClientSessions()`, split admin sessions from everything else —
  reshaped to the frozen §2.9 `hosts`/`admins` field lists, **minus the
  chat-room noise** (`$global->rooms`) and **minus the mandatory `gzcompress`**
  legacy always applies (a caller wanting compression uses envelope `enc:"gzip"`
  instead, per §1). `host_id` is parsed from the uid the hub itself bound at auth
  (never client-supplied); vps-module sessions missing `type`/`ip` fall back to
  the `$global->hosts` registry row (`vps_masters`, keyed by `vps_id`). Reply
  `{ok:true,data:{hosts:arr,admins:arr}}`.
- `Events::handleAdminTimers()` — `admin.timers`, replaces chat `timers`
  (`msgTimers`). §2.9's diff-note records that legacy `msgTimers` replies with an
  **empty** `{type:"timers"}` (its status calls are commented out) — so v1 here
  is a **genuine improvement**, not just parity: it returns the real registry
  payload the CLI `ListCommand` wants, name → `{interval, timer_id}` (with the
  optional `last_run` emitted only when present). The registry is
  `$global->timers`, enriched at `Timer::add()` registration time on the
  timer-hosting server (myadmin1, worker id 0) for each of
  `processing_queue_timer`, `processing_queue_reaper`, `boardctl_queue_timer`,
  `vps_queue_timer`, `memcache_queue_timer`, `map_queue_timer`,
  `hyperv_update_list_timer`, `hyperv_queue_timer`. Reply
  `{ok:true,data:{timers:map<str,obj>}}` (`{}` on a server that hosts no timers).
- `Events::handleAdminRunning()` — `admin.running`, replaces chat/agent
  `run_list`. Read-only over the **SAME** `$global->running` registry that step
  2.3's `cmd.exec` (`handleCmdExec`) writes; it reads and never CAS-mutates the
  map. Handles **both** entry shapes that coexist there: v1 entries (uuid
  `run_id`, `started` present) and legacy `run_command` entries (md5 key, no
  `started`). Every entry is reshaped to the frozen §2.9 record `{run_id, host,
  command, interact, update_after, for, rows, cols, started}`; legacy `type` is
  dropped and `run_id` falls back to the legacy `id`/registry key. Reply
  `{ok:true,data:{running:arr<obj>}}` (`[]` when nothing is in flight).

**`last_run` deferral (deliberate, spec-conformant — NOT a gap).** `admin.timers`
does not live-track `last_run`. Per the frozen §2.9 spec `last_run` is **optional**
(`{interval:int, last_run:ts?, timer_id:int}`), so emitting `{interval, timer_id}`
alone is fully conformant. Wiring real `last_run` would require writing a
timestamp from **inside** each timer callback body — and several of those
callbacks (`processing_queue_timer` / `vps_queue_timer` / `boardctl_queue_timer`)
are **invariant-frozen**: they contain CAS-lock, DB-retry and task-dispatch logic
that must stay **byte-for-byte identical** through the migration. Not touching
those bodies is the conservative, spec-conformant choice — **confirmed sound by
an independent Review Agent**, not an oversight. It remains a legitimate future
enhancement if `last_run` is ever needed: it would require careful, **flag-gated
instrumentation added inside each callback**, unlocked only once those callbacks
are no longer frozen.

**`started:0` sentinel semantics (LOW note #1, now documented).** In
`admin.running`, only step-2.3 v1 `handleCmdExec` entries carry `started`. A
legacy `run_command` entry (md5-keyed, no `started` field) is reported with
`started:0` — an explicit **sentinel meaning "predates v1 `started` tracking"**,
NOT "started at the unix epoch". Consumers MUST treat `started:0` as "start time
unknown", never as a real timestamp.

**Non-blocking review notes (LOW severity, documented as follow-ups — no code
change this step):**

1. **`started:0` sentinel for legacy running entries** — documented in the entry
   above; consumers must not read it as a real timestamp.
2. **Legacy `ima:"client"` chat sessions lump into `admin.hosts`'s `hosts`
   array** with a digits-stripped `host_id`. Spec-faithful (every non-admin
   Gateway session is a `hosts` row here), but worth a note for anyone building
   mixed-mode tooling: the `hosts` array is not exclusively provisioning hosts.
3. **Reply-side `enc:"gzip"` for large `admin.hosts` payloads is not
   implemented.** Compression is optional per §1 (a SHOULD, not a MUST) and the
   hub always sends plain `data`; fine to defer — a future perf optimization for
   large fleets, not a correctness gap.
4. **Empty-string fallbacks for `online`/`name`/`ip` on sparse legacy sessions**
   are lenient relative to §2.9's typed fields. Harmless; documented so it is not
   mistaken for a bug.
5. **Step-isolation caveat (informational).** All of steps 2.1–2.8 remain
   uncommitted in a single working tree, so per-step diffs are not cleanly
   isolated in git history yet. Purely informational — the program's established
   rule is NOT to commit during Phase 2 execution.

Still dormant behind Flag A: with the flag OFF (the default) all three ops
produce no reply and no state read/write beyond the parse — a fleet-wide runtime
no-op. Legacy `clients`/`timers`/`run_list` byte-unchanged; `php -l` clean.

Verified by `tests/EventsV1AdminTest.php` (19 tests / 156 assertions); full suite
now 241 tests / 1296 assertions, 100% green; reviewed CLEAN by an independent
Review Agent (first pass, no Fix Agent iteration needed).

### Phase 2, step 2.9 — HTTP trigger endpoint (`POST /trigger_payment.php`)

**Delivered.** A hub-side-only, authenticated HTTP endpoint,
`Web/trigger_payment.php`, that lets an authenticated caller nudge the existing
payment-queue processing to run **now** instead of waiting for the next 30s
`processing_queue_timer` tick (plan step 2.9; the eventual caller is mystage's
`queue_process_payment` in plan step 4.3, replacing the legacy WS `paymentprocess`
message). It contains **no payment business logic** and never touches
`queue_log`/billing tables itself.

- **Routing reality (filename-based, not path-based).** `start_web.php`'s
  WebServer maps URL paths to files under `Web/` **by filename** (e.g.
  `Web/queue.php` → `/queue.php`), so the plan's conceptual "`POST /trigger/payment`"
  is realized as **`POST /trigger_payment.php`** (per the plan E3 file-touch map,
  "new `Web/trigger_*.php`"). There is no URL-path router; the file basename *is*
  the route.
- **Auth (shared-secret token, constant-time compare).** The caller supplies a
  `token` POST field, compared with `hash_equals()` against the `WS_TRIGGER_TOKEN`
  constant expected in the out-of-repo `config.settings.php` (already loaded by
  `start_web.php`'s `onWorkerStart`). Consistent with `docs/AUTH_DESIGN.md` §4
  item 3's constant-time compare rule. It **fails closed**: if `WS_TRIGGER_TOKEN`
  is undefined or empty, every request is rejected (`unauthorized`) — the endpoint
  is never open. The token is read only from `$_POST`, making this POST-only (a
  bare GET presents no token and always fails auth). The required manual operator
  provisioning of this secret is documented in `docs/AUTH_DESIGN.md` §10.
- **Flag A dormancy gate (B8 ship-dormant).** Gated behind
  `FeatureFlags::useNewHandling()`. With Flag A OFF (the default), a *valid*
  authenticated request still gets `{"status":"error","error":"disabled"}` and
  nothing is nudged — deploying this file is a runtime no-op fleet-wide until an
  operator turns Flag A on.
- **Nudge, not duplicate (design rationale).** On success it calls
  **`Events::processing_queue_timer()`** directly — the **exact same** method the
  registered 30s timer (`Events.php` `onWorkerStart`) and the legacy
  `msgPaymentprocess()` handler already invoke on-demand. That method is
  **CAS-safe by design**: it takes the GlobalData `processing_queue` CAS lock
  before reading the queue and dispatches each row to the dedicated payment
  TaskWorker pool (`Events::PAYMENT_TASK_ADDRESS`, `Text://127.0.0.1:2209`) via
  `Events::dispatchTask('processing_queue_task', $row, …)`. The endpoint therefore
  **reuses** the existing, frozen queue-processing path rather than duplicating
  `Tasks/processing_queue_task.php`'s logic — no divergent second code path, and
  no possibility of double-processing (if the timer is already mid-tick the CAS
  simply loses and the nudge is a harmless no-op).
- **Response shape (body-only, always HTTP 200).** Like other `Web/*.php` scripts
  in this codebase, the WebServer emits the included file's output as a plain 200;
  logical success/failure is carried in the JSON body's `status`/`error` fields:
  - success: `{"status":"ok","nudged":"processing_queue_timer","ts":<unix>}`
  - failure: `{"status":"error","error":"<unauthorized|disabled|unavailable>"}`

**Testing approach (include()-based black-box + subprocess fixture).** Verified by
`tests/TriggerPaymentEndpointTest.php`, which drives the endpoint as a **black box**
by `include()`-ing `Web/trigger_payment.php` under controlled `$_POST`/`$_SERVER`
superglobals and constant state, then asserting on the captured JSON body — this
exercises the real file end-to-end without standing up a live WebServer. Because
the genuinely-*undefined*-constant branch (`WS_TRIGGER_TOKEN` never `define()`d)
cannot be reproduced in-process once the constant is defined by any earlier test,
that path is covered by a dedicated **subprocess fixture**,
`tests/fixtures/trigger_payment_undefined_token.php`, run in a fresh PHP process
where the constant is truly absent.

**Non-blocking review notes (LOW severity, documented as follow-ups — no code
change this step):**

1. **CAS-lost race reports `ok`.** If an HTTP nudge arrives while the timer is
   already mid-tick, `processing_queue_timer()`'s CAS lock is already held, so the
   nudge does no new work — yet the endpoint still replies `{"status":"ok"}`. This
   is **cosmetic, not a correctness issue** (no duplicate work happens either way);
   documented so the `ok` is not misread as "a fresh drain definitely ran".
2. **Synchronous nudge briefly stalls one WebServer worker.** `processing_queue_timer()`
   runs inline in the handling WebServer worker process, briefly occupying it while
   it takes the CAS lock and dispatches rows. This is the **same performance
   characteristic as the timer's own regular tick** (which likewise runs the body
   synchronously in its host process) — **acceptable, not a regression**.
3. **Always HTTP 200 regardless of logical outcome.** The real outcome is in the
   body's `status`/`error` fields, not the HTTP status line. This is an
   **infrastructure limitation of how this codebase invokes `Web/` scripts**
   (already true of the other `Web/*.php` endpoints), **not a design choice of this
   endpoint** and not a defect.
4. **Test-coverage honesty (from the Test Agent).** The nudge test only exercises
   the **empty-queue path** of `processing_queue_timer()` — it proves genuine
   execution reaches and completes inside that method, **not** an exhaustive
   re-test of the timer's own already-covered logic. One drafted test that needed a
   real socket connect was **removed as redundant** with existing `FeatureFlagsTest`
   coverage.

Still dormant behind Flag A: with the flag OFF (the default) a valid authenticated
request nudges nothing (`disabled`) — a fleet-wide runtime no-op. Touches/duplicates
**no** existing queue or task logic; `php -l` clean.

Verified by `tests/TriggerPaymentEndpointTest.php` (9 tests / 34 assertions); suite
250/1330, 100% green; reviewed CLEAN.

---

## 7. Cross-reference

This document freezes the *what*. For the *why* — architecture, dual-transport
parity guarantees, rollout flags, and the ⛔ queue/HyperV invariant — see
`ws_revamp_plan.md` **Part B** (B1 architecture, B2 envelope, B3 op sketch, B4
HTTP/WS parity, B5 auth, B6 channels, B7 clients, B8 flag lifecycle) and the
decisions log in `ws_progress.md`. Any change to a field list in this file is a
**breaking protocol change** and requires a `v` bump (hub supports N and N−1)
plus sign-off per the plan's review gates.

### Appendix A — legacy → v1 op mapping (bridge table)

| Legacy (WS `type` / HTTP `action`) | v1 op |
|---|---|
| `login` (ima:host / ima:admin) | `auth.hello` / `auth.welcome` |
| `ping` / `pong` | `ping` / `pong` |
| `run` | `cmd.exec` |
| `running` (with `stdin`) | `cmd.stdin` |
| `running` (with `stdout`/`stderr`) | `cmd.output` |
| `ran` | `cmd.exit` |
| `stop_run` | `cmd.kill` |
| `run_list` | `admin.running` |
| *(none — new)* | `pty.open/data/resize/close` |
| HTTP `get_queue` / `get_qs_queue` | `queue.pull` |
| HTTP `get_new_vps` / `get_new_qs` | `queue.provision` |
| *(none — new)* | `queue.ack` |
| HTTP any `ServiceQueueHandler` action | `queue.action` |
| `vps_info` (WS) / HTTP `server_info`,`vps_info` | `telemetry.host` |
| HTTP `server_info_extra`,`vps_info_extra` | `telemetry.host_extra` |
| HTTP `cpu_usage` | `telemetry.cpu` |
| `bandwidth` (WS) / HTTP `bandwidth` | `telemetry.bandwidth` |
| `vps_list` (WS) / HTTP `server_list` | `telemetry.inventory` |
| `phpsysinfo` | `telemetry.sysinfo` |
| `get_map` (WS both directions) / HTTP `get_map` | `config.maps` |
| HTTP `get_info` | `config.topology` |
| HTTP `get_template` | `config.template` |
| HTTP `lock` / `unlock` | `vps.lock` / `vps.unlock` |
| HTTP `finished` | `vps.finished` |
| HTTP `install_progress` | `vps.progress` |
| `self-update` | `agent.update` |
| `clients` | `admin.hosts` |
| `timers` | `admin.timers` |
| `say` (is:room) | `channel.publish` / `chat.send` |
| `say` (is:client) | `chat.send` (`to` form) |
| `login`/`logout` broadcasts | `channel.presence` / `chat.presence` |
| `paymentprocess` | *(not carried — becomes HTTP `POST /trigger/payment`, plan P2.9)* |
| `self_update` broadcast relay, `run_local`, rooms UI messages | *(dropped — dead chat layer, P7.1)* |
