# Troubleshooting

[← Kembali ke Home](Home.md)

Kumpulan masalah nyata yang pernah ditemui di proyek ini beserta akar
masalah dan cara diagnosanya. Ditambahkan berdasarkan insiden aktual, bukan
teori — kalau menemukan kasus baru, tambahkan pola yang sama di sini.

## `Service cloudflared tidak ditemukan` langsung disusul `aktif dan terhubung`

**Gejala**: log installer menampilkan `[WARN] Service cloudflared tidak
ditemukan` lalu tepat setelahnya `[OK] cloudflared service aktif dan
terhubung` — dua pesan yang saling kontradiktif.

**Akar masalah**: `service_enable_now()` lama di `modules/lib.sh` memakai
`service_exists()` (`systemctl list-unit-files | grep ...`) sebagai gerbang
sebelum `enable`+`start`. Tepat setelah unit file baru ditulis +
`daemon-reload`, `list-unit-files` bisa false-negative — sehingga
`enable`/`start` di-skip diam-diam. Pesan "aktif dan terhubung" yang
muncul setelahnya berasal dari pengecekan `is-active` yang **terpisah**,
yang kebetulan true karena proses lama (dari instalasi sebelumnya) masih
berjalan — bukan bukti bahwa config baru sudah diterapkan.

**Fix**: `service_enable_now()` sekarang langsung mencoba `systemctl
enable` sebagai satu-satunya sumber kebenaran (bukan `service_exists`
dulu). Kalau `enable` gagal, baru dianggap "tidak ditemukan".

## cloudflared aktif tapi tunnel tidak connect di dashboard

**Gejala**: `systemctl status cloudflared` menunjukkan `active (running)`,
tapi Cloudflare Zero Trust Dashboard tetap menampilkan **"Waiting for your
Tunnel to connect..."** untuk tunnel yang baru dibuat.

**Cara diagnosa**:
```bash
sudo systemctl status cloudflared --no-pager   # lihat baris ExecStart di bagian CGroup
sudo journalctl -u cloudflared -n 80 --no-pager
```

**Akar masalah yang ditemukan**: `systemctl start` adalah **no-op** kalau
service sudah berstatus `active`. Jadi kalau `install.sh` dijalankan ulang
dan menulis ulang unit file (token/config baru) sambil cloudflared versi
LAMA masih berjalan (dari instalasi sebelumnya, atau tunnel lain yang
tidak terkait), `daemon-reload` **tidak** merestart proses yang sedang
berjalan — hanya memperbarui definisi unit di systemd. Proses lama tetap
hidup memakai token/tunnel yang lama. Tandanya persis:
```
systemd[1]: cloudflared.service: Current command vanished from the unit
file, execution of the command list won't be resumed.
```
Baris `ExecStart` di output `systemctl status` (bagian `CGroup:`) akan
menunjukkan command line **lama** (mis. `--token eyJ...` tertanam
langsung), bukan `EnvironmentFile=` + `tunnel run` polos yang seharusnya.

**Fix permanen**: `service_enable_now()` di `modules/lib.sh` sekarang
memakai `systemctl restart` (bukan `start`) kalau service sudah aktif,
supaya config baru selalu benar-benar diterapkan.

**Fix cepat manual** (tanpa perlu redeploy dulu):
```bash
sudo systemctl restart cloudflared
sudo systemctl status cloudflared --no-pager   # pastikan ExecStart sudah benar & tunnelID sesuai
```

Lihat juga [Cloudflare Tunnel](Cloudflare-Tunnel.md) untuk cara kerja
lengkap token-based tunnel di proyek ini.

## `mysql` menampilkan usage help, bukan hasil query

**Gejala**: menjalankan `mysql -u root <db> "UPDATE ...;"` malah
menampilkan teks bantuan `Usage: mysql [OPTIONS] [database]`, bukan hasil
query.

**Akar masalah**: lupa flag `-e`. Tanpa `-e`, string SQL dibaca sebagai
argumen **database kedua**, bukan sebagai query — `mysql` gagal parse
argumen dan menampilkan usage help, tanpa pernah terkoneksi ke database.

**Fix**: selalu sertakan `-e "..."`, atau untuk query yang mengandung
karakter `$` (lihat kasus berikut), gunakan heredoc.

## Query SQL yang mengandung hash bcrypt (`$2y$12$...`) rusak lewat shell

**Gejala**: `UPDATE panel_users SET password_hash='$2y$12$...' WHERE ...`
terlihat berhasil dijalankan (tidak ada error), tapi login tetap gagal
dengan password baru.

**Akar masalah**: hash bcrypt PHP selalu mengandung tanda `$` (format
`$2y$12$<22 karakter salt><31 karakter hash>`). Kalau query dibungkus
**double quotes** (`"..."`) di bash, `$2y`, `$12`, dan `$<salt>` di-expand
sebagai variabel shell (kebanyakan tidak terdefinisi → jadi string kosong)
**sebelum** perintah sampai ke `mysql` — hash yang tersimpan jadi rusak
walau tidak ada pesan error sama sekali.

**Fix**: jangan pernah bungkus hash bcrypt dengan double quotes di bash.
Gunakan heredoc dengan delimiter yang di-quote (mencegah ekspansi apa pun
di dalamnya):
```bash
mysql -u root <db> <<'SQL'
UPDATE panel_users SET password_hash='$2y$12$...' WHERE username='...';
SQL
```
Verifikasi hasilnya persis (58 karakter, tidak terpotong):
```bash
mysql -u root <db> -e "SELECT username, password_hash FROM panel_users WHERE username='<username>';"
```

Lihat [Pemulihan Akun Admin](Pemulihan-Akun-Admin.md) untuk prosedur
lengkap reset password.

## Instalasi terhenti tidak terduga

`install.sh` men-trap error apa pun (`set -uo pipefail` + `trap ... ERR`)
dan menunjuk ke log lengkap: `/var/log/yuuka-installer/`. Selalu cek log
ini dulu — biasanya baris terakhir sebelum trap terpicu sudah cukup
menunjukkan modul dan perintah mana yang gagal.

## Perubahan kode tidak terlihat di server setelah edit lokal

Ingat: repo lokal (tempat kamu edit) dan server adalah dua checkout
terpisah. Perubahan baru berlaku di server setelah:
1. Kode benar-benar dipindahkan ke server (`git pull` setelah commit+push,
   atau `rsync`/`scp` manual).
2. Untuk file PHP (`panel-src/`): re-run `install.sh` (tahap 9 me-rsync
   ulang) atau rsync manual — langsung aktif tanpa restart apa pun.
3. Untuk modul shell (`modules/*.sh`): hanya berpengaruh kalau tahap
   instalasi terkait dijalankan ulang secara eksplisit. Lihat
   [Re-run untuk Update](Instalasi.md#re-run-untuk-update).
