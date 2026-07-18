-- ==============================================================================
-- Server Panel - Database Schema
-- Charset: utf8mb4 / InnoDB throughout. Imported automatically by
-- modules/mariadb.sh during install (idempotent - only runs if empty).
-- ==============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------
-- panel_users - panel administrators / operators (RBAC)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS panel_users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(64) NOT NULL UNIQUE,
    email           VARCHAR(190) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin','operator','developer','viewer') NOT NULL DEFAULT 'viewer',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at   DATETIME NULL,
    last_login_ip   VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- login_attempts - brute force / rate limiting
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(64) NOT NULL,
    ip_address      VARCHAR(45) NOT NULL,
    success         TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_lookup (ip_address, attempted_at),
    INDEX idx_login_attempts_user (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- activity_log - audit trail for every state-changing panel action
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NULL,
    action          VARCHAR(100) NOT NULL,
    description     VARCHAR(500) NULL,
    ip_address      VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_user (user_id, created_at),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES panel_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- websites - PHP native / multi-version PHP sites
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS websites (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain          VARCHAR(190) NOT NULL UNIQUE,
    php_version     VARCHAR(8) NOT NULL,
    document_root   VARCHAR(255) NOT NULL,
    nginx_conf_name VARCHAR(200) NOT NULL,
    is_enabled      TINYINT(1) NOT NULL DEFAULT 1,
    ssl_enabled     TINYINT(1) NOT NULL DEFAULT 0,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_websites_user FOREIGN KEY (created_by) REFERENCES panel_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- nodejs_apps - metadata only. Runtime truth (status/cpu/mem/uptime)
-- always comes live from `pm2 jlist`, never read from this table.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS nodejs_apps (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_name            VARCHAR(100) NOT NULL,
    pm2_name            VARCHAR(100) NOT NULL UNIQUE,
    domain              VARCHAR(190) NULL,
    project_path        VARCHAR(255) NOT NULL,
    node_version        VARCHAR(8) NOT NULL,
    port                INT UNSIGNED NOT NULL,
    start_command       VARCHAR(255) NOT NULL DEFAULT 'server.js',
    build_command       VARCHAR(255) NULL,
    instances           INT UNSIGNED NOT NULL DEFAULT 1,
    exec_mode           ENUM('fork','cluster') NOT NULL DEFAULT 'fork',
    autorestart         TINYINT(1) NOT NULL DEFAULT 1,
    watch               TINYINT(1) NOT NULL DEFAULT 0,
    max_memory_restart  VARCHAR(16) NOT NULL DEFAULT '512M',
    node_env            VARCHAR(32) NOT NULL DEFAULT 'production',
    is_managed          TINYINT(1) NOT NULL DEFAULT 1,
    last_known_status   VARCHAR(32) NULL COMMENT 'Historical/audit only - never the runtime source of truth',
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_nodejs_port (port),
    CONSTRAINT fk_nodejs_user FOREIGN KEY (created_by) REFERENCES panel_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- app_env_variables - per Node.js app environment variables.
-- Secret values are stored encrypted (see EnvService - AES-256-GCM using
-- APP_KEY from .env), never in plaintext, never logged.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_env_variables (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_id          INT UNSIGNED NOT NULL,
    var_key         VARCHAR(128) NOT NULL,
    var_value_enc   TEXT NOT NULL,
    is_secret       TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_app_var (app_id, var_key),
    CONSTRAINT fk_env_app FOREIGN KEY (app_id) REFERENCES nodejs_apps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- databases_registry - tenant databases provisioned through the panel
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS databases_registry (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    db_name         VARCHAR(64) NOT NULL UNIQUE,
    db_user         VARCHAR(32) NOT NULL,
    note            VARCHAR(255) NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_db_user FOREIGN KEY (created_by) REFERENCES panel_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- domains - unified domain registry (PHP website or Node.js app)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS domains (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain              VARCHAR(190) NOT NULL UNIQUE,
    type                ENUM('php','nodejs') NOT NULL,
    website_id          INT UNSIGNED NULL,
    nodejs_app_id       INT UNSIGNED NULL,
    ssl_enabled         TINYINT(1) NOT NULL DEFAULT 0,
    cloudflare_proxied  TINYINT(1) NOT NULL DEFAULT 0,
    is_enabled          TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_domain_website FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE,
    CONSTRAINT fk_domain_nodejs FOREIGN KEY (nodejs_app_id) REFERENCES nodejs_apps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- cron_jobs - scheduled tasks per website / node app
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cron_jobs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    owner_type      ENUM('php','nodejs') NOT NULL,
    website_id      INT UNSIGNED NULL,
    nodejs_app_id   INT UNSIGNED NULL,
    schedule        VARCHAR(64) NOT NULL COMMENT 'standard 5-field cron expression',
    command_type    ENUM('php_artisan','php_script','node_script') NOT NULL,
    command_arg     VARCHAR(255) NOT NULL COMMENT 'validated relative script path only, no free-form shell',
    is_enabled      TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at     DATETIME NULL,
    last_run_status VARCHAR(16) NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cron_website FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE,
    CONSTRAINT fk_cron_nodejs FOREIGN KEY (nodejs_app_id) REFERENCES nodejs_apps(id) ON DELETE CASCADE,
    CONSTRAINT fk_cron_user FOREIGN KEY (created_by) REFERENCES panel_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- backups - database / website / node app backups
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS backups (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type            ENUM('database','website','nodejs') NOT NULL,
    target_name     VARCHAR(190) NOT NULL,
    file_path       VARCHAR(255) NOT NULL,
    size_bytes      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status          ENUM('completed','failed','running') NOT NULL DEFAULT 'running',
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_backup_user FOREIGN KEY (created_by) REFERENCES panel_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- health_checks - optional HTTP health monitor per Node.js app
-- (informational only - PM2 remains the source of truth for process state)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS health_checks (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nodejs_app_id       INT UNSIGNED NOT NULL,
    url                 VARCHAR(255) NOT NULL,
    http_method         ENUM('GET','HEAD','POST') NOT NULL DEFAULT 'GET',
    timeout_seconds     TINYINT UNSIGNED NOT NULL DEFAULT 5,
    interval_seconds    SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    is_enabled          TINYINT(1) NOT NULL DEFAULT 1,
    last_status         ENUM('healthy','unhealthy','timeout','connection_refused','unknown') NOT NULL DEFAULT 'unknown',
    last_status_code    SMALLINT UNSIGNED NULL,
    last_response_ms    INT UNSIGNED NULL,
    last_checked_at     DATETIME NULL,
    failure_count       INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_health_app (nodejs_app_id),
    CONSTRAINT fk_health_app FOREIGN KEY (nodejs_app_id) REFERENCES nodejs_apps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- settings - simple key/value panel configuration
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    setting_key     VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value   TEXT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('deployment_mode', 'direct'),
    ('cpu_alert_threshold', '85'),
    ('mem_alert_threshold', '85'),
    ('restart_alert_threshold', '10');

SET FOREIGN_KEY_CHECKS = 1;
