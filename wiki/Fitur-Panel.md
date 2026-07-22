# Fitur Panel

[← Kembali ke Home](Home.md)

Menu sidebar (`panel-src/public/partials/sidebar.php`) dan permission RBAC
yang menjaganya (lihat [RBAC & Role](RBAC.md) untuk role mana yang punya
akses):

| Menu | File halaman | Permission view | Service utama |
|---|---|---|---|
| Dashboard | `dashboard.php` | `monitoring.view` | `SystemService` |
| Website PHP | `websites.php` | `website.view` | `NginxService` |
| Node.js Apps | `nodejs.php` | `nodejs.view` | `NodeService`, `EnvService`, `HealthCheckService` |
| Database | `databases.php` | `database.view` | `DatabaseService` |
| Domain | `domains.php` | `domain.manage` | `DomainService`, `SSLService` |
| Cron Jobs | `cron.php` | `cron.view` | `CronService` |
| Backup | `backups.php` | `backup.view` | `BackupService` |
| Log | `logs.php` | `logs.view` | `LogService` |
| Cloudflare Tunnel | `cloudflare.php` | `monitoring.view` | `CloudflareService` |
| Manajemen User | `users.php` | `users.manage` | `UserService` |
| Pengaturan | `settings.php` | `settings.manage` | — (tabel `settings`) |

## Dashboard

Ringkasan sistem real-time: status service inti (`SystemService::
serviceStatuses()` — nginx, mariadb, php-fpm, dst lewat `service-status`
subcommand di panel-exec.sh), jumlah aplikasi Node.js yang sedang running
(`nodejsRunningCount()` — dihitung dari `pm2 jlist` live, bukan tabel DB),
dan statistik umum (`summary()`). Data live disegarkan lewat polling AJAX
(`public/ajax_stats.php`, `public/ajax_pm2.php`) tanpa reload halaman.

## Website PHP

CRUD website statis/PHP native, ditangani `NginxService`:

- `createWebsite($domain, $phpVersion, $userId)` — membuat direktori
  document root (lewat `fs-mkdir-website`), menulis vhost Nginx (lewat
  `nginx-write-config` — divalidasi `nginx -t` sebelum diaktifkan, otomatis
  rollback ke config lama kalau invalid), lalu `nginx-enable`.
- `toggleWebsite($id, $enable, $userId)` — enable/disable vhost tanpa
  menghapus file (`nginx-enable`/`nginx-disable`).
- `deleteWebsite($id, $deleteFiles, $userId)` — hapus vhost
  (`nginx-delete`), opsional sekalian hapus folder document root
  (`fs-remove-website`).

Setiap website terikat ke satu versi PHP-FPM tertentu (`php_version`,
kolom di tabel `websites`) — pool PHP-FPM per versi sudah disiapkan saat
instalasi ([Instalasi](Instalasi.md) tahap 6).

## Node.js Apps

Bagian paling kompleks, ditangani `NodeService`. Prinsip inti: **status
runtime selalu dari `pm2 jlist` langsung**, tabel `nodejs_apps` cuma
metadata (lihat [Arsitektur](Arsitektur.md) pilar #2).

- `createApp(...)` — validasi port bebas (`isPortAvailable`/
  `findFreePort`, range default 3000-3999, dicek juga lewat `port-check`
  di server), menulis `ecosystem.config.js` PM2 (lewat `pm2-deploy`,
  dijalankan sebagai user `nodeapps`), lalu `pm2 save` supaya bertahan
  setelah reboot.
- `controlApp($id, $action, $userId)` — start/stop/restart/reload lewat
  subcommand `pm2-start`/`pm2-stop`/`pm2-restart`/`pm2-reload`.
- `combinedStatus()` — gabungan data `pm2 jlist` (runtime) + tabel
  `nodejs_apps` (metadata) untuk ditampilkan di UI.
- `importUnmanaged($pm2Name, $userId)` — mengambil alih proses PM2 yang
  sudah berjalan tapi belum terdaftar di panel (misalnya dideploy manual
  sebelumnya) ke dalam pencatatan panel.
- `deleteApp($id, $deleteFiles, $userId)` — `pm2-delete` + opsional hapus
  folder aplikasi (`fs-remove-nodeapp`).
- `getLogs($id, $lines)` / `clearLogs($id)` — `pm2-logs` (`--nostream`,
  maksimum 1000 baris dipaksa server-side) / `pm2-flush`. Halaman:
  `nodejs_logs.php`.

### Environment Variables (`nodejs_env.php`)

Dikelola per-aplikasi lewat `EnvService`. Nilai disimpan **terenkripsi**
di tabel `app_env_variables` (`var_value_enc`, AES-256-GCM, kunci dari
`APP_KEY` di `.env` — lihat [Keamanan](Keamanan.md)). Nilai secret
disamarkan (`••••••••`) di UI dengan tombol show/hide, mendukung
import/export format `.env` (`parseDotEnv()`/`toDotEnvExport()`).
Perubahan baru berlaku setelah menekan **Terapkan & Restart** (menulis
ulang `ecosystem.config.js` lalu `pm2 start ... --update-env`).

### Health Check (`nodejs_health.php`)

`HealthCheckService` — cek HTTP periodik per aplikasi (GET/HEAD/POST) lewat
cURL PHP langsung (**tidak** lewat shell, URL tidak pernah menyentuh
command line). Status: `healthy` / `unhealthy` / `timeout` /
`connection_refused` / `unknown`. Dijalankan oleh
`scripts/health_check_runner.php` lewat cron sistem `* * * * *`
(`runDueChecks()` — hanya menjalankan yang sudah lewat interval-nya).
Murni informasional; **PM2 tetap satu-satunya sumber kebenaran** untuk
apakah proses benar-benar hidup.

## Database

`DatabaseService`, dua koneksi terpisah (lihat
[Arsitektur](Arsitektur.md#dua-koneksi-database-pdo)):

- `listLive()` — daftar database MariaDB sesungguhnya di server (lewat
  koneksi `panel_provisioner`).
- `createDatabase($dbName, $dbUser, $password, $note, $userId)` — membuat
  database + user MariaDB baru, `GRANT` scoped ke database itu saja,
  dicatat di tabel `databases_registry`.
- `dropDatabase($registryId, $userId)` — hapus database + user terkait.

## Domain

`DomainService` — registry gabungan domain untuk website PHP maupun
aplikasi Node.js (tabel `domains`, kolom `type` ENUM `php`/`nodejs`,
`website_id` XOR `nodejs_app_id`):

- `setCloudflareProxied($id, $proxied, $userId)` — menandai domain
  di-proxy lewat Cloudflare (kolom `cloudflare_proxied`) — status
  penanda saja, tidak mengubah konfigurasi Cloudflare dari sisi panel.
- `toggle($id, $enable, $userId)` — enable/disable domain.
- SSL per-domain ditangani `SSLService::issueForDomain()` /
  `removeCertificate()` lewat Certbot mode webroot (`certbot-issue`/
  `certbot-remove` di panel-exec.sh) — tidak berlaku di mode deployment
  `tunnel` murni (lihat [Cloudflare Tunnel](Cloudflare-Tunnel.md)).

## Cron Jobs

`CronService` — jadwal terjadwal per website PHP atau aplikasi Node.js,
ditulis sebagai file diskrit `/etc/cron.d/panel-<id>` (**bukan** mengedit
crontab bersama di tempat), lewat subcommand `cron-write`/`cron-delete`.

Tiga `command_type` yang didukung, masing-masing dibangun dari template
tetap (bukan string bebas dari user):

| `command_type` | Command yang dibangun | User eksekusi |
|---|---|---|
| `php_artisan` | `php{version} {siteRoot}/artisan schedule:run` | `www-data` |
| `php_script` | `php{version} {siteRoot}/{command_arg}` | `www-data` |
| `node_script` | `node {command_arg}` (dengan NVM di-load) | `nodeapps` |

`command_arg` untuk `php_script`/`node_script` divalidasi
`Validator::relativeScriptPath()` (charset `[a-zA-Z0-9_./-]` saja, tanpa
`..`) sebelum disimpan — mencegah command injection maupun path traversal
lewat argumen ini.

## Backup

`BackupService` — tiga jenis backup, semuanya lewat `panel-exec.sh` karena
`panel` tidak punya akses baca langsung ke file `www-data`/`nodeapps`, dan
`mysqldump` perlu jalan sebagai `root` (unix_socket auth):

| Jenis | Method | Subcommand |
|---|---|---|
| Database | `backupDatabase($dbName, $userId)` | `mysqldump-db` |
| Website | `backupWebsite($domain, $userId)` | `backup-tar-website` |
| Aplikasi Node.js | `backupNodeApp($appName, $userId)` | `backup-tar-nodeapp` |

`restore($backupId, $userId)` — **sebelum** melakukan restore, panel
otomatis membuat backup baru dari kondisi saat itu terlebih dahulu,
sehingga restore selalu bisa dibatalkan/diulang. Semua file backup
tersimpan di `/opt/server-panel/storage/backups/`, path selalu divalidasi
`require_path_within()` di sisi bash agar tidak bisa keluar dari direktori
itu.

## Log

`LogService` — tail (bukan `tail -f`, snapshot statis, maks baris dipaksa
server-side) untuk beberapa sumber log lewat whitelist `logkey` di
`panel-exec.sh` (`log-tail`/`log-clear`):

- `nginxAccess($domain)` / `nginxError($domain)` — `/var/log/nginx/
  {domain}-access.log` / `-error.log`.
- `phpFpmError($phpVersion)` — `/var/log/php{version}-fpm.log` (versi
  dibatasi whitelist 7.4–8.4).
- `deploymentLog()` — `/var/log/yuuka-installer/deployment.log`.
- `panelAppLog()` — log aplikasi panel sendiri (`app-error.log` di
  `storage/logs/`, dibaca langsung karena berada dalam `open_basedir`
  panel, tidak perlu lewat `panel-exec.sh`).

## Cloudflare Tunnel

Lihat halaman khusus: [Cloudflare Tunnel](Cloudflare-Tunnel.md).

## Manajemen User

`UserService`, permission `users.manage` (admin only):

- `create($username, $email, $password, $role, $actingUserId)` — password
  di-hash `PASSWORD_BCRYPT`.
- `changeRole($userId, $role, $actingUserId)`, `setActive($userId, $active,
  $actingUserId)` (nonaktifkan tanpa hapus), `changePassword(...)`,
  `delete($userId, $actingUserId)`.

Semua aksi di atas dicatat ke `activity_log`. Untuk skenario "lupa
password saat tidak bisa login sama sekali", lihat
[Pemulihan Akun Admin](Pemulihan-Akun-Admin.md) (dilakukan langsung lewat
database, bukan lewat UI ini).

## Pengaturan

Key/value sederhana di tabel `settings` (lihat
[Skema Database](Skema-Database.md#settings)):
`deployment_mode`, `cpu_alert_threshold`, `mem_alert_threshold`,
`restart_alert_threshold` — dipakai Dashboard untuk menentukan ambang
warna/alert monitoring.
