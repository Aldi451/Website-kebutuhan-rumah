# Audit keamanan website kebutuhan rumah

Tanggal: 16 November 2026. Ruang lingkup: review kode pada branch audit, tes PHP 8.2 WASM/SQLite terisolasi, tes JavaScript, dan pemeriksaan dependency npm. **Bukan pentest domain produksi, bukan sertifikasi aman, dan tidak membuktikan website pernah/tidak pernah dibobol.** Belum menguji Apache/InfinityFree, MySQL, Google OAuth nyata, atau perangkat HP. Tingkat risiko di bawah adalah penilaian kualitatif, bukan skor CVSS formal.

## Ringkasan temuan dan perbaikan

| ID | Prioritas | Temuan sebelum update | Dampak / syarat | Perbaikan |
| --- | --- | --- | --- | --- |
| SEC-01 | Tinggi | `app_create_schema()` membuat admin dengan password publik yang sama di semua instalasi. | Pengambilalihan akun jika pemilik belum mengganti password; admin dapat mengunduh backup berisi hash/password API/token. Tidak berarti akun dengan password khusus sudah bocor. | Tidak lagi membuat admin dengan password publik. Instalasi baru memerlukan `admin_bootstrap_password` unik dari config hosting. Login admin dengan password bawaan lama ditolak. Bootstrap dapat mengganti password bawaan lama, tidak menimpa password yang sudah dikustomisasi. |
| SEC-02 | Tinggi | Endpoint login tidak memiliki pembatasan percobaan persisten. | Password guessing/credential stuffing dapat dilakukan berulang. | Counter database atomik per akun (10 percobaan/15 menit) dan IP (30/15 menit), termasuk login sukses; HTTP 429 + Retry-After. Akun dikenali berdasarkan ID database agar alias huruf/collation tidak memisahkan counter. Tidak mempercayai X-Forwarded-For dari klien. Input login dibatasi sebelum password hashing. |
| SEC-03 | Sedang | Ganti/reset password tidak membatalkan sesi yang telah login. Tidak ada batas usia sesi yang eksplisit pada aplikasi. | Sesi yang dicuri tetap dapat dipakai setelah pemilik mengganti password. | Sesi terikat fingerprint hash password; perubahan hash membatalkan sesi lain pada request berikutnya. Sesi pelaku perubahan diregenerasi, sesi OAuth tertunda dihapus. Idle timeout 30 menit dan absolute timeout 12 jam. Sesi sebelum update otomatis tidak valid. |
| SEC-04 | Sedang | `unzipEntry()` mempercayai metadata ukuran dan memuat hasil decompression penuh sebelum memeriksa batas. | File Excel buatan pihak lain yang dipilih pengguna dapat menghabiskan memori tab (ZIP expansion bomb). Tidak memerlukan upload file ke server karena importer berjalan di browser. | Hitung byte selama streaming, batalkan di atas 20 MiB per XML, cocokkan ukuran deklarasi dengan hasil, periksa stored ZIP. Tolak DTD/entity dan alamat kolom di luar rentang Excel. |
| SEC-05 | Sedang, bersyarat | Aturan root hanya menolak `config.php` persis; salinan seperti `config.php.bak`/backup SQL di root tidak dilindungi oleh aturan tersebut. | Kebocoran jika operator meletakkan salinan/backup di folder publik dan Apache melayaninya. Audit tidak menemukan file kredensial produksi yang sudah terbuka. | Aturan `.htaccess` diperluas untuk config/salinan, DB/SQL/arsip/log, dotfile; `includes/.htaccess` menolak akses HTTP langsung. Preview Node mendapat filter path tambahan. Jangan mengandalkan aturan ini sebagai pengganti menyimpan backup di luar webroot. |
| SEC-06 | Defense-in-depth | Tidak ada kebijakan CSP dan pembatasan framing halaman utama yang terpusat; request API tidak punya batas ukuran aplikasi sebelum JSON decode. | Memperbesar dampak jika injeksi HTML muncul di perubahan mendatang; risiko clickjacking produksi dan konsumsi memori request berlebihan. Ini bukan bukti XSS yang sudah berhasil dieksploitasi. | CSP dengan nonce untuk inline bootstrap, script hanya self/nonce, object/base diblokir, nosniff, no-referrer, Permissions-Policy, no-store. Framing dibatasi same-origin di PHP produksi; preview WASM sengaja diizinkan tampil dalam iframe Arena. Batas body API 12 MiB diperiksa sebelum decode. |

Tambahan: password **baru** (buat anggota, ganti, reset, bootstrap) harus 12–72 byte dan tidak boleh mengandung NUL. Batas 72 byte menghindari pemotongan diam-diam bcrypt. Password khusus lama tetap bisa dipakai untuk migrasi; sebaiknya ganti dengan passphrase unik. Panjang dalam byte berbeda dari jumlah karakter untuk emoji/huruf non-ASCII.

## Kontrol lama yang diperiksa

- Query input utama memakai PDO prepared statements; tidak ditemukan injeksi SQL yang terkonfirmasi pada jalur yang direview. Ini bukan jaminan seluruh kemungkinan SQL injection sudah tertutup.
- Aksi POST API memeriksa CSRF; pengaturan integrasi, daftar anggota, dan backup memerlukan admin.
- Edit/hapus transaksi/pengingat memeriksa pemilik atau admin. Membaca data rumah bersama serta menandai reminder bersama sebagai selesai adalah perilaku kolaboratif yang ada, bukan isolasi antar-rumah. Aplikasi **bukan multi-tenant**.
- Rendering data utama memakai escaping HTML. CSV laporan sudah menangani awalan formula untuk kolom teks.
- Upload bukti memeriksa ukuran, MIME gambar, dan dimensi; gambar disimpan di database, bukan sebagai file PHP yang bisa dieksekusi. Konversi server bergantung pada GD.
- Google OAuth memiliki state sekali pakai, PKCE, dan konfigurasi admin. Rahasia tidak ditampilkan dalam endpoint settings.
- Backup Telegram memakai secret HMAC dan jendela waktu; webhook/cron memakai secret. Secret harus tetap dijaga, bukan URL publik untuk dibagikan.

## Risiko yang masih ada / perlu tindakan pemilik

1. **Secret integrasi masih plaintext dalam database dan backup SQL.** Client Secret Google, refresh token, token Fonnte, serta secret webhook yang disimpan di settings ikut backup. Ini belum dienkripsi dalam update ini; enkripsi at-rest yang benar membutuhkan kunci terpisah dan strategi pemulihan. Simpan backup terenkripsi/offline, batasi akun database dan admin, jangan share file SQL/Telegram backup. Jika diduga bocor, rotasi/revoke kredensial di penyedia terkait.
2. **HTTPS dan hosting belum diverifikasi.** Pastikan seluruh akses produksi HTTPS, PHP mengenali HTTPS agar cookie Secure aktif, tidak menampilkan error/debug, direktori terlarang memberikan 403/404, dan file `.htaccess` benar-benar diterapkan. Nginx tidak membaca `.htaccess` sehingga memerlukan aturan server ekuivalen. CSP dapat perlu penyesuaian untuk plugin hosting, tetapi jangan menonaktifkannya tanpa menilai dampak.
3. **Rate limit bukan WAF/DDoS protection atau MFA.** Percobaan terdistribusi tetap berisiko; pelaku bisa menyebabkan akun terkena cooldown sementara. Pengguna di IP NAT/proxy yang sama berbagi kuota. Aplikasi tidak mempercayai header forwarded; jika hosting menaruh semua pengguna di satu REMOTE_ADDR, verifikasi proxy tepercaya sebelum mengubah strategi IP. Tabel limiter butuh izin CREATE/INSERT/UPDATE/DELETE. Jangan membuka akses login jika limiter gagal.
4. **Belum ada pembatasan kuota semua aksi anggota/notifikasi.** Anggota yang sah/diambil alih dapat mengirim notifikasi berulang atau menambah data hingga kuota hosting habis. Hanya beri akun pada orang tepercaya; kuota/rate limit per aksi adalah pekerjaan lanjutan.
5. **Webhook/cron dengan secret dalam query URL** bisa masuk access log. Gunakan header rahasia bila pemanggil mendukungnya, batasi akses log, dan rotasi secret jika URL tersebar. Tidak ditambahkan verifikasi signature vendor tanpa kepastian dukungan/protokol Fonnte.
6. **Impor dan file besar masih memerlukan sumber daya.** Batas streaming memperbaiki jalur ZIP bomb yang diuji, tetapi bukan sandbox file universal; hanya impor dokumen dari sumber tepercaya. Pertimbangkan batas request server dan memory_limit yang sesuai.
7. Dependensi PHP/hosting dan Node perlu diperbarui rutin. `npm audit` hanya mencakup package npm yang dikenal; tidak mencakup kelemahan platform hosting, kode PHP kustom, atau kerentanan yang belum diterbitkan. Dependency PHP-WASM saat ini memberi peringatan engine pada Node 22; gunakan Node yang sesuai persyaratan package untuk tooling. Runtime hosting tetap PHP, bukan Node.

## Tes dan hasil

Semua tes berikut berjalan lokal dan lulus:

- `node scripts/test-security.mjs`: lint PHP yang terlibat; instalasi tanpa password publik; bootstrap/recovery terbatas; batas password; sesi sebelum upgrade, idle/absolute timeout, revocation; counter melewati ambang dan kedaluwarsa; endpoint login, CSRF, header, admin/member; perubahan password membatalkan sesi kedua; reset admin membatalkan sesi anggota; login default lama ditolak.
- `node scripts/test-import-security.mjs`: ZIP valid/CSV valid, ukuran stored palsu, ukuran deflate palsu, ekspansi terkendali 21 MiB dengan metadata mengaku kecil ditolak saat streaming, DTD ditolak.
- `node scripts/test-google-calendar.mjs`: tes regresi konfigurasi/OAuth/event Google.
- `node scripts/test-calendar.mjs`: tes regresi URL/zona waktu kalender manual.
- Pemeriksaan sintaks JS, `git diff --check`, dan `npm audit` (0 kerentanan yang dilaporkan saat audit).

Test fixture memakai database WASM in-memory, bukan data produksi. Tidak ada percobaan membobol domain atau akun Google pengguna. **Aturan Apache, MySQL, layout/browser CSP, upload perangkat, serta Google OAuth/InfinityFree harus diuji saat deployment.**

## Langkah pemasangan aman

1. Backup database dan file konfigurasi, simpan di luar `htdocs`; jangan kirim ke chat/repository.
2. **Sebelum update**, jika admin masih memakai password bawaan, tambahkan ke array konfigurasi `config.php` hosting:
   ```php
   'admin_bootstrap_password' => 'ISI_PASSWORD_UNIK_12_SAMPAI_72_BYTE',
   ```
   Ganti placeholder dengan password unik milik sendiri, jangan memakai teks contoh. Jika admin sudah memakai password khusus, tidak perlu menambahkan opsi ini. Jangan menimpa konfigurasi database yang lama.
3. Ekstrak `update/website-update.zip` terbaru ke root website. Pastikan `.htaccess`, `includes/app.php`, `includes/wa.php`, `includes/security.php`, `includes/.htaccess`, dan `assets/importer.js` ikut. Jika aturan hosting dikustomisasi, gabungkan aturan dengan hati-hati. Jangan mengunggah seluruh folder repository beserta `.git`, file rahasia lokal, atau dataset.
4. Login ulang. Untuk kasus password bawaan, login dengan password baru yang disiapkan di langkah 2. Hapus nilai bootstrap dari config setelah berhasil. Mengisi bootstrap tidak bisa mengambil alih admin yang sudah memakai password khusus.
5. Hapus ZIP dari folder publik. Bersihkan cache browser (Ctrl+F5). Uji login/logout, impor, upload bukti, laporan, dan login Google. Koneksi Google tersimpan tidak sengaja dihapus, tetapi proses OAuth yang sedang tertunda sebelum update perlu diulang.
6. Cek tanpa login: `/api.php?action=backup/download` harus ditolak; `/includes/security.php`, `/config.php.bak`, `/.git/HEAD`, serta backup SQL tidak boleh bisa diunduh. Jangan membuat salinan config asli untuk tes; gunakan file dummy tanpa rahasia di staging jika perlu.
7. Untuk dugaan kompromi sebelumnya: audit akun anggota/admin, reset password, revoke Google consent, rotasi secret integrasi, periksa access log dan file asing. Patch ini tidak otomatis membersihkan backdoor atau memulihkan kebocoran yang sudah terjadi.


## Tindak lanjut laporan eksternal

Patch lanjutan dan pembaruan status ada pada **SECURITY-REMEDIATION.md**: HSTS khusus HTTPS, header isolasi origin produksi, header entry point/error yang lebih menyeluruh, no-store untuk lampiran, dan endpoint RFC 9116 yang memerlukan kontak nyata dari pemilik. Tes tambahan: `node scripts/test-assessment-remediation.mjs`. Dokumen ini dan dokumen tindak lanjut tidak mengklaim konfigurasi hosting/DNS/TLS sudah diverifikasi.


## Pembaruan tahap 1: pembatas notifikasi

Risiko pengiriman berulang kini dimitigasi dengan counter database atomik untuk percobaan WhatsApp/Google, kuota pengguna/rumah, dan cooldown kirim manual per reminder. Jalur pengiriman WhatsApp terpusat sehingga broadcast, bot, cron, dan tes memakai pembatas yang sama. Pengiriman otomatis reminder yang dimatikan juga tidak lagi dijalankan sebelum pemeriksaan pengaturan. Lihat README untuk angka batas dan konsekuensi pengiriman parsial.

Tes baru `scripts/test-notification-limits.mjs` memeriksa batas dan expiry, cooldown lintas pengguna/login, kuota rumah/background, pemisahan layanan, pengiriman terpusat, HTTP 429/Retry-After, penyimpanan reminder saat kuota habis, dan kegagalan database yang menahan pengiriman. Ini tes lokal SQLite/WASM, bukan uji konkurensi MySQL atau layanan produksi. Kuota pembuatan data secara umum, 2FA admin, dan enkripsi token tetap belum diterapkan.


## Pemeriksaan lanjutan: webhook, debug, dan otorisasi objek

**Temuan terkonfirmasi lokal (bersyarat):** `whatsapp.php?debug=1` sebelumnya mengembalikan `reply_text` yang dapat memuat saldo/laporan. Pemanggil harus memiliki secret webhook dan mengetahui nomor bot yang mendapat akses laporan; tidak perlu sesi login dashboard. Tes fixture admin bot + permintaan saldo gagal sebelum patch karena isi laporan muncul di HTTP, lalu lulus setelah semua pengembalian `reply_text` dihapus. Dampak: memperbesar kebocoran jika secret URL/log sudah bocor. Tidak ada klaim telah dieksploitasi di produksi.

Perbaikan: respons webhook hanya metadata status, batas body 64 KiB, validasi tipe/panjang field, GET/POST saja, key array ditolak tanpa cast warning, error konfigurasi generik, dan pengiriman gagal tidak lagi dilaporkan `replied=true`. Detail exception cron tidak lagi dikirim pada mode debug. Jalur bearer-secret vendor tetap memerlukan rotasi saat diduga bocor; tidak ditambahkan signature fiktif yang tidak didukung provider.

Tes otorisasi tambahan menolak modifikasi transaksi/reminder/lampiran akun lain dan pengiriman Google untuk catatan akun lain oleh anggota, menolak reset admin, serta memastikan pemalsuan `user_id` tidak mengubah pemilik transaksi. Semua menggunakan database fixture lokal. Pembacaan data bersama memang fitur aplikasi satu rumah; bukan bukti kebocoran anonim.

Tes tambahan: `scripts/test-webhook-security.mjs`, perluasan `scripts/test-security.mjs`. Belum menguji provider live, MySQL, atau data produksi; 2FA dan enkripsi token masih belum termasuk patch ini.


## Pemeriksaan lanjutan: batas decoding gambar dan serialisasi SQL backup

**Penguatan ketersediaan:** gambar terkompresi dibatasi byte, tetapi jalur lama masih mengizinkan 40 juta piksel tanpa menghitung sisa memori sebelum GD decode. Alokasi piksel berpotensi melampaui memori shared hosting. Batas diturunkan ke 8 juta piksel dan ditambahkan perkiraan memori sebelum decode; kegagalan decoder ditolak, bukan disimpan sebagai file asli. Tes memakai PNG grayscale valid 4000×2500 yang kecil secara terkompresi dan memastikan ditolak sebelum decode. Tidak dilakukan eksperimen menghabiskan memori server produksi atau klaim OOM telah terjadi.

**Integritas backup / defense-in-depth:** serialisasi lama menggabungkan penggandaan backslash manual dan quoting PDO sumber. Hasil bergantung pada driver serta mode SQL saat restore; ada risiko perubahan karakter/double escaping. Sekarang teks dikodekan sebagai literal hex UTF-8 untuk MySQL/MariaDB. Tes memastikan data kutip, backslash, Unicode, NUL, dan teks mirip SQL hanya menjadi data reversibel. Tidak diklaim ada SQL injection langsung atau exploit restore yang telah dibuktikan pada produksi; restore MySQL staging masih wajib.

Tes `scripts/test-backup-upload.mjs` lulus lokal. Encoding backup **tidak** menyelesaikan risiko secret at-rest/backup bocor, dan dapat memperbesar ukuran backup. 2FA dan enkripsi token belum ditambahkan. Lihat README untuk batasan GD tanpa dukungan, memori hosting, dan prosedur retest.
