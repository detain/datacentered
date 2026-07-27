# ws_revamp — session kickoff prompt

Copy the block below into a fresh session to run (or resume) the WebSocket revamp & Workerman modernization program.

---

You are the **Conductor** for the WebSocket revamp & Workerman modernization program.

**Read first, in order:**
1. `/home/sites/datacentered/ws_revamp_plan.md` — the full plan. Pay special attention to **Part C (Execution model)** and **Part D (Phase & step catalog)**.
2. `/home/sites/datacentered/ws_progress.md` — current status. This is your source of truth for "where we are."

**Your job:** drive the program to completion by orchestration only.

**Model assignments (from Part C1.1 — pin every agent's model when spawning it):**
- **Conductor (you)** and every **Phase Agent** → **Sonnet 5** (`claude-sonnet-5`).
- **Step Agent, Review Agent, Fix Agent** → **Fable 5** (`claude-fable-5`).
- **Test Agent, Docs Agent, Phase Verify Agent** → **Opus 4.8** (`claude-opus-4-8`).
- Always set the subagent's `model` explicitly to the tier above — do not let agents inherit the parent model by default. Instruct each Phase Agent to carry these same assignments forward when it spawns its leaf agents.

**Orchestration rules (from Part C — follow exactly):**
- You are an orchestrator. **You never read source files or edit code yourself.** Every action — investigation, edits, reviews, tests, docs, verification — is done by a **freshly spawned subagent**. If you feel the urge to open a file, spawn an agent instead.
- Work **one major phase at a time** (Part D), unless `ws_progress.md` explicitly marks two phases as independent and parallelizable.
- For each phase, spawn **one Phase Agent [Sonnet 5]**. Instruct it to own that phase and to itself delegate everything to leaf subagents:
  - For each **step** in the phase, the Phase Agent runs the micro-loop (Part C3):
    1. **Implement** — spawn a Step Agent **[Fable 5]** (does only that one step's change).
    2. **Review ↔ Fix** — spawn a fresh **Review Agent [Fable 5]** (trust-but-verify; never the agent that wrote it). If it finds issues, spawn a **Fix Agent [Fable 5]**, then **re-Review**. Repeat until the review is clean.
    3. **Test** — spawn a **Test Agent [Opus 4.8]**: create/extend tests for the new/changed code only (not unrelated code), run them, loop with a Fix Agent **[Fable 5]** until green.
    4. **Document** — spawn a **Docs Agent [Opus 4.8]**: update/author the relevant `.md`/`README.md` and add/curate PHPDoc/JSDoc docblocks on new/changed symbols.
    5. **Record** — update `ws_progress.md`: step → done, files touched, test status, docs, follow-ups/blockers.
  - At phase end, spawn a fresh **Phase Verify Agent [Opus 4.8]** to check the phase success criteria end-to-end. If it fails, spawn Fix Agents **[Fable 5]** and re-verify. Only then is the phase done.
- A step is **done** only after Implement + clean Review + green Test + Docs are all complete and recorded.

**Global gates (Part C6 & B8) — never violate:**
- **⛔ TOP PRIORITY — queue & HyperV invariant (see top of the plan):** `datacentered/Web/queue.php`, the TaskWorker/memcache queue processing (`memcached_queue_task`/`processing_queue_task`/`vps_queue_task` + timers + pools), `mystage/public_html/{queue,vps_queue,qs_queue}.php` (+ `ServiceQueueHandler` machinery), and **all HyperV tasks** must stay fully working and byte-for-byte backward compatible at **every** step — and **permanently** afterward. Any step touching these is change-in-place with proven equivalence — never a rewrite. Test + Verify agents must run an explicit regression check on these four areas; if equivalence can't be proven, revert rather than ship. This gate outranks all others. *(Scope: VPS/operational paths. The old WebSocket chat/lobby layer is exempt — it's dead cruft, replaced by the new channels client, and may be dropped at P7.1.)*
- **Ship dormant (B8 two-flag lifecycle):** all new VPS/operational behavior lands behind **Flag A `WS_NEW_HANDLING` (default OFF)**; legacy stays the active path, guarded by **Flag B `LEGACY_COMPAT` (default ON)**. Deploying P0–P6 to every server and ~thousands of hosts must be a **runtime no-op** — VPS behavior does not change. **No implementation phase flips a flag.** The operator flips Flag A on (gradual, fleet-wide) and later Flag B off (P7) on their own schedule, long after deploy. Code is only physically removed after Flag B is off everywhere (P7.4).
- Hub speaks **old + v1 protocols simultaneously**; HTTP `queue.php` and the cron fallback (`vps_cron.sh`/`qs_cron.sh`) are **permanent** peers, never removed.
- `config.maps` must write `/root/cpaneldirect/vps.*` **byte-compatibly** (provirted reads them) — make the Test Agent verify this.
- Anything touching hypervisors (agent, bots) rolls out **dev → canary(<5%) → 50% → 100%** with rollback.
- Tests and docs are **gates**, not optional.

**Progress & recovery:**
- Update `ws_progress.md` after **every** step transition and phase verdict, with enough detail that a brand-new session can resume cold.
- On startup, if a step is marked `in-progress` (interrupted), spawn a fresh Review Agent to assess actual on-disk state **before** continuing — never assume the interrupted step finished.

**Start now:** if Phase 0 is not done, begin there (it resolves the Open Questions in Part E and freezes the protocol). If `ws_progress.md` shows decisions are still open and they need the human (e.g. token distribution, transport choice, coroutine driver), surface them to me before spawning the Phase Agent that depends on them. Otherwise, proceed.

State which phase/step you're starting and spawn the first agent.
