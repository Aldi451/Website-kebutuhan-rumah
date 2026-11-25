# Dashboard kebutuhan rumah

Website pribadi bergaya dashboard merchant/fintech: ringkas, bersih, dengan aksen hijau-teal. Fokusnya untuk mencatat keuangan rumah dan menyimpan pengingat keluarga.

## Fitur

- **Ringkasan keuangan:** saldo dari transaksi yang tercatat, pemasukan/pengeluaran bulan ini, grafik arus kas enam bulan, dan transaksi terbaru.
- **Catat uang masuk & keluar:** kategori, tanggal, nominal Rupiah, catatan opsional, dan **bukti foto** yang boleh dikosongkan. Semua anggota rumah berbagi buku catatan yang sama; tiap baris tetap mencantumkan pembuatnya.
- **Ubah catatan (edit):** transaksi pada tabel **Keuangan** dan pengingat pada daftar **Pengingat** bisa diedit lewat ikon pensil — nominal, tanggal, kategori, catatan, bahkan jenis transaksi atau jadwal pengingatnya. Bukti foto yang sudah ada tetap tersimpan kecuali kamu memilih foto baru.
- **Laporan keuangan:** pilih satu tanggal acuan khusus; tabel per kategori otomatis membandingkan Today, Yesterday, variannya, MTD/LMTD, serta YTD/LYTD beserta variannya. Tanggal acuan menggerakkan semua rentang; hasil matriks bisa diunduh sebagai CSV.
- **Target pengeluaran bulanan:** buat batas belanja untuk bulan yang dipilih dan lihat progres dari total uang keluar.
- **Bukti gambar:** foto nota, struk, transfer, atau kondisi barang bisa dilampirkan pada uang masuk/keluar maupun pengingat. Gambar **dikompres dulu di HP/browser** (maksimal 1600 px, ± 20–500 KB) lalu dikompres lagi di server, baru disimpan ke database. Fitur ini **opsional**: kolom bukti bisa dilewati kapan saja, dan bukti bisa ditambah, diganti, atau dihapus belakangan tanpa mengubah catatannya.
- **Backup & cek database:** menu **Pengaturan** menampilkan jumlah baris tiap tabel dan ukuran bukti gambar yang tersimpan, plus tombol **Unduh backup .sql** untuk menyimpan salinan seluruh data (siap diimpor ke phpMyAdmin).
- **Pengingat:** simpan judul, tanggal, **jam (opsional)**, kategori, catatan, dan bukti foto opsional, lalu ubah, tandai selesai, atau hapus. Contoh: “Ganti oli motor” tanggal 26 jam 10:30, atau “Bayar tagihan air” tanggal 20 **tanpa jam** (dianggap sepanjang hari).
- **Import massal Excel & clipboard:** unggah transaksi atau pengingat dari workbook `.xlsx`/CSV, atau **salin-tempel (copy-paste) langsung dari Excel/Google Sheets**. Format cepat 5 kolom (Tanggal, Jenis, Kategori, Jumlah, Keterangan) boleh tanpa baris judul kolom, ada tombol **Tempel dari clipboard**, **Salin format sample Excel**, **Bersihkan teks**, dan **Proses & parse data**. Hasil baca tampil sebagai tabel yang bisa diedit sebelum disimpan; maksimal 500 baris sekali import.
- **Akun admin dan anggota:** admin dapat menambahkan akun anggota dan mengatur ulang kata sandinya. Setiap pengguna dapat mengganti kata sandi akunnya sendiri.
- **Notifikasi WhatsApp via Fonnte (opsional):** pengingat bisa dikirim ke nomor WhatsApp atau grup saat pengingat baru disimpan. Endpoint cron tersedia untuk mengirim pesan lagi ketika waktunya tiba, ada tombol **Tes kirim pengingat** di Pengaturan, dan tiap pengingat punya tombol **kirim manual** supaya kabarnya bisa diteruskan kapan saja tanpa menunggu cron.
- **Bot WhatsApp (tanya laporan & rekap bulanan):** nomor yang didaftarkan admin bisa bertanya langsung lewat WhatsApp — rekap bulan ini/bulan lalu, target dan status minus, kategori tertentu, pengeluaran terbesar, transaksi terakhir, hingga saldo (khusus admin). Bot juga mengirim **laporan bulanan otomatis** di akhir bulan berisi pemasukan, pengeluaran, dan apakah pengeluaran sudah sesuai target atau minus.
- **Bahasa Indonesia dan Rupiah**, responsif untuk HP maupun desktop.

## Di mana datanya tersimpan (dan SQL-nya di mana)?

Aplikasi **tidak** memakai file SQL terpisah yang harus dijalankan lebih dulu. Tabel database
dibuat dan diperbarui otomatis setiap kali website dibuka, langsung dari kode di
`includes/app.php`:

- `app_schema_statements()` — daftar lengkap `CREATE TABLE` untuk pengguna, transaksi,
  target bulanan, pengingat, pengaturan, bukti gambar (`attachments`), dan nomor bot
  WhatsApp (`wa_contacts`).
- `app_create_schema()` — menjalankan daftar itu saat pertama kali aplikasi dijalankan.
- `app_ensure_column()` — pembaruan ringan (`ALTER TABLE ... ADD COLUMN`) untuk instalasi lama,
  jadi tidak ada data yang hilang saat aplikasi versi baru diunggah.

Lokasi penyimpanan data:

| Tempat | Database | Keterangan |
| --- | --- | --- |
| Hosting (InfinityFree/cPanel) | **MySQL/MariaDB** | Ditentukan oleh `dsn`, `db_user`, `db_password` di `config.php`. Buka phpMyAdmin untuk melihat isi tabel kapan saja. |
| Preview lokal (`npm start`) | **SQLite** | File `storage/rumahku.sqlite`. File ini ditulis ulang setiap kali ada perubahan, jadi catatanmu tetap ada walau server preview dimatikan dan dinyalakan lagi. |

Kalau ingin struktur tabelnya dalam bentuk file SQL (misalnya untuk membuat database lewat
phpMyAdmin sebelum mengunggah website, atau memeriksa instalasi lama), isinya tersedia di
folder `database/`:

- `database/schema-mysql.sql` — seluruh tabel versi MySQL.
- `database/schema-sqlite.sql` — seluruh tabel versi SQLite.
- `database/upgrade-mysql.sql` — penambahan tabel/kolom baru (aman diulang) untuk instalasi lama.

Cara mencadangkan data:

1. Login sebagai admin → **Pengaturan → Backup & database**.
2. Di situ terlihat jumlah transaksi, pengingat, bukti gambar, dan total baris yang tersimpan.
3. Pilih **Unduh backup .sql**. File berisi struktur tabel + seluruh isi data (gambar bukti
   disertakan sebagai nilai hex selama totalnya masih wajar, yaitu di bawah ± 6 MB).
4. Simpan file tersebut; isinya bisa diimpor kembali kapan saja lewat phpMyAdmin → Import.
   Untuk hosting, ekspor rutin dari phpMyAdmin juga bisa dilakukan.

Catatan: database berisi data pribadi (termasuk hash kata sandi). Jangan letakkan database di
folder yang bisa diakses publik dan jangan bagikan file backup ke orang lain.

## Import transaksi dan pengingat dari Excel

Ada dua cara masuk: unggah file, atau salin-tempel dari Excel/Google Sheets. Keduanya berujung pada pratinjau yang sama sebelum data disimpan.

### A. Import cepat dari clipboard (menu Keuangan)

1. Buka **Keuangan**, lalu pilih **Import Excel**. Panel **Import data dari copy paste Excel / clipboard** langsung terbuka.
2. Salin (Ctrl+C) beberapa baris dari Excel atau Google Sheets. Urutan kolom yang dipakai:

   | Kolom 1 | Kolom 2 | Kolom 3 | Kolom 4 | Kolom 5 |
   | --- | --- | --- | --- | --- |
   | **Tanggal** | **Jenis** | **Kategori** | **Jumlah (Rp)** | **Keterangan** |
   | `2026-07-30` / `30/07/2026` | `Masuk` / `Keluar` | `Makanan` / `Payroll` | `50000` / `50.000` | `Makan siang` / `Gaji` |

3. Tempel ke kotak **Paste data Excel di sini**, atau tekan **Tempel dari clipboard** untuk membacanya otomatis (butuh HTTPS; kalau browser menolak, tempel manual dengan Ctrl+V). Keterangan baris/kolom yang terbaca muncul di bawah kotak.
4. Tekan **Proses & parse data**. Hasil baca muncul sebagai tabel pratinjau yang **bisa langsung diperbaiki** — tanggal, jenis, keterangan, kategori, dan jumlah diedit di tempat; baris bermasalah ditandai merah dan tidak ikut diimpor. Baris juga bisa dihapus satu per satu.
5. Tekan **Import n baris** untuk menyimpan. Tombol **Salin format sample Excel** menyalin dua baris contoh siap tempel, sedangkan **Bersihkan teks** mengosongkan kotak paste dan pratinjau.

Pemisah kolom yang diterima: tab (hasil copy Excel/Sheets), titik koma, garis tegak, koma, atau dua spasi atau lebih. Baris judul kolom boleh disertakan atau tidak — kalau ada, otomatis dilewati.

### B. Unggah file `.xlsx`/CSV (Keuangan dan Pengingat)

1. Unduh template CSV yang sesuai (bisa dibuka dan diedit di Excel), atau pilih workbook `.xlsx`/CSV pada kotak **Pilih file Excel atau CSV**. File `.xls` lama perlu disimpan ulang sebagai `.xlsx` atau CSV UTF-8.
2. Untuk transaksi, kolom wajibnya **Jenis**, **Keterangan/Nama transaksi**, **Jumlah**, dan **Tanggal**; jenis diisi `Pemasukan`/`Pengeluaran` atau `Masuk`/`Keluar`. Header umum seperti `type`, `kind`, `title`, `description`, `amount`, `transaction_date`, `category`, dan `note` dikenali. Import Keuangan hanya membuat transaksi uang masuk/keluar; tidak menyentuh pengingat, anggota, kontak, atau pengaturan WhatsApp. ID, `user_id`, dan `created_at` lama tidak dipertahankan; transaksi baru dicatat atas akun yang sedang login. Untuk pengingat, kolom wajibnya **Judul** dan **Tanggal**; **Jam** opsional — bila dikosongkan pengingatnya menjadi "sepanjang hari" dan kabarnya dikirim sekitar 09:00. Kategori dan catatan bersifat opsional.
3. Tanggal dapat ditulis `YYYY-MM-DD` atau `DD/MM/YYYY`. Periksa pratinjau; semua baris tampil dan yang tidak valid ditandai merah dengan alasan di bawahnya agar bisa diedit atau dihapus. Maksimal 500 baris per import, ukuran file maksimal 10 MB.

Setiap impor menambahkan catatan baru—mengimpor file atau blok yang sama lagi dapat menghasilkan duplikat. Pengingat impor tidak mengirim pesan WhatsApp satu per satu; notifikasi saat waktunya tetap mengikuti jadwal cron yang sudah dipasang. Berkas pemasangan fitur clipboard ada di `update/README.md`.

## Menyimpan bukti gambar (opsional)

Bukti bisa dilampirkan saat mencatat, atau ditambahkan belakangan.

1. Buka **Keuangan** → **Uang masuk/Uang keluar**, atau **Pengingat** → **Buat pengingat**.
2. Pada bagian **Bukti / foto (opsional, boleh dilewati)**, pilih foto dari galeri atau kamera.
   Di HP foto biasanya 2–8 MB; aplikasi mengecilkannya dulu (sisi terpanjang maksimal 1600 px,
   kualitas JPEG menurun otomatis) sampai kira-kira di bawah 550 KB. Ukuran sebelum dan sesudah
   kompres ditampilkan, termasuk keterangan “mis. 3,4 MB → 240 KB”.
3. Simpan. Gambar yang benar-benar tersimpan biasanya hanya puluhan sampai ratusan KB, sehingga
   database tetap ringan. Server memeriksa ulang gambarnya dan mengompres sekali lagi (sekaligus
   menghapus data EXIF/GPS dari foto).
4. Tidak ada foto? Lewati saja kolomnya — catatan tetap tersimpan seperti biasa.
5. Saat **mengedit** transaksi atau pengingat, kolom bukti menampilkan info foto yang sudah
   tersimpan. Pilih foto baru untuk mengganti (dikompres seperti biasa), atau biarkan kosong untuk
   menyimpan bukti yang lama.

Melihat, mengganti, atau menghapus bukti yang sudah tersimpan:

- Klik ikon **kamera** pada baris transaksi, tabel **Keuangan**, daftar **Pengingat**, atau pada
  kartu di halaman **Ringkasan**. Jendela bukti akan menampilkan gambar ukuran penuh.
- **Ganti foto** memilih gambar baru (otomatis dikompres lalu ditimpa). **Hapus bukti** hanya
  menghapus gambarnya, catatan keuangannya tetap ada. **Unduh gambar** menyimpan file ke perangkat.
- Izinnya sama seperti menghapus catatan: pembuat catatan atau admin rumah. Anggota lain tetap
  bisa melihat buktinya karena data rumah dibagikan bersama.
- Batas aman di server: gambar hasil kiriman maksimal ± 1,6 MB dan 12.000 px; gambar yang lebih
  besar ditolak dengan pesan yang menjelaskan cara memperbaikinya.

## Mengubah catatan, jam pengingat, dan kirim manual

### Edit transaksi dan pengingat

Catatan yang salah tidak perlu dihapus lalu dibuat ulang:

1. Di tabel **Keuangan**, klik ikon **pensil** pada baris transaksi. Di daftar **Pengingat**, klik
   ikon **pensil** pada kartunya.
2. Ubah bagian mana pun: jenis transaksi (masuk/keluar), nama, nominal, kategori, tanggal,
   catatan; untuk pengingat: judul, tanggal, jam, kategori, dan catatan.
3. Simpan. Bukti foto yang sudah ada **tidak ikut terhapus** — pilih foto baru di modal edit bila
   mau menggantinya, atau lewati kolomnya untuk membiarkan bukti yang lama.
4. Kalau tanggal transaksi dipindah ke bulan lain, tabel Keuangan otomatis membuka bulan yang baru
   supaya perubahannya langsung terlihat.
5. Menggeser jadwal pengingat ke masa depan membuat cron boleh mengirim kabar lagi pada waktu yang
   baru; jadwal yang masih di masa lalu tidak dikirim ulang.

Izinnya sama seperti menghapus: pembuat catatan atau admin rumah.

### Jam pengingat itu opsional

Kolom **Tanggal** wajib, kolom **Jam** boleh dikosongkan:

- **Diisi jam** → daftar menampilkan jamnya, dan kabar WhatsApp dikirim pada jam itu (WIB).
- **Dikosongkan** → pengingat dianggap **sepanjang hari**; daftar menampilkan “Seharian” dengan
  tanda “Tanpa jam”, dan kabarnya dikirim sekitar **09:00** pada tanggal tersebut.
- Tombol **Tanpa jam** di sebelah kolom jam menghapus isian jam kapan saja, termasuk saat mengedit
  pengingat lama yang sudah punya jam.

### Tes dan kirim pengingat manual

- **Pengaturan → Notifikasi WhatsApp (Fonnte) → Tes kirim pengingat** (admin): mengirim contoh pesan
  pengingat ke WhatsApp supaya bentuk kabarnya bisa dicek tanpa membuat catatan baru.
  Tombol **Tes kirim WhatsApp** mengirim pesan percobaan koneksi yang lebih singkat.
- **Daftar Pengingat → ikon kirim** pada tiap pengingat: meneruskan pengingat itu ke WhatsApp saat
  itu juga. Berguna untuk menguji notifikasi, mengingatkan anggota rumah sekali lagi, atau
  menutupi cron yang belum terpasang. Pengingat yang sudah jatuh tempo dan dikirim manual ditandai
  sudah dikabari, jadi cron tidak mengirim pesan yang sama dua kali.
- Semua anggota rumah yang login boleh memakai tombol kirim manual; hasilnya masuk ke WhatsApp
  rumah yang sama.

## Laporan keuangan

Menu **Laporan** merangkum catatan berdasarkan kategori dan periode:

- **Hari ini** dibanding kemarin
- **Bulan ini sampai hari ini** dibanding bulan lalu sampai tanggal yang sama (agar 13 Oktober tidak dibanding dengan 30 hari penuh bulan September)
- **Bulan lalu** dibanding dua bulan lalu (bulan kalender penuh)
- **Tahun ini sampai hari ini** dibanding tahun lalu sampai tanggal yang sepadan
- **Tahun lalu** dibanding dua tahun lalu
- **Rentang tanggal khusus**, dibanding rentang sebelumnya dengan jumlah hari yang sama

Pilih satu kategori (misalnya Bensin) atau semua kategori, lalu saring uang masuk, uang keluar, atau keduanya. Laporan menampilkan porsi kategori, transaksi terbesar, selisih nominal, persentase, dan unduhan CSV. Hari ini juga dibanding dengan hari yang sama minggu lalu; bulan berjalan menampilkan proyeksi akhir bulan. Kategori yang hanya beda huruf (Bensin/bensin) digabung. Rata-rata harian memakai hari kalender, plus peringatan jika banyak hari belum dicatat. Akurasi mengikuti tanggal pada transaksi.

## Login awal

Tidak ada password admin bawaan lagi. Sebelum membuka instalasi baru, isi `admin_bootstrap_password` di **config.php milik hosting** dengan password unik 12–72 byte (untuk huruf ASCII sama dengan 12–72 karakter). Akun awal memakai username **ALDI**. Login dengan password yang kamu pilih, lalu hapus nilai bootstrap dari config.php; hash password tetap tersimpan di database.

**Penting untuk upgrade:** jika admin lama masih memakai password bawaan, login itu sekarang ditolak. Isi `admin_bootstrap_password` seperti di atas, muat ulang, lalu login dengan password baru. Password admin yang sudah pernah dikustomisasi **tidak** ditimpa oleh bootstrap. Jika lupa password khusus tersebut, pemulihan memerlukan akses database/hosting; jangan membuka endpoint reset tanpa autentikasi.

Semua sesi sebelum update keamanan harus login ulang. Setelah ganti/reset password, sesi akun tersebut di perangkat lain menjadi tidak valid. Sesi berhenti setelah 30 menit tidak aktif atau maksimal 12 jam. Login dibatasi per akun dan alamat IP dalam jendela 15 menit. Lihat **SECURITY-AUDIT.md** untuk hasil audit dan checklist pemasangan.

## Coba di komputer lokal

Siapkan `config.php` lokal berisi `admin_bootstrap_password` unik (DSN boleh dikosongkan untuk SQLite). Jangan memakai password produksi pada preview publik. Perlu Node.js 22 atau lebih baru. PHP 8.2 WebAssembly digunakan hanya untuk menjalankan preview lokal; situs yang diunggah ke hosting tetap berjalan sebagai PHP biasa.

## Pasang di InfinityFree / shared hosting PHP

Tidak perlu Node.js di server hosting. Aplikasi menggunakan PHP 8.1+ dan **PDO MySQL**; folder `storage/` juga memiliki proteksi `.htaccess` untuk instalasi lokal SQLite.

1. Buat database MySQL dari panel hosting. Catat host, nama database, username, dan password database dari panel tersebut.
2. Salin `config.example.php` menjadi `config.php`. Isi `dsn`, `db_user`, dan `db_password` sesuai detail hosting. Contoh DSN tersedia di dalam file; gunakan nilai host yang benar dari panel akunmu. Set `timezone` ke `Asia/Jakarta`. Untuk instalasi baru/admin dengan password bawaan lama, isi juga `admin_bootstrap_password` dengan password unik 12–72 byte.
3. Unggah isi repository ke folder web hosting (umumnya `htdocs` atau `public_html`), termasuk `.htaccess`, folder `assets/`, `includes/`, `storage/`, dan `database/`. Jangan unggah `.git/`, `node_modules/`, backup SQL/ZIP, file rahasia lokal, atau `storage/rumahku.sqlite` dari komputer lokal.
4. Buka domain. Tabel database dan akun admin awal dibuat otomatis saat pertama kali aplikasi dijalankan. Kalau lebih suka membuat tabelnya sendiri lebih dulu, impor `database/schema-mysql.sql` lewat phpMyAdmin, atau `database/upgrade-mysql.sql` untuk database yang sudah ada. Jika hosting tidak mengaktifkan ekstensi PDO MySQL, GD, atau memblokir pembuatan tabel, minta hosting mengaktifkannya.
5. Login, lalu langsung ganti kata sandi admin. Dari menu **Anggota rumah**, admin dapat membuat akun tambahan.
6. Cek **Pengaturan → Backup & database** untuk memastikan jumlah barisnya sudah sesuai, lalu unduh backup pertama sebagai salinan.

`config.php` sengaja tidak disertakan di Git. Simpan salinan rahasianya dengan aman. Jangan kirim password database atau token Fonnte ke chat publik. Backup berkala dapat dilakukan dari menu Pengaturan (unduh `.sql`) atau dengan mengekspor database dari phpMyAdmin.

Ekstensi PHP yang dipakai: **PDO MySQL/SQLite** (wajib), **GD** (untuk kompresi ulang gambar bukti di server; kalau GD tidak aktif aplikasi tetap berjalan, gambar hanya dikompres di browser), dan **fileinfo** (opsional).

### Kalau setelah memasang update halaman menampilkan kode program

Gejalanya: halaman utama menampilkan tulisan seperti `(() => { 'use strict'; const boot = window.APP_BOOT …` atau `:root { … }` sebagai teks biasa. Penyebabnya bukan database, melainkan **isi `assets/app.js` (atau `style.css`) tertimpa ke `index.php`**, sehingga server hanya mengirim berkas itu sebagai teks.

Perbaikan:

1. Unggah ulang berkas `index.php` dari paket update ke `htdocs/index.php` (root). Pastikan `app.js`, `style.css`, dan `clipboard-import.js` berada di `htdocs/assets/`, bukan di root dan bukan menimpa `index.php`.
2. Cara paling aman: unggah `update/website-update.zip` ke `htdocs` lalu **Extract** — ZIP-nya sudah membawa struktur `index.php` + `assets/…` yang benar.
3. Unggah `update/cek-pasang.php` ke `htdocs`, buka `https://domainmu/cek-pasang.php`, lalu ikuti daftar **OK / PERIKSA / MASALAH** yang muncul. Setelah beres, hapus file pemeriksa itu dari hosting.
4. Muat ulang situs dengan **Ctrl+F5**.

Detail lengkap ada di `update/README.md` (bagian B–D).

## Backup SQL otomatis ke Telegram (opsional, Worker eksternal)

InfinityFree Free tidak menyediakan cron dan akses Telegram Bot API dari PHP hosting dibatasi. Untuk backup harian, jadwalkan file `cloudflare-backup/worker.js` sebagai Cloudflare Worker; Worker mengambil SQL lewat endpoint PHP bertanda tangan HMAC lalu mengirimnya sebagai dokumen ke chat privat Telegram. Cloudflare Cron berjalan dalam UTC, jadi `0 17 * * *` berarti sekitar pukul 00.00 WIB.

1. Unggah `telegram-backup.php` dan versi terbaru `includes/app.php` ke hosting. Di `config.php` hosting, tambahkan `telegram_backup_secret` dengan secret acak minimal 32 karakter. Gunakan secret yang sama untuk Worker; jangan commit atau kirim nilainya lewat chat.
2. Buat Worker dari `cloudflare-backup/worker.js`, tambahkan Cron Trigger `0 17 * * *`, lalu set binding/secret `BACKUP_URL` (misalnya `https://domainmu/telegram-backup.php`), `BACKUP_HMAC_SECRET`, `TELEGRAM_BOT_TOKEN`, dan `TELEGRAM_CHAT_ID`. Token dan HMAC harus disimpan sebagai **Secrets**, bukan di source code.
3. Mulai chat privat dengan bot lewat `/start` dan gunakan chat ID privat. Uji handler secara lokal dengan `npx wrangler dev --test-scheduled` (isi `.dev.vars`, jangan commit), lalu periksa log Worker setelah deploy. Kalau InfinityFree mengembalikan halaman challenge alih-alih SQL, backup akan dibatalkan dan Worker mengirim notifikasi gagal.

File SQL dibatasi sampai 45 MiB agar dapat dikirim lewat Telegram. Backup berisi data sensitif termasuk hash kata sandi dan pengaturan aplikasi; kirim hanya ke chat privat yang aman, jangan ke grup. Telegram bukan penyimpanan end-to-end terenkripsi. Seperti backup manual, gambar bukti hanya dimasukkan jika total ukurannya tidak lebih dari 6 MB; Worker memberi peringatan bila ekspor SQL melewatkan gambar.

Untuk file yang perlu dipasang, lihat `update/README.md`. Folder `update/` sekarang hanya mengemas fitur **import cepat dari clipboard** (`website/index.php`, `website/assets/app.js`, `website/assets/clipboard-import.js`, `website/assets/style.css`), plus **`website-update.zip`** yang siap diekstrak di `htdocs` dan **`cek-pasang.php`** untuk memeriksa hasil pemasangan. Paket bisa disegarkan dari source dengan `npm run package:update` (sekaligus membuat ulang ZIP). Paket backup Telegram lama sudah dihapus dari folder ini; sumbernya tetap ada di `telegram-backup.php`, `includes/app.php`, dan `cloudflare-backup/`.

## Hubungkan notifikasi WhatsApp (Fonnte)

Notifikasi memakai gateway **Fonnte** (fonnte.com). Fonnte mengirim pesan lewat nomor WhatsApp yang kamu sambungkan sendiri, jadi tidak ada bot Telegram yang perlu dibuat.

1. Daftar atau masuk ke **fonnte.com**, buka menu **Device**, lalu tambahkan perangkat baru.
2. Sambungkan WhatsApp: buka detail perangkat dan **scan QR** dari HP (WhatsApp → **Perangkat tertaut** → **Tautkan perangkat**). Tunggu sampai status perangkat **connect**.
3. Salin **token perangkat** (bukan token akun) dari menu Device.
4. Login sebagai admin di website, buka **Pengaturan → Notifikasi WhatsApp (Fonnte)**. Tempel token dan isi **nomor WhatsApp tujuan** — boleh nomor sendiri, nomor anggota keluarga, atau ID grup seperti `6281234567890-1234567890@g.us`.
5. Pilih **Simpan & hubungkan**. Aplikasi memeriksa token dan status perangkat, memvalidasi nomor tujuan, lalu mengirim pesan percobaan. Setelah tersimpan, setiap pengingat baru langsung dikirim ke WhatsApp.
6. Uji bentuk kabarnya dengan **Tes kirim pengingat**, atau teruskan pengingat tertentu lewat ikon kirim pada daftar **Pengingat**.

Catatan penting:

- Token disimpan di database server dan tidak pernah dikirim kembali ke browser.
- Setiap pesan memakai **kuota Fonnte**. Paket gratis punya batas kirim; sisa kuota terlihat di dashboard Fonnte dan pada tombol **Cek perangkat**.
- Server hosting harus bisa membuat koneksi keluar HTTPS ke `api.fonnte.com`. Fitur ini tidak bisa diuji di preview `npm start` karena PHP WebAssembly di sana tidak punya akses internet.
- Kalau tujuanmu sebuah grup, pastikan nomor pengirim sudah menjadi anggota grup tersebut.
- Untuk fitur tanya-jawab dan rekap bulanan otomatis, lanjutkan ke bagian [Bot WhatsApp](#bot-whatsapp-tanya-laporan-lewat-chat).
- Jika token dan nomor diisi langsung di `config.php`, kosongkan juga di sana saat ingin melepas koneksi. Tombol **Lepas koneksi** hanya menghapus pengaturan yang disimpan di database.

### Update dari versi bot Telegram

Versi ini mengganti notifikasi Telegram dengan WhatsApp (Fonnte) sekaligus menambah tabel `wa_contacts` untuk nomor yang boleh memakai bot. Aplikasi membuat tabel itu otomatis saat website dibuka, jadi tidak ada data yang hilang.

> Catatan: tabel `wa_contacts` adalah satu-satunya perubahan struktur database. Kalau ingin membuatnya manual, impor `database/upgrade-mysql.sql` lewat phpMyAdmin.

1. **Backup dulu:** login admin → **Pengaturan → Backup & database → Unduh backup .sql** (atau ekspor lewat phpMyAdmin).
2. **Timpa file lama** di hosting dengan versi baru, minimal: `api.php`, `index.php`, `cron.php`, `includes/app.php`, `assets/app.js`, `config.example.php`, `README.md`, dan file di folder `database/`. **Tambahkan juga dua file baru** yang belum ada di versi Telegram: `includes/wa.php` dan `whatsapp.php`. Jangan menimpa `config.php` milikmu.
3. `config.php` lama tetap bisa dipakai. Kalau dulu mengisi token Telegram di sana, ganti bagian tersebut menjadi:
   ```php
   'fonnte_token' => '',
   'fonnte_target' => '',
   ```
   Keduanya boleh dikosongkan dan diisi dari menu Pengaturan.
4. Buka website, login admin, lalu isi **Pengaturan → Notifikasi WhatsApp (Fonnte)** dengan token perangkat Fonnte dan nomor tujuan. Pengaturan bot Telegram lama tidak dibaca lagi dan akan dihapus otomatis saat koneksi WhatsApp disimpan atau dilepas.
5. **Cron tidak perlu diubah.** Alamat `/cron.php?key=...` yang sudah berjalan otomatis mengirim pesan WhatsApp dengan koneksi baru. Pastikan tugas terjadwalnya tetap aktif.
6. Muat ulang halaman dengan **Ctrl+F5** (atau kosongkan cache browser) agar tampilan Pengaturan yang baru dipakai.
7. Uji **Tes kirim WhatsApp**, lalu **Tes kirim pengingat**. Kalau keduanya masuk, notifikasi dasar sudah selesai.
8. Untuk fitur tanya-jawab, buka **Pengaturan → Bot WhatsApp**, salin **alamat webhook**, lalu tempel di dashboard Fonnte pada **Device → Edit → Webhook**. Tambahkan nomor yang boleh bertanya di panel yang sama.

Catatan: jangan mengunggah `storage/rumahku.sqlite` dari preview lokal ke hosting MySQL; file itu hanya untuk preview.

### Notifikasi saat waktu pengingat tiba

Pengingat tetap tersimpan dan terlihat di situs tanpa cron, tetapi pesan WhatsApp pada **tanggal/jam jatuh tempo** perlu pemicu terjadwal. Jika paket hosting menyediakan cron, jadwalkan permintaan ke:

```text
https://DOMAIN-KAMU/cron.php?key=RAHASIA_CRON
```

Atur `cron_secret` di `config.php` dengan nilai acak rahasia minimal 32 karakter; endpoint akan menolak permintaan jika nilainya kosong atau salah. Jadwalkan setiap 5–15 menit. Jika paket gratis tidak menyediakan cron, gunakan scheduler eksternal yang tepercaya dan jangan membagikan URL/rahasia tersebut — atau kirim manual lewat ikon kirim pada daftar **Pengingat** saat dibutuhkan. Pesan saat pengingat **dibuat** tetap dikirim langsung jika WhatsApp sudah terhubung.

Pengingat **sepanjang hari** (tanpa jam) tersimpan pada 09:00 di tanggalnya, jadi cron yang jalan tiap 5–15 menit akan mengirim kabarnya pagi itu.

### Troubleshooting WhatsApp (Fonnte)

**Pesan “Server hosting tidak bisa menghubungi Fonnte” atau `Could not resolve host: api.fonnte.com`**

Artinya **server hosting** tidak bisa menjangkau Fonnte. Token belum sempat diperiksa, jadi isiannya belum tentu salah. Aplikasi memanggil `https://api.fonnte.com` dari server (bukan dari browser), sehingga hosting harus mengizinkan koneksi keluar ke alamat itu.

- Pastikan koneksi keluar HTTPS (port 443) ke `api.fonnte.com` diizinkan dan ekstensi PHP cURL/OpenSSL aktif. Beberapa hosting gratis membatasi koneksi keluar; hubungi penyedia hosting bila masih gagal.
- `npm start` (preview lokal) memakai PHP WebAssembly tanpa akses internet, jadi WhatsApp tidak bisa diuji di sana. Aplikasi menampilkan pesan penjelasan, bukan error server.

**Token valid, tetapi perangkat belum terhubung**

Buka dashboard Fonnte → **Device** → scan QR ulang sampai statusnya **connect**. Token perangkat tetap sama; setelah tersambung, pilih **Cek perangkat** atau simpan ulang di Pengaturan.

**`token invalid` atau `unknown user`**

Pastikan yang dipakai adalah **token perangkat** dari menu Device, bukan token akun dari menu Setting. Salin ulang token tanpa spasi tambahan.

**`target invalid` atau nomor tidak terdaftar**

Gunakan format `08...` atau `62...` tanpa spasi/tanda hubung. Untuk grup, salin ID grup dari dashboard Fonnte (berakhiran `@g.us`) dan pastikan nomor pengirim sudah bergabung ke grup itu.

**Kuota habis atau pesan tidak terkirim**

Cek sisa kuota dan masa aktif perangkat di dashboard Fonnte. Setiap pesan memakai kuota, termasuk pesan percobaan dan kirim manual.

**Token pernah terkirim ke chat atau tempat umum?**

Anggap sudah bocor. Putuskan atau hapus perangkat tersebut di dashboard Fonnte, buat perangkat baru bila perlu, lalu simpan token barunya di **Pengaturan → Notifikasi WhatsApp (Fonnte)**.

**Pesan percobaan masuk, tetapi cron tidak mengirim**

Pastikan tugas terjadwal mengarah ke `/cron.php?key=...`, `cron_secret` di `config.php` minimal 32 karakter, dan cron berjalan setiap 5–15 menit. Cron memakai koneksi WhatsApp yang sama dengan tombol tes.

## Bot WhatsApp: tanya laporan lewat chat

Setelah notifikasi Fonnte tersambung, nomor WhatsApp yang didaftarkan bisa langsung bertanya ke bot.

### 1. Hubungkan webhook di Fonnte

1. Login admin → **Pengaturan → Bot WhatsApp**.
2. Salin **alamat webhook** (berisi kunci rahasia `?key=...`).
3. Buka dashboard Fonnte → **Device** → **Edit** → tempel alamat itu di kolom **Webhook** → simpan.
4. Kirim pesan `halo` dari nomor yang sudah didaftarkan. Bot harus membalas menu bantuan.

Alamat webhook hanya perlu dipasang sekali. Kalau kunci diganti lewat tombol **Ganti kunci webhook**, tempel alamat baru di Fonnte — alamat lama langsung tidak berlaku.

### 2. Atur nomor yang boleh bertanya

Di **Pengaturan → Bot WhatsApp → Nomor yang boleh bertanya**, tambahkan nomor keluarga beserta hak aksesnya:

| Akses | Yang bisa dilihat |
| --- | --- |
| **Admin** | Semua laporan: uang masuk, uang keluar, saldo, target, kategori, dan transaksi terakhir. |
| **Anggota** | Total pengeluaran, status target, kategori pengeluaran, dan pengeluaran terakhir. Uang masuk dan saldo tidak ditampilkan. |

Nomor yang tidak terdaftar akan dibalas pesan berisi nomor mereka sendiri dan petunjuk agar admin menambahkannya. Nomor yang dinonaktifkan juga mendapat pemberitahuan. Untuk grup, daftarkan **ID grup** (berakhiran `@g.us`) — semua anggota grup itu bisa bertanya, dan balasannya dikirim ke grup. Di grup, bot hanya menjawab pertanyaan yang dikenali (misalnya `laporan` atau `target`), jadi obrolan sehari-hari tidak ikut dibalas.

Tombol **Nonaktifkan** menghentikan akses tanpa menghapus nomor; **Hapus** mengeluarkannya dari daftar.

### 3. Contoh pertanyaan

| Ketik di WhatsApp | Jawaban bot |
| --- | --- |
| `laporan` | Rekap bulan berjalan: pemasukan, pengeluaran, sisa, target, kategori terbesar. |
| `laporan bulan lalu` | Rekap bulan sebelumnya. |
| `laporan oktober 2025` | Rekap bulan tertentu. |
| `laporan tahun ini` / `laporan 2025` | Total setahun. |
| `pengeluaran kategori tagihan bulan ini` | Total dan porsi kategori tertentu. |
| `target bulan ini` | Target, terpakai, sisa, dan status sesuai target/minus. |
| `5 pengeluaran terbesar bulan ini` | Daftar pengeluaran terbesar. |
| `transaksi terakhir` | Lima catatan terbaru. |
| `saldo` | Saldo keseluruhan (khusus admin). |
| `bantuan` / `halo` | Menu contoh pertanyaan. |

Bot hanya membaca pesan teks. Lampiran (foto/berkas) dibalas dengan petunjuk singkat.

### 4. Laporan bulanan otomatis

Di panel yang sama, aktifkan **Laporan bulanan otomatis**, pilih tanggal dan jam kirim (WIB), lalu simpan.

- **Hari terakhir bulan berjalan** (default, jam 20:00) — mengirim rekap bulan yang sedang berjalan di hari terakhirnya, termasuk status target. Cocok untuk menjawab “bulan ini sesuai target atau minus?”.
- **Tanggal 1–28** — mengirim rekap **bulan lalu** pada tanggal tersebut (misalnya tanggal 1 pagi).
- Laporan dikirim ke **nomor utama** (target notifikasi) dan semua nomor terdaftar yang aktif. Nomor admin menerima laporan lengkap; nomor anggota hanya menerima pengeluaran dan status target.
- Setiap periode hanya dikirim sekali. Kalau cron sempat mati, aplikasi mengirim susulan pada tanggal 1–3 untuk mode hari terakhir.
- Tombol **Kirim laporan sekarang** bisa mengirim rekap bulan tertentu kapan saja tanpa menunggu jadwal. Hasilnya langsung muncul sebagai notifikasi di halaman, dan tidak mengubah jadwal otomatis berikutnya.

Laporan bulanan memakai cron yang sama dengan pengingat:

```text
https://DOMAIN-KAMU/cron.php?key=RAHASIA_CRON
```

Pastikan tugas terjadwal berjalan setiap 5–15 menit dan `cron_secret` di `config.php` minimal 32 karakter. Tanpa cron, laporan otomatis tidak akan terkirim.

### 5. Keamanan dan batasan bot

- Alamat webhook adalah kunci rahasia. Jangan dibagikan; ganti kunci bila pernah bocor.
- Bot hanya menjawab nomor/grup yang terdaftar dan aktif. Nomor asing dibatasi maksimal 10 balasan per jam agar kuota tidak habis.
- Pesan dari perangkat bot sendiri dan kiriman webhook ganda diabaikan supaya tidak terjadi balasan berulang.
- Setiap balasan memakai kuota Fonnte. Pertanyaan panjang atau berulang tetap memotong kuota, jadi arahkan anggota memakai contoh perintah di atas.
- Fitur bot tidak bisa diuji di preview `npm start` karena PHP WebAssembly tidak punya akses internet. Gunakan hosting untuk mencoba.
- Respons webhook tidak mengembalikan isi laporan, termasuk jika memakai `debug=1`. Periksa jawaban di WhatsApp nomor terdaftar atau lihat laporan melalui dashboard setelah login. Secret webhook bukan pengganti sesi admin.

### Troubleshooting bot WhatsApp

**Bot tidak membalas sama sekali**

- Pastikan alamat webhook sudah ditempel di **Device → Edit → Webhook** pada Fonnte dan disimpan.
- Pastikan perangkat Fonnte berstatus **connect**.
- Pastikan nomor pengirim ada di **Nomor yang boleh bertanya** dan statusnya **Aktif**.
- Kalau grup, pastikan **ID grup** (`...@g.us`) yang didaftarkan, bukan nomor anggota.
- Kalau baru mengganti kunci webhook, tempel ulang alamat barunya di Fonnte.

**Balasan masuk ke nomor yang salah**

Bot membalas ke pengirim (chat pribadi) atau ke grup tempat pertanyaan dikirim. Periksa apakah pengirim memakai nomor yang terdaftar.

**Laporan bulanan tidak terkirim**

- Pastikan saklar **Laporan bulanan otomatis** aktif dan jadwalnya sudah lewat.
- Pastikan cron berjalan setiap 5–15 menit dan `cron_secret` benar.
- Lihat status **Terakhir dikirim otomatis** di panel; kalau kosong, cron belum pernah mengirim.
- Cek kuota Fonnte dan status perangkat.

**Bot menjawab “belum terdaftar” padahal nomor sudah ditambahkan**

Pastikan format nomor sama dengan yang dikirim Fonnte (biasanya `62...`). Aplikasi menormalkan `08...`, `+62...`, dan spasi/tanda hubung, tetapi nomor yang berbeda (mis. nomor lama vs baru) tetap dianggap berbeda.

**Bot tidak memahami pertanyaan**

Gunakan kata kunci yang tersedia (`laporan`, `target`, `saldo`, `kategori ...`, `terbesar`, `terakhir`, `tahun ini`). Ketik `bantuan` untuk melihat contoh.

## Keamanan dan batasan

- Password akun disimpan dalam bentuk hash, bukan teks biasa. API menggunakan prepared statement, session cookie `HttpOnly`/`SameSite`, dan perlindungan CSRF.
- `storage/` berisi database SQLite lokal dan ditolak oleh `.htaccess`. Untuk hosting, gunakan MySQL dan jangan membuat database dapat diakses publik.
- Akun anggota yang ditambahkan berbagi data rumah bersama. Penghapusan, pengeditan catatan, dan penggantian bukti gambar dibatasi pada pembuatnya atau admin rumah.
- Tombol **kirim manual** pengingat boleh dipakai semua anggota yang login, dan pesannya masuk ke WhatsApp rumah yang sama — jadi jangan dipakai untuk mengirim berkali-kali tanpa perlu.
- Satu target pengeluaran disimpan untuk tiap bulan. Target mengukur **pengeluaran yang sudah dicatat**, jadi hasilnya bergantung pada kelengkapan input transaksi.
- Gambar bukti disimpan di dalam database (tabel `attachments`) dalam bentuk JPEG terkompresi, biasanya puluhan sampai ratusan KB per gambar. Karena itu jaga ukurannya: kuota database hosting gratis umumnya terbatas, dan menghapus bukti yang tidak diperlukan akan mengembalikan ruang.
- Bukti gambar bisa dilihat semua anggota rumah yang login (seperti catatan lain). Jangan simpan foto yang tidak ingin dilihat anggota lain.
- Nomor WhatsApp yang didaftarkan bisa membaca ringkasan keuangan lewat chat. Berikan akses **Admin** hanya kepada orang yang memang boleh melihat uang masuk dan saldo, dan perlakukan alamat webhook sebagai kata sandi.
- Ini alat pencatatan pribadi, bukan aplikasi bank atau pembukuan pajak. Selalu simpan backup.

## Ide lanjutan yang cocok untuk website pribadi

Kalau ingin dikembangkan setelah versi dasar ini, yang paling berguna biasanya: ekspor transaksi ke CSV/Excel, transaksi berulang (listrik/internet/langganan), target dana darurat atau tabungan, inventaris barang rumah beserta garansi/nota servis, dan ringkasan pengeluaran per kategori. Untuk pemakaian keluarga, riwayat perubahan dan backup otomatis juga layak diprioritaskan.

## Google Kalender otomatis (konfigurasi lewat Pengaturan)

Setelah satu kali memasang paket update terbaru, admin tidak perlu mengedit script/config.php untuk konfigurasi Google lagi. Buka **Pengaturan → Google Kalender otomatis**.

### Setup satu kali

1. Gunakan website pada domain **HTTPS**. Hosting perlu PHP cURL, sertifikat CA yang valid, dan akses keluar ke `accounts.google.com`, `oauth2.googleapis.com`, serta `www.googleapis.com`.
2. Di Google Cloud Console buat/pilih project, aktifkan **Google Calendar API**, dan siapkan **Google Auth Platform / OAuth consent screen**. Jika audience External masih Testing, tambahkan email Google sendiri sebagai **test user**. Izin offline pada mode Testing dapat kedaluwarsa setelah 7 hari; hubungkan ulang bila perlu atau siapkan publishing sesuai persyaratan Google.
3. Buat OAuth Client ID bertipe **Web application**. Daftarkan **Authorized redirect URI** persis seperti yang ditampilkan di Pengaturan: `https://domain-kamu/api.php?action=google/callback`. Jangan gunakan URL preview untuk koneksi produksi.
4. Isi Client ID, Client Secret, callback HTTPS, dan waktu notifikasi di Pengaturan. Centang **Otomatis kirim reminder baru**, lalu **Simpan konfigurasi**.
5. Klik **Hubungkan akun Google**, pilih akun tujuan, dan setujui izin kalender. Setelah kembali, buka Pengaturan untuk memeriksa status. Client Secret yang dikosongkan saat menyimpan akan tetap memakai nilai lama. Mengganti kredensial/callback memutus koneksi lama dan memerlukan login ulang.
6. Buat reminder beberapa menit ke depan. Klik **Simpan** satu kali: reminder disimpan di website, lalu agenda dibuat melalui API di **kalender utama** akun Google yang terhubung, dengan alarm popup sesuai Pengaturan. Tidak perlu tombol Simpan kedua di Google.

### Perilaku dan batasan

- Satu akun/kalender utama untuk seluruh rumah, bukan koneksi per anggota. Judul, kategori, catatan, dan waktu reminder baru dari semua anggota dikirim ke kalender ini. Lampiran gambar tidak dikirim.
- Tanpa jam → pukul **09:00**, durasi agenda 30 menit, memakai zona waktu server. Setelan alarm baru berlaku pada agenda yang dibuat setelah pengaturan disimpan.
- Jika Google gagal, reminder **tetap tersimpan**. Pesan hasil simpan dan panel Pengaturan menjelaskan kegagalan terakhir. Tombol **Google ↻** pada reminder aktif milik sendiri (atau admin) mengirim/mencoba lagi; ID agenda tetap membuat retry aman jika respons Google sebelumnya timeout. Tombol ini juga bisa mengirim agenda lama satu per satu selama fitur otomatis aktif. Tidak ada retry latar belakang/cron.
- **Belum sinkronisasi dua arah**: impor massal, edit, selesai, aktifkan ulang, dan hapus tidak otomatis memperbarui agenda. Kelola agenda yang sudah terkirim langsung di Google. Retry agenda yang sudah ada tidak memperbarui judul/jadwal/alarm.
- Ikon kalender lama tetap tersedia sebagai alternatif formulir manual tanpa OAuth, tetapi menyimpan lewat formulir tersebut bisa membuat duplikat agenda otomatis.
- Google Calendar menentukan pengiriman notifikasi; aktifkan izin notifikasi/suara dan sinkronisasi akun di HP. Mode senyap, Jangan Ganggu, dan penghemat baterai dapat memengaruhinya. Uji di perangkat asli.
- Tombol putus koneksi menghapus refresh token lokal dan menonaktifkan otomatis, bukan menghapus agenda lama. Untuk mencabut persetujuan sepenuhnya, hapus akses aplikasi melalui halaman keamanan akun Google. Mengganti akun mengubah tujuan agenda berikutnya; agenda lama tetap di akun lama.

### Keamanan dan pemeliharaan

Konfigurasi hanya bisa dikelola admin. OAuth memakai state sekali pakai (10 menit), sesi login admin, dan PKCE; semua aksi POST dilindungi CSRF. Client Secret dan refresh token hanya di database server, tidak ditampilkan dalam respons Pengaturan; **nilai ini tersimpan di database dan ikut backup SQL**, sehingga lindungi akses database/backup seperti kata sandi. Jangan kirim kredensial melalui chat. Scope yang diminta `calendar.events.owned` untuk mengelola event pada kalender yang dimiliki akun.

Tidak memerlukan perubahan skema database: memakai tabel `app_settings` yang sudah ada. File runtime baru `includes/google-calendar.php` wajib ikut diunggah. Jika memperoleh `redirect_uri_mismatch`, samakan callback di Google Cloud dan Pengaturan, termasuk HTTPS dan query string. Jika `invalid_grant`, hubungkan ulang akun. Jika HTTP 403, periksa Calendar API, consent, dan izin akun.

Tes lokal: `node scripts/test-google-calendar.mjs` (PHP melalui WASM) dan `node scripts/test-calendar.mjs`. Tes OAuth dengan akun nyata dan notifikasi HP memerlukan deployment HTTPS serta kredensial Google milik admin; tes lokal tidak menggantikannya.


## Tindak lanjut laporan tim keamanan (headers, TLS, security.txt)

Lihat **SECURITY-REMEDIATION.md** untuk pemetaan nomor temuan laporan eksternal, langkah pemasangan, dan retest. Perubahan terbaru mencakup HSTS khusus HTTPS (awalnya 1 hari), header COOP/CORP/COEP produksi, header pada semua entry point PHP, lampiran no-store, dan pembatasan metode API.

`/.well-known/security.txt` disediakan melalui rewrite ke `security-contact.php`. Isi `security_contact` (mailto atau URL HTTPS kontak pelaporan yang kamu kuasai) dan `security_contact_expires` (UTC masa depan, maksimal sekitar satu tahun) di config.php agar aktif. Kontak akan menjadi publik; jika belum valid, endpoint sengaja mengembalikan 503, bukan alamat contoh. Pastikan mod_rewrite berfungsi. Opsi tersedia pada config.example.php; jangan menimpa config.php hosting.

HSTS bukan perbaikan sertifikat: verifikasi HTTPS/redirect dan domain melalui hosting sebelum menaikkan `hsts_max_age` dari 86400 ke 31536000. Tidak ada perubahan DNS/WAF/sertifikat otomatis. Patch ini belum diuji di domain produksi dan tidak menjamin skor scanner tertentu.


## Pembatas notifikasi (penguatan tahap 1)

Pengiriman WhatsApp melewati pembatas terpusat sebelum menghubungi Fonnte, termasuk tes koneksi, broadcast, notifikasi transaksi/reminder baru, bot, dan cron. Pembuatan agenda Google memiliki kuota terpisah sebelum refresh token/panggilan Calendar API.

- **30 percobaan per pengguna per 15 menit per layanan**. Proses tanpa sesi login (cron/bot) memakai satu kuota `system` dengan batas sama.
- **100 percobaan total satu rumah per 15 menit per layanan**. Satu tujuan WhatsApp dihitung satu percobaan: broadcast ke 5 nomor memakai hingga 5 slot. Google menghitung satu percobaan membuat agenda (termasuk proses refresh token), bukan setiap HTTP request.
- **Cooldown 60 detik per reminder per layanan** untuk tombol kirim manual WhatsApp/Google, dibagi antaranggota. Gagal mengirim tetap memakai cooldown; mengganti akun tidak melewatinya.
- Percobaan gagal juga dihitung. Counter disimpan di tabel `security_notification_limits`, bukan cookie, sehingga login ulang tidak menghapus batas. Tabel dibuat otomatis. Hosting memerlukan izin CREATE/INSERT/UPDATE/DELETE; kegagalan penyimpanan kuota menahan pengiriman, bukan melewatkan pembatas.
- Counter menggunakan jendela tetap sejak slot pertama. Reservasi beberapa scope bersifat konservatif: penolakan kuota rumah dapat tetap memakai slot pengguna. Ini bukan sliding-window atau perlindungan DDoS menyeluruh.
- Jika batas tercapai saat membuat reminder, data website tetap disimpan dan respons menjelaskan bahwa notifikasi tidak terkirim. Kirim ulang setelah masa tunggu melalui tombol manual. Tidak ada antrean retry baru. Broadcast yang hanya berhasil sebagian menyebut jumlah gagal; retry dapat mengirim lagi ke penerima yang sudah menerima. Cron lama dapat menganggap pengiriman parsial selesai, jadi periksa hasil jika ada banyak penerima.
- Endpoint reminder/manual Google dan tombol tes utama mengembalikan HTTP 429 + Retry-After saat dibatasi; operasi yang sekaligus menyimpan data tetap melaporkan keberhasilan penyimpanan dengan kegagalan notifikasi. Jalur laporan/bot dapat memakai format respons kegagalan yang sudah ada, tetapi tetap tidak memanggil penyedia setelah kuota habis.
- Jenis notifikasi reminder yang dinonaktifkan diperiksa **sebelum** pengiriman otomatis, bukan sesudah. Tombol manual tetap permintaan eksplisit pengguna.

Uji: `node scripts/test-notification-limits.mjs`. Tes menggunakan SQLite/PHP-WASM dan token fixture tanpa mengirim pesan nyata. Konkurensi MySQL serta pengiriman produksi tetap perlu retest di hosting. Nilai batas di atas ditetapkan dalam kode, belum ada menu konfigurasi kuota.

**Belum termasuk 2FA admin dan enkripsi token at-rest.** Token masih berada di database/backup; lindungi backup dan akses admin. Dua penguatan tersebut memerlukan tahap terpisah beserta pemulihan akun/kunci.


## Penguatan webhook dan pemeriksaan hak akses

- `whatsapp.php` tidak lagi mengembalikan `reply_text`, meskipun pemanggil memiliki secret webhook dan menambahkan `debug=1`. Sebelumnya kombinasi secret yang bocor dan nomor admin bot yang diketahui dapat membuka isi laporan melalui HTTP tanpa sesi dashboard. Ini direproduksi dengan data fixture lokal, bukan pada hosting produksi.
- Secret webhook hanya dibaca dari konfigurasi yang sudah dibuat lewat Pengaturan; akses anonim tidak membuat secret baru. Kegagalan membaca konfigurasi memberikan respons generik 503.
- Body webhook maksimal **64 KiB**, tipe dan panjang field penting divalidasi sebelum diproses. Metode yang didukung GET (pemeriksaan endpoint) dan POST (pesan masuk); yang lain ditolak. Batas ini untuk payload teks/metadata, bukan file media inline.
- Respons `replied` kini mengikuti hasil pengiriman sebenarnya, termasuk ketika layanan tidak terhubung atau kuota dibatasi. Cron juga tidak lagi menampilkan detail exception walaupun diberi `debug=1`; detail kegagalan tetap di log server.
- **Secret tetap rahasia:** pengirim dengan secret masih dapat memalsukan metadata webhook dan memicu bot ke kontak terdaftar. Patch ini mengurangi kebocoran lewat respons HTTP, tidak membuktikan identitas pengirim tanpa signature vendor. Jika pernah membagikan URL webhook, ganti kunci di Pengaturan lalu perbarui URL pada Fonnte. Pakai header `X-Webhook-Key` bila layanan pengirim mendukungnya; batasi akses log yang mungkin memuat query URL.
- Tes hak akses memastikan anggota tidak bisa edit/hapus transaksi dan reminder milik akun lain, mengganti/menghapus lampiran milik akun lain, mengirim Google untuk catatan orang lain, atau mereset password admin. `user_id` yang dipalsukan pada pembuatan transaksi diabaikan oleh server. Membaca data rumah bersama dan menandai reminder bersama tetap perilaku kolaboratif yang disengaja, bukan isolasi antar-tenant.

Tes: `node scripts/test-webhook-security.mjs` dan `node scripts/test-security.mjs`. Tidak mengirim pesan nyata dan tidak mengubah data produksi. Integrasi Fonnte nyata, batas payload provider, dan MySQL tetap perlu retest di hosting.


## Penguatan upload bukti dan format backup SQL

Gambar bukti dibatasi **8 megapiksel** sebelum decoding (sebelumnya 40 megapiksel). Batas byte terkompresi tetap 1.600.000 byte. Saat GD tersedia, aplikasi memperkirakan kebutuhan memori gambar/canvas dan menyisakan margin sebelum membuka gambar; gambar ditolak jika melampaui sisa `memory_limit`. File dengan decoder GD gagal tidak lagi diterima mentah. Jika muncul penolakan, kecilkan resolusi terlebih dahulu; browser biasanya sudah mengecilkan foto sebelum upload. Pemeriksaan ini mengurangi risiko kehabisan memori, bukan menjamin semua format/decoder bebas masalah. Jika GD tidak tersedia, pengecekan format/dimensi tetap berjalan tetapi tidak ada penulisan ulang atau penghapusan metadata di server.

Backup MySQL/MariaDB kini menulis teks sebagai `CONVERT(X'…' USING utf8mb4)`, bukan menggabungkan penggandaan backslash manual dengan `PDO::quote`. Tujuannya menjaga karakter backslash, kutip, Unicode, dan byte kontrol tanpa bergantung pada driver sumber atau mode backslash MySQL. **Hex adalah encoding reversibel, bukan enkripsi.** Secret dan hash password tetap dapat dipulihkan dari backup; jaga backup tetap privat. File SQL bisa lebih besar (hex kira-kira dua kali ukuran byte teks), sehingga periksa kuota/limit backup otomatis.

Format tetap untuk impor MySQL/MariaDB, bukan SQL SQLite langsung. Lakukan uji restore di database staging kosong sebelum bergantung pada backup baru. Tes `node scripts/test-backup-upload.mjs` memeriksa pemulihan byte literal, isi dump, PNG terkompresi kecil dengan 10 juta piksel yang ditolak sebelum decoding, kalkulasi anggaran memori, PNG kecil valid, penolakan SVG, dan batas ukuran byte. Tes lokal tidak menjalankan restore MySQL atau menguji penggunaan memori GD di InfinityFree.

## Filter daftar pengingat

Kategori dan status bisa digabung dengan satu tanggal periode. Pilih **19/11/2026** untuk menampilkan jadwal **1–19 November 2026**, termasuk seluruh tanggal 19. Bulan/tahun lain dan tanggal setelah pilihan tidak ikut. Tanggal awal otomatis tanggal 1 pada bulan yang dipilih, bukan rentang bebas. Nilai awal dan reset memakai hari ini dalam zona waktu aplikasi. Input tanggal menggunakan kalender browser (tampilan mengikuti locale perangkat); keterangan rentang selalu memakai DD/MM/YYYY. Ringkasan mengikuti kategori/periode; status hanya menyaring daftar.

Pilihan bertahan selama navigasi dalam sesi halaman, tidak disimpan setelah reload. Filter bekerja lokal pada maksimal 1.000 pengingat yang dikembalikan API; saat batas tersebut tercapai, UI menampilkan keterbatasan itu. Uji: `node scripts/test-reminder-filters.mjs`.

### Status Cancel pada pengingat

Tombol **Cancel** membatalkan tanpa menghapus data; ada konfirmasi, badge, dan filter **Cancel** yang bisa digabung kategori/periode. Pembuat/admin boleh membatalkan dan mengaktifkan ulang; perilaku kolaboratif tandai selesai/aktif pada pengingat non-cancel tetap seperti sebelumnya. Cancel tidak termasuk aktif/terlambat, tidak dipilih cron yang hanya mengambil `pending`, dan ditolak saat kirim WhatsApp manual. Tombol kalender disembunyikan; Google otomatis juga hanya menerima `pending`. Agenda yang sudah ada di Google Kalender **tidak otomatis dihapus**.

API `reminder/toggle` menerima status opsional `pending`, `done`, `cancel`; tanpa status, pengingat selesai/cancel kembali aktif. `completed_at` hanya terisi untuk `done`. Kolom status sudah VARCHAR(12), jadi tidak perlu migrasi database. Pengaktifan ulang tidak menghapus riwayat `due_notified_at`, agar notifikasi yang sudah pernah terkirim tidak terduplikasi; kirim manual bila diperlukan.

### Pemilih periode dashboard & akumulasi saldo akhir (ending balance)

Dashboard menyediakan bar **Periode (`‹ Bulan & Tahun ›` + tombol `Bulan ini`)** yang mengatur seluruh kartu ringkasan, grafik 6 bulan terakhir hingga periode tersebut, target pengeluaran, dan daftar maksimal 7 transaksi terbaru pada periode terpilih.

Rumus kartu **Saldo tercatat** memakai saldo akhir kumulatif berantai (*running ending balance*):
- `opening_balance` = total pemasukan dikurangi pengeluaran sebelum tanggal 1 bulan terpilih (sama dengan **total ending bulan sebelumnya**).
- `period_net` = pemasukan bulan terpilih dikurangi pengeluaran bulan terpilih.
- `balance` = `opening_balance + period_net` (transaksi pada bulan-bulan setelah periode terpilih tidak ikut dihitung).

Dengan begitu, pengguna dapat menggeser periode bulan demi bulan (mis. Jan 2026 → Feb 2026 → Mar 2026) untuk melacak di bulan mana selisih mulai terjadi terhadap saldo rekening nyata. Tes: `node scripts/test-dashboard-month.mjs`.

## Master kategori dan input manual

Form tambah/edit transaksi dan pengingat sekarang menggunakan input kategori dengan lookup (datalist browser). Pilih saran yang ada, atau ketik nama baru. Kategori baru masuk master ketika catatan disimpan, bukan ketika baru diketik. Lookup diambil ulang saat form dibuka sehingga kategori yang pernah digunakan tersedia di sesi/perangkat berikutnya. Ganti jenis transaksi untuk melihat lookup Uang Masuk atau Uang Keluar; teks yang sedang diketik tidak dihapus.

Tabel `categories`:
- `id`: primary key otomatis.
- `category_type`: `income`, `expense`, atau `reminder` (field pembeda jenis kategori).
- `name`: nama tampilan, maksimal 40 byte mengikuti batas kategori lama.
- `name_key`: SHA-256 nama yang dirapikan spasi dan dijadikan huruf kecil; bukan enkripsi.
- `UNIQUE(category_type, name_key)` mencegah duplikat dalam jenis yang sama, termasuk insert bersamaan. Nama yang sama pada jenis berbeda diperbolehkan karena lookup-nya berbeda. Huruf non-ASCII memakai case-fold lowercase bila ekstensi mbstring tersedia; tanpa mbstring lowercase hanya ASCII.

Aplikasi membuat tabel secara otomatis. Backfill satu kali membaca seluruh kategori transaksi/pengingat lama, bukan hanya 1.000 baris daftar UI, lalu menambah kategori bawaan. Penanda `categories_migrated_v1` disimpan hanya setelah backfill berhasil. Nama kategori pada catatan historis tidak diubah massal: varian tulisan lama tetap ada di laporan lama, tetapi master lookup tidak menggandakan nama yang ekuivalen. Catatan baru/edit/impor menggunakan nama canonical master. Tidak ditambahkan foreign key/category_id pada catatan lama; kolom `category` tetap kompatibel dengan laporan dan backup sebelumnya. Master kategori ikut backup SQL.

Endpoint `categories/list` memerlukan login. Penambahan kategori mengikuti hak akses simpan/edit catatan dan CSRF yang sudah ada. Tidak ada hapus/rename master pada tahap ini. Jika lookup gagal dimuat, pengguna diberi pesan dan tetap bisa mengetik manual.

**Pemasangan:** backup database terlebih dahulu, upload `includes/app.php`, `api.php`, `assets/app.js`, dan `index.php` bersama-sama (atau ZIP kumulatif). Akun database membutuhkan izin CREATE/INSERT/SELECT untuk master dan penanda migrasi. Jangan mengimpor ulang seluruh dump/schema ke database produksi untuk fitur ini. Skrip SQL di `database/` juga mencantumkan tabel baru untuk instalasi manual. Tes lokal menggunakan SQLite/PHP-WASM; migrasi dan konkurensi MySQL hosting tetap perlu verifikasi staging.

Tes baru: `node scripts/test-categories.mjs` dan `node scripts/test-category-ui.mjs` (backfill idempotent, batas scope, deduplikasi, create/edit/import, invalid input, backup, input manual, escaping, serta respons lookup terlambat).
