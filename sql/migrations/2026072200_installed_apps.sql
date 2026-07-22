-- ==============================================================================
-- Migration: installed_apps - tracks which app (App Installer) is deployed
-- on which website. Unlike sql/schema.sql (imported once, on a fresh DB
-- only), every file under sql/migrations/ is applied on EVERY install.sh
-- run and every `yp repair panel` - CREATE TABLE IF NOT EXISTS makes this
-- naturally idempotent, so no state_mark gating is needed.
-- ==============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS installed_apps (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    website_id      INT UNSIGNED NOT NULL,
    app_slug        VARCHAR(64) NOT NULL,
    app_version     VARCHAR(32) NULL,
    db_name         VARCHAR(64) NULL,
    installed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      INT UNSIGNED NULL,
    UNIQUE KEY uq_installed_apps_website (website_id),
    CONSTRAINT fk_installed_apps_website FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE,
    CONSTRAINT fk_installed_apps_db FOREIGN KEY (db_name) REFERENCES databases_registry(db_name) ON DELETE SET NULL,
    CONSTRAINT fk_installed_apps_user FOREIGN KEY (created_by) REFERENCES panel_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
