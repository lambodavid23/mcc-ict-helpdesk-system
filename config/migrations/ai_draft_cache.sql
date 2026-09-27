-- AI Draft Cache Migration
-- Smart ICT Helpdesk System - Mutare City Council
--
-- Caches AI/locally composed resolution drafts per ticket so the technician
-- panel's "Generate Draft" returns instantly on repeat use instead of paying
-- the language model round trip again.
--
-- The cache key hashes the engine version, model, ticket, category and the
-- problem text, so changing the prompt or the model automatically invalidates
-- old entries rather than serving stale drafts.
--
-- The application treats this table as optional: AIAssistantService checks for
-- it at runtime and silently skips caching if it is absent. Safe to run with:
--     mysql -u root < config/migrations/ai_draft_cache.sql

USE mcc_helpdesk;

CREATE TABLE IF NOT EXISTS ai_draft_cache (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    cache_key  CHAR(64)    NOT NULL,
    ticket_id  INT          NOT NULL DEFAULT 0,
    category   VARCHAR(20)  NULL,
    draft      MEDIUMTEXT   NOT NULL,
    source     VARCHAR(20)  NOT NULL DEFAULT 'local',
    note       TEXT         NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cache_key (cache_key),
    KEY idx_ticket (ticket_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Keep the table from growing without bound. Drafts older than a week are
-- never served anyway, because reads are capped at 6 hours. LIMIT bounds the
-- transaction, since each commit costs a disk fsync.
DELETE FROM ai_draft_cache WHERE created_at < (NOW() - INTERVAL 7 DAY) LIMIT 500;
