# dc.html Multi-User WebSocket Integration Plan

---
status: not-started
phase: 1
updated: 2026-07-26
orchestrator: trust-but-verify
---

## Overview

Integrate `dc.html` (3D datacenter at `/home/sites/mystage/public_html/admin/dc.html`) with the datacentered WebSocket server (`wss://mystage.interserver.net:7272`) so admins auto-authenticate via their mystage PHP session and see each other's avatars and nameplates in the 3D scene in real-time.

## Orchestrator Loop Pattern

Each step below follows this loop:

```
CODER AGENT → REVIEWER AGENT
                  ↓ (if problems)
              CODER FIX AGENT → REVIEWER AGENT (repeat until clean)
                  ↓ (if clean)
              TEST AGENT → (fix loop if needed)
                  ↓
              DOCUMENTATION AGENT
                  ↓
              NEXT STEP
```

The orchestrator (this plan) does **no file/git work itself**. It spawns subagents to do everything.

---

## Step Dependencies

```
Step 1 ─┬─► Step 2 ──► Step 3 ──► Step 4 ──► Step 5 (manual)
        │                                          │
        └──────────────────────────────────────────┘
                                                    │
Step 6 (tests) ◄───────────────────────────────────┘
      │
      ▼
Step 7 ─┬─► Step 8 ─┬─► Step 9 ─┬─► Step 10 ─┬─► Step 11 ──► Step 12 ──► Step 13
                   │           │             │                            │
                   └───────────┴─────────────┘                            │
                                                                        │
                                                                    Step 14 ──► Step 15 ──► Step 16 (manual)
                                                                                      │
                                                                    Step 17 (docs) ◄─┘
```

---

## Phase 1: Authentication Integration

---

### STEP 1: Expose PHP session variables to Smarty template

**Who**: `mystage` project
**File to modify**: Template controller / Smarty assign for `dc.html` — likely in `/home/sites/mystage/include/` or the admin controller that renders `dc.html`

**What to do**:

The admin template that renders `dc.html` must expose three variables to Smarty:
- `$session_id` — the 32-char MD5 session ID
- `$account_lid` — the admin's login/username
- `$account_id` — the admin's numeric account ID

In `route.php` line 348 we can already see these are assigned to Smarty:
```php
'session_id' => \MyAdmin\App::session()->sessionid,
'user_name'  => \MyAdmin\App::accounts()->data['account_lid'],
```

The issue is `dc.html` is a raw `.html` file served directly, NOT via Smarty. So we need to either:
- (A) Convert `dc.html` to a Smarty template (`.tpl`) and assign these vars, OR
- (B) Serve them via a tiny PHP wrapper that sets `window.DC_*` inline script

**Recommended**: Option B — create a `dc.php` wrapper in `/home/sites/mystage/public_html/admin/dc.php`:

```php
<?php
require_once __DIR__.'/index.php';  // loads session/auth context

$session_id  = \MyAdmin\App::session()->sessionid;
$account_lid = \MyAdmin\App::accounts()->data['account_lid'] ?? '';
$account_id  = \MyAdmin\App::accounts()->data['account_id'] ?? 0;

echo '<!DOCTYPE html><html lang="en"><head>...
<script>
  window.DC_SESSION_ID = "'.htmlspecialchars($session_id, ENT_QUOTES, 'UTF-8').'";
  window.DC_USER_NAME  = "'.htmlspecialchars($account_lid, ENT_QUOTES, 'UTF-8').'";
  window.DC_ACCOUNT_ID = '.(int)$account_id.';
</script>
...rest of original dc.html inline...</html>';
```

**Acceptance criteria**:
- `dc.php` exists at `/home/sites/mystage/public_html/admin/dc.php`
- Requesting it from an authenticated admin session returns HTML with correct `window.DC_SESSION_ID`, `window.DC_USER_NAME`, `window.DC_ACCOUNT_ID`
- Requesting without auth returns appropriate redirect or empty values
- Existing `dc.html` remains unmodified (or becomes a fallback)

**Tests**:
- Manual: request `dc.php` in browser as authenticated admin → check `window.DC_SESSION_ID` in DevTools

---

### STEP 2: Add inline session script to `dc.html` (backup / fallback path)

**Who**: `mystage` project
**File to modify**: `/home/sites/mystage/public_html/admin/dc.html`

**What to do**:
Add the inline script block right before `</body>` in `dc.html` as a fallback. This ensures `dc.html` still works if served directly:

```html
<script>
  window.DC_SESSION_ID = window.DC_SESSION_ID || "";
  window.DC_USER_NAME  = window.DC_USER_NAME  || "";
  window.DC_ACCOUNT_ID = window.DC_ACCOUNT_ID || 0;
</script>
```

**Acceptance criteria**:
- `dc.html` has the inline script before `</body>`
- Does not break existing dc.html behavior when opened without auth
- No JS errors in console on load

**Tests**: None (manual browser check)

---

### STEP 3: Create `dc-ws.js` — WebSocket client module

**Who**: `mystage` project
**File to create**: `/home/sites/mystage/public_html/js/dc-ws.js`

**What to do**:
Implement the full `dc-ws.js` module as documented in the main plan body. Key features:
- Connect to `wss://mystage.interserver.net:7272`
- On open: send v1 `auth.hello { role: "admin", session: DC_SESSION_ID }`
- On auth success: set `window.DC_AUTHED = true`, `window.DC_UID = data.uid`, `window.DC_PRESENCE = {...}`
- Expose `window.DC_PRESENCE_SEND(op, data)` — sends v1 envelope without waiting for reply
- Auto-reconnect with exponential backoff (3s delay, max 10 attempts)
- Dispatch custom DOM events: `dc:auth-success`, `dc:auth-failure`, `dc:connected`, `dc:disconnected`
- Dispatch `dc:v1-event` for any `dc.*`, `channel.*`, `chat.*` incoming ops

**Acceptance criteria**:
- File exists at `/home/sites/mystage/public_html/js/dc-ws.js`
- Code passes `eslint` if config exists, or basic JS syntax check
- Exports `window.DC_WS`, `window.DC_AUTHED`, `window.DC_UID`, `window.DC_PRESENCE`, `window.DC_PRESENCE_SEND`
- Module is a proper IIFE with no global leakage except the `DC_*` window exports

**Tests**:
- JS syntax check: `node --check /home/sites/mystage/public_html/js/dc-ws.js` (if Node available)
- Manual: load in browser, confirm `window.DC_AUTHED` eventually becomes `true` when session is valid

---

### STEP 4: Add `dc-ws.js` script tag to `dc.html`

**Who**: `mystage` project
**File to modify**: `/home/sites/mystage/public_html/admin/dc.html`

**What to do**:
Add module script include for `dc-ws.js` before `dc.js` and `dc-multi.js`:

```html
<script type="module" src="/js/dc-ws.js?v=p1"></script>
<script type="module" src="/js/dc.js?v=p45"></script>
<script type="module" src="/js/dc-multi.js?v=p24"></script>
```

Also update the inline session script (Step 2) to be a proper ES module that reads `window.DC_SESSION_ID` and initializes the DC_PRESENCE state — or keep it simple and have `dc-ws.js` read from `window.DC_SESSION_ID` directly.

**Acceptance criteria**:
- `dc.html` loads `dc-ws.js` as ES module before other DC scripts
- Version `?v=p1` cache-bust string is used (or similar)

**Tests**: Manual browser load — check Network tab confirms dc-ws.js loads before dc.js

---

### STEP 5: Manual auth verification

**Who**: orchestrator/manual
**Files**: All Phase 1 files

**What to do**:
Manual browser test:
1. Open `dc.html` (or `dc.php`) in Chrome as an authenticated admin
2. Open DevTools → Console
3. After page load, check:
   - `window.DC_SESSION_ID` is a 32-char hex string
   - `window.DC_AUTHED === true`
   - `window.DC_UID` is a numeric string (account_id)
   - `window.DC_USER_NAME` matches the admin username
4. In Network tab, confirm a WebSocket connection was made to port 7272 and an `auth.hello` frame was sent with the correct `session` field
5. Confirm no JS errors in console

**If fails**: Log findings and return to Step 1 or Step 3 as appropriate.

---

## Phase 2: Server-Side Presence Handlers

---

### STEP 6: Create `tests/EventsV1DcPresenceTest.php`

**Who**: `datacentered` project
**File to create**: `/home/sites/datacentered/tests/EventsV1DcPresenceTest.php`

**What to do**:
Write the complete PHPUnit test class as documented in the main plan body. Follow the exact same pattern as `EventsV1AuthHelloTest.php`:

- `FakeChannelClient` class alias before `Events.php` loads (captures `Channel::publish` calls)
- `AuthFakeGlobalDataClient` (reuse from V1TestSupport)
- `FakeAuthDb` (if DB reads needed — join/move/leave are GlobalData-only so may not need it)
- Test methods:
  - `testJoinAddsMemberToGlobalData`
  - `testJoinBroadcastsToChannel`
  - `testJoinRepliesSuccessToClient`
  - `testMoveUpdatesMemberPosition`
  - `testMoveBroadcastsUpdatedPosition`
  - `testMoveSilentlyIgnoresUnjoinedMember`
  - `testLeaveRemovesMember`
  - `testLeaveBroadcastsRemoval`
  - `testLeaveRepliesSuccessToClient`
  - `testUnauthenticatedClientCannotSendPresenceOps` (or `testPresenceOpsRequireAuth`)
  - `testPresenceOpsAreDormantWhenFlagAIsOff`
- Uses the existing `V1TestSupport.php` fake Gateway seam

**Acceptance criteria**:
- File exists at `tests/EventsV1DcPresenceTest.php`
- `php vendor/bin/phpunit tests/EventsV1DcPresenceTest.php` runs but shows FAIL (tests fail because handlers don't exist yet) — this is expected!
- All 11+ test methods are found and run

**Tests**: Run `php vendor/bin/phpunit tests/EventsV1DcPresenceTest.php` — confirm tests are found, confirm they FAIL (because handlers not implemented yet)

---

### STEP 7: Add `handleDcPresenceJoin()` to `Events.php`

**Who**: `datacentered` project
**File to modify**: `/home/sites/datacentered/Applications/Chat/Events.php`

**What to do**:
Add the `handleDcPresenceJoin()` method to the `Events` class. The full implementation is in the plan body above (lines ~321-374).

Key behaviors:
1. Check `$_SESSION['uid']` — reject with `forbidden` if not authed
2. Validate `x`, `z`, `yaw` from `$envelope['data']` (numeric, default 0)
3. Store entry in `$global->dc_presence[$uid]`
4. Send success reply to sender (`Gateway::sendToClient`)
5. Broadcast `dc.presence.joined` via `Channel::publish('dc_presence', ...)`
6. Log with `Worker::safeEcho`
7. Add `case 'dc.presence.join':` to the `dispatchV1()` switch inside the Flag A block

Also add `Channel::joinGroup($client_id, 'dc_presence')` in `handleAuthHello()` after the `admins` group join (around line 694).

**Acceptance criteria**:
- `handleDcPresenceJoin()` method exists in Events class
- `dispatchV1()` switch has `case 'dc.presence.join':` that calls it
- `handleAuthHello()` calls `Channel::joinGroup($client_id, 'dc_presence')` after successful auth
- Code style passes: `php vendor/bin/php-cs-fixer fix --dry-run`
- Tests in `EventsV1DcPresenceTest.php` for join now PASS

**Tests**: Run `php vendor/bin/phpunit tests/EventsV1DcPresenceTest.php` — confirm join tests PASS

---

### STEP 8: Add `handleDcPresenceMove()` to `Events.php`

**Who**: `datacentered` project
**File to modify**: `/home/sites/datacentered/Applications/Chat/Events.php`

**What to do**:
Add `handleDcPresenceMove()` method. Full implementation in plan body (lines ~389-435).

Key behaviors:
1. Fire-and-forget — NO reply to sender (reduces server→client traffic)
2. Check `$global->dc_presence[$uid]` exists — if not, silently return
3. Validate and apply `x`, `z`, `yaw` updates to the GlobalData entry
4. Broadcast `dc.presence.updated` via `Channel::publish('dc_presence', ...)`

Add `case 'dc.presence.move':` to `dispatchV1()` switch.

**Acceptance criteria**:
- `handleDcPresenceMove()` method exists
- `dispatchV1()` switch has `case 'dc.presence.move':`
- No reply sent to the sender (move is one-way)
- `testMoveUpdatesMemberPosition` and `testMoveBroadcastsUpdatedPosition` PASS
- `testMoveSilentlyIgnoresUnjoinedMember` PASS

**Tests**: Run `php vendor/bin/phpunit tests/EventsV1DcPresenceTest.php` — confirm move tests PASS

---

### STEP 9: Add `handleDcPresenceLeave()` to `Events.php`

**Who**: `datacentered` project
**File to modify**: `/home/sites/datacentered/Applications/Chat/Events.php`

**What to do**:
Add `handleDcPresenceLeave()` method. Full implementation in plan body (lines ~450-493).

Key behaviors:
1. Remove `$global->dc_presence[$uid]`
2. Call `Channel::leaveGroup($client_id, 'dc_presence')` to stop receiving broadcasts
3. Send success reply to sender
4. Broadcast `dc.presence.left` via `Channel::publish('dc_presence', ...)`

Add `case 'dc.presence.leave':` to `dispatchV1()` switch.

**Acceptance criteria**:
- `handleDcPresenceLeave()` method exists
- `dispatchV1()` switch has `case 'dc.presence.leave':`
- `testLeaveRemovesMember`, `testLeaveBroadcastsRemoval`, `testLeaveRepliesSuccessToClient` PASS

**Tests**: Run `php vendor/bin/phpunit tests/EventsV1DcPresenceTest.php` — confirm leave tests PASS

---

### STEP 10: Register all `dc.presence.*` ops in `OP_HANDLERS`

**Who**: `datacentered` project
**File to modify**: `/home/sites/datacentered/Applications/Chat/Events.php`

**What to do**:
Add the three `case` entries to the `dispatchV1()` switch (done in Steps 7-9, but verify all three are present):

```php
case 'dc.presence.join':
    self::handleDcPresenceJoin($client_id, $envelope);
    return;
case 'dc.presence.move':
    self::handleDcPresenceMove($client_id, $envelope);
    return;
case 'dc.presence.leave':
    self::handleDcPresenceLeave($client_id, $envelope);
    return;
```

Also verify `Channel::joinGroup($client_id, 'dc_presence')` was added to `handleAuthHello()`.

**Acceptance criteria**:
- All three `dc.presence.*` cases exist in `dispatchV1()` switch
- `testPresenceOpsAreDormantWhenFlagAIsOff` PASSES (proves Flag A gating works)
- `testUnauthenticatedClientCannotSendPresenceOps` PASSES

**Tests**: Run `php vendor/bin/phpunit tests/EventsV1DcPresenceTest.php` — confirm ALL tests PASS

---

### STEP 11: Run all datacentered tests + CS fixer

**Who**: `datacentered` project
**Files**: All modified datacentered files

**What to do**:
1. Run code style: `php vendor/bin/php-cs-fixer fix --dry-run` — fix any issues
2. Run all PHPUnit tests: `php vendor/bin/phpunit`
3. Confirm no regressions in existing tests (especially `EventsV1AuthHelloTest`)

**Acceptance criteria**:
- CS fixer: no changes needed or all changes applied cleanly
- All existing tests still pass
- All new `EventsV1DcPresenceTest` tests pass

**Tests**: Full `php vendor/bin/phpunit` run

---

## Phase 3: Client-Side Presence Module

---

### STEP 12: Create `dc-presence.js` — avatar rendering + presence tracking

**Who**: `mystage` project
**File to create**: `/home/sites/mystage/public_html/js/dc-presence.js`

**What to do**:
Implement the full `dc-presence.js` module as documented in the plan body (lines ~547-817).

Key features:
- Waits for `window.DC` (Three.js scene) to be ready
- `_buildAvatar(uid, name, x, z, yaw)` — creates `THREE.Group` with:
  - Cylinder body (0.12r, 0.28h, 8 segments, blue)
  - Sphere head (0.10r, white)
  - `THREE.Sprite` name label above head (canvas texture, 256×48, pill-shaped bg, white text)
  - Name sprite visible ONLY if `uid !== window.DC_PRESENCE.uid` (can't see your own nameplate)
- `_onPresenceJoined(data)` — creates avatar mesh + stores in `remoteAvatars` Map
- `_onPresenceUpdated(data)` — lerps existing mesh to new position
- `_onPresenceLeft(data)` — removes mesh from scene + deletes from Map
- `DC_PRESENCE_MOVE(x, z, yaw)` — throttled to 250ms, calls `DC_PRESENCE_SEND`
- `DC_PRESENCE_JOIN(x, z, yaw)` — calls `DC_PRESENCE_SEND('dc.presence.join', ...)`
- `DC_PRESENCE_LEAVE()` — calls `DC_PRESENCE_SEND('dc.presence.leave', ...)`
- Listens to `dc:auth-success`, `dc:auth-failure`, `dc:disconnected`, `dc:v1-event`
- On `dc:v1-event` with op `dc.presence.joined/updated/left`, dispatches to appropriate handler

**Acceptance criteria**:
- File exists at `/home/sites/mystage/public_html/js/dc-presence.js`
- JS syntax valid: `node --check /home/sites/mystage/public_html/js/dc-presence.js`
- Exports: `DC_PRESENCE_JOIN`, `DC_PRESENCE_MOVE`, `DC_PRESENCE_LEAVE`
- No global namespace pollution beyond `window.DC_PRESENCE_*`

**Tests**:
- JS syntax check
- Manual browser test (Step 16)

---

### STEP 13: Integrate `DC_PRESENCE_*` calls into `dc-multi.js`

**Who**: `mystage` project
**File to modify**: `/home/sites/mystage/public_html/js/dc-multi.js`

**What to do**:
Add presence calls to the `dc-multi.js` animation loop and scene entry/exit points:

**1. Scene entry** (in `trySpawn()`, around line 400-412):
```javascript
// After player spawn is set up:
if (typeof window.DC_PRESENCE_JOIN === 'function') {
  window.DC_PRESENCE_JOIN(S.player.position.x, S.player.position.z, S.facing);
}
```

**2. Animation loop** (in `frame()`, around line 176):
```javascript
// Throttled presence update — only fire when position changed significantly.
if (typeof window.DC_PRESENCE_MOVE === 'function') {
  window.DC_PRESENCE_MOVE(S.player.position.x, S.player.position.z, S.facing);
}
```

**3. On scene leave / pointer lock exit** (in `syncPlayerFromCamera()` or add new hook):
```javascript
if (typeof window.DC_PRESENCE_LEAVE === 'function') {
  window.DC_PRESENCE_LEAVE();
}
```

Also ensure `dc-presence.js` is loaded AFTER `dc-multi.js` in the HTML (Step 14).

**Acceptance criteria**:
- `dc-multi.js` calls `DC_PRESENCE_JOIN` once on spawn
- `dc-multi.js` calls `DC_PRESENCE_MOVE` on each frame (throttling happens inside `DC_PRESENCE_MOVE`)
- `dc-multi.js` calls `DC_PRESENCE_LEAVE` on pointer lock exit
- No JS errors in console when loading dc.html with presence module

**Tests**: Manual browser load — check console for no errors on scene entry

---

### STEP 14: Add `dc-presence.js` script tag to `dc.html`

**Who**: `mystage` project
**File to modify**: `/home/sites/mystage/public_html/admin/dc.html`

**What to do**:
Update the script loading order:

```html
<script type="module" src="/js/dc-ws.js?v=p1"></script>
<script type="module" src="/js/dc.js?v=p45"></script>
<script type="module" src="/js/dc-multi.js?v=p24"></script>
<script type="module" src="/js/dc-presence.js?v=p1"></script>
```

**Acceptance criteria**:
- `dc.html` loads `dc-presence.js` as ES module after all other DC scripts
- Version string `?v=p1` is used for cache busting

**Tests**: Manual — verify all 4 modules load in correct order in Network tab

---

### STEP 15: Run CS fixer on all mystage modified files

**Who**: `mystage` project
**Files**: All modified mystage files (`dc.html`, any new files)

**What to do**:
If mystage has a CS fixer config, run it. If not, do basic lint checks:
- JS files: `node --check` on each `.js` file
- PHP files (if any): syntax check `php -l`
- No changes to `dc.html` that break existing functionality

**Acceptance criteria**:
- All modified files pass basic syntax checks
- No unintended whitespace or formatting changes

**Tests**: Basic syntax checks pass

---

### STEP 16: Manual browser multi-user test

**Who**: orchestrator/manual
**Files**: All Phase 1 + Phase 2 + Phase 3 files

**What to do**:
Full integration test in two browser windows (Chrome + Firefox, or regular + incognito):

1. **Window A**: Open `dc.html` as admin `alice` — confirm:
   - `window.DC_AUTHED === true` in console
   - `window.DC_UID` is set
   - An avatar for `alice` does NOT show her own nameplate

2. **Window B**: Open `dc.html` as admin `bob` — confirm:
   - `window.DC_AUTHED === true`
   - In the 3D scene, `bob` sees `alice`'s avatar with a visible nameplate above it
   - `alice` does NOT see her own nameplate but `bob` sees "alice" above her avatar

3. **Movement test**: Move `bob`'s character around the datacenter
   - Confirm `alice`'s scene shows `bob`'s avatar moving in near real-time (~250ms latency acceptable)
   - Confirm position updates are smooth (interpolated by `dc-presence.js`)

4. **Leave test**: Close Window B (bob's tab)
   - Confirm `alice`'s scene removes `bob`'s avatar within ~1-2 seconds

5. **Disconnect test**: While `alice` is connected, disconnect network or close WebSocket
   - Confirm `alice`'s client attempts reconnect
   - Confirm `alice`'s avatar doesn't get stuck in the scene for other users

**If any test fails**: Log findings precisely — which step failed, what was expected vs what happened — and assign to appropriate fix agent.

**Acceptance criteria**:
- All 3 manual test scenarios pass with zero console errors
- Other window's avatar is visible with nameplate
- Own nameplate is NOT visible to self
- Avatar disappears within 2s of tab close

---

## Phase 4: Documentation

---

### STEP 17: Update `PROTOCOL_V1.md` with new v1 ops

**Who**: `datacentered` project
**File to modify**: `/home/sites/datacentered/docs/PROTOCOL_V1.md`

**What to do**:
Document the new `dc.presence.*` v1 operations in the protocol spec:

Add to the v1 ops catalog (section §2 or wherever ops are listed):

| Op | Direction | Description | Auth required |
|---|---|---|---|
| `dc.presence.join` | client→server | Enter 3D scene at (x, z, yaw) | Yes (admin/host/bot) |
| `dc.presence.move` | client→server | Position/rotation update | Yes |
| `dc.presence.leave` | client→server | Exit 3D scene | Yes |
| `dc.presence.joined` | server→client | New member entered (broadcast) | — |
| `dc.presence.updated` | server→client | Member moved (broadcast) | — |
| `dc.presence.left` | server→client | Member departed (broadcast) | — |

For each op, document:
- Envelope structure (v1 format with `op`, `data` fields)
- `dc.presence.join/leave`: server replies with `{ok: true/false, re: <id>}`
- `dc.presence.move`: no reply (fire-and-forget)
- Broadcast via `Channel:dc_presence` group
- Required fields in `data`

Also document:
- `Channel:joinGroup('dc_presence')` happens automatically on successful `auth.hello`
- Presence data stored in `GlobalData dc_presence` hash
- Stale presence: no automatic cleanup on disconnect (documented limitation)

**Acceptance criteria**:
- `docs/PROTOCOL_V1.md` updated with the new ops table
- All 6 new ops are documented with correct direction and structure
- Stale presence limitation is noted
- No existing documentation is broken or removed

**Tests**: Read the updated doc section and verify all 6 ops are listed with correct structure

---

## Test Summary

| Step | Test Type | Command |
|------|-----------|---------|
| 6 | PHPUnit (will fail until handlers exist) | `php vendor/bin/phpunit tests/EventsV1DcPresenceTest.php` |
| 7-10 | PHPUnit (each handler added) | `php vendor/bin/phpunit tests/EventsV1DcPresenceTest.php` |
| 11 | Full PHPUnit + CS fixer | `php vendor/bin/phpunit` + `php vendor/bin/php-cs-fixer fix --dry-run` |
| 12 | JS syntax check | `node --check /home/sites/mystage/public_html/js/dc-ws.js` |
| 13 | JS syntax check | `node --check /home/sites/mystage/public_html/js/dc-presence.js` |
| 16 | Manual browser | Two-window integration test |

---

## File Inventory

| Step | File | Project | Action |
|------|------|---------|--------|
| 1 | `/home/sites/mystage/public_html/admin/dc.php` | mystage | **NEW** |
| 1 | Smarty template controller for dc.html | mystage | MODIFY |
| 2 | `/home/sites/mystage/public_html/admin/dc.html` | mystage | MODIFY |
| 3 | `/home/sites/mystage/public_html/js/dc-ws.js` | mystage | **NEW** |
| 4 | `/home/sites/mystage/public_html/admin/dc.html` | mystage | MODIFY |
| 6 | `/home/sites/datacentered/tests/EventsV1DcPresenceTest.php` | datacentered | **NEW** |
| 7 | `/home/sites/datacentered/Applications/Chat/Events.php` | datacentered | MODIFY |
| 8 | `/home/sites/datacentered/Applications/Chat/Events.php` | datacentered | MODIFY |
| 9 | `/home/sites/datacentered/Applications/Chat/Events.php` | datacentered | MODIFY |
| 10 | `/home/sites/datacentered/Applications/Chat/Events.php` | datacentered | MODIFY |
| 12 | `/home/sites/mystage/public_html/js/dc-presence.js` | mystage | **NEW** |
| 13 | `/home/sites/mystage/public_html/js/dc-multi.js` | mystage | MODIFY |
| 14 | `/home/sites/mystage/public_html/admin/dc.html` | mystage | MODIFY |
| 17 | `/home/sites/datacentered/docs/PROTOCOL_V1.md` | datacentered | MODIFY |

---

## Key References

| File | Lines | Purpose |
|------|-------|---------|
| `Applications/Chat/Events.php` | `handleAuthHello()` 620–720 | Existing admin session auth — add `Channel::joinGroup` here |
| `Applications/Chat/Events.php` | `dispatchV1()` 388–548 | Add `dc.presence.*` cases here |
| `docs/PROTOCOL_V1.md` | §2.1, §3 | Auth spec — add `dc.presence.*` ops here |
| `tests/V1TestSupport.php` | full | Fake Gateway seam pattern for tests |
| `tests/EventsV1AuthHelloTest.php` | full | Pattern to follow for `EventsV1DcPresenceTest.php` |
| `/home/sites/mystage/public_html/admin/dc.html` | full | 3D datacenter HTML shell |
| `/home/sites/mystage/public_html/js/dc-multi.js` | `frame()` ~176, `trySpawn()` ~400 | Add `DC_PRESENCE_MOVE`/`_JOIN`/`_LEAVE` calls here |
| `mystage/public_html/route.php` | 41, 348 | Shows existing `$session_id` and `$user_name` Smarty assigns |

---

## Open Questions / Future Work (Not in Scope)

- **Stale presence cleanup**: `onClose` hook to broadcast `dc.presence.left` on unexpected disconnect — no entry in this plan
- **Phase 3 (Live Metrics)**: `Tasks/dc_metrics.php`, `dc.metrics.*` v1 ops, rack heatmap overlays — completely deferred
- **Avatar model reuse**: Eventually use Mixamo characters from `dc-multi.js` manifest instead of simple cylinder+spheres
- **mystage test suite**: mystage has no PHPUnit setup — no server-side JS tests possible without adding one
