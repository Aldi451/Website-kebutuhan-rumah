# Dashboard kebutuhan rumah

Website pribadi bergaya dashboard merchant/fintech: ringkas, bersih, dengan aksen hijau-teal. Fokusnya untuk mencatat keuangan rumah dan menyimpan pengingat keluarga.

## Fitur

- **Ringkasan keuangan:** saldo dari transaksi yang tercatat, pemasukan/pengeluaran bulan ini, grafik arus kas enam bulan, dan transaksi terbaru.
- **Catat uang masuk & keluar:** kategori, tanggal, nominal Rupiah, catatan opsional, dan **bukti foto** yang boleh dikosongkan. Semua anggota rumah berbagi buku catatan yang sama; tiap baris tetap mencantumkan pembuatnya.
- **Laporan keuangan:** rekap per kategori untuk hari ini, bulan ini sampai hari ini, bulan lalu, tahun ini, tahun lalu, atau rentang tanggal. Bisa dibandingkan dengan periode sepadan (misalnya bensin bulan ini vs bulan lalu) dan diunduh sebagai CSV.
- **Target pengeluaran bulanan:** buat batas belanja untuk bulan yang dipilih dan lihat progres dari total uang keluar.
- **Bukti gambar:** foto nota, struk, transfer, atau kondisi barang bisa dilampirkan pada uang masuk/keluar maupun pengingat. Gambar **dikompres dulu di HP/browser** (maksimal 1600 px, ± 20–500 KB) lalu dikompres lagi di server, baru disimpan ke database. Fitur ini **opsional**: kolom bukti bisa dilewati kapan saja, dan bukti bisa ditambah, diganti, atau dihapus belakangan tanpa mengubah catatannya.
- **Backup & cek database:** menu **Pengaturan** menampilkan jumlah baris tiap tabel dan ukuran bukti gambar yang tersimpan, plus tombol **Unduh backup .sql** untuk menyimpan salinan seluruh data (siap diimpor ke phpMyAdmin).
- **Pengingat:** simpan judul, tanggal dan jam, kategori, catatan, dan bukti foto opsional, lalu tandai selesai atau hapus. Contoh: “Ganti oli motor” tanggal 26.
- **Import massal Excel:** unggah transaksi atau pengingat dari workbook `.xlsx` atau CSV, pratinjau baris yang valid, dan tambahkan sampai 500 baris sekaligus.
- **Akun admin dan anggota:** admin dapat menambahkan akun anggota dan mengatur ulang kata sandinya. Setiap pengguna dapat mengganti kata sandi akunnya sendiri.
- **Bot Telegram opsional:** bot menerima kabar saat pengingat baru disimpan. Endpoint cron tersedia untuk mengirim pesan lagi ketika waktunya tiba.
- **Bahasa Indonesia dan Rupiah**, responsif untuk HP maupun desktop.

## Di mana datanya tersimpan (dan SQL-nya di mana)?

Aplikasi **tidak** memakai file SQL terpisah yang harus dijalankan lebih dulu. Tabel database
dibuat dan diperbarui otomatis setiap kali website dibuka, langsung dari kode di
`includes/app.php`:

- `app_schema_statements()` — daftar lengkap `CREATE TABLE` untuk pengguna, transaksi,
  target bulanan, pengingat, pengaturan, dan bukti gambar (`attachments`).
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

1. Buka menu **Keuangan** atau **Pengingat**, lalu pilih **Import Excel**.
2. Unduh template CSV yang sesuai (bisa dibuka dan diedit di Excel), atau pilih workbook `.xlsx` yang sudah ada. Format `.xls` lama perlu disimpan ulang sebagai `.xlsx` atau CSV UTF-8.
3. Baris pertama pada sheet pertama harus berisi nama kolom. Untuk transaksi, kolom wajibnya **Jenis**, **Nama transaksi**, **Nominal**, dan **Tanggal**; jenis diisi `Pemasukan` atau `Pengeluaran`. Untuk pengingat, kolom wajibnya **Judul** dan **Tanggal**; **Jam** opsional dan akan menjadi 09:00 bila kosong. Kategori dan catatan bersifat opsional.
4. Tanggal dapat ditulis `YYYY-MM-DD` atau `DD/MM/YYYY`. Periksa pratinjau; baris yang tidak valid dilewati dan daftar masalahnya ditampilkan. Maksimal 500 baris per file, ukuran maksimal 10 MB.

Setiap impor menambahkan catatan baru—mengunggah file yang sama lagi dapat menghasilkan duplikat. Pengingat impor tidak mengirim pesan Telegram satu per satu; notifikasi saat waktunya tetap mengikuti jadwal cron yang sudah dipasang.

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

Melihat, mengganti, atau menghapus bukti yang sudah tersimpan:

- Klik ikon **kamera** pada baris transaksi, tabel **Keuangan**, daftar **Pengingat**, atau pada
  kartu di halaman **Ringkasan**. Jendela bukti akan menampilkan gambar ukuran penuh.
- **Ganti foto** memilih gambar baru (otomatis dikompres lalu ditimpa). **Hapus bukti** hanya
  menghapus gambarnya, catatan keuangannya tetap ada. **Unduh gambar** menyimpan file ke perangkat.
- Izinnya sama seperti menghapus catatan: pembuat catatan atau admin rumah. Anggota lain tetap
  bisa melihat buktinya karena data rumah dibagikan bersama.
- Batas aman di server: gambar hasil kiriman maksimal ± 1,6 MB dan 12.000 px; gambar yang lebih
  besar ditolak dengan pesan yang menjelaskan cara memperbaikinya.

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

Pada database yang baru dibuat, akun admin otomatis disiapkan:

- **Username:** `ALDI`
- **Password:** `admin123`

Segera ganti kata sandi ini di **Pengaturan → Keamanan akun** setelah login, terutama sebelum situs dibuka untuk pengguna lain.

## Coba di komputer lokal

Perlu Node.js 22 atau lebih baru. PHP 8.2 WebAssembly digunakan hanya untuk menjalankan preview lokal; situs yang diunggah ke hosting tetap berjalan sebagai PHP biasa.



## Pasang di InfinityFree / shared hosting PHP

Tidak perlu Node.js di server hosting. Aplikasi menggunakan PHP 8.1+ dan **PDO MySQL**; folder `storage/` juga memiliki proteksi `.htaccess` untuk instalasi lokal SQLite.

1. Buat database MySQL dari panel hosting. Catat host, nama database, username, dan password database dari panel tersebut.
2. Salin `config.example.php` menjadi `config.php`. Isi `dsn`, `db_user`, dan `db_password` sesuai detail hosting. Contoh DSN tersedia di dalam file; gunakan nilai host yang benar dari panel akunmu. Set `timezone` ke `Asia/Jakarta`.
3. Unggah isi repository ke folder web hosting (umumnya `htdocs` atau `public_html`), termasuk `.htaccess`, folder `assets/`, `includes/`, `storage/`, dan `database/`. Jangan unggah `node_modules/` atau `storage/rumahku.sqlite` dari komputer lokal.
4. Buka domain. Tabel database dan akun admin awal dibuat otomatis saat pertama kali aplikasi dijalankan. Kalau lebih suka membuat tabelnya sendiri lebih dulu, impor `database/schema-mysql.sql` lewat phpMyAdmin, atau `database/upgrade-mysql.sql` untuk database yang sudah ada. Jika hosting tidak mengaktifkan ekstensi PDO MySQL, GD, atau memblokir pembuatan tabel, minta hosting mengaktifkannya.
5. Login, lalu langsung ganti kata sandi admin. Dari menu **Anggota rumah**, admin dapat membuat akun tambahan.
6. Cek **Pengaturan → Backup & database** untuk memastikan jumlah barisnya sudah sesuai, lalu unduh backup pertama sebagai salinan.

`config.php` sengaja tidak disertakan di Git. Simpan salinan rahasianya dengan aman. Jangan kirim password database atau token Telegram ke chat publik. Backup berkala dapat dilakukan dari menu Pengaturan (unduh `.sql`) atau dengan mengekspor database dari phpMyAdmin.

Ekstensi PHP yang dipakai: **PDO MySQL/SQLite** (wajib), **GD** (untuk kompresi ulang gambar bukti di server; kalau GD tidak aktif aplikasi tetap berjalan, gambar hanya dikompres di browser), dan **fileinfo** (opsional).

## Hubungkan bot Telegram

1. Buat bot menggunakan **@BotFather**, lalu simpan token yang diberikan.
2. Buka chat bot tersebut dan kirim `/start`.
3. Login sebagai admin, buka **Pengaturan → Notifikasi Telegram**, tempel token, lalu pilih **Cari Chat ID**.
4. Pilih **Simpan & hubungkan**. Aplikasi memverifikasi bot dan mengirim pesan percobaan. Setelah terhubung, setiap pengingat baru akan mengirim pesan ke chat tersebut.

Token bot disimpan di database server dan tidak dikirim kembali ke browser. Koneksi keluar HTTPS dari hosting ke `api.telegram.org` harus diizinkan — **hosting gratis InfinityFree memblokirnya**, lihat [Troubleshooting Telegram](#troubleshooting-telegram). Jika fitur Cari Chat ID tidak menemukan pesan, kirim `/start` lagi dan coba ulangi.

### Notifikasi saat waktu pengingat tiba

Pengingat tetap tersimpan dan terlihat di situs tanpa cron, tetapi pesan Telegram pada **tanggal/jam jatuh tempo** perlu pemicu terjadwal. Jika paket hosting menyediakan cron, jadwalkan permintaan ke:

```text
https://DOMAIN-KAMU/cron.php?key=RAHASIA_CRON
```

Atur `cron_secret` di `config.php` dengan nilai acak rahasia minimal 32 karakter; endpoint akan menolak permintaan jika nilainya kosong atau salah. Jadwalkan setiap 5–15 menit. Jika paket gratis tidak menyediakan cron, gunakan scheduler eksternal yang tepercaya dan jangan membagikan URL/rahasia tersebut. Pesan saat pengingat **dibuat** tetap dikirim langsung jika bot sudah terhubung.

### Troubleshooting Telegram

**Error `Could not resolve host: api.telegram.org`** (atau pesan “Server hosting tidak bisa menghubungi Telegram”)

Artinya **server hosting** tidak bisa menjangkau Telegram. Token dan Chat ID belum sempat diperiksa Telegram, jadi isiannya belum tentu salah. Aplikasi memanggil `https://api.telegram.org` dari server (bukan dari browser), sehingga hosting harus mengizinkan koneksi keluar ke sana.

- **InfinityFree paket gratis memblokir koneksi keluar ke Telegram** karena penyalahgunaan. Error ini muncul walau token benar dan tidak bisa diatasi dari kode aplikasi ([penjelasan di forum InfinityFree](https://forum.infinityfree.com/t/request-post/116465)). Admin InfinityFree menyebut paket premium kemungkinan besar tidak memblokirnya, tetapi pastikan dulu ke penyedia hosting.
- Di hosting lain: pastikan koneksi keluar HTTPS (port 443) ke `api.telegram.org` diizinkan dan ekstensi PHP cURL/OpenSSL aktif. Jika masih gagal, minta bantuan penyedia hosting.
- `npm start` (preview lokal) memakai PHP WebAssembly tanpa akses internet, jadi Telegram tidak bisa diuji di sana. Aplikasi menampilkan pesan penjelasan, bukan error server.

**Pesan lain setelah koneksi berhasil**

- `Forbidden: bot can't initiate conversation with a user` → buka chat bot di Telegram, tekan **Start** (atau kirim `/start`), lalu coba lagi.
- `Unauthorized` → token salah atau sudah dicabut; salin ulang dari @BotFather.
- `Bad Request: chat not found` → Chat ID salah. Kirim `/start` ke bot lalu pakai tombol **Cari Chat ID**.

**Token pernah terkirim ke chat atau tempat umum?** Anggap sudah bocor. Buka @BotFather → `/revoke` → pilih bot, lalu simpan token baru di **Pengaturan → Notifikasi Telegram**.

## Keamanan dan batasan

- Password akun disimpan dalam bentuk hash, bukan teks biasa. API menggunakan prepared statement, session cookie `HttpOnly`/`SameSite`, dan perlindungan CSRF.
- `storage/` berisi database SQLite lokal dan ditolak oleh `.htaccess`. Untuk hosting, gunakan MySQL dan jangan membuat database dapat diakses publik.
- Akun anggota yang ditambahkan berbagi data rumah bersama. Penghapusan catatan dibatasi pada pembuatnya atau admin.
- Satu target pengeluaran disimpan untuk tiap bulan. Target mengukur **pengeluaran yang sudah dicatat**, jadi hasilnya bergantung pada kelengkapan input transaksi.
- Gambar bukti disimpan di dalam database (tabel `attachments`) dalam bentuk JPEG terkompresi, biasanya puluhan sampai ratusan KB per gambar. Karena itu jaga ukurannya: kuota database hosting gratis umumnya terbatas, dan menghapus bukti yang tidak diperlukan akan mengembalikan ruang.
- Bukti gambar bisa dilihat semua anggota rumah yang login (seperti catatan lain). Jangan simpan foto yang tidak ingin dilihat anggota lain.
- Ini alat pencatatan pribadi, bukan aplikasi bank atau pembukuan pajak. Selalu simpan backup.

## Ide lanjutan yang cocok untuk website pribadi

Kalau ingin dikembangkan setelah versi dasar ini, yang paling berguna biasanya: ekspor transaksi ke CSV/Excel, transaksi berulang (listrik/internet/langganan), target dana darurat atau tabungan, inventaris barang rumah beserta garansi/nota servis, dan ringkasan pengeluaran per kategori. Untuk pemakaian keluarga, riwayat perubahan dan backup otomatis juga layak diprioritaskan.
