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
assets/app.js
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
