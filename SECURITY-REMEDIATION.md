# Tindak lanjut laporan tim keamanan — 16 November 2026

Referensi: PDF `5_6329826099656139075.pdf`, ID `SEC-AEV-20261116-R53`, target tertulis `anggaran.page.rg`. PDF bersifat confidential dan **tidak disalin ke repository**. Dokumen ini memetakan temuan ke perbaikan kode, bukan menyatakan deployment produksi telah diperbaiki atau mengulang skor vendor.

## 1. Validasi bukti sebelum menyimpulkan

Ada ketidaksesuaian yang perlu diklarifikasi dengan tim penguji:

- Tabel port menyatakan 80/443 CLOSED, tetapi ringkasan/telemetry menyatakan keduanya ditemukan terbuka.
- Host/IP tidak berhasil ditentukan dan TLS handshake gagal. Status sertifikat kemudian ditulis VALID dengan sisa waktu N/A. Masa berlaku/keamanan sertifikat **belum terverifikasi**.
- Tidak ada JWT, GraphQL, endpoint AI, atau `/api/v1/telemetry` dalam aplikasi ini. Respons 404 pada rute generik tidak membuktikan keamanan endpoint sebenarnya (`/api.php?action=...`).
- `Cache-Control: no-store` dan invalidasi sesi adalah kontrol berbeda. Patch sebelumnya sudah menguji invalidasi sesi setelah ganti/reset password; patch ini juga menutup cache pada lampiran.
- Tidak adanya CSP bukan bukti bahwa SQLi/XSS sudah dapat dieksploitasi. Tidak adanya Cloudflare juga tidak otomatis berarti tidak ada kontrol perimeter. `robots.txt`/`llms.txt` bukan mekanisme autentikasi atau bukti ketahanan terhadap prompt injection.
- Selector DKIM yang umum tidak terdeteksi bukan bukti semua selector DKIM tidak ada. DNSSEC/CAA/email bergantung pada domain sebenarnya dan DNS authoritative yang dikuasai pemilik.

Minta tim mengonfirmasi ejaan domain, resolusi DNS dari jaringan penguji, certificate chain/SNI, HTTP status/final URL, serta raw response headers. InfinityFree bisa menyajikan halaman challenge sebelum PHP; header pada challenge adalah tanggung jawab hosting, bukan header aplikasi. Jangan menganggap challenge/timeout sebagai PASS untuk SQLi, XSS, atau auth.

## 2. Pemetaan perbaikan

| Temuan PDF | Status di kode terbaru | Batasan verifikasi |
| --- | --- | --- |
| #02 / #47: HSTS hilang | Ditambahkan pada respons PHP yang benar-benar HTTPS, awal `max-age=86400`, host-only. `hsts_max_age` dapat dinaikkan setelah TLS diuji. | Tidak memperbaiki sertifikat, DNS, atau first-request HTTP. Tidak memasukkan subdomain/preload tanpa audit. HTTP→HTTPS perlu diaktifkan pada hosting setelah sertifikat valid. |
| #03–06 / #48–51: framing, CSP, nosniff, referrer/permissions | Patch sebelumnya dipertahankan dan diperluas ke semua entry point PHP: API, halaman, callback, cron, webhook, backup, dan error yang melalui aplikasi. Header baseline juga diset lewat `.htaccess` untuk static/Apache responses jika mod_headers tersedia. | CSP menggunakan nonce di PHP, tidak ditimpa CSP statis kedua. Respons dari proxy/challenge sebelum PHP tidak bisa diubah oleh kode aplikasi. |
| #08 / #52: COOP/CORP/COEP | Produksi: `same-origin` / `same-origin` / `require-corp`. Semua aset aplikasi saat ini berasal dari origin sendiri. | Preview WASM sengaja tidak memakai batas framing/isolation agar iframe Arena tetap berfungsi. OAuth saat ini redirect satu tab; popup OAuth pihak lain/iframe eksternal yang ditambahkan kelak harus diuji ulang. |
| #11: rate limit | Patch sebelumnya: persisten DB, per akun/IP, HTTP 429 dan Retry-After; dites lintas sesi. | Bukan WAF/DDoS protection. Uji IP NAT/proxy hosting agar tidak membagi limit ke seluruh pengguna secara tidak sengaja. |
| #13: no-store | Respons privat sudah no-store, termasuk lampiran bukti yang sebelumnya `max-age=120`. Logout/reset password juga diuji membatalkan akses sesi. | Browser yang sudah menyimpan data sebelum patch perlu dibersihkan; no-store tidak menghapus screenshot/file yang pernah diunduh. |
| #19: banner | PHP `X-Powered-By` dihapus oleh aplikasi. | Banner Server yang ditambahkan webserver/proxy harus diatur penyedia hosting. |
| #20 / #73: RFC 9116 security.txt | Endpoint `/.well-known/security.txt` disiapkan dengan rewrite ke `security-contact.php`. Hanya diterbitkan setelah pemilik mengisi kontak nyata dan expiry valid. | **Belum aktif secara substantif tanpa kontak pemilik**: mengembalikan 503 jika kosong/invalid/expired, bukan mengarang alamat. Apache rewrite atau routing ekuivalen wajib berfungsi. |
| #53: metode HTTP | API hanya GET/POST; halaman dan kontak keamanan GET/HEAD; metode lain 405 + Allow. | TRACE/CONNECT yang ditangani sebelum PHP perlu konfigurasi webserver. Tidak mengklaim mitigasi HTTP request smuggling hanya dengan pembatasan metode. |
| #01 / #07 / #45–46 / #69–72 / #75–78 | **Tindakan hosting/DNS; tidak dipalsukan sebagai perbaikan PHP.** | WAF, TLS/certificate, SPF/DMARC/DKIM, CAA, DNSSEC, OCSP, dan takeover memerlukan verifikasi DNS/provider. |
| #74 / #79: llms.txt/robots AI | Tidak menambah file semata-mata agar skor naik. | File ini bersifat advisory dan tidak mengamankan konten privat. Akun/data tetap harus dilindungi autentikasi dan otorisasi. |

## 3. Konfigurasi yang diperlukan pemilik

Tambahkan opsi berikut pada array konfigurasi **config.php hosting yang sudah ada**, bukan mengganti seluruh file. Jangan kirim kredensial ke chat.

- `hsts_max_age`: default **86400** (1 hari). Setelah HTTPS dan perpanjangan sertifikat dipastikan sehat, dapat diubah ke **31536000** (1 tahun). Nilai **0** mencabut kebijakan HSTS melalui respons HTTPS. Tidak menggunakan `includeSubDomains` atau `preload` karena domain/subdomain lain belum diaudit.
- `security_contact`: **alamat mailto yang benar-benar dipantau** atau **URL HTTPS formulir pelaporan milik pemilik**. Kontak ini menjadi publik. Jangan isi placeholder atau alamat yang tidak dikuasai.
- `security_contact_expires`: waktu UTC RFC 3339, contoh format `2027-05-15T00:00:00Z`, harus masih di masa depan dan maksimal sekitar satu tahun. Tinjau serta perbarui kontak sebelum tanggal ini.

Tidak ada kontak/email yang diisi otomatis. Setelah kedua opsi kontak benar, `/.well-known/security.txt` menampilkan `Contact`, `Expires`, dan `Preferred-Languages` sebagai UTF-8 plain text. Endpoint tidak perlu sesi/database. Jika canonical path masih 404 tetapi `/security-contact.php` berfungsi, periksa rewrite/mod_rewrite; jangan menganggap path langsung PHP sudah menggantikan RFC 9116.

Jika memakai subdomain gratis, pastikan kamu memang memiliki akses DNS yang diperlukan sebelum mengaktifkan DNSSEC/CAA atau kebijakan email. **Jangan menambah SPF/DMARC/DKIM asal-asalan**: untuk domain yang mengirim email, ikuti record resmi penyedia email dan cek alignment; DKIM membutuhkan selector/kunci dari penyedia. Untuk domain yang tidak mengirim email, kebijakan penolakan bisa dipertimbangkan setelah memastikan layanan lain tidak menggunakannya. WAF/Cloudflare bukan wajib merek tertentu; pilih kontrol yang didukung paket hosting dan kepemilikan domain.

## 4. Deployment dan retest

1. Backup privat; baca `SECURITY-AUDIT.md` tentang perubahan login/password bawaan. Patch kumulatif ini mewajibkan login ulang dan menolak password admin bawaan.
2. Upload/ekstrak `update/website-update.zip`. Sertakan dotfile `.htaccess`, `includes/`, dan `security-contact.php`. Jangan timpa config/database. Jika `.htaccess` hosting punya aturan sendiri, gabungkan dengan hati-hati; Jika module/AllowOverride menolak aturan, perbaiki aturan/provider, bukan menghapus semua proteksi.
3. Isi kontak keamanan nyata dan expiry. Hapus ZIP dari folder publik.
4. Periksa HTTPS di browser normal pada **domain yang benar**, termasuk nama sertifikat, chain, dan expiry. Aktifkan redirect HTTPS di hosting bila sertifikat valid. PHP harus menerima `HTTPS=on/1`; aplikasi sengaja tidak mempercayai `X-Forwarded-Proto` dari klien sembarang. Jika reverse proxy tepercaya dipakai, minta pemetaan scheme yang aman dari hosting, jangan percaya semua header forwarded.
5. Dari jaringan yang dapat menjangkau hosting, periksa GET halaman dan respons 401 API. Contoh perintah (ganti DOMAIN dengan domain benar, ini belum dieksekusi terhadap produksi):
   ```sh
   curl -sS -D - -o /dev/null https://DOMAIN/
   curl -sS -D - -o /dev/null 'https://DOMAIN/api.php?action=me'
   curl -sS -D - https://DOMAIN/.well-known/security.txt
   ```
   Periksa status, URL final, dan body. Jika yang muncul halaman anti-bot InfinityFree, hasil tersebut bukan respons aplikasi; lakukan retest yang disetujui lewat browser dan simpan raw headers dari DevTools.
6. Pastikan CSP nonce halaman cocok dengan inline bootstrap, aset lokal tetap termuat, login/upload/CSV/Excel berfungsi, dan Google OAuth redirect serta notifikasi diuji kembali. Uji framing dari origin lain di staging; jangan melemahkan semua header agar popup/iframe eksternal bekerja.
7. Minta tim menguji endpoint asli dengan akun uji berizin: CSRF, batas login, otorisasi admin/member, reset password pada dua sesi, no-store lampiran. Jangan brute force akun produksi atau mencoba membypass provider tanpa persetujuan.

## 5. Pengujian lokal

- `node scripts/test-assessment-remediation.mjs`: header mode produksi/preview, deteksi HTTPS tanpa mempercayai header palsu, HSTS rollout/rollback, validasi kontak/expiry/injeksi newline, GET/HEAD/405, header pada respons error API/cron/webhook/backup, kontak tanpa sesi, penghapusan X-Powered-By.
- `node scripts/test-security.mjs`: regresi autentikasi, batas login, sesi/reset password, CSP nonce dan lampiran no-store.
- `node scripts/test-import-security.mjs`, `node scripts/test-calendar.mjs`, `node scripts/test-google-calendar.mjs`: regresi impor/kalender.
- Tes HSTS lokal memakai metadata HTTPS dan pemeriksaan header; **bukan handshake TLS nyata**. Mod_headers/mod_rewrite Apache, browser cross-origin isolation, DNS, dan hosting belum diuji oleh tes WASM.

Tidak ada janji bahwa skor scan akan menjadi A/100. Targetnya adalah memperbaiki kontrol yang relevan dan mendapatkan bukti retest yang dapat dipercaya.

---

## 6. Evaluasi laporan 24 November 2026 (`anggaran.page.gd`) & pengerasan lanjutan

Referensi lanjutan: `SEC-AEV-20261124-R63` (24 Nov 2026, target `https://anggaran.page.gd`, IP `185.27.134.113`, `Server: openresty`). PDF bersifat confidential dan tidak disalin ke repository.

### Temuan forensik atas hasil scanner eksternal

1. **Intersepsi anti-bot `openresty` (`aes.js`) pada subdomain InfinityFree (`*.page.gd`):**
   - Scanner otomatis tanpa engine browser menerima respons `HTTP 200` milik challenge `openresty` sebelum mencapai Apache/`.htaccess`/PHP. Hal ini dibuktikan oleh telemetri scanner yang melaporkan `HTTP 200` pada rute fiktif `/api/`, `/api/chat`, dan `/.well-known/llms.txt` yang tidak ada di kode aplikasi.
   - Karena halaman challenge `openresty` hosting tidak menyertakan header HSTS, CSP, X-Frame-Options, `nosniff`, Referrer/Permissions-Policy, maupun COOP/CORP/COEP, vektor **#02–#06, #08, #18, dan #47–#52** dinilai `FAIL/MISSED` oleh scanner, sementara vektor aplikasi (**#09–#17, #80**) berstatus *unverified false-pass* dari sisi scanner.
   - Challenge `aes.js` juga menghambat pemanggil server-to-server (webhook Fonnte, cron eksternal, dan Worker backup Telegram).
2. **Batasan infrastruktur subdomain `.page.gd`:**
   - Subdomain berbagi `page.gd` tidak dapat didaftarkan langsung ke Cloudflare DNS/WAF (**#01**) dan membatasi kustomisasi penuh record email anti-spoofing SPF/DMARC/DKIM (**#45, #69–#71**). Untuk mengaktifkan WAF edge, HSTS tingkat proxy, serta kebijakan `v=spf1 -all` dan `_dmarc` (`p=reject`), gunakan domain milik sendiri (misalnya `.my.id`/`.id`/`.com`).

### Perbaikan keamanan kode (*white-box hardening*) pada update ini

| Area | Celah yang ditutup | Implementasi |
| --- | --- | --- |
| Anti-Lockout Login DoS | Penyerang yang mengetahui username admin (`ALDI`) sebelumnya dapat memicu lockout global akun hanya dengan 10 percobaan gagal per 15 menit dari satu IP asing. | Pembatas ketat 10 percobaan/15 menit diikat ke pasangan `(IP + akun)` (`ip_account`), dilengkapi batas IP (30/15 menit) dan rem darurat kegagalan terdistribusi (`account_fail`: 60 gagal/15 menit). Login sukses mereset pasangan `(IP + akun)` pemilik. |
| Host Header & Proto Poisoning | `app_public_base_url()` sebelumnya membaca `HTTP_HOST` dan `HTTP_X_FORWARDED_PROTO` tanpa validasi saat membentuk URL webhook ber-secret. | Validasi ketat hostname RFC/IP (`app_validated_http_host()`), dukungan `public_base_url` eksplisit, serta `trusted_proxy_ips` opsional di `config.php`. |
| Enkripsi Secret At-Rest | Token Fonnte, Client Secret & Refresh Token Google, serta secret webhook tersimpan plaintext di `app_settings` dan ikut ter-ekspor ke backup `.sql`. | Enkripsi AEAD (`AES-256-GCM` / `XChaCha20-Poly1305` dengan HKDF-SHA256 & AAD nama setting) melalui `app_encryption_key` di `config.php`, lengkap dengan migrasi otomatis nilai plaintext lama. |
| Cookie Prefix & Lampiran | Cookie sesi HTTPS belum memakai prefix `__Host-`; endpoint lampiran belum memiliki CSP `sandbox` tersendiri dan fallback non-GD belum membersihkan EXIF. | Nama sesi otomatis menjadi `__Host-rumahku_session` saat HTTPS (`Path=/`, `Secure`, `HttpOnly`, tanpa `Domain`). Endpoint `attachment` memvalidasi whitelist MIME, memasang CSP `sandbox`, dan membersihkan segmen EXIF/GPS/komentar JPEG bila GD tidak tersedia. |
| Otorisasi Anggota | `target/save` dan `reminder/send` sebelumnya dapat dipanggil anggota biasa untuk mengubah target rumah atau mengirim pengingat milik pengguna lain. | `target/save` dibatasi khusus `admin`; `reminder/send` dibatasi untuk pembuat pengingat atau `admin` (selaras antara API dan UI). |
| Anti-Replay & Konkurensi | `telegram-backup.php` mengizinkan replay tanda tangan yang sama selama ±5 menit; deduplikasi `whatsapp.php` hanya menyimpan 1 hash non-atomik; `cron.php` berisiko kirim ganda saat tumpang tindih. | Signature `telegram-backup.php` menjadi sekali pakai (single-use) + rate limit; deduplikasi webhook multi-pesan atomik 120 detik di database; klaim baris pengingat & kunci laporan bulanan atomik di `cron.php`. |
| Paket Update & Diagnostik | `storage/.htaccess` belum ikut di `website-update.zip`; `update/cek-pasang.php` dapat diakses tanpa login dan dihapus lewat `GET ?hapus=1`. | `storage/.htaccess` disertakan ke paket update/ZIP; `cek-pasang.php` mewajibkan sesi admin aktif saat aplikasi sudah terpasang serta memakai `POST` + token CSRF untuk hapus mandiri. |

### Opsi tambahan `config.php`

```php
'public_base_url' => 'https://anggaran.page.gd',
'app_encryption_key' => 'ISI_KUNCI_ACAK_MINIMAL_32_KARAKTER_DAN_SIMPAN_TERPISAH',
'trusted_proxy_ips' => [], // isi hanya jika IP reverse proxy tetap & terdokumentasi hosting
```

Tes otomatis tambahan: `node scripts/test-deep-hardening.mjs`.
