# Folder database

Berisi rancangan database (SQL) supaya datamu bisa dicek, dicadangkan, dan dipindahkan kapan saja.

| File | Untuk apa |
| --- | --- |
| `schema-mysql.sql` | Struktur lengkap untuk **MySQL / MariaDB** (hosting seperti InfinityFree atau cPanel). Impor lewat phpMyAdmin bila ingin membuat tabel manual. |
| `schema-sqlite.sql` | Struktur lengkap untuk **SQLite**, dipakai preview lokal (`storage/rumahku.sqlite`) dan alat seperti DB Browser for SQLite. |
| `upgrade-mysql.sql` | Pembaruan untuk database lama: menambah tabel `attachments` (bukti gambar), tabel `wa_contacts` (nomor bot WhatsApp), dan kolom pengingat yang sempat belum ada. Aman dijalankan berulang. |

## Apakah harus dijalankan manual?

Tidak. Aplikasi membuat dan memperbarui tabelnya sendiri setiap kali dibuka
(lihat `includes/app.php`, fungsi `app_create_schema()` dan `app_schema_statements()`).
File SQL di folder ini adalah cerminan dari kode tersebut, gunakan kalau:

- ingin membuat database lewat phpMyAdmin sebelum website diunggah, atau
- ingin memastikan instalasi lama sudah punya semua tabel/kolom terbaru, atau
- ingin melihat sendiri struktur tabel dan tipenya.

## Di mana datanya tersimpan?

- **Di hosting:** tabel MySQL di database yang dipilih pada `config.php`
  (`dsn`, `db_user`, `db_password`). Semua catatan uang masuk/keluar, pengingat,
  target bulanan, akun, dan gambar bukti tersimpan di sana.
- **Di preview lokal (komputer):** file SQLite `storage/rumahku.sqlite`.
  File ini otomatis diperbarui oleh server preview dan diabaikan Git.

Backup bisa diunduh dari menu **Pengaturan → Backup & database** sebagai file `.sql`
yang siap diimpor kembali ke phpMyAdmin.
