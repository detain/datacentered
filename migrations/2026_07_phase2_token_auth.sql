-- Phase 2, step 2.2 — Token auth schema (docs/AUTH_DESIGN.md §2)
--
-- Adds per-identity bearer-token columns to the host registries and creates
-- the bot registry, backing the v1 auth.hello handshake
-- (Applications/Chat/Events.php::handleAuthHello). The auth path stays dormant
-- behind Flag A (WS_NEW_HANDLING); applying this migration alone changes no
-- runtime behavior.
--
-- Source-controlled DDL only. There is NO migration runner in this repo: this
-- file MUST be applied MANUALLY by an operator/deploy process, and is NEVER
-- run automatically by the application. Behavior-neutral by design:
--   * all new columns are NULLable with NULL defaults (a NULL hash means
--     "no token issued yet" — such a host can only use the legacy path),
--   * ws_bots is a brand-new, initially-empty table,
--   * no existing code INSERTs into vps_masters/qs_masters with an explicit
--     column list (verified 2026-07-04), and every SELECT * / named-column
--     consumer is unaffected by added nullable columns.
--
-- DDL matches docs/AUTH_DESIGN.md §2 exactly. Plain ADD COLUMN (no
-- IF NOT EXISTS) per that document's own DDL; re-running on an already-
-- migrated schema fails loudly with "Duplicate column name", which is the
-- desired behavior for an operator-applied one-shot migration.

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
