# Dashboard kebutuhan rumah

Website pribadi bergaya dashboard merchant/fintech: ringkas, bersih, dengan aksen hijau-teal. Fokusnya untuk mencatat keuangan rumah dan menyimpan pengingat keluarga.

## Fitur

- **Ringkasan keuangan:** saldo dari transaksi yang tercatat, pemasukan/pengeluaran bulan ini, grafik arus kas enam bulan, dan transaksi terbaru.
- **Catat uang masuk & keluar:** kategori, tanggal, nominal Rupiah, dan catatan opsional. Semua anggota rumah berbagi buku catatan yang sama; tiap baris tetap mencantumkan pembuatnya.
- **Laporan keuangan:** rekap per kategori untuk hari ini, bulan ini sampai hari ini, bulan lalu, tahun ini, tahun lalu, atau rentang tanggal. Bisa dibandingkan dengan periode sepadan (misalnya bensin bulan ini vs bulan lalu) dan diunduh sebagai CSV.
- **Target pengeluaran bulanan:** buat batas belanja untuk bulan yang dipilih dan lihat progres dari total uang keluar.
- **Pengingat:** simpan judul, tanggal dan jam, kategori, catatan, lalu tandai selesai atau hapus. Contoh: “Ganti oli motor” tanggal 26.
- **Import massal Excel:** unggah transaksi atau pengingat dari workbook `.xlsx` atau CSV, pratinjau baris yang valid, dan tambahkan sampai 500 baris sekaligus.
- **Akun admin dan anggota:** admin dapat menambahkan akun anggota dan mengatur ulang kata sandinya. Setiap pengguna dapat mengganti kata sandi akunnya sendiri.
- **Bot Telegram opsional:** bot menerima kabar saat pengingat baru disimpan. Endpoint cron tersedia untuk mengirim pesan lagi ketika waktunya tiba.
- **Bahasa Indonesia dan Rupiah**, responsif untuk HP maupun desktop.

## Import transaksi dan pengingat dari Excel

1. Buka menu **Keuangan** atau **Pengingat**, lalu pilih **Import Excel**.
2. Unduh template CSV yang sesuai (bisa dibuka dan diedit di Excel), atau pilih workbook `.xlsx` yang sudah ada. Format `.xls` lama perlu disimpan ulang sebagai `.xlsx` atau CSV UTF-8.
3. Baris pertama pada sheet pertama harus berisi nama kolom. Untuk transaksi, kolom wajibnya **Jenis**, **Nama transaksi**, **Nominal**, dan **Tanggal**; jenis diisi `Pemasukan` atau `Pengeluaran`. Untuk pengingat, kolom wajibnya **Judul** dan **Tanggal**; **Jam** opsional dan akan menjadi 09:00 bila kosong. Kategori dan catatan bersifat opsional.
4. Tanggal dapat ditulis `YYYY-MM-DD` atau `DD/MM/YYYY`. Periksa pratinjau; baris yang tidak valid dilewati dan daftar masalahnya ditampilkan. Maksimal 500 baris per file, ukuran maksimal 10 MB.

Setiap impor menambahkan catatan baru—mengunggah file yang sama lagi dapat menghasilkan duplikat. Pengingat impor tidak mengirim pesan Telegram satu per satu; notifikasi saat waktunya tetap mengikuti jadwal cron yang sudah dipasang.

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

```bash
npm install
npm start
```

Buka alamat yang dicetak di terminal (biasanya `http://localhost:4173`), lalu login dengan akun awal di atas. Preview memakai SQLite dan menyimpan database lokal di `storage/rumahku.sqlite` (file ini diabaikan Git). Untuk menghentikan preview, tekan `Ctrl+C`.

## Pasang di InfinityFree / shared hosting PHP

Tidak perlu Node.js di server hosting. Aplikasi menggunakan PHP 8.1+ dan **PDO MySQL**; folder `storage/` juga memiliki proteksi `.htaccess` untuk instalasi lokal SQLite.

1. Buat database MySQL dari panel hosting. Catat host, nama database, username, dan password database dari panel tersebut.
2. Salin `config.example.php` menjadi `config.php`. Isi `dsn`, `db_user`, dan `db_password` sesuai detail hosting. Contoh DSN tersedia di dalam file; gunakan nilai host yang benar dari panel akunmu. Set `timezone` ke `Asia/Jakarta`.
3. Unggah isi repository ke folder web hosting (umumnya `htdocs` atau `public_html`), termasuk `.htaccess`, folder `assets/`, `includes/`, dan `storage/`. Jangan unggah `node_modules/` atau `storage/rumahku.sqlite` dari komputer lokal.
4. Buka domain. Tabel database dan akun admin awal dibuat otomatis saat pertama kali aplikasi dijalankan. Jika hosting tidak mengaktifkan ekstensi PDO MySQL atau memblokir pembuatan tabel, minta hosting mengaktifkannya.
5. Login, lalu langsung ganti kata sandi admin. Dari menu **Anggota rumah**, admin dapat membuat akun tambahan.

`config.php` sengaja tidak disertakan di Git. Simpan salinan rahasianya dengan aman. Jangan kirim password database atau token Telegram ke chat publik. Backup berkala dapat dilakukan dengan mengekspor database dari phpMyAdmin.

## Hubungkan bot Telegram

1. Buat bot menggunakan **@BotFather**, lalu simpan token yang diberikan.
2. Buka chat bot tersebut dan kirim `/start`.
3. Login sebagai admin, buka **Pengaturan → Notifikasi Telegram**, tempel token, lalu pilih **Cari Chat ID**.
4. Pilih **Simpan & hubungkan**. Aplikasi memverifikasi bot dan mengirim pesan percobaan. Setelah terhubung, setiap pengingat baru akan mengirim pesan ke chat tersebut.

Token bot disimpan di database server dan tidak dikirim kembali ke browser. Koneksi keluar HTTPS dari hosting ke `api.telegram.org` harus diizinkan. Jika fitur Cari Chat ID tidak menemukan pesan, kirim `/start` lagi dan coba ulangi.

### Notifikasi saat waktu pengingat tiba

Pengingat tetap tersimpan dan terlihat di situs tanpa cron, tetapi pesan Telegram pada **tanggal/jam jatuh tempo** perlu pemicu terjadwal. Jika paket hosting menyediakan cron, jadwalkan permintaan ke:

```text
https://DOMAIN-KAMU/cron.php?key=RAHASIA_CRON
```

Atur `cron_secret` di `config.php` dengan nilai acak rahasia minimal 32 karakter; endpoint akan menolak permintaan jika nilainya kosong atau salah. Jadwalkan setiap 5–15 menit. Jika paket gratis tidak menyediakan cron, gunakan scheduler eksternal yang tepercaya dan jangan membagikan URL/rahasia tersebut. Pesan saat pengingat **dibuat** tetap dikirim langsung jika bot sudah terhubung.

## Keamanan dan batasan

- Password akun disimpan dalam bentuk hash, bukan teks biasa. API menggunakan prepared statement, session cookie `HttpOnly`/`SameSite`, dan perlindungan CSRF.
- `storage/` berisi database SQLite lokal dan ditolak oleh `.htaccess`. Untuk hosting, gunakan MySQL dan jangan membuat database dapat diakses publik.
- Akun anggota yang ditambahkan berbagi data rumah bersama. Penghapusan catatan dibatasi pada pembuatnya atau admin.
- Satu target pengeluaran disimpan untuk tiap bulan. Target mengukur **pengeluaran yang sudah dicatat**, jadi hasilnya bergantung pada kelengkapan input transaksi.
- Ini alat pencatatan pribadi, bukan aplikasi bank atau pembukuan pajak. Selalu simpan backup.

## Ide lanjutan yang cocok untuk website pribadi

Kalau ingin dikembangkan setelah versi dasar ini, yang paling berguna biasanya: ekspor transaksi ke CSV/Excel, transaksi berulang (listrik/internet/langganan), target dana darurat atau tabungan, inventaris barang rumah beserta garansi/nota servis, dan ringkasan pengeluaran per kategori. Untuk pemakaian keluarga, riwayat perubahan dan backup otomatis juga layak diprioritaskan.
