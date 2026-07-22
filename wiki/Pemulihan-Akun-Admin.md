# Pemulihan Akun Admin

[← Kembali ke Home](Home.md)

Tidak ada tool CLI bawaan untuk reset password (`grep` seluruh repo untuk
`reset`/`seed_admin` tidak menemukan apa pun) — akun admin pertama hanya
dibuat sekali saat instalasi (`module_panel_create_admin()` di
`modules/panel.sh`, dijaga pengecekan `COUNT(*) FROM panel_users`, tidak
bisa dipicu ulang lewat installer). Kalau lupa username/password, cara
paling aman adalah langsung lewat database di server — root MariaDB bisa
akses tanpa password lewat `unix_socket` secara lokal.

> Prosedur ini persis meniru apa yang dilakukan `module_panel_create_admin`
> saat instalasi pertama kali: `php -r 'password_hash(...)'` lalu `INSERT`/
> `UPDATE` lewat `mysql -u root`.

## 1. Cari Nama Database

```bash
sudo cat /opt/server-panel/.env | grep DB_DATABASE
```

## 2. Lihat Username yang Ada

```bash
sudo mysql -u root <nama_database> -e "SELECT id, username, email, role, is_active FROM panel_users;"
```

## 3. Buat Hash Bcrypt untuk Password Baru

```bash
php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT), PHP_EOL;' 'PasswordBaruYangKuat!'
```
Hasilnya diawali `$2y$12$...` (60 karakter).

> **Perhatian**: hash ini mengandung tanda `$`. Jangan pernah bungkus
> dengan *double quotes* di langkah berikutnya — lihat penjelasan lengkap
> di [Troubleshooting](Troubleshooting.md#query-sql-yang-mengandung-hash-bcrypt-2y12-rusak-lewat-shell).

## 4. Update Password (Cara Aman)

Gunakan heredoc dengan delimiter **ber-quote** (`'SQL'`) — ini mencegah
bash meng-expand tanda `$` di dalam hash:

```bash
mysql -u root <nama_database> <<'SQL'
UPDATE panel_users SET password_hash='<hash_dari_langkah_3>' WHERE username='<username>';
SQL
```

Kalau akun ternyata `is_active = 0` (terlihat di langkah 2), aktifkan
sekalian:
```bash
mysql -u root <nama_database> <<'SQL'
UPDATE panel_users SET password_hash='<hash>', is_active=1 WHERE username='<username>';
SQL
```

## 5. Verifikasi

```bash
mysql -u root <nama_database> -e "SELECT username, password_hash, is_active FROM panel_users WHERE username='<username>';"
```
Pastikan `password_hash` persis 60 karakter, tidak terpotong, diawali
`$2y$12$`.

## 6. (Opsional) Bersihkan Rate Limit Login

Kalau sebelumnya sempat berkali-kali salah login sampai kena rate limit
(5x gagal / 15 menit, lihat [Model Keamanan](Keamanan.md)):
```bash
mysql -u root <nama_database> -e "DELETE FROM login_attempts WHERE username='<username>';"
```

Setelah langkah 4 selesai, langsung bisa login — tidak perlu restart PHP-FPM
atau apa pun, panel membaca ulang dari database di setiap request.

## Kalau Tidak Ada Akun Admin Sama Sekali

Kalau tabel `panel_users` benar-benar kosong (bukan sekadar lupa
password), buat baris baru langsung:

```bash
HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT), PHP_EOL;' 'PasswordKuat!')
mysql -u root <nama_database> <<SQL
INSERT INTO panel_users (username, email, password_hash, role, is_active, created_at)
VALUES ('admin', 'admin@example.com', '${HASH}', 'admin', 1, NOW());
SQL
```

Perhatikan di sini heredoc **tidak** di-quote (`SQL` tanpa kutip) supaya
`${HASH}` (variabel shell, bukan bagian dari hash) tetap di-expand — hash
bcrypt-nya sendiri sudah aman karena disimpan utuh di variabel `$HASH`
sebelum masuk heredoc, bukan diketik ulang manual.
