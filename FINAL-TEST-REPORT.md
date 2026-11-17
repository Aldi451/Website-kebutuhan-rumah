# Hasil pengujian akhir

Branch: `arena/db5205e2-website-kebutuhan-rumah`.

**Kesimpulan:** seluruh pemeriksaan lokal yang dijalankan lulus. Paket konsisten dengan sumber dan siap untuk uji deployment/staging. Ini bukan sertifikasi 100% aman atau bukti seluruh patch sudah aktif di produksi.

## 1. Tes lokal — 9 suite lulus

| Suite | Cakupan utama |
| --- | --- |
| test-assessment-remediation.mjs | Header, kontak keamanan, validasi HTTPS, metode HTTP, respons error |
| test-backup-upload.mjs | Encoding backup reversibel, karakter khusus, batas piksel/memori, validasi gambar |
| test-calendar.mjs | URL kalender manual dan zona waktu |
| test-google-calendar.mjs | Konfigurasi/OAuth/event Google dengan fixture |
| test-import-security.mjs | Batas ekstraksi ZIP, metadata ukuran palsu, DTD |
| test-notification-limits.mjs | Kuota pengguna/rumah/background, cooldown, 429, reminder tetap tersimpan |
| test-report.mjs | Tanggal acuan, filter lengkap, respons terlambat, ringkasan dan CSV |
| test-security.mjs | Login, CSRF, pembatas login, pencabutan sesi, otorisasi objek antaranggota |
| test-webhook-security.mjs | Tidak ada isi laporan di debug webhook, validasi secret/input, batas body, status pengiriman |

Tambahan:
- Parsing sintaks PHP dengan `TOKEN_PARSE`: **12 file lulus**.
- `node --check` untuk `assets/*.js`, `server.mjs`, dan `scripts/*.mjs`: lulus.
- `git diff --check`: lulus.
- `npm audit`: **0 kerentanan yang dilaporkan** pada dependency saat pemeriksaan; bukan audit kode PHP/hosting dan bukan jaminan tidak ada kerentanan yang belum dikenal.

Tes memakai fixture lokal PHP-WASM/SQLite dan pengujian helper JavaScript. Tidak ada pengiriman WhatsApp nyata, akses kalender Google pengguna, atau perubahan data produksi. Tes UI menggunakan stub/helper, bukan pengujian seluruh interaksi browser nyata.

## 2. Paket deployment

`update/website-update.zip`:
- CRC arsip valid.
- Manifest cocok dengan seluruh file dalam `update/website/`: **16 file runtime**.
- Isi setiap file ZIP sama dengan salinan update dan file sumbernya.
- Tidak ada path traversal/absolute path, config.php produksi, database, dump SQL, atau PDF audit di ZIP.
- SHA-256: `2daad11e1effd0be75d1d42dc2497a14bd546230127308911d603f475a31ce32`.

Panduan pemasangan kumulatif: `update/README.md`. Jangan menimpa config.php/database. Backup dahulu dan hapus ZIP dari folder publik setelah ekstraksi. Perubahan lanjutan mencakup file root `whatsapp.php` dan `cron.php`, bukan hanya `includes/`.

## 3. Pemeriksaan live ringan, tanpa login

Target: `https://anggaran.page.gd/`.

| Request GET | Respons yang terlihat |
| --- | --- |
| `/api.php?action=settings/get` | JSON menolak akses karena sesi tidak ada/berakhir |
| `/api.php?action=report&anchor_date=2026-02-28` | JSON menolak akses karena sesi tidak ada/berakhir |
| `/.well-known/security.txt` | Kontak keamanan belum dikonfigurasi atau sudah kedaluwarsa |

Akses web mengembalikan konten respons, bukan raw header lengkap. Tidak menyimpulkan status HSTS/CSP/cookie/TLS produksi atau versi deployment dari pemeriksaan ini. Tidak dilakukan brute force, uji pembebanan, akses dengan akun pengguna, atau pemindaian menyeluruh endpoint live.

## 4. Belum selesai / syarat retest

1. Isi kontak keamanan publik dan expiry agar security.txt aktif.
2. Uji restore backup baru ke database MySQL/MariaDB staging kosong. Encoding hex bukan enkripsi; seluruh backup tetap sensitif dan bisa lebih besar.
3. Uji upload foto normal pada PHP/GD dan memory_limit hosting yang sebenarnya.
4. Verifikasi header Apache/rewrite, HTTPS, hak akses antar-akun uji, Google OAuth, dan pengiriman ke nomor sendiri setelah deployment. Tes lokal tidak menggantikan uji konkurensi MySQL atau provider nyata.
5. 2FA admin dan enkripsi secret/token masih belum diterapkan. Lindungi akses admin, database, dan backup. Rotasi secret webhook jika URL pernah tersebar.
6. Tidak ada klaim website pernah/tidak pernah dibobol. Patch tidak membersihkan kompromi lama atau file asing di hosting.
