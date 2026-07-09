-- Phase 2, step 2.7 — Chat persistence schema (docs/PROTOCOL_V1.md §4, OQ5)
--
-- Creates the chat_messages table backing the v1 channel.*/chat.* ops
-- (Applications/Chat/Events.php::handleChannelPublish/handleChatSend →
-- Tasks/chat_message.php). Fixes the OQ5-verified legacy gap: today's say()
-- writes to an unbounded GlobalData rooms[0]['messages'] array (never trimmed,
-- always room index 0) and direct messages are never persisted at all. The
-- chat path stays dormant behind Flag A (WS_NEW_HANDLING); applying this
-- migration alone changes no runtime behavior.
--
-- Source-controlled DDL only. There is NO migration runner in this repo: this
-- file MUST be applied MANUALLY by an operator/deploy process, and is NEVER
-- run automatically by the application. Behavior-neutral by design:
--   * chat_messages is a brand-new, initially-empty table,
--   * no existing code reads or writes it (only the Flag-A-gated v1 path does),
--   * `body` stores RAW text — NOT the pre-rendered nl2br(htmlspecialchars())
--     HTML legacy say() stores; rendering/escaping is a client concern (§2.10).
--
-- DDL matches the PROTOCOL_V1.md §4 schema sketch verbatim. Plain CREATE TABLE
-- (no IF NOT EXISTS) per the token-auth migration's convention; re-running on
-- an already-migrated schema fails loudly with "Table already exists", which
-- is the desired behavior for an operator-applied one-shot migration.

CREATE TABLE chat_messages (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    channel   VARCHAR(191)    NOT NULL,
    `from`    VARCHAR(64)     NOT NULL,
    body      TEXT            NOT NULL,
    level     VARCHAR(16)     NOT NULL DEFAULT 'chat',
    ts        INT UNSIGNED    NOT NULL,
    KEY idx_channel_ts (channel, ts),
    KEY idx_channel_id (channel, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
