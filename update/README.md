# Paket update — import cepat dari clipboard (copy-paste Excel)

Folder ini berisi file runtime untuk fitur **Import data dari copy paste Excel / clipboard** pada menu Keuangan. Hanya file yang berubah pada fitur ini yang dikemas; file lain di hosting tidak perlu disentuh. **Jangan upload seluruh folder `update/` ke `htdocs`.** `update/.htaccess` menolak akses web langsung jika folder ini tidak sengaja ikut terunggah.

## A. File yang perlu disalin ke hosting

Salin isi `website/` ke document root situs (`htdocs`), dengan susunan yang sama:

| File paket | Tujuan di hosting | Status |
| --- | --- | --- |
| `website/index.php` | `htdocs/index.php` | diganti (versi aset naik, memuat `clipboard-import.js`) |
| `website/assets/app.js` | `htdocs/assets/app.js` | diganti (panel paste, pratinjau bisa diedit) |
| `website/assets/clipboard-import.js` | `htdocs/assets/clipboard-import.js` | **baru** |
| `website/assets/style.css` | `htdocs/assets/style.css` | diganti (gaya panel clipboard) |

Yang **tidak** perlu diubah: `config.php`, `api.php`, `includes/`, `database/`, dan `assets/importer.js` (pembaca file `.xlsx`/CSV tetap sama). Fitur ini hanya memakai endpoint `transactions/import` yang sudah ada.

Setelah menyalin, buka situs lalu tekan **Ctrl+F5** (atau muat ulang paksa) agar browser memakai `app.js`/`style.css` versi baru.

## B. Cara memakai fitur

1. Buka menu **Keuangan** → **Import Excel**.
2. Salin (Ctrl+C) baris data dari Excel/Google Sheets. Urutan kolom yang dipakai (boleh tanpa baris judul kolom):

   | Kolom 1 | Kolom 2 | Kolom 3 | Kolom 4 | Kolom 5 |
   | --- | --- | --- | --- | --- |
   | Tanggal | Jenis | Kategori | Jumlah (Rp) | Keterangan |
   | `2026-07-30` / `30/07/2026` | `Masuk` / `Keluar` | `Makanan` / `Payroll` | `50000` / `50.000` | `Makan siang` / `Gaji` |

3. Tempel ke kotak **Paste data Excel di sini** (Ctrl+V), atau tekan **Tempel dari clipboard** untuk membaca clipboard otomatis.
4. Tekan **Proses & parse data**. Hasil baca tampil dalam tabel yang **bisa langsung diedit** (tanggal, jenis, keterangan, kategori, jumlah) dan baris yang salah ditandai merah.
5. Tekan **Import n baris** untuk menyimpan ke database.

Tombol **Salin format sample Excel** menyalin dua baris contoh (dipisah tab) supaya bisa ditempel ke Excel sebagai gambaran format. Tombol **Bersihkan teks** mengosongkan kotak paste dan pratinjau.

Catatan:

- Baris judul kolom boleh disertakan atau tidak; kalau ada, otomatis dilewati. Header umum (`Tanggal`, `Jenis`, `Kategori`, `Jumlah (Rp)`, `Keterangan`, `type`, `kind`, `title`, `amount`, `transaction_date`, `category`, `note`) tetap dikenali.
- Pemisah kolom yang diterima: tab (hasil copy Excel/Sheets), titik koma, garis tegak, koma, atau dua spasi/lebih.
- Tanggal: `YYYY-MM-DD`, `DD/MM/YYYY`, atau nomor seri Excel. Nominal: `25000`, `25.000`, `Rp 25.000`.
- Maksimal 500 baris sekali import. Setiap import membuat baris baru; data yang sama diimpor dua kali akan menjadi ganda.
- Akses clipboard otomatis butuh HTTPS. Jika browser menolak, tempel manual dengan Ctrl+V di kotak paste.

## C. Fitur lain yang tidak lagi dikemas di sini

Paket lama (backup harian ke Telegram) sudah dihapus dari folder ini sesuai permintaan agar `update/` hanya berisi fitur terbaru. File sumbernya **tidak dihapus** dari repository, masih ada di root: `telegram-backup.php`, `includes/app.php`, dan `cloudflare-backup/`. Bila paket backup diperlukan lagi, buat ulang dari sumber tersebut.

## D. Menyegarkan paket

Setelah file sumber di root berubah, jalankan dari root repository:

```sh
npm run package:update
```

Perintah ini juga menghapus sisa paket lama sehingga `update/website/` selalu mencerminkan fitur yang sedang dikemas.
