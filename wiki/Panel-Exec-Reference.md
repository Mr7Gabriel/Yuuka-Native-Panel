# Referensi `panel-exec.sh`

[← Kembali ke Home](Home.md)

`/opt/server-panel/scripts/panel-exec.sh` adalah **satu-satunya** jembatan
privilese antara panel (user `panel`, tanpa privilese) dan operasi
level-root. Lihat [Model Keamanan](Keamanan.md) untuk prinsip desainnya.
Dipanggil dari PHP lewat `Executor::run($subcommand, $args, $stdin,
$timeout)` → `sudo -n panel-exec.sh <subcommand> [args...]`.

Setiap pemanggilan (sukses maupun ditolak) dicatat ke
`/opt/server-panel/storage/logs/panel-exec-audit.log`.

## Daftar Subcommand

| Subcommand | Argumen | STDIN | Fungsi |
|---|---|---|---|
| `nginx-test` | – | – | `nginx -t` |
| `nginx-reload` | – | – | `nginx -t` lalu `systemctl reload nginx` |
| `nginx-write-config` | `<site>` | isi config | Tulis `sites-available/<site>.conf`, validasi `nginx -t`, rollback otomatis ke backup kalau invalid |
| `nginx-enable` | `<site>` | – | Symlink ke `sites-enabled`, validasi, reload |
| `nginx-disable` | `<site>` | – | Hapus symlink `sites-enabled`, reload |
| `nginx-delete` | `<site>` | – | Hapus config available+enabled, reload |
| `pm2-deploy` | `<app>` | isi `ecosystem.config.js` | Tulis ecosystem file di bawah `nodeapps`, `pm2 start --update-env`, `pm2 save` |
| `pm2-start` / `pm2-stop` / `pm2-restart` / `pm2-reload` | `<app>` | – | Kontrol proses PM2 (sebagai user `nodeapps`) |
| `pm2-delete` | `<app>` | – | `pm2 delete` + `pm2 save` |
| `pm2-jlist` | – | – | `pm2 jlist` — sumber kebenaran status runtime Node.js |
| `pm2-describe` | `<app>` | – | `pm2 describe` |
| `pm2-logs` | `<app>` `[lines]` | – | `pm2 logs --nostream`, maks 1000 baris dipaksa server |
| `pm2-flush` | `<app>` | – | Bersihkan log PM2 aplikasi |
| `pm2-save` | – | – | `pm2 save` |
| `certbot-issue` | `<domain>` `<email>` | – | `certbot certonly --webroot` |
| `certbot-remove` | `<domain>` | – | `certbot delete --cert-name` |
| `service-status` | `<svc>` | – | `systemctl is-active` — whitelist: `nginx`, `mariadb`, `cloudflared`, `php{7.4-8.4}-fpm` |
| `mysqldump-db` | `<db>` `<outfile>` | – | `mysqldump --single-transaction --routines --triggers -u root`, output dikunci di `storage/backups` |
| `mysql-restore-db` | `<db>` `<infile>` | – | `mysql -u root <db> < infile` |
| `cloudflared-status` | – | – | `systemctl is-active cloudflared` |
| `cloudflared-start` / `-stop` / `-restart` | – | – | Kontrol service cloudflared |
| `cloudflared-version` | – | – | `cloudflared --version` |
| `disk-usage` | – | – | `df` untuk `/` (panel tidak bisa panggil `disk_total_space()` sendiri karena `open_basedir` tidak termasuk `/`) |
| `fs-mkdir-website` | `<domain>` | – | Buat `/var/www/<domain>/public`, chown `www-data` |
| `fs-remove-website` | `<domain>` | – | Hapus `/var/www/<domain>` (menolak menghapus base dir itu sendiri) |
| `fs-remove-nodeapp` | `<app>` | – | Hapus `/home/nodeapps/apps/<app>` |
| `port-check` | `<port>` | – | Cek port sedang listening atau bebas (`ss -ltn`) |
| `backup-tar-website` | `<domain>` `<outfile>` | – | `tar czf` folder website ke `storage/backups` |
| `backup-tar-nodeapp` | `<app>` `<outfile>` | – | `tar czf` folder aplikasi Node.js |
| `restore-tar-website` | `<infile>` `<domain>` | – | Extract tar ke `/var/www`, chown `www-data` |
| `restore-tar-nodeapp` | `<infile>` `<app>` | – | Extract tar ke `/home/nodeapps/apps`, chown `nodeapps` |
| `cron-write` | `<jobid>` (`panel-<id>`) | isi file cron | Tulis `/etc/cron.d/<jobid>` |
| `cron-delete` | `<jobid>` | – | Hapus file cron |
| `log-tail` | `<logkey>` `[lines]` | – | Tail log, whitelist logkey, maks 2000 baris |
| `log-clear` | `<logkey>` | – | Kosongkan log (hanya `nginx-access:*` / `nginx-error:*`) |

Subcommand di luar daftar ini **selalu** ditolak (`exit 2`), tidak peduli
argumen apa pun yang diberikan.

## Pola Validasi Argumen

Semua argumen dicocokkan ke salah satu regex tetap sebelum dipakai
(`require_match`):

| Nama | Regex | Dipakai untuk |
|---|---|---|
| `RE_SITENAME` | `^[a-zA-Z0-9._-]{1,200}$` | Nama file config Nginx |
| `RE_APPNAME` | `^[a-zA-Z0-9_-]{1,64}$` | Nama aplikasi Node.js / PM2 |
| `RE_DOMAIN` | `^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$` | Nama domain |
| `RE_EMAIL` | `^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$` | Email (Certbot) |
| `RE_DBNAME` | `^[a-zA-Z0-9_]{1,64}$` | Nama database |
| `RE_LINES` | `^[0-9]{1,4}$` | Jumlah baris log |
| `RE_PORT` | `^[0-9]{1,5}$` | Nomor port |
| `RE_CRONID` | `^panel-[0-9]+$` | ID file cron |

Path file/direktori selalu ditambah pengecekan `require_path_within()`:
`realpath -m` hasilnya harus berada tepat di bawah base directory tetap
(`/var/www`, `/home/nodeapps/apps`, `storage/backups`, dst) — kalau tidak,
langsung ditolak, tidak peduli apakah regex nama-nya lolos.

## Menambah Subcommand Baru

Kalau perlu menambah operasi privileged baru:

1. Tulis fungsi `op_xxx()` baru mengikuti pola validasi di atas — **selalu**
   validasi argumen dulu sebelum dipakai, jangan pernah interpolasi
   variabel yang belum divalidasi ke command yang dieksekusi.
2. Tambahkan satu baris ke blok `case` dispatch di paling bawah file.
3. Pastikan pemanggil di PHP (`Executor::run()`) juga memvalidasi argumen
   yang sama di sisi PHP (`Validator` class) — validasi dua lapis adalah
   prinsip desain yang tidak boleh dilewati (lihat
   [Model Keamanan](Keamanan.md)).
