-- Mobile Web Audit - Core Web Vitals tracker
-- MySQL 5.5+ / MariaDB 5.5+ — no version-specific column types are used, so this
-- loads on shared hosting without knowing what it runs.
--
--   mysql -u root -p -e "CREATE DATABASE web_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
--   mysql -u root -p web_audit < db/schema.sql

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Sites
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sites (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(190)  NOT NULL,
    url             VARCHAR(500)  NOT NULL,
    url_hash        CHAR(40)      NOT NULL,
    host            VARCHAR(190)  NOT NULL,
    sitemap_url     VARCHAR(500)  DEFAULT NULL,
    clickup_list_id VARCHAR(64)   DEFAULT NULL COMMENT 'overrides the global ClickUp list',
    score_threshold TINYINT UNSIGNED DEFAULT NULL COMMENT 'NULL = use global threshold',
    is_active       TINYINT(1)    NOT NULL DEFAULT 1,
    created_at      DATETIME      NOT NULL,
    updated_at      DATETIME      NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_sites_url (url_hash),
    KEY idx_sites_host (host)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Pages discovered from the sitemap (or added by hand)
-- is_tracked = 0 means "excluded": kept on record, never audited.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pages (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id                 INT UNSIGNED NOT NULL,
    url                     VARCHAR(2048) NOT NULL,
    url_hash                CHAR(40)      NOT NULL,
    path                    VARCHAR(500)  NOT NULL DEFAULT '/',
    source                  ENUM('sitemap','manual') NOT NULL DEFAULT 'sitemap',
    is_tracked              TINYINT(1)    NOT NULL DEFAULT 1,
    sitemap_lastmod         DATE          DEFAULT NULL,
    first_seen_at           DATETIME      NOT NULL,
    last_seen_in_sitemap_at DATETIME      DEFAULT NULL,
    last_audit_at           DATETIME      DEFAULT NULL,
    last_score              TINYINT UNSIGNED DEFAULT NULL,
    previous_score          TINYINT UNSIGNED DEFAULT NULL,
    audit_count             INT UNSIGNED  NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_pages_site_url (site_id, url_hash),
    KEY idx_pages_tracked (site_id, is_tracked),
    KEY idx_pages_score (site_id, last_score),
    CONSTRAINT fk_pages_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Exclusion rules: wildcard patterns auto-excluded on every sitemap import
-- e.g.  /tag/*   *?replytocom=*   /wp-json/*
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS exclusion_rules (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id    INT UNSIGNED NOT NULL,
    pattern    VARCHAR(255) NOT NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_rule (site_id, pattern),
    CONSTRAINT fk_rules_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- One row per PageSpeed Insights call. This is the ranking history.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audits (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    page_id              INT UNSIGNED NOT NULL,
    site_id              INT UNSIGNED NOT NULL,
    run_id               INT UNSIGNED DEFAULT NULL,
    strategy             VARCHAR(10)  NOT NULL DEFAULT 'mobile',
    status               ENUM('ok','error') NOT NULL DEFAULT 'ok',
    error_message        VARCHAR(500) DEFAULT NULL,
    performance_score    TINYINT UNSIGNED DEFAULT NULL,
    accessibility_score  TINYINT UNSIGNED DEFAULT NULL,
    best_practices_score TINYINT UNSIGNED DEFAULT NULL,
    seo_score            TINYINT UNSIGNED DEFAULT NULL,
    -- Lab data (Lighthouse)
    lcp_ms               INT UNSIGNED DEFAULT NULL,
    fcp_ms               INT UNSIGNED DEFAULT NULL,
    cls                  DECIMAL(6,3) DEFAULT NULL,
    tbt_ms               INT UNSIGNED DEFAULT NULL,
    si_ms                INT UNSIGNED DEFAULT NULL,
    ttfb_ms              INT UNSIGNED DEFAULT NULL,
    -- Field data (CrUX, the numbers Google actually ranks on)
    field_lcp_ms         INT UNSIGNED DEFAULT NULL,
    field_cls            DECIMAL(6,3) DEFAULT NULL,
    field_inp_ms         INT UNSIGNED DEFAULT NULL,
    field_verdict        VARCHAR(20)  DEFAULT NULL,
    -- LONGTEXT, not JSON: the app encodes/decodes this itself, and JSON as a
    -- column type needs MySQL 5.7+. LONGTEXT loads anywhere.
    opportunities        LONGTEXT     NULL,
    lighthouse_version   VARCHAR(20)  DEFAULT NULL,
    duration_ms          INT UNSIGNED DEFAULT NULL,
    fetched_at           DATETIME     NOT NULL,
    created_at           DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_audits_page_time (page_id, fetched_at),
    KEY idx_audits_site_time (site_id, fetched_at),
    KEY idx_audits_run (run_id),
    CONSTRAINT fk_audits_page FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- A scan run groups the audits triggered together
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scan_runs (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id      INT UNSIGNED NOT NULL,
    type         ENUM('full','selected','quick','scheduled') NOT NULL DEFAULT 'full',
    status       ENUM('queued','running','completed','cancelled') NOT NULL DEFAULT 'queued',
    total_items  INT UNSIGNED NOT NULL DEFAULT 0,
    done_items   INT UNSIGNED NOT NULL DEFAULT 0,
    failed_items INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL,
    started_at   DATETIME DEFAULT NULL,
    finished_at  DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_runs_site (site_id, created_at),
    KEY idx_runs_status (status),
    CONSTRAINT fk_runs_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scan_items (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id      INT UNSIGNED NOT NULL,
    page_id     INT UNSIGNED NOT NULL,
    url         VARCHAR(2048) NOT NULL,
    status      ENUM('pending','running','done','error') NOT NULL DEFAULT 'pending',
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    error       VARCHAR(500) DEFAULT NULL,
    started_at  DATETIME DEFAULT NULL,
    finished_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_items_run_status (run_id, status),
    KEY idx_items_page (page_id),
    CONSTRAINT fk_items_run FOREIGN KEY (run_id) REFERENCES scan_runs (id) ON DELETE CASCADE,
    CONSTRAINT fk_items_page FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Tasks: opened automatically whenever a page scores below the threshold
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tasks (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id         INT UNSIGNED NOT NULL,
    page_id         INT UNSIGNED NOT NULL,
    audit_id        BIGINT UNSIGNED DEFAULT NULL,
    title           VARCHAR(255) NOT NULL,
    details         TEXT,
    threshold       TINYINT UNSIGNED NOT NULL,
    score_at_open   TINYINT UNSIGNED NOT NULL,
    latest_score    TINYINT UNSIGNED DEFAULT NULL,
    priority        ENUM('critical','high','normal') NOT NULL DEFAULT 'high',
    status          ENUM('open','in_progress','resolved','ignored') NOT NULL DEFAULT 'open',
    assignee        VARCHAR(120) DEFAULT NULL,
    resolution_note VARCHAR(255) DEFAULT NULL,
    clickup_task_id   VARCHAR(64)  DEFAULT NULL,
    clickup_task_url  VARCHAR(500) DEFAULT NULL,
    clickup_synced_at DATETIME     DEFAULT NULL,
    clickup_error     VARCHAR(500) DEFAULT NULL,
    opened_at       DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    resolved_at     DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_tasks_status (status, priority),
    KEY idx_tasks_page (page_id, status),
    KEY idx_tasks_site (site_id, status),
    CONSTRAINT fk_tasks_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_page FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Key/value settings editable from the UI (falls back to .env)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    name       VARCHAR(64) NOT NULL,
    value      TEXT,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (name, value, updated_at) VALUES
    ('score_threshold', '80', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE name = name;
