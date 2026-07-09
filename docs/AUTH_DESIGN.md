# AUTH_DESIGN — Token Auth for Hosts/Bots + Session Auth for Admins

Status: **DESIGN ONLY** (Phase 0 Step 0.4) · Per plan B5 (Auth) and B8 (flag lifecycle) · Decision OQ1: **hub config push** (decided 2026-06-29)

This document specifies token issuance, storage, push, validation, rotation, and admin session auth for the WebSocket revamp. **No code ships from this step.** Everything described here is dormant behind **Flag A (`WS_NEW_HANDLING`, default OFF)** — while OFF, behavior is byte-identical to today.

---

## 0. Current state (investigated 2026-07-01)

What the code actually does today, so this design plugs into real structures:

- **Host auth is IP-only.** Two places:
  - `Web/queue.php` — every action (`get_queue`, `get_new_vps`, `get_qs_queue`, …) resolves the caller by
    `SELECT * FROM vps_masters LEFT JOIN vps_master_details USING (vps_id) WHERE vps_ip = $_SERVER['REMOTE_ADDR']`
    (and the parallel `qs_masters`/`qs_master_details` lookup keyed on `qs_ip` for QuickServers). No credential of any kind — possession of the IP *is* the identity.
  - `Applications/Chat/Events.php::msgLogin` (`ima == 'host'`, ~line 1220) — same `vps_masters WHERE vps_ip = REMOTE_ADDR` lookup; on match it sets `$_SESSION` (`uid = 'vps'.$vps_id`, `module`, `name`, `ima`, `ip`, `type`, `login=true`), binds UID, joins the `hosts` group, and CAS-updates the `$global->hosts` map.
- **Admin auth** (`Events.php::msgLogin`, `ima == 'admin'`, ~line 1265) has two branches:
  1. `session_id` supplied → validated against the mystage DB: `sessions LEFT JOIN accounts ON session_owner=account_id WHERE account_ima="admin" AND session_id = :session_id`. **This branch is the keeper.**
  2. `username`/`password` supplied → `accounts WHERE account_lid=:username AND account_passwd = md5(:password)`. **This is the MD5 path B5 drops.**
- **`vps_masters` columns actually referenced** in this repo: `vps_id`, `vps_name`, `vps_ip`, `vps_type` (plus everything via `SELECT *` and the `vps_master_details` join). `qs_masters` referenced via `qs_id`, `qs_ip`.
- **No bot registry exists.** Nothing in `Events.php` or `Tasks/` defines or queries a bots table; "bot" identities (future automated non-host clients) are a new concept introduced here.
- **`onConnect` is empty** (`Events.php` line 202) — identity is established only when the client volunteers a `login` message. Unauthenticated connections currently sit unbound.
- Existing hub→host push op referenced by the plan: `agent.update hub→host { url?, restart:true }` — precedent for hub-initiated config delivery over the established connection.

**Threats this design addresses:** IP spoofing / NAT-shared IPs / IP reassignment impersonating a host; MD5 (unsalted, fast) admin password storage/verification on the WS path; no per-identity revocation today (you can only delete the row or change the IP).

---

## 1. Goal

Replace **IP-only trust** (hosts) and the **MD5 password path** (admins) with:

- **Hosts and bots:** per-identity bearer tokens, generated and distributed by the hub ("hub config push", OQ1). Token + source-IP check (defense in depth, per B5).
- **Admins/browsers:** keep session-based auth — `auth.hello{role:"admin", session}` validated against the existing mystage session table, exactly the mechanism branch (1) above already uses. Drop the MD5 username/password branch on the new path.
- **Dormant by default (B8):** all of this sits behind Flag A. With Flag A OFF (the default, everywhere, indefinitely), the legacy `msgLogin` IP/session/MD5 flow and the IP-keyed `queue.php` flow continue **untouched and byte-identical**. Nothing changes until an operator opts a node in.

Non-goals: mTLS/client certificates (may be layered later; TLS already terminates at gateway 7272 per B5), customer/guest auth (explicitly out of scope, as today), HTTP `queue.php` auth changes (HTTP stays IP-keyed under Flag B; token auth applies to the v1 WS path).

## 2. Token issuance & storage

**Generation (hub-side only; hosts/bots never mint their own):**

- `random_bytes(32)` from the hub, hex-encoded → 64-char string, 256 bits of entropy. CSPRNG, no wordlists, no derivation from host attributes.
- Recommended wire format: prefixed for greppability/incident response — `dchost_<64 hex>` for hosts, `dcbot_<64 hex>` for bots. The prefix is stored with the token (it is part of the token string).
- **Storage is hashed, not plaintext.** Store `hash('sha256', $token)` in the DB; the plaintext exists only in memory during issuance/push and on the host's disk. (A 256-bit random token needs no salt/bcrypt — brute force is infeasible; SHA-256 keeps lookup O(1) by exact match.) A DB read (SQLi, backup leak) then does not yield usable credentials.

**Schema — hosts.** Extend `vps_masters` (and mirror on `qs_masters`) rather than invent a parallel table, since every auth path already resolves identity through these rows:

```sql
ALTER TABLE vps_masters
    ADD COLUMN vps_token_hash   CHAR(64)  NULL DEFAULT NULL COMMENT 'sha256 hex of bearer token; NULL = no token issued',
    ADD COLUMN vps_token_issued DATETIME  NULL DEFAULT NULL,
    ADD COLUMN vps_token_prev_hash CHAR(64) NULL DEFAULT NULL COMMENT 'previous token during rotation grace window',
    ADD COLUMN vps_token_prev_expires DATETIME NULL DEFAULT NULL;

ALTER TABLE qs_masters
    ADD COLUMN qs_token_hash    CHAR(64)  NULL DEFAULT NULL,
    ADD COLUMN qs_token_issued  DATETIME  NULL DEFAULT NULL,
    ADD COLUMN qs_token_prev_hash CHAR(64) NULL DEFAULT NULL,
    ADD COLUMN qs_token_prev_expires DATETIME NULL DEFAULT NULL;
```

Nullable columns → the migration is a no-op for every existing query (`SELECT *` consumers in `Tasks/*.php`, `Web/queue.php`, `Events.php` are unaffected). A `NULL` hash means "no token issued yet" — such a host can only use the legacy path.

**Schema — bots.** No registry exists today, so create one:

```sql
CREATE TABLE ws_bots (
    bot_id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bot_name         VARCHAR(64)  NOT NULL,
    bot_ip           VARCHAR(45)  NULL DEFAULT NULL COMMENT 'optional pinned source IP (defense in depth); NULL = no IP pin',
    bot_token_hash   CHAR(64)     NULL DEFAULT NULL,
    bot_token_issued DATETIME     NULL DEFAULT NULL,
    bot_token_prev_hash CHAR(64)  NULL DEFAULT NULL,
    bot_token_prev_expires DATETIME NULL DEFAULT NULL,
    bot_enabled      TINYINT(1)   NOT NULL DEFAULT 1,
    bot_channels     TEXT         NULL COMMENT 'optional JSON allow-list of channel patterns (B6 role gating)',
    UNIQUE KEY bot_name (bot_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Lookup:** `auth.hello` carries `host_id` (e.g. `vps1234`, `qs88`, `bot:deploybot`), so validation is a primary-key row fetch followed by a hash compare — never a scan of all token hashes.

## 3. Push mechanism (hub config push, per OQ1)

The hub delivers a newly issued or rotated token to a host/bot **over the connection the host already holds**, in the same hub→host direction as the existing `agent.update` op:

- New protocol-v1 op, provisionally **`config.token`** (hub→host): `{ host_id, token, issued_at }`. The exact op name/envelope will be normatively defined in **PROTOCOL_V1.md** (being authored in a parallel Step 0 task); this document defines the semantics only and defers wire format to that spec.
- **Bootstrap (first token, host has no token yet):** the host connects and authenticates via whatever path it is entitled to — under Flag A=off that is legacy IP `msgLogin`; under Flag A=on a token-less known host (`vps_token_hash IS NULL`, source IP matches `vps_ip`) is accepted in a **provisional** state whose only permitted next exchange is receiving `config.token`. Operator triggers issuance (admin UI/CLI on the hub); hub generates, stores the hash, pushes plaintext once over WSS. IP-match-as-bootstrap is acceptable because it is no *weaker* than the status quo and is a one-time promotion to a stronger credential.
- **Host-side persistence:** agent writes the token to a root-only file (e.g. `/etc/datacentered/agent_token`, mode `0600`) and **acks** (`config.token_ack {host_id, token_fingerprint: sha256-prefix}`). The hub marks the token *active only on ack* — see rotation (§6) for why.
- **Offline hosts:** the pending token sits in the DB (`*_token_hash` set, awaiting ack — trackable via `issued` timestamp vs ack state); push is retried on the host's next connect. Until acked, the previous credential (or legacy IP path under Flag B) still works, so a missed push never strands a host.
- The plaintext token appears only: in hub memory at generation, in one WSS frame, and in the host's `0600` file. It is never logged (`Worker::safeEcho` of auth frames must redact `token`).

## 4. Validation flow (hosts/bots)

New v1 op replacing the trust decision in `msgLogin{ima:'host'}`:

```
agent → hub : auth.hello { role: "host"|"bot", host_id: "vps1234", token: "dchost_..." }
hub         : validate (below)
hub → agent : auth.welcome { host_id, name, ... }            # success
            | auth.error   { code, message }                 # explicit rejection, then close
```

Hub validation steps, in order:

1. **Flag gate first.** If Flag A is OFF for this hub (or `auth.hello` arrives while dormant): the new handler is inert — the frame falls through to today's unknown-type handling and the legacy `msgLogin` path remains the only live auth. (See §7.)
2. Parse `host_id` → fetch the single row (`vps_masters`, `qs_masters`, or `ws_bots` by role/prefix). Unknown id → `auth.error {code:"unknown_host"}`.
3. **Constant-time token compare:** `hash_equals($row['vps_token_hash'], hash('sha256', $presented))`. Also check `*_token_prev_hash` if within its `prev_expires` grace window (rotation, §6). Mismatch → `auth.error {code:"bad_token"}`. `hash_equals` (not `==`/`===`) is mandatory to prevent timing side-channels.
4. **Source-IP defense in depth (per B5):** `$_SERVER['REMOTE_ADDR']` must equal `vps_ip`/`qs_ip` (or `bot_ip` when pinned; bots with `bot_ip NULL` skip this). Token valid but IP wrong → `auth.error {code:"ip_mismatch"}` and an operator-visible alert, since that combination smells like token theft. IP is *secondary* — an IP match without a valid token is never sufficient on this path.
5. On success: populate the same session shape legacy sets today (`uid`, `module`, `name`, `ima`, `ip`, `type`, `login`), `bindUid`, join group, CAS-update `$global->hosts` — identical downstream state so everything after auth (queue ops, channels, PTY) is agnostic to *which* auth admitted the connection. Reply `auth.welcome`.

**Error codes** (closed set, machine-readable so agents can distinguish "re-bootstrap" from "retry"). This is the authoritative set the hub emits on the v1 auth path (`handleAuthHello()` / `sendV1Error()` in `Events.php`); PROTOCOL_V1.md §2.1's `auth_failed` is only an illustrative generic example, not a concrete code — the codes actually sent are:

| code | role | meaning |
|---|---|---|
| `unknown_host` | host/bot | no such identity row for the presented `host_id` |
| `bad_token` | host/bot | token (and any in-grace prev-token) does not match |
| `ip_mismatch` | host/bot | valid token but source IP ≠ registered IP (or host row has no registered IP) — operator-visible alert |
| `no_token_issued` | host/bot | identity exists but `*_token_hash` is NULL (also the revocation state) |
| `bot_disabled` | bot | `bot_enabled = 0` |
| `unsupported_credential` | admin | legacy `username`/`password` shape presented; not a defined v1 credential |
| `bad_session` | admin | `session` missing, or not a valid admin session |
| `bad_request` | any | malformed `auth.hello` (e.g. `role` not one of host/bot/admin) |
| `auth_disabled` | any | Flag A off (should not normally be observed since agents only send `auth.hello` when their own Flag A is on) |

(`internal` — transient backend/DB failure — is also possible but is not an auth-decision code.) After any of these the hub sends the general error reply and closes the connection; no partial auth states.

**Legacy coexistence:** the legacy `msgLogin` IP-only path is **not modified by this design at all** — with Flag A off it is the only path; with Flag A on it remains available as long as Flag B (`LEGACY_COMPAT`) is ON, so a host rolled back from the new agent re-authenticates the old way without operator surgery. Only Flag B→OFF (P7.3, operator action) retires it.

**Rate limiting:** failed `auth.hello` attempts per source IP should be throttled (e.g. exponential backoff after N failures, tracked in GlobalData/Redis) — cheap insurance even though 256-bit tokens are not guessable. **Deferred: not implemented as of step 2.2** — `handleAuthHello()` rejects+closes on each bad attempt but does not yet throttle repeat offenders. Tracked as a follow-up (see PROTOCOL_V1.md §6 and the reconciliation notes below).

## 5. Admin / browser auth

```
browser → hub : auth.hello { role: "admin", session: "<mystage session_id>" }
hub → browser : auth.welcome { session, uid, name, hub_time } | auth.error { code:"bad_session" }
```

- Validation **reuses branch (1) of today's `msgLogin` admin path verbatim**: look up `sessions LEFT JOIN accounts ON session_owner = account_id WHERE account_ima='admin' AND session_id = :session` against the mystage DB (the same DB `self::$db` already points at; equivalently `App::session()` semantics on the mystage side). The mystage app continues to create/expire sessions exactly as it does now — **no new admin-side code or credential is introduced**; the browser client simply forwards the session id it already possesses (as `ws_terminal.js`/`ws_channels.js` will per B7).
- **The MD5 branch is not implemented in the v1 path.** `auth.hello{role:"admin", username, password}` is not a defined shape; `auth.error {code:"unsupported_credential"}`. The legacy `msgLogin` MD5 branch physically remains in `Events.php` under Flag B until P7 cleanup, but no new client uses it.
- **`img` is not part of the frozen v1 `auth.welcome`.** An earlier draft of the diagram above showed `auth.welcome { uid, name, img }`, but PROTOCOL_V1.md's frozen `auth.welcome` table (§2.1) lists `session`, `host_id`, `uid`, `name`, `hub_time`, `timers` — no `img`. The code follows the frozen table: the admin `auth.welcome` sends `{ session, uid, name, hub_time }` and does **not** send `img` (the account picture is still resolved into the session as `$_SESSION['img']` for other uses, but it is not returned in the welcome frame). Delivering the avatar to the browser at connect time is deferred/out of scope for the frozen v1 auth surface; the diagram above has been aligned to the frozen table. See the reconciliation notes below.
- Session revocation is free: mystage logout/expiry deletes the `sessions` row → next validation fails. For long-lived WS connections the hub SHOULD re-validate the session id periodically (e.g. on a coarse timer or per privileged op) so a logged-out admin doesn't retain a live socket indefinitely; exact cadence is an implementation-phase decision.

## 6. Rotation & revocation

Both are operator actions on the hub (admin UI/CLI), centralized per OQ1.

**Rotation (routine, zero-downtime):**
1. Operator triggers rotate for `vps1234`. Hub generates a new token; moves current `vps_token_hash` → `vps_token_prev_hash` with `vps_token_prev_expires = NOW() + grace` (suggested grace: 1 hour); writes the new hash + `issued`.
2. Hub pushes `config.token` with the new token (immediately if connected, else on next connect — where the *old* token still authenticates within grace, which is exactly why the grace window exists).
3. Host persists and acks. On ack, the hub clears `prev_hash`/`prev_expires` — old token dead immediately, not at grace expiry.
4. If the host never acks before grace expiry, `prev_hash` lapses and the host must re-bootstrap (§3) — a visible, alertable condition, not a silent lockout of the *current* token.

**Revocation (compromise / decommission, immediate):**
- Set `vps_token_hash = NULL, vps_token_prev_hash = NULL` (or `bot_enabled = 0`). Next `auth.hello` fails `no_token_issued`; the hub also actively closes any live connection bound to that uid (`Gateway::closeClient` via the bound uid) so revocation is immediate, not next-reconnect.
- Caveat while Flag B is ON: a revoked host could still fall back to legacy IP auth — inherent to the B8 dual-running contract. Full revocation strength arrives only in State 3 (Flag B off). For an actual compromise during States 1–2 the operator additionally changes/blocks `vps_ip` exactly as they would today.

**No built-in expiry** (tokens live until rotated/revoked): matches the fleet reality of thousands of long-lived hosts; scheduled rotation is an operator policy layered on the rotate primitive, not enforced by the hub.

## 7. Flag interaction (B8)

- **Everything in §§2–6 is inert while Flag A (`WS_NEW_HANDLING`) is OFF** — the default, everywhere. Deploying this to all hubs and thousands of hosts is a runtime no-op: no schema *reads* on the auth path, no new ops emitted, no behavioral delta. The schema migration itself (nullable columns / new empty table) is deploy-time and behavior-neutral.
- Every new decision point gates on the Step 0.5 helper — `FeatureFlags::useNewHandling()` (hub-side per server; agent-side per host) — **this design has a hard dependency on Step 0.5 (feature-flag infrastructure) landing first**; token issuance UI, `config.token` push, and `auth.hello` handling all check it before doing anything.
- Flag A is per-server/per-host and reversible without redeploy: toggling a host back off means its agent stops sending `auth.hello` and resumes legacy `msgLogin`/HTTP-IP auth, which Flag B (`LEGACY_COMPAT`, default ON) guarantees still works. Tokens already issued simply sit unused in the DB — re-enabling later needs no re-issuance.
- Flag B→OFF (State 3, operator-only, P7.3) is the *only* event that disables legacy IP auth and the MD5 branch at runtime; physical deletion of that code follows later (P7) and is outside this design.

## 8. Consistency check

This design matches **B5 as written**: hosts/bots get per-identity bearer tokens generated by the hub, stored in `vps_masters` (plus the `qs_masters` mirror and a new `ws_bots` registry), and distributed by hub config push over the existing channel with centralized rotation (OQ1); agents send `auth.hello{token}` and the hub validates token (constant-time) **plus** source IP as defense in depth; admins authenticate with `auth.hello{role:"admin", session}` against the existing mystage session, and the MD5 password path is dropped from the new surface. It matches **B8**: it ships dormant behind Flag A (default OFF — deploy is a runtime no-op fleet-wide), it is reversible per host without redeploy, and the legacy IP-only and MD5 paths are left byte-for-byte untouched and remain the active default under Flag B until the operator — never the implementation — flips B off in P7. No step herein modifies `Web/queue.php`, the queue/HyperV task paths, or existing `msgLogin` behavior, so the ⛔ queue & HyperV invariant is unaffected.

---

## 9. Reconciliation notes (step 2.2)

PROTOCOL_V1.md is the FROZEN contract and the code (`Applications/Chat/Events.php::handleAuthHello()`) follows it. Where this design's prose diverged from the frozen spec / the shipped code, this document was aligned to them (the frozen §1/§2/§3 field tables in PROTOCOL_V1.md were **not** touched; only its §6 implementation-status area gained clarifying notes). Changes made in step 2.2:

1. **Error-code set (this doc §4 vs PROTOCOL_V1.md §2.1).** PROTOCOL_V1.md §2.1 mentions `auth_failed` as an illustrative *generic* reply code, not a concrete auth-failure code. The authoritative machine-readable set is the closed set in §4 of this document. §4 was expanded into a table and made consistent with what `handleAuthHello()`/`sendV1Error()` actually emit: it now also lists `bad_session`, `bad_request`, and `unsupported_credential` (previously only described in §5 prose) alongside `unknown_host`, `bad_token`, `ip_mismatch`, `no_token_issued`, `bot_disabled`, `auth_disabled`. A one-line clarification was added to PROTOCOL_V1.md §6 (status area, not the frozen §2.1 table) noting that the concrete codes are this §4 closed set and that §2.1's `auth_failed` was only an example.

2. **`img` in admin `auth.welcome` (this doc §5 vs the frozen `auth.welcome` table).** The §5 diagram previously showed `auth.welcome { uid, name, img }`. PROTOCOL_V1.md's frozen `auth.welcome` table does not list `img`, and the code does not send it (it sends `{ session, uid, name, hub_time }`; `img` is only stored in the session). The §5 diagram was aligned to the frozen table and an explicit note added that delivering the avatar in the welcome frame is deferred/out of scope for frozen v1. No change to the frozen PROTOCOL_V1.md table.

3. **Deferred follow-up — auth rate limiting (§4).** Per-source-IP throttling of failed `auth.hello` attempts is called for in §4 but is **not implemented as of step 2.2**. §4 was reworded from "are throttled" to "should be throttled … deferred", and a matching deferred note was recorded in PROTOCOL_V1.md §6.

---

## 10. HTTP trigger endpoint token — `WS_TRIGGER_TOKEN` (step 2.9)

Step 2.9 added the hub-side HTTP nudge endpoint `Web/trigger_payment.php`
(`POST /trigger_payment.php`; see PROTOCOL_V1.md §6, step 2.9). It authenticates
with a **shared-secret token**, distinct from the per-identity host/bot bearer
tokens specified in §§2–6 above: those are per-identity, hub-minted, DB-stored,
and pushed over WSS; this is a single static secret guarding one hub-local HTTP
control endpoint. The two share only the **constant-time compare** discipline of
§4 item 3 — the endpoint compares the presented POST `token` field against the
configured secret with `hash_equals()`, never `==`/`===`.

### Required manual operator step (security-critical)

`WS_TRIGGER_TOKEN` **must be defined manually** as a high-entropy constant in the
out-of-repo `/home/my/include/config/config.settings.php` before this endpoint
can ever succeed. This follows the same **manually-applied, no code-based
auto-provisioning** convention this program already uses for other operator
steps — e.g. the `migrations/*.sql` files, which carry no migration runner and
"MUST be applied MANUALLY by an operator/deploy process" (`migrations/2026_07_phase2_token_auth.sql`
header). The application never generates, writes, or backfills this constant;
there is deliberately no auto-provisioning fallback.

Recommendation: generate it the same way §2 mints bearer tokens — e.g.
`bin2hex(random_bytes(32))` (256 bits, CSPRNG) — and treat it as a secret (never
commit it, never log it).

### Fails closed if unconfigured/empty — intentional, not a bug

If `WS_TRIGGER_TOKEN` is **undefined or empty**, the endpoint **rejects every
request** (`{"status":"error","error":"unauthorized"}`) — it is never open. This
is a deliberate fail-closed property: an un-provisioned hub exposes the file but
grants nothing. Do **not** "fix" this by relaxing the empty-token guard or by
having the code fabricate a default token; a missing/empty secret MUST mean "deny
all", exactly as shipped. The presented token is also read only from `$_POST`,
which makes this a POST-only endpoint (a bare GET presents no POST `token` field
and therefore always fails auth).

The endpoint is additionally gated behind **Flag A** (`FeatureFlags::useNewHandling()`):
even with a correct token, it stays a runtime no-op (`{"status":"error","error":"disabled"}`)
until an operator turns Flag A on — so provisioning the token is necessary but
not sufficient to make it live, consistent with the B8 ship-dormant contract used
everywhere else in this program.
