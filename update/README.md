# Update master kategori + input manual

Form transaksi/pengingat kini menerima kategori yang diketik manual sekaligus lookup kategori terdahulu. Jenis lookup: Uang Masuk (`income`), Uang Keluar (`expense`), dan Pengingat (`reminder`). Nama kategori baru disimpan saat catatan disimpan.

**Backup database dahulu.** Timpa `includes/app.php`, `api.php`, `assets/app.js`, dan `index.php` bersama-sama; paket ZIP ini tetap kumulatif. Tabel `categories` dibuat otomatis dengan field id, category_type, name, name_key dan unique constraint per jenis/nama. Backfill sekali membaca kategori lama tanpa menghapus/mengubah catatan lama; kategori bawaan juga disertakan. Tidak perlu impor ulang database. Pastikan izin database untuk CREATE/SELECT/INSERT dan penanda migrasi app_settings tersedia.

Uji di staging MySQL sebelum produksi: buka form, cek kategori lama, simpan nama baru, buka ulang, coba nama dengan beda kapital/spasi, cek lookup jenis lain, dan uji backup. Tes lokal SQLite/markup dan semua regresi lulus; integrasi hosting/MySQL nyata belum diuji. Detail aturan deduplikasi dan batasan ada di README utama.

---

# Perbaikan visibilitas Edit pengingat

Tombol Edit kini berlabel teks dan ditempatkan pertama dalam deretan aksi. Tombol aksi dapat turun baris agar tidak memaksa lebar kartu. Hak edit tetap pembuat/admin, termasuk status Aktif, Selesai, dan Cancel. Mengedit tidak mengubah status secara otomatis.

Untuk instalasi terbaru, timpa `assets/app.js`, `assets/style.css`, dan `index.php` bersama-sama (cache versi JS 31 / CSS 18). Jika masih melihat tampilan lama, lakukan hard refresh. Tidak perlu migrasi database. ZIP tetap kumulatif dengan perbaikan terdahulu.

Tes lokal markup dan API: tombol Edit untuk admin/pemilik pada semua status, penyimpanan judul/kategori/tanggal/jam/catatan, status tetap, serta penolakan edit akun lain. Penyebab pasti tampilan pada hosting pengguna belum diverifikasi melalui browser live.

---

# Update transaksi dashboard bulan berjalan

Daftar transaksi dashboard kini hanya menampilkan maksimal 7 transaksi terbaru pada bulan/tahun berjalan, berdasarkan tanggal transaksi dan zona waktu aplikasi. Bulan kosong menampilkan pesan khusus tanpa mencampur riwayat bulan lain. Saldo sepanjang waktu dan riwayat Keuangan tidak berubah.

Perubahan tambahan ini ada di `api.php`, `assets/app.js`, dan `index.php` (versi cache). Paket tetap mencakup fitur Cancel di bawah. Tidak perlu migrasi database. Tes dashboard bulan berjalan, laporan, keamanan/Cancel API, dan filter pengingat lulus lokal.

---

# Update status Cancel pengingat

Timpa `api.php`, `index.php`, `assets/app.js`, dan `assets/style.css` untuk fitur Cancel pada instalasi yang sudah mengikuti update sebelumnya. ZIP tetap kumulatif. Tidak ada perubahan config/database atau migrasi.

Pembuat/admin bisa Cancel dan mengaktifkan lagi. Filter Cancel mengikuti kategori/periode. Cancel tidak aktif, tidak diproses cron, dan tidak dapat dikirim WhatsApp manual. Data tetap tersimpan. Agenda Google yang sudah tersimpan tidak otomatis dihapus. Riwayat notifikasi terdahulu dipertahankan saat aktif kembali.

Tes: tambahan Cancel API/UI di `scripts/test-security.mjs` dan `scripts/test-reminder-filters.mjs`.

---

# Update filter daftar pengingat

Kategori + status Aktif/Selesai kini bisa digabung dengan satu tanggal periode. Contoh 19/11/2026 menampilkan jadwal 01/11/2026 sampai 19/11/2026 (inklusif), bukan seluruh bulan atau rentang bebas. Nilai awal/reset adalah hari ini. Ada jumlah hasil dan keadaan kosong. Filter berlaku pada maksimal 1.000 pengingat yang dimuat API (peringatan muncul saat batas tercapai).

Untuk instalasi yang sudah memakai seluruh patch keamanan sebelumnya, perubahan fitur ini cukup menimpa `index.php`, `assets/app.js`, dan `assets/style.css`. Versi cache aset diperbarui. ZIP tetap kumulatif; tidak mengubah config/database. Laporan pengujian akhir keamanan sebelumnya adalah catatan historis; hash ZIP di laporan tersebut bukan hash paket setelah penambahan fitur ini.

Tes fitur: `node scripts/test-reminder-filters.mjs` (lulus), regresi laporan dan kalender (lulus).

---

# Pemeriksaan lanjutan — upload bukti dan integritas backup

Perubahan terbaru ada pada **includes/app.php** (paket tetap kumulatif dengan pembatas notifikasi dan perbaikan webhook). Upload kini maksimal 8 megapiksel dan memeriksa perkiraan sisa memori sebelum GD decode; kecilkan foto jika ditolak. Format literal teks backup SQL diperbarui agar tidak bergantung pada escaping backslash PDO/mode MySQL.

**Backup tetap sensitif: hex bukan enkripsi.** Simpan backup lama dengan aman, lalu uji restore backup baru pada database MySQL/MariaDB staging kosong; periksa backslash, kutip, dan Unicode. Ukuran dump dapat meningkat, jadi periksa batas backup otomatis. Tidak perlu mengubah config atau impor migrasi untuk memakai perbaikan ini. Jangan menimpa database produksi saat uji restore.

Tes lokal tambahan: `node scripts/test-backup-upload.mjs`. Pemakaian memori GD, kompatibilitas restore MySQL, serta hosting produksi belum diuji lewat sesi ini.

---

# Pemeriksaan lanjutan — tutup keluaran laporan di debug webhook

Paket terbaru juga membawa **whatsapp.php** dan **cron.php**. Wajib timpa kedua file di root hosting, selain berkas tahap pembatas notifikasi di bawah. Webhook tidak lagi mengembalikan isi laporan melalui `debug=1`, body dibatasi 64 KiB, input divalidasi, dan status pengiriman tidak lagi mengaku sukses saat gagal. Detail exception cron tidak dipublikasikan melalui debug.

**Jika URL/secret webhook pernah dibagikan:** ganti kunci di Pengaturan → Bot WhatsApp, lalu perbarui URL webhook di dashboard Fonnte. Jangan kirim kunci ke chat. Patch tidak otomatis merotasi secret agar integrasi yang sah tidak putus. Verifikasi pesan teks normal dari nomor milik sendiri setelah deployment; provider yang mengirim file media inline >64 KiB perlu disesuaikan ke metadata/URL, bukan menaikkan batas tanpa pemeriksaan.

Tes lokal: `node scripts/test-webhook-security.mjs` serta pengujian otorisasi antaranggota di `node scripts/test-security.mjs`. Laporan temuan ada di SECURITY-AUDIT.md. Tidak ada perubahan database produksi melalui sesi ini.

---

# Pembatas notifikasi — penguatan tahap 1

Paket terbaru menambahkan kuota pengiriman WhatsApp/Google dan cooldown reminder manual, serta memperbaiki notifikasi reminder nonaktif yang sebelumnya diperiksa setelah pengiriman. Data reminder tetap disimpan jika pengiriman dibatasi.

Untuk instalasi yang sudah memakai update sebelumnya, perubahan runtime tahap ini ada pada **api.php**, **includes/app.php**, **includes/security.php**, dan **includes/google-calendar.php**. Timpa ke lokasi yang sama; jangan timpa config.php atau database. ZIP membawa paket kumulatif. Tabel counter dibuat otomatis saat dibutuhkan; tidak perlu impor SQL manual, tetapi user database perlu izin CREATE/INSERT/UPDATE/DELETE.

Batas: 30 percobaan/pengguna/15 menit dan 100/rumah/15 menit **per layanan**; background cron/bot berbagi kuota system 30. WhatsApp menghitung tiap penerima. Tombol reminder manual mendapat cooldown 60 detik per reminder per layanan. Percobaan gagal tetap dihitung. Tunggu waktu yang ditampilkan sebelum mengirim ulang. Broadcast dapat berhasil sebagian; periksa pesan hasil. Detail perilaku dan batasan ada di README utama.

**Tahap ini belum memasang 2FA atau enkripsi token.** Backup tetap sensitif. Tes lokal: `node scripts/test-notification-limits.mjs`; setelah pemasangan lakukan uji dengan penerima sendiri, jangan spam nomor lain atau sengaja menghabiskan kuota produksi.

---

# Perbaikan tanggal acuan laporan

Tanggal acuan, jenis transaksi, kategori, dan parameter pembanding kini dikirim lengkap ke API. Sebelumnya helper URL memotong parameter setelah `period`, sehingga server memakai tanggal hari ini meskipun input tanggal sudah berubah. Respons laporan lama yang datang terlambat juga diabaikan bila filter sudah berubah.

Jika seluruh update keamanan sebelumnya sudah terpasang, cukup timpa **`index.php`** dan **`assets/app.js`** dengan versi di `update/website/`, lalu Ctrl+F5. Tidak perlu mengubah database atau konfigurasi Google. ZIP tetap membawa paket runtime kumulatif; baca catatan keamanan di bawah jika memakai paket penuh.

Uji: pilih 28 Februari 2026, pastikan label tanggal, Today/Yesterday, MTD/LMTD, YTD/LYTD, ringkasan, serta CSV mengacu ke tanggal itu. Uji filter kategori dan jenis transaksi juga. Tes otomatis: `node scripts/test-report.mjs` (frontend asli → PHP API dengan database fixture terisolasi).

# Tambahan hasil laporan tim keamanan

Paket sekarang juga membawa `security-contact.php`, rewrite `/.well-known/security.txt`, HSTS pada HTTPS, header COOP/CORP/COEP produksi, no-store untuk lampiran, dan header bersama untuk endpoint PHP. Baca **SECURITY-REMEDIATION.md** di repository sebelum deployment. Header baseline static/Apache memerlukan mod_headers; canonical security.txt memerlukan mod_rewrite.

**Kontak belum diisi otomatis.** Di config.php hosting, isi `security_contact` dengan mailto/URL HTTPS pelaporan yang kamu kuasai dan `security_contact_expires` dengan waktu UTC masa depan (maksimal sekitar satu tahun). Keduanya wajib valid agar security.txt mengembalikan 200; jika belum siap/expired, statusnya 503. Kontak ini bersifat publik. HSTS mulai 86400 detik; verifikasi sertifikat/HTTPS hosting sebelum memperpanjang masa berlakunya. Jangan mengaktifkan HSTS includeSubDomains/preload tanpa audit seluruh domain.

DNS, SPF/DMARC/DKIM, CAA, DNSSEC, sertifikat, dan WAF tidak berubah lewat ZIP ini. Lakukan retest di domain yang benar; sebagian bukti laporan eksternal perlu dikonfirmasi.

# Update keamanan — baca sebelum memasang

1. Backup database dan simpan **di luar folder publik**. Jangan menimpa `config.php` lama dengan file contoh.
2. Jika password admin masih bawaan, tambahkan `admin_bootstrap_password` dengan password unik **12–72 byte** ke array `return [...]` dalam `config.php` hosting sebelum upload. Jika password sudah dikustomisasi, langkah ini tidak perlu. Jangan membagikan nilai ini.
3. Ekstrak ZIP terbaru ke `htdocs`, termasuk file tersembunyi **`.htaccess`** dan seluruh `includes/` dari paket. Paket tidak membawa kredensial atau database. Jika `.htaccess` hosting sudah dikustomisasi, gabungkan aturan dengan hati-hati.
4. Login ulang. Akun dengan password bawaan lama menggunakan password bootstrap yang baru. Setelah berhasil, hapus nilai `admin_bootstrap_password` dari config.php.
5. Hapus ZIP dari `htdocs`, lakukan Ctrl+F5, dan uji login, ganti password, upload bukti, dan OAuth. Pastikan `/includes/security.php`, `/config.php.bak`, `/.git/HEAD`, serta file backup SQL ditolak (403/404).

Perubahan: tidak ada password admin publik; login throttling persisten; sesi dicabut saat password berubah; batas sesi; CSP/headers; pembatasan berkas sensitif dan ukuran request. Tabel limiter dibuat otomatis saat login, tanpa impor SQL manual. Lihat `SECURITY-AUDIT.md` pada repository. Pengaturan Google yang sudah tersimpan tidak dihapus.

# Update Google Kalender otomatis

Paket terbaru menambahkan **Pengaturan → Google Kalender otomatis**: Client ID/Secret, callback, login OAuth, pilihan alarm, dan sakelar otomatis. Setelah koneksi disetujui, Simpan reminder baru juga membuat agenda di kalender utama Google. Tidak perlu edit script untuk mengganti konfigurasi berikutnya.

**Upload paket ini satu kali terlebih dahulu**; gunakan ZIP di bawah. File baru `includes/google-calendar.php` wajib ikut dipasang. Tidak perlu migrasi database. Hosting harus mendukung HTTPS, PHP cURL, dan koneksi ke Google. Panduan Google Cloud lengkap ada pada README utama bagian Google Kalender otomatis. Impor massal dan edit/hapus belum disinkronkan.

# Paket update — pembanding periode laporan & import clipboard

Paket ini membawa perubahan **Laporan keuangan** (pemilih periode pembanding dan rentang tanggal khusus) sekaligus file import clipboard yang sudah ada. Hanya file runtime yang perlu diperbarui yang dikemas; file lain di hosting tidak perlu disentuh. **Jangan upload seluruh folder `update/` ke `htdocs`.**

---

## A. Cara tercepat & paling aman: ekstrak ZIP

1. Unduh **`update/website-update.zip`**.
2. Di File Manager hosting, buka `htdocs` → unggah ZIP itu → pilih **Extract** dan timpa file lama.
3. Buka domain, lalu tekan **Ctrl+F5**.

ZIP tersebut sudah membawa struktur folder yang benar:

```
index.php
api.php
whatsapp.php
cron.php
security-contact.php
includes/google-calendar.php
includes/app.php
includes/wa.php
includes/security.php
includes/.htaccess
.htaccess
assets/app.js
assets/importer.js
assets/calendar.js
assets/clipboard-import.js
assets/style.css
```

`index.php` dan `api.php` tetap di root; semua berkas tampilan otomatis masuk ke `assets/`. Risiko tertukar antarfile (misalnya isi `app.js` masuk ke `index.php`) menjadi nol.

## B. Salin manual (bila hosting tidak bisa mengekstrak ZIP)

Salin isi `website/` ke document root situs (`htdocs`), **dengan susunan folder yang sama**:

| File paket | Tujuan di hosting | Status |
| --- | --- | --- |
| `website/index.php` | `htdocs/index.php` (root!) | diganti |
| `website/api.php` | `htdocs/api.php` (root!) | diganti |
| `website/security-contact.php` | `htdocs/security-contact.php` | baru, kontak publik terkonfigurasi |
| `website/.htaccess` | `htdocs/.htaccess` | aturan keamanan |
| `website/includes/*` termasuk `.htaccess` | `htdocs/includes/` | wajib ikut |
| `website/assets/app.js` | `htdocs/assets/app.js` | diganti |
| `website/assets/clipboard-import.js` | `htdocs/assets/clipboard-import.js` | **baru** |
| `website/assets/style.css` | `htdocs/assets/style.css` | diganti |

Perhatikan: **`index.php` dan `api.php` diletakkan di root.** Tiga berkas lainnya masuk ke folder `assets/` yang sudah ada. Jangan menimpa `index.php` dengan `app.js`/`style.css` — kalau itu terjadi, browser akan menampilkan kode program sebagai teks, bukan halaman website.

Yang **tidak** perlu diubah: `config.php`, `includes/`, `database/`, dan `assets/importer.js` (pembaca file `.xlsx`/CSV tetap sama). Versi `api.php` terbaru dalam paket diperlukan untuk rentang tanggal pembanding.

Setelah menyalin, buka situs lalu tekan **Ctrl+F5** agar browser memakai `app.js`/`style.css` versi baru.

## C. Periksa hasil pemasangan

Unggah **`update/cek-pasang.php`** ke `htdocs`, lalu buka `https://domainmu/cek-pasang.php`. Halaman itu memeriksa:

- apakah `index.php` masih berisi kode PHP (bukan isi `app.js`/`style.css`),
- apakah `assets/app.js`, `assets/clipboard-import.js`, `assets/style.css`, dan `assets/importer.js` ada dan sudah versi baru,
- apakah ada berkas aset yang salah tempat di root,
- apakah `api.php`, `includes/app.php`, `config.php`, folder `storage/`, dan versi PHP sesuai.

Hasilnya berupa daftar **OK / PERIKSA / MASALAH** beserta langkah perbaikannya. Setelah beres, hapus file pemeriksa lewat tautan **“hapus sekarang”** di bagian bawah halaman (atau hapus manual). File ini tidak menulis atau mengirim data ke mana pun.

## D. Kalau ada masalah

| Gejala | Penyebab | Perbaikan |
| --- | --- | --- |
| Browser menampilkan kode JavaScript mentah (`(() => { 'use strict'; const boot = window.APP_BOOT …`) di halaman utama | `index.php` tertimpa isi `assets/app.js` | Unggah ulang `update/website/index.php` ke `htdocs/index.php`. Pastikan `app.js` diletakkan di `htdocs/assets/app.js`. Jalankan `cek-pasang.php` untuk memastikan. |
| Halaman menampilkan kode CSS mentah (`:root { … }`) | `index.php` tertimpa isi `style.css` | Unggah ulang `update/website/index.php`. |
| Tampilan polos tanpa warna / ukuran font aneh | `assets/style.css` tidak ada, salah folder, atau versi lama | Unggah `update/website/assets/style.css` ke `htdocs/assets/style.css`. |
| Panel “Import data dari copy paste Excel / clipboard” tidak muncul | `index.php` atau `assets/app.js` masih versi lama | Unggah `update/website/index.php` **dan** `update/website/assets/app.js`. |
| Muncul pesan “Pembaca data belum siap. Muat ulang halaman lalu coba lagi.” | `assets/clipboard-import.js` belum diunggah | Unggah berkas itu ke `htdocs/assets/clipboard-import.js`. |
| Tombol **Tempel dari clipboard** menolak membaca | Browser butuh HTTPS + izin clipboard | Tempel manual dengan Ctrl+V di kotak paste (fitur tetap jalan). |
| Periode pembanding tidak bisa dipilih atau hasil perbandingan tidak mengikuti tanggal | `api.php` masih versi lama | Unggah `update/website/api.php` ke `htdocs/api.php`, lalu muat ulang halaman. |
| Tampilan masih seperti versi lama | Cache browser | Ctrl+F5, atau buka di jendela incognito. |

## E. Memilih periode laporan dan pembanding

Di menu **Laporan keuangan**, pilih satu **Tanggal acuan laporan**. Seluruh tabel mengikuti tanggal itu: kolom **Today**, **Yesterday**, **Varian**; **MTD**, **LMTD**, **Varian**; dan **YTD**, **LYTD**, **Varian**. Tanggal setiap rentang ditampilkan di kepala kolom. Varian dihitung sebagai periode pertama dikurangi periode kedua. Baris tabel merangkum kategori, dan pilihan jenis transaksi mengatur apakah nilainya uang masuk atau uang keluar.

Sesuai contoh, fitur ini **tidak menambahkan** tombol/kolom Actual, Delete, Budget, atau Forecast yang dicoret.

## F. Cara memakai import clipboard

1. Buka menu **Keuangan** → **Import Excel**.
2. Salin (Ctrl+C) baris data dari Excel/Google Sheets. Urutan kolom yang dipakai (boleh tanpa baris judul kolom):

   | Kolom 1 | Kolom 2 | Kolom 3 | Kolom 4 | Kolom 5 |
   | --- | --- | --- | --- | --- |
   | Tanggal | Jenis | Kategori | Jumlah (Rp) | Keterangan |
   | `2026-07-30` / `30/07/2026` | `Masuk` / `Keluar` | `Makanan` / `Payroll` | `50000` / `50.000` | `Makan siang` / `Gaji` |

3. Tempel ke kotak **Paste data Excel di sini** (Ctrl+V), atau tekan **Tempel dari clipboard** untuk membaca clipboard otomatis.
4. Tekan **Proses & parse data**. Semua baris (maks. 500) tampil dalam tabel yang **bisa langsung diedit** (tanggal, jenis, keterangan, kategori, jumlah); baris bermasalah ditandai merah dengan alasan tepat di bawah barisnya. Baris merah tidak hilang: perbaiki selnya sampai tanda merah hilang, atau hapus satu per satu.
5. Tekan **Import n baris** untuk menyimpan baris valid. Jika masih ada baris merah, website minta konfirmasi dan hanya menyimpan baris valid; tombol **Hapus baris merah** juga bisa dipakai bila memang tidak mau menyimpan baris tersebut.

Tombol **Salin format sample Excel** menyalin dua baris contoh (dipisah tab) supaya bisa ditempel ke Excel sebagai gambaran format. Tombol **Bersihkan teks** mengosongkan kotak paste dan pratinjau.

Catatan:

- Baris judul kolom boleh disertakan atau tidak; kalau ada, otomatis dilewati. Header umum (`Tanggal`, `Jenis`, `Kategori`, `Jumlah (Rp)`, `Keterangan`, `type`, `kind`, `title`, `amount`, `transaction_date`, `category`, `note`) tetap dikenali.
- Pemisah kolom yang diterima: tab (hasil copy Excel/Sheets), titik koma, garis tegak, koma, atau dua spasi/lebih.
- Tanggal: `YYYY-MM-DD`, `DD/MM/YYYY`, atau nomor seri Excel. Nominal: `25000`, `25.000`, `Rp 25.000`.
- Maksimal 500 baris sekali import. Setiap import membuat baris baru; data yang sama diimpor dua kali akan menjadi ganda.
- Akses clipboard otomatis butuh HTTPS. Jika browser menolak, tempel manual dengan Ctrl+V di kotak paste.

## G. Fitur lain yang tidak lagi dikemas di sini

Paket lama (backup harian ke Telegram) sudah dihapus dari folder ini agar `update/` hanya berisi fitur terbaru. File sumbernya **tidak dihapus** dari repository: `telegram-backup.php`, `includes/app.php`, dan `cloudflare-backup/` masih ada di root. Bila paket backup diperlukan lagi, buat ulang dari sumber tersebut.

## H. Menyegarkan paket

Setelah berkas sumber di root berubah, jalankan dari root repository:

```sh
npm run package:update
```

Perintah ini:

1. menyalin ulang berkas runtime ke `update/website/` (sekaligus menghapus sisa paket lama),
2. membuat ulang `update/website-update.zip` dari isi `update/website/`.

Kalau hanya ingin membuat ZIP-nya: `npm run package:zip`.
