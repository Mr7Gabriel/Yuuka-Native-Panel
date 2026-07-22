# Mode Deployment & Cloudflare Tunnel

[← Kembali ke Home](Home.md)

## Tiga Mode Deployment

Dipilih saat instalasi (bisa diubah lewat re-run installer):

1. **Direct** — Nginx + IP publik + Let's Encrypt.
2. **Tunnel** — Cloudflare Tunnel saja, tidak ada port publik yang dibuka
   untuk panel/aplikasi (origin `127.0.0.1`). SSL publik (Certbot) dilewati
   karena Cloudflare menangani TLS di edge.
3. **Hybrid** — Nginx tetap publik, Cloudflare Tunnel tersedia sebagai jalur
   tambahan.

Cloudflare Tunnel murni **network ingress** — autentikasi panel tetap wajib
berjalan di lapisan aplikasi (login, RBAC, dst tidak berubah). Untuk
keamanan tambahan opsional, aktifkan **Cloudflare Access** di Zero Trust
Dashboard di depan tunnel (bukan dependency wajib, tidak dikonfigurasi
otomatis oleh installer ini).

## Cara Kerja cloudflared di Server Ini

Modul `modules/cloudflare.sh` men-setup cloudflared sebagai **token-based
tunnel** (dibuat lewat Zero Trust Dashboard: *Networks > Tunnels > Create a
tunnel > Token*), bukan `cloudflared tunnel login` (mode cert.pem lokal).

- **Token disimpan** di `/etc/cloudflared/tunnel.env` (permission `600`,
  owner root), dalam format `KEY=VALUE`:
  ```
  TUNNEL_TOKEN=eyJhIjoi...
  ```
  Ditulis oleh `module_cloudflare_store_token()`. Token **tidak pernah**
  dicatat ke log, tidak pernah masuk database, tidak pernah ditampilkan di
  UI panel (lihat `CloudflareService.php` — hanya membaca status via
  `systemctl`, tidak pernah membaca isi token).
- **Systemd unit** `/etc/systemd/system/cloudflared.service` memuat token
  lewat `EnvironmentFile=/etc/cloudflared/tunnel.env` + `ExecStart=
  /usr/bin/cloudflared --no-autoupdate tunnel run` (tanpa argumen
  `--token`). cloudflared membaca token dari environment variable
  `TUNNEL_TOKEN` secara native.

  > Sebelumnya (versi lama) token disisipkan langsung di `ExecStart=` lewat
  > `--token $(cat tunnel.token)`. Ini **rusak** karena `ExecStart=` di
  > systemd tidak dieksekusi lewat shell — `$(cat ...)` tidak pernah
  > di-expand, systemd malah mencoba menafsirkannya sebagai specifier-nya
  > sendiri. `EnvironmentFile=` + `tunnel run` polos adalah mekanisme yang
  > benar.
- **Ingress rules** (hostname → service lokal mana) untuk tunnel berbasis
  token dikonfigurasi **di Cloudflare Zero Trust Dashboard**
  (*Networks > Tunnels > [tunnel] > Public Hostname*), **bukan** file lokal
  `config.yml` — installer ini tidak menulis ingress rules apa pun secara
  otomatis. Kalau domain tidak bisa diakses walau tunnel status "Healthy",
  cek dulu apakah Public Hostname sudah dikonfigurasi di dashboard dan DNS
  CNAME domain sudah mengarah ke `<tunnel-id>.cfargotunnel.com`.

## Kontrol dari Panel

Menu **Cloudflare Tunnel** di sidebar panel (permission: `monitoring.view`
untuk lihat status, aksi start/stop/restart lewat `CloudflareService.php`)
membaca/mengontrol cloudflared lewat `Executor` → `panel-exec.sh` (lihat
[Referensi panel-exec.sh](Panel-Exec-Reference.md#daftar-subcommand)):

| Subcommand `panel-exec.sh` | Fungsi |
|---|---|
| `cloudflared-status` | `systemctl is-active cloudflared` |
| `cloudflared-start` / `-stop` / `-restart` | Kontrol service |
| `cloudflared-version` | `cloudflared --version` (dipakai juga untuk deteksi "terinstall atau tidak") |

Panel **tidak pernah** membaca isi `/etc/cloudflared/tunnel.env` secara
langsung — pool PHP-FPM panel `open_basedir`-nya terkunci ke
`/opt/server-panel:/tmp:/proc`, jadi status selalu diperoleh lewat
`Executor` yang berjalan sebagai root melalui `panel-exec.sh`.

## Diagnosa Cepat

```bash
sudo systemctl status cloudflared --no-pager
sudo journalctl -u cloudflared -n 80 --no-pager
```

Yang perlu diperhatikan di output:

- **Command line di bagian `CGroup:`** — kalau masih menunjukkan
  `--token eyJ...` di ExecStart, berarti proses yang jalan sekarang masih
  pakai konfigurasi lama (perlu `systemctl restart cloudflared` supaya
  proses baru dimulai dengan `EnvironmentFile=`).
- **Blok `CONNECTIVITY PRE-CHECKS`** di journalctl — kalau semua `PASS`,
  server tidak punya masalah jaringan keluar ke Cloudflare edge sama
  sekali; kalau tunnel tetap "Waiting for your Tunnel to connect..." di
  dashboard padahal precheck semua PASS, curigai proses lama yang belum
  di-restart (lihat [Troubleshooting](Troubleshooting.md#cloudflared-aktif-tapi-tunnel-tidak-connect-di-dashboard)).
- **`tunnelID=` di baris "Starting tunnel"** — cocokkan dengan tunnel ID
  yang kamu buat di Zero Trust Dashboard untuk domain yang dituju. Server
  bisa saja punya cloudflared yang terhubung sempurna, tapi ke tunnel yang
  **salah** (token lama/tunnel lain yang tidak sengaja masih terpasang).
