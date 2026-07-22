# Arsitektur

[← Kembali ke Home](Home.md)

## Tiga Pilar Desain

1. **Nginx adalah satu-satunya web server / reverse proxy.**
   Semua request HTTP(S) — website PHP, aplikasi Node.js, phpMyAdmin, dan
   panel itu sendiri — masuk lewat Nginx. Apache tidak pernah diinstall.

2. **PM2 adalah satu-satunya process manager Node.js.**
   Tidak ada aplikasi yang dijalankan dengan `nohup`, `screen`,
   `node app.js &`, atau systemd unit per-aplikasi. Semua status runtime
   (CPU, RAM, uptime, restart count, status) dibaca langsung dari
   `pm2 jlist` — database panel (tabel `nodejs_apps`) **hanya** menyimpan
   metadata (nama, domain, path, port), bukan status runtime. Kolom
   `last_known_status` di tabel itu murni historis/audit, bukan sumber
   kebenaran (lihat [Skema Database](Skema-Database.md)).

3. **Satu jembatan privilese tunggal.**
   Panel PHP berjalan sebagai user sistem tanpa privilese (`panel`),
   terisolasi dari website (`www-data`) dan dari proses Node.js
   (`nodeapps`). Semua operasi yang butuh root (menulis config Nginx,
   mengelola PM2 sebagai user lain, menerbitkan SSL, dump database, dll)
   **hanya** bisa lewat satu script root-owned:
   `/opt/server-panel/scripts/panel-exec.sh`, dipanggil lewat satu baris
   sudoers `NOPASSWD` yang membatasi user `panel` HANYA menjalankan script
   itu. Detail lengkap di [Model Keamanan](Keamanan.md) dan
   [Referensi panel-exec.sh](Panel-Exec-Reference.md).

## Struktur Direktori Repo

```
yuuka_native-panel/
├── install.sh                  # Orchestrator utama: sudo bash install.sh
├── modules/                    # Modul installer (bash), masing-masing idempotent
│   ├── lib.sh                  # Logging, warna CLI, helper idempotency, backup config
│   ├── system.sh               # Cek Ubuntu, update, dependency, user sistem (panel, nodeapps)
│   ├── mariadb.sh              # Install + secure MariaDB, akun panel_app & panel_provisioner
│   ├── nginx.sh                # Install Nginx, snippet bersama (security header, proxy, ACME)
│   ├── php.sh                  # Install PHP 7.4-8.4 (PPA ondrej/php), pool tuning per versi
│   ├── nodejs.sh                # NVM + Node 18/20/22 + PM2, semuanya di bawah user nodeapps
│   ├── phpmyadmin.sh            # Install phpMyAdmin manual (tanpa Apache), config Nginx
│   ├── ssl.sh                    # Certbot (webroot mode) + auto-renewal
│   ├── cloudflare.sh             # cloudflared opsional, token disimpan permission 600
│   └── panel.sh                   # Deploy panel, pool PHP-FPM khusus, sudoers, admin pertama
├── sql/schema.sql              # Skema database panel (server_panel)
├── nginx-templates/            # Contoh output konfigurasi Nginx (referensi/dokumentasi)
└── panel-src/                  # Source code panel, di-deploy ke /opt/server-panel
    ├── bootstrap.php            # Wiring: config, session aman, autoloader
    ├── .env.example
    ├── public/                   # Document root Nginx (satu-satunya folder yang exposed)
    │   ├── index.php, login.php, dashboard.php, websites.php, nodejs.php, ...
    │   ├── nodejs_env.php, nodejs_logs.php, nodejs_health.php
    │   ├── ajax_stats.php, ajax_pm2.php   # endpoint polling live (JSON)
    │   ├── assets/{css,js}/
    │   └── partials/{header,sidebar,footer,flash}.php
    ├── app/
    │   ├── config/      # Config.php (.env loader), database.php (2 koneksi PDO)
    │   ├── controllers/ # sengaja kosong - lihat catatan di bawah
    │   ├── services/    # Business logic + validasi + RBAC + audit log
    │   └── helpers/     # Auth, Csrf, Rbac, Validator, response helpers
    ├── scripts/         # panel-exec.sh (root-owned) + health_check_runner.php (cron)
    └── storage/
        ├── logs/        # executor.log, app-error.log, cron-panel-*.log, dst
        ├── backups/     # Hasil backup database/website/aplikasi
        └── sessions/    # Session PHP native (bukan di /tmp bersama)
```

## Kenapa `app/controllers/` Kosong

Ini bukan bug atau bagian yang belum selesai. Setiap halaman di `public/*.php`
sudah berperan sebagai controller tipis: validasi request → panggil method
`Service` → render view di file yang sama. Menambahkan lapisan `Controller`
terpisah untuk setiap halaman hanya akan menjadi boilerplate kosong
(constructor yang memanggil satu service lalu include satu view) tanpa
manfaat nyata pada skala aplikasi ini. Folder tetap ada agar strukturnya
sesuai spesifikasi dan siap dipakai bila kompleksitas bertambah nanti.

## Dua Koneksi Database (PDO)

`panel-src/app/config/database.php` membuka dua koneksi PDO berbeda:

| Koneksi | User MariaDB | Privilese | Dipakai untuk |
|---|---|---|---|
| App | `panel_app` | Akses penuh **hanya** ke database `server_panel` | Semua query CRUD panel sehari-hari |
| Provisioner | `panel_provisioner` | `CREATE`/`DROP`/`CREATE USER`/`GRANT` | Membuat database & user MariaDB baru untuk tenant (menu Database) |

Pemisahan ini membatasi blast radius: kalau ada bug SQL injection di query
sehari-hari, koneksi `panel_app` tidak punya privilese untuk membuat/menghapus
database lain di server.

## Simplifikasi yang Disengaja

Beberapa hal disederhanakan secara sadar untuk menjaga codebase tetap bisa
dipahami & diaudit, bukan karena keterbatasan teknis:

- Tidak ada layer ORM/query builder — PDO prepared statement langsung,
  cukup untuk skema yang tidak terlalu kompleks ini.
- `app/controllers/` kosong by design (lihat penjelasan di atas).
- Health check HTTP dijalankan oleh cron `* * * * *` yang memanggil
  `scripts/health_check_runner.php` (bukan daemon terpisah) — cukup untuk
  interval minimum 10 detik yang didukung UI.
- phpMyAdmin diinstall manual dari tarball resmi (bukan `apt install
  phpmyadmin`) khusus untuk menghindari dependency Apache/dbconfig-common
  bawaan paket Ubuntu.
