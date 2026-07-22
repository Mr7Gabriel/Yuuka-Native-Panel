# Skema Database

[← Kembali ke Home](Home.md)

Database MariaDB `server_panel` (nama sesungguhnya = `DB_DATABASE` di
`.env`, default `server_panel`), charset `utf8mb4`, seluruh tabel InnoDB.
Diimpor otomatis oleh `modules/mariadb.sh` saat instalasi (idempotent —
hanya jalan kalau database masih kosong). Sumber: `sql/schema.sql`.

## `panel_users`

Administrator/operator panel (RBAC).

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `username` | VARCHAR(64) UNIQUE | |
| `email` | VARCHAR(190) UNIQUE | |
| `password_hash` | VARCHAR(255) | `password_hash($pw, PASSWORD_BCRYPT)` — selalu diawali `$2y$` |
| `role` | ENUM admin/operator/developer/viewer | default `viewer` |
| `is_active` | TINYINT(1) | default 1 |
| `last_login_at`, `last_login_ip` | | |

Lihat [Pemulihan Akun Admin](Pemulihan-Akun-Admin.md) untuk cara reset
manual lewat tabel ini.

## `login_attempts`

Rate limiting brute force (5x gagal / 15 menit per IP+username, ditegakkan
di `panel-src/app/helpers/auth.php`).

| Kolom | Keterangan |
|---|---|
| `username`, `ip_address`, `success` | |
| `attempted_at` | Index gabungan `(ip_address, attempted_at)` dan `(username, attempted_at)` untuk lookup cepat |

## `activity_log`

Audit trail semua aksi state-changing di panel.

| Kolom | Keterangan |
|---|---|
| `user_id` | FK ke `panel_users`, `ON DELETE SET NULL` (log tetap ada walau user dihapus) |
| `action`, `description`, `ip_address`, `created_at` | |

## `websites`

Website PHP native/multi-versi.

| Kolom | Keterangan |
|---|---|
| `domain` | UNIQUE |
| `php_version` | Versi PHP-FPM yang dipakai vhost ini |
| `document_root`, `nginx_conf_name` | |
| `is_enabled`, `ssl_enabled` | |
| `created_by` | FK `panel_users`, `ON DELETE SET NULL` |

## `nodejs_apps`

**Metadata saja** — status runtime (CPU/RAM/uptime/status) **selalu**
dibaca live dari `pm2 jlist`, bukan dari tabel ini (lihat
[Arsitektur](Arsitektur.md)).

| Kolom | Keterangan |
|---|---|
| `pm2_name` | UNIQUE, nama proses di PM2 |
| `domain`, `project_path`, `node_version`, `port` (UNIQUE) | |
| `start_command`, `build_command` | |
| `instances`, `exec_mode` (fork/cluster), `autorestart`, `watch`, `max_memory_restart` | Parameter `ecosystem.config.js` |
| `node_env` | default `production` |
| `is_managed` | Membedakan app yang dikelola penuh vs. hasil `importUnmanaged()` |
| `last_known_status` | **Historis/audit only** — komentar di skema eksplisit menyebut ini bukan sumber kebenaran runtime |

## `app_env_variables`

Environment variable per aplikasi Node.js, nilai **terenkripsi**.

| Kolom | Keterangan |
|---|---|
| `app_id` | FK `nodejs_apps`, `ON DELETE CASCADE` |
| `var_key` | UNIQUE per `app_id` |
| `var_value_enc` | AES-256-GCM, kunci dari `APP_KEY` di `.env` (lihat `EnvService`) |
| `is_secret` | Menentukan apakah disamarkan (`••••••••`) di UI |

## `databases_registry`

Database tenant yang diprovisikan lewat panel (bukan seluruh database di
server — hanya yang dibuat lewat menu Database).

| Kolom | Keterangan |
|---|---|
| `db_name` UNIQUE, `db_user`, `note` | |
| `created_by` | FK `panel_users` |

## `domains`

Registry domain gabungan — satu domain menunjuk ke **salah satu**
`website_id` atau `nodejs_app_id` (tidak keduanya).

| Kolom | Keterangan |
|---|---|
| `domain` UNIQUE | |
| `type` | ENUM `php` / `nodejs` |
| `website_id`, `nodejs_app_id` | FK, `ON DELETE CASCADE` |
| `ssl_enabled`, `cloudflare_proxied`, `is_enabled` | |

## `cron_jobs`

Lihat penjelasan lengkap command template di
[Fitur Panel § Cron Jobs](Fitur-Panel.md#cron-jobs).

| Kolom | Keterangan |
|---|---|
| `owner_type` | ENUM `php`/`nodejs` |
| `website_id`, `nodejs_app_id` | FK, `ON DELETE CASCADE` |
| `schedule` | Ekspresi cron 5 kolom |
| `command_type` | ENUM `php_artisan`/`php_script`/`node_script` |
| `command_arg` | **Path script relatif tervalidasi saja, bukan shell bebas** |
| `last_run_at`, `last_run_status` | |

## `backups`

| Kolom | Keterangan |
|---|---|
| `type` | ENUM `database`/`website`/`nodejs` |
| `target_name`, `file_path`, `size_bytes` | |
| `status` | ENUM `completed`/`failed`/`running` |
| `created_by` | FK `panel_users` |

## `health_checks`

Satu health check per aplikasi Node.js (`UNIQUE KEY uq_health_app`),
murni informasional — lihat
[Fitur Panel § Health Check](Fitur-Panel.md#health-check-nodejs_healthphp).

| Kolom | Keterangan |
|---|---|
| `nodejs_app_id` | FK `nodejs_apps`, `ON DELETE CASCADE`, UNIQUE |
| `url`, `http_method` (GET/HEAD/POST) | |
| `timeout_seconds`, `interval_seconds` | |
| `last_status` | ENUM `healthy`/`unhealthy`/`timeout`/`connection_refused`/`unknown` |
| `last_status_code`, `last_response_ms`, `last_checked_at`, `failure_count` | |

## `settings`

Key/value sederhana.

| `setting_key` | Nilai default | Kegunaan |
|---|---|---|
| `deployment_mode` | `direct` | Mode deployment aktif (direct/tunnel/hybrid) |
| `cpu_alert_threshold` | `85` | Ambang alert CPU (%) di Dashboard |
| `mem_alert_threshold` | `85` | Ambang alert RAM (%) di Dashboard |
| `restart_alert_threshold` | `10` | Ambang jumlah restart PM2 sebelum dianggap bermasalah |

## Diagram Relasi (ringkas)

```
panel_users ──< activity_log
     │              │
     ├──< websites ──┼──< domains (type=php)
     │      │        │
     ├──< nodejs_apps─┼──< domains (type=nodejs)
     │      │  │      │
     │      │  ├──< app_env_variables
     │      │  └──< health_checks
     │      │
     ├──< databases_registry
     ├──< cron_jobs >── websites / nodejs_apps
     └──< backups
```
