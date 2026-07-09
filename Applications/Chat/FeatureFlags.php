<?php

/**
 * WS-revamp feature flags (plan B8 two-flag lifecycle).
 *
 * Flag A `WS_NEW_HANDLING` — default OFF. Settable globally AND per
 * individual web/datacentered server or VPS/QS host (per-host override
 * falls back to the global default when unset).
 * Flag B `LEGACY_COMPAT` — default ON, global only.
 *
 * Storage: GlobalData variables at GLOBALDATA_IP:2207, so flags are
 * runtime-readable/toggleable across every worker process without a
 * redeploy or restart. Absent variables mean "default" — a fresh
 * GlobalData server (or an unreachable one) yields A=OFF, B=ON, i.e.
 * exactly today's behavior.
 *
 * GlobalData variable names:
 *   - ws_new_handling            (int 0/1) Flag A global default; missing = 0 (OFF)
 *   - ws_new_handling_host_<id>  (int 0/1) Flag A per-host override; missing = inherit global
 *   - ws_legacy_compat           (int 0/1) Flag B; missing = 1 (ON)
 *
 * Three-state lifecycle (B8):
 *   State 1 — Dormant  (A=OFF, B=ON):  exactly today's behavior (default).
 *   State 2 — Adoption (A=ON,  B=ON):  new path active where A is on; legacy
 *                                      still works everywhere; reversible per host.
 *   State 3 — New-only (A=ON,  B=OFF): backward compatibility disabled.
 *
 * Typical call-site pattern a future phase (P1–P6) will use to branch:
 *   if (FeatureFlags::useNewHandling($hostId)) {
 *       // new WS handling for this host/server
 *   } elseif (FeatureFlags::legacyCompatEnabled()) {
 *       // unchanged legacy path (the active default today)
 *   }
 *
 * NOTE: dormant scaffolding (P0.5) — nothing calls this yet. Wiring into
 * code paths happens in later phases (P1–P6) per the ship-dormant gate.
 *
 * @see docs/FEATURE_FLAGS.md    operator + call-site documentation
 * @see ws_revamp_plan.md        section B8 (rollout & feature-flag lifecycle)
 */

class FeatureFlags
{
    /** Flag A global-default variable name in GlobalData. */
    public const VAR_NEW_HANDLING = 'ws_new_handling';

    /** Flag A per-host override variable-name prefix in GlobalData. */
    public const VAR_NEW_HANDLING_HOST_PREFIX = 'ws_new_handling_host_';

    /** Flag B variable name in GlobalData. */
    public const VAR_LEGACY_COMPAT = 'ws_legacy_compat';

    /**
     * Lazily-created fallback GlobalData client (used when no $global exists
     * in this process, e.g. web/CLI contexts).
     *
     * @var \GlobalData\Client|null
     */
    private static $client = null;

    /**
     * Flag A — should this server/host use the new WS handling?
     *
     * Checks the per-host override first (when a $hostId is given), then the
     * global default. Missing variables and any GlobalData error mean OFF —
     * the legacy path stays active (fail-safe per B8 "ship dormant"). If
     * GlobalData is unreachable (no $global, no lazy client, or a throwing
     * client), this returns false so callers transparently take the legacy
     * path: deploying dormant code is a runtime no-op.
     *
     * Example:
     *   if (FeatureFlags::useNewHandling($hostId)) { ... new path ... }
     *
     * @param string|int|null $hostId optional VPS/QS host or server identifier;
     *                                 null/'' consults only the global default
     * @return bool true only if Flag A is ON for this host (or globally);
     *              false on any error or when unset (fail-safe to legacy)
     */
    public static function useNewHandling($hostId = null)
    {
        try {
            $global = self::globalData();
            if ($global === null) {
                return false;
            }
            if ($hostId !== null && $hostId !== '') {
                $var = self::hostVar($hostId);
                if (isset($global->$var)) {
                    return (bool) $global->$var;
                }
            }
            $var = self::VAR_NEW_HANDLING;
            return isset($global->$var) ? (bool) $global->$var : false;
        } catch (\Throwable $e) {
            self::log('FeatureFlags::useNewHandling GlobalData error, defaulting OFF: '.$e->getMessage()."\n");
            return false;
        }
    }

    /**
     * Flag B — is the legacy handling still enabled? Global only.
     *
     * Missing variable and any GlobalData error mean ON (legacy compat is
     * the permanent default until an operator flips it off, per B8). When
     * GlobalData is unreachable this returns true, keeping the legacy path
     * available — the fail-safe direction for Flag B.
     *
     * @return bool true when legacy handling is still enabled (the default);
     *              only false once an operator has explicitly turned it off
     */
    public static function legacyCompatEnabled()
    {
        try {
            $global = self::globalData();
            if ($global === null) {
                return true;
            }
            $var = self::VAR_LEGACY_COMPAT;
            return isset($global->$var) ? (bool) $global->$var : true;
        } catch (\Throwable $e) {
            self::log('FeatureFlags::legacyCompatEnabled GlobalData error, defaulting ON: '.$e->getMessage()."\n");
            return true;
        }
    }

    /**
     * Set Flag A at runtime (operator tooling, P7).
     *
     * @param string|int|null $hostId host/server id for a per-host override, or null to set the global default
     * @param bool $on
     * @return bool true if the flag was written to GlobalData
     */
    public static function setNewHandling($hostId, $on)
    {
        try {
            $global = self::globalData();
            if ($global === null) {
                return false;
            }
            $var = ($hostId !== null && $hostId !== '') ? self::hostVar($hostId) : self::VAR_NEW_HANDLING;
            $global->$var = $on ? 1 : 0;
            return true;
        } catch (\Throwable $e) {
            self::log('FeatureFlags::setNewHandling GlobalData error: '.$e->getMessage()."\n");
            return false;
        }
    }

    /**
     * Remove a per-host Flag A override so the host inherits the global default again.
     *
     * @param string|int $hostId
     * @return bool true if the override was removed (or did not exist)
     */
    public static function clearNewHandlingOverride($hostId)
    {
        try {
            $global = self::globalData();
            if ($global === null) {
                return false;
            }
            $var = self::hostVar($hostId);
            if (isset($global->$var)) {
                unset($global->$var);
            }
            return true;
        } catch (\Throwable $e) {
            self::log('FeatureFlags::clearNewHandlingOverride GlobalData error: '.$e->getMessage()."\n");
            return false;
        }
    }

    /**
     * Set Flag B (global legacy-compat switch) at runtime (operator tooling, P7).
     *
     * @param bool $on
     * @return bool true if the flag was written to GlobalData
     */
    public static function setLegacyCompat($on)
    {
        try {
            $global = self::globalData();
            if ($global === null) {
                return false;
            }
            $var = self::VAR_LEGACY_COMPAT;
            $global->$var = $on ? 1 : 0;
            return true;
        } catch (\Throwable $e) {
            self::log('FeatureFlags::setLegacyCompat GlobalData error: '.$e->getMessage()."\n");
            return false;
        }
    }

    /**
     * Build the GlobalData variable name for a per-host Flag A override.
     * Host ids may be hostnames/IPs; normalize to a safe variable name.
     *
     * @param string|int $hostId
     * @return string
     */
    public static function hostVar($hostId)
    {
        return self::VAR_NEW_HANDLING_HOST_PREFIX.preg_replace('/[^A-Za-z0-9_]/', '_', (string) $hostId);
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
            if (class_exists('\Workerman\Worker', false) && \Workerman\Worker::getAllWorkers()) {
                \Workerman\Worker::safeEcho($message);
                return;
            }
        } catch (\Throwable $e) {
            // fall through to error_log
        }
        error_log(rtrim($message, "\n"));
    }

    /**
     * Get the GlobalData client: reuse the process-wide $global when present
     * (worker contexts), otherwise lazily connect using GLOBALDATA_IP.
     *
     * @return \GlobalData\Client|null null when no client can be created
     */
    private static function globalData()
    {
        global $global;
        if ($global instanceof \GlobalData\Client) {
            return $global;
        }
        if (self::$client instanceof \GlobalData\Client) {
            return self::$client;
        }
        if (!defined('GLOBALDATA_IP')) {
            $settings = '/home/my/include/config/config.settings.php';
            if (is_readable($settings)) {
                require_once $settings;
            }
        }
        if (!defined('GLOBALDATA_IP')) {
            return null;
        }
        self::$client = new \GlobalData\Client(GLOBALDATA_IP.':2207');
        return self::$client;
    }
}
