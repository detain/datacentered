<?php

/**
 * WS-revamp feature flags (plan B8 two-flag lifecycle).
 *
 * Flag A `WS_NEW_HANDLING` — default ON. Settable globally AND per
 * individual web/datacentered server or VPS/QS host (per-host override
 * falls back to the global default when unset).
 * Flag B `LEGACY_COMPAT` — default ON, global only.
 * Flag C `DC_BOT_PRESENCE` — default ON, global only (gates bot
 * spawn/move/cleanup for the datacenter 3D presence system).
 *
 * Storage: Redis through the SharedState facade (dc:flag: namespace,
 * no TTL — flags persist until an operator toggles them), so flags are
 * runtime-readable/toggleable across every worker process without a
 * redeploy or restart. This replaced the retired GlobalData variables
 * of the GlobalData→Redis migration.
 *
 * Redis key names (full keys, written by SharedState::set as JSON ints):
 *   - dc:flag:ws_new_handling            (int 0/1) Flag A global default; absent = 1 (ON)
 *   - dc:flag:ws_new_handling_host_<id>  (int 0/1) Flag A per-host override; absent = inherit global
 *   - dc:flag:ws_legacy_compat           (int 0/1) Flag B; absent = 1 (ON)
 *   - dc:flag:dc_bot_presence            (int 0/1) Flag C; absent = 1 (ON)
 *
 * UNSET vs UNREACHABLE are deliberately different, and the shipped code
 * behavior is the contract (an earlier revision of this header claimed
 * "ws_new_handling … missing = 0 (OFF)" — the code never did that; the
 * v1 handler has been enabled-by-default since commit 9eabb50, and this
 * header now matches the code it always described):
 *   - Redis reachable, flag ABSENT      => A=ON,  B=ON, C=ON (the shipped defaults).
 *   - Redis unreachable / commands throw => A=OFF, B=ON, C=ON, and every writer
 *     returns false. Each accessor funnels that state through its own
 *     catch (\Throwable) branch — deploying dormant code stays a runtime
 *     no-op (B8 "ship dormant"), legacy compat and bot presence survive
 *     an outage because disabling them is the destructive direction.
 *
 * "Unreachable" covers BOTH SharedState failure modes: no resolvable client
 * AND a live-but-dead handle. SharedState wraps every command, so a thrown
 * transport exception no longer escapes — it degrades the call to a fail-safe
 * return and marks itself dead (transportFailed() true until a re-probe
 * succeeds, ~30s windows, self-healing once Redis returns). readFlag() turns
 * either signal into the exception that routes the accessor into its
 * documented fail-safe branch, so a swallowed transport throw is never
 * mistaken for an unset flag (they have OPPOSITE Flag A defaults).
 *
 * Three-state lifecycle (B8):
 *   State 1 — Dormant  (A=OFF, B=ON):  explicit operator setting, or the
 *                                      fail-safe state while Redis is down.
 *   State 2 — Adoption (A=ON,  B=ON):  the unset default today.
 *   State 3 — New-only (A=ON,  B=OFF): backward compatibility disabled.
 *
 * Typical call-site pattern a future phase (P1–P6) will use to branch:
 *   if (FeatureFlags::useNewHandling($hostId)) {
 *       // new WS handling for this host/server
 *   } elseif (FeatureFlags::legacyCompatEnabled()) {
 *       // unchanged legacy path
 *   }
 *
 * @see Applications/Chat/SharedState.php  the Redis facade (dc:* namespace guard)
 * @see docs/FEATURE_FLAGS.md              operator + call-site documentation
 * @see ws_revamp_plan.md                  section B8 (rollout & feature-flag lifecycle)
 */

require_once __DIR__.'/SharedState.php';

class FeatureFlags
{
    /** Flag A global-default Redis key (SharedState dc:flag: namespace). */
    public const VAR_NEW_HANDLING = SharedState::PREFIX_FLAG.'ws_new_handling';

    /** Flag A per-host override Redis key prefix (SharedState dc:flag: namespace). */
    public const VAR_NEW_HANDLING_HOST_PREFIX = SharedState::PREFIX_FLAG.'ws_new_handling_host_';

    /** Flag B Redis key (SharedState dc:flag: namespace). */
    public const VAR_LEGACY_COMPAT = SharedState::PREFIX_FLAG.'ws_legacy_compat';

    /** Flag C Redis key — DC bot presence (datacenter 3D). */
    public const VAR_DC_BOT_PRESENCE = SharedState::PREFIX_FLAG.'dc_bot_presence';

    /** @var array<string,bool> methods (readers and writers) that already logged a fail-safe this process */
    private static $failSafeLogged = [];

    /**
     * Flag A — should this server/host use the new WS handling?
     *
     * Checks the per-host override first (when a $hostId is given), then the
     * global default. An unset flag means ON — the new handling is the
     * shipped default — while any Redis error means OFF so callers
     * transparently take the legacy path ("ship dormant", B8).
     *
     * Example:
     *   if (FeatureFlags::useNewHandling($hostId)) { ... new path ... }
     *
     * @param string|int|null $hostId optional VPS/QS host or server identifier;
     *                                 null/'' consults only the global default
     * @return bool true only if Flag A is ON for this host (or globally);
     *              true when unset (new handling is the default);
     *              false when Redis is unreachable or errors (fail-safe OFF)
     */
    public static function useNewHandling($hostId = null)
    {
        try {
            if ($hostId !== null && $hostId !== '') {
                $override = self::readFlag(self::hostVar($hostId));
                if ($override !== null) {
                    return (bool) $override;
                }
            }
            $globalDefault = self::readFlag(self::VAR_NEW_HANDLING);

            return $globalDefault === null ? true : (bool) $globalDefault;
        } catch (\Throwable $e) {
            self::logFailSafeOnce(
                'useNewHandling',
                'FeatureFlags::useNewHandling Redis error, defaulting OFF (legacy path): '.$e->getMessage()."\n"
            );

            return false;
        }
    }

    /**
     * Flag B — is the legacy handling still enabled? Global only.
     *
     * An unset flag and any Redis error mean ON (legacy compat stays
     * available until an operator explicitly flips it off, per B8) —
     * disabling compatibility during an outage would be the destructive
     * direction.
     *
     * @return bool true when legacy handling is still enabled (the default);
     *              only false once an operator has explicitly turned it off
     */
    public static function legacyCompatEnabled()
    {
        try {
            $value = self::readFlag(self::VAR_LEGACY_COMPAT);

            return $value === null ? true : (bool) $value;
        } catch (\Throwable $e) {
            self::logFailSafeOnce('legacyCompatEnabled', 'FeatureFlags::legacyCompatEnabled Redis error, defaulting ON: '.$e->getMessage()."\n");

            return true;
        }
    }

    /**
     * Flag C — is the DC bot presence system enabled?
     *
     * When enabled, a bot avatar wanders the datacenter for each location
     * whenever a real user joins the DC presence session. An unset flag
     * and any Redis error mean ON — the load-bearing default for the
     * Events.php spawn/move/cleanup gates.
     *
     * @return bool true when bot presence is enabled (the default);
     *              false only when disabled via an operator toggle
     */
    public static function dcBotPresenceEnabled()
    {
        try {
            $value = self::readFlag(self::VAR_DC_BOT_PRESENCE);

            return $value === null ? true : (bool) $value;
        } catch (\Throwable $e) {
            self::logFailSafeOnce('dcBotPresenceEnabled', 'FeatureFlags::dcBotPresenceEnabled Redis error, defaulting ON: '.$e->getMessage()."\n");

            return true;
        }
    }

    /**
     * Set Flag C (DC bot presence) at runtime (operator tooling).
     *
     * @param bool $on
     * @return bool true if the flag was written to Redis
     */
    public static function setDcBotPresence($on)
    {
        try {
            return self::guardWrite(
                SharedState::set(self::VAR_DC_BOT_PRESENCE, $on ? 1 : 0),
                'setDcBotPresence key: '.self::VAR_DC_BOT_PRESENCE
            );
        } catch (\Throwable $e) {
            self::logFailSafeOnce('setDcBotPresence', 'FeatureFlags::setDcBotPresence Redis error: '.$e->getMessage()."\n");

            return false;
        }
    }

    /**
     * Set Flag A at runtime (operator tooling, P7).
     *
     * @param string|int|null $hostId host/server id for a per-host override, or null to set the global default
     * @param bool $on
     * @return bool true if the flag was written to Redis
     */
    public static function setNewHandling($hostId, $on)
    {
        try {
            $key = ($hostId !== null && $hostId !== '') ? self::hostVar($hostId) : self::VAR_NEW_HANDLING;

            return self::guardWrite(SharedState::set($key, $on ? 1 : 0), 'setNewHandling key: '.$key);
        } catch (\Throwable $e) {
            self::logFailSafeOnce('setNewHandling', 'FeatureFlags::setNewHandling Redis error: '.$e->getMessage()."\n");

            return false;
        }
    }

    /**
     * Remove a per-host Flag A override so the host inherits the global default again.
     *
     * @param string|int $hostId
     * @return bool true if the override was removed (or did not exist);
     *              false when Redis is unavailable or errors (fail-closed)
     */
    public static function clearNewHandlingOverride($hostId)
    {
        try {
            $key = self::hostVar($hostId);
            if (SharedState::client() === null) {
                // A null client is either "never configured" (plain fail-closed
                // false, unchanged) or the facade's transport-dead short-circuit;
                // guardTransport escalates only the latter so the writer's
                // once-log fires when a live-but-dead handle swallowed the del.
                self::guardTransport('clearNewHandlingOverride key: '.$key);

                return false;
            }
            SharedState::del($key);
            self::guardTransport('clearNewHandlingOverride key: '.$key);

            return true;
        } catch (\Throwable $e) {
            self::logFailSafeOnce('clearNewHandlingOverride', 'FeatureFlags::clearNewHandlingOverride Redis error: '.$e->getMessage()."\n");

            return false;
        }
    }

    /**
     * Set Flag B (global legacy-compat switch) at runtime (operator tooling, P7).
     *
     * @param bool $on
     * @return bool true if the flag was written to Redis
     */
    public static function setLegacyCompat($on)
    {
        try {
            return self::guardWrite(
                SharedState::set(self::VAR_LEGACY_COMPAT, $on ? 1 : 0),
                'setLegacyCompat key: '.self::VAR_LEGACY_COMPAT
            );
        } catch (\Throwable $e) {
            self::logFailSafeOnce('setLegacyCompat', 'FeatureFlags::setLegacyCompat Redis error: '.$e->getMessage()."\n");

            return false;
        }
    }

    /**
     * Build the Redis key for a per-host Flag A override.
     * Host ids may be hostnames/IPs; normalize to a safe key suffix.
     *
     * @param string|int $hostId
     * @return string full dc:flag:-namespaced key
     */
    public static function hostVar($hostId)
    {
        return self::VAR_NEW_HANDLING_HOST_PREFIX.preg_replace('/[^A-Za-z0-9_]/', '_', (string) $hostId);
    }

    /**
     * Read one flag key through the SharedState facade.
     *
     * A null Redis client is turned into an exception on purpose: it routes
     * "unreachable" through each accessor's catch (\Throwable) fail-safe
     * branch, which is the single documented place the defaults live. The
     * facade's own null-on-unavailable return could not distinguish
     * "Redis down" from "flag unset", and those two have OPPOSITE defaults
     * for Flag A.
     *
     * Since SharedState wraps command throws into fail-safe returns, a
     * live-but-dead handle also reads as null — indistinguishable from
     * "unset" at the value level. guardTransport() closes that gap right after
     * the read: while the facade distrusts its transport, this null is NOT an
     * authoritative "absent" answer, so it is escalated the same way the
     * no-client state is. (A plain unset flag leaves the facade healthy, so
     * the documented unset⇒ON default still applies.)
     *
     * @param string $key full dc:flag: key
     * @return int|null the stored value, or null when the flag is unset
     * @throws \RuntimeException when Redis is unreachable (no client or dead transport)
     */
    private static function readFlag(string $key)
    {
        if (SharedState::client() === null) {
            throw new \RuntimeException('SharedState has no Redis client (flag read: '.$key.')');
        }

        $value = SharedState::get($key);
        self::guardTransport('flag read: '.$key);

        return $value;
    }

    /**
     * Escalate a swallowed transport failure. SharedState degrades throwing
     * commands to its documented fail-safe returns (nothing escapes the facade
     * any more); a fail-safe value is NOT an authoritative Redis answer.
     * While the facade reports transport-dead, throw so this class's
     * documented catch (\Throwable) fail-safe branch runs — the unreachable
     * defaults live there and must never be mistaken for the unset defaults.
     * The plain no-client-never-configured state leaves transportFailed()
     * false, so fail-closed/false paths that used to log nothing still do.
     *
     * @param string $context what was being attempted, for the exception message
     * @return void
     * @throws \RuntimeException when SharedState distrusts the Redis transport
     */
    private static function guardTransport(string $context): void
    {
        if (SharedState::transportFailed()) {
            throw new \RuntimeException('SharedState transport failed (no authoritative Redis access) during '.$context);
        }
    }

    /**
     * Writer-side wrapper of guardTransport: SharedState::set() returns false
     * both for a refused write and (since the facade wraps transport throws)
     * for "the write never reached Redis". The latter must escalate into the
     * writer's catch so the operator's once-log explains WHY the toggle did
     * not stick; the value itself is returned untouched on the happy path.
     *
     * @param bool   $written the facade's return
     * @param string $context
     * @return bool
     * @throws \RuntimeException when the false came from a dead transport
     */
    private static function guardWrite(bool $written, string $context): bool
    {
        if (!$written) {
            self::guardTransport($context);
        }

        return $written;
    }

    /**
     * Fail-safe logging, once per accessor/writer per process: useNewHandling()
     * runs on hot dispatch paths (Events.php, trigger_payment) and operator
     * tooling can loop the setters against a throwing Redis, so a per-call log
     * line while Redis is down buries everything else — same rationale as
     * SharedState::logUnavailableOnce(). The per-method key keeps each guard
     * independent: one accessor falling over never silences another's first
     * report.
     *
     * @param string $accessor
     * @param string $message
     * @return void
     */
    private static function logFailSafeOnce(string $accessor, string $message): void
    {
        if (isset(self::$failSafeLogged[$accessor])) {
            return;
        }
        self::$failSafeLogged[$accessor] = true;
        self::log($message);
    }

    /**
     * Process-safe log helper: uses Worker::safeEcho() inside worker
     * processes, error_log() elsewhere (web/CLI contexts).
     *
     * @param string $message
     * @return void
     */
    private static function log($message)
    {
        try {
            static $workers = null;
            if ($workers === null) {
                $workers = class_exists('\Workerman\Worker', false) ? \Workerman\Worker::getAllWorkers() : [];
            }
            if (!empty($workers)) {
                \Workerman\Worker::safeEcho($message);
                return;
            }
        } catch (\Throwable $e) {
            // fall through to error_log
        }
        error_log(rtrim($message, "\n"));
    }
}
