# RBAC & Role

[← Kembali ke Home](Home.md)

4 role, permanen didefinisikan di `panel-src/app/helpers/rbac.php`. Admin
**selalu** lolos semua permission check (`Rbac::can()` short-circuit untuk
role admin) — matriks di bawah berlaku untuk role non-admin.

## Ringkasan per Role

| Role | Akses |
|---|---|
| **Admin** | Penuh — termasuk manajemen user, pengaturan, Cloudflare Tunnel. |
| **Operator** | Kelola website, aplikasi Node.js, database, domain, SSL, backup — tidak bisa mengelola user panel atau pengaturan server. |
| **Developer** | Deploy/kontrol aplikasi Node.js (start/stop/restart/reload), kelola environment variable, lihat log, kelola cron — tidak bisa hapus website/aplikasi/database. |
| **Viewer** | Hanya melihat status & monitoring. |

## Matriks Permission Lengkap

Sumber: `panel-src/app/helpers/rbac.php:16-48`.

| Permission | Admin | Operator | Developer | Viewer |
|---|:---:|:---:|:---:|:---:|
| `server.manage_configuration` | ✅ | | | |
| `users.manage` | ✅ | | | |
| `settings.manage` | ✅ | | | |
| `cloudflare.manage` | ✅ | | | |
| `website.create` | ✅ | ✅ | | |
| `website.delete` | ✅ | ✅ | | |
| `website.toggle` | ✅ | ✅ | | |
| `website.view` | ✅ | ✅ | ✅ | ✅ |
| `nodejs.create` | ✅ | ✅ | ✅ | |
| `nodejs.delete` | ✅ | ✅ | | |
| `nodejs.control` | ✅ | ✅ | ✅ | |
| `nodejs.env.manage` | ✅ | ✅ | ✅ | |
| `nodejs.view` | ✅ | ✅ | ✅ | ✅ |
| `nodejs.logs.view` | ✅ | ✅ | ✅ | |
| `database.manage` | ✅ | ✅ | | |
| `database.view` | ✅ | ✅ | ✅ | ✅ |
| `domain.manage` | ✅ | ✅ | | |
| `ssl.manage` | ✅ | ✅ | | |
| `backup.manage` | ✅ | ✅ | | |
| `backup.view` | ✅ | ✅ | ✅ | ✅ |
| `cron.manage` | ✅ | ✅ | ✅ | |
| `cron.view` | ✅ | ✅ | ✅ | ✅ |
| `logs.view` | ✅ | ✅ | ✅ | |
| `monitoring.view` | ✅ | ✅ | ✅ | ✅ |

> Catatan: `nodejs.delete` diizinkan untuk Operator tapi **bukan**
> Developer — konsisten dengan ringkasan role ("Developer ... tidak bisa
> hapus website/aplikasi/database").

## Cara Kerja di Kode

```php
Rbac::require('database.manage');   // redirect ke /login.php kalau belum login,
                                     // 403 + flash error + redirect ke dashboard kalau tidak berizin
```

`Rbac::can($role, $permission)` dipakai juga di `partials/sidebar.php` untuk
menyembunyikan menu yang tidak relevan bagi role user yang sedang login —
tapi ini **hanya UI**, penegakan sesungguhnya selalu lewat `Rbac::require()`
di setiap endpoint yang mengubah state.

## Menambah Role/Permission Baru

Perubahan cukup di satu tempat: array `$matrix` di
`panel-src/app/helpers/rbac.php`. Tidak ada tabel `roles`/`permissions` di
database — role disimpan sebagai `ENUM('admin','operator','developer',
'viewer')` langsung di kolom `panel_users.role` (lihat
[Skema Database](Skema-Database.md)), jadi menambah role baru juga perlu
migrasi `ALTER TABLE` untuk memperluas `ENUM` tersebut.
