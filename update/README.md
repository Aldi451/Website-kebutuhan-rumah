# Paket Update Lengkap Website (`website-update.zip`)

Tidak perlu mengunggah atau memilih file satu per satu. Seluruh skrip yang perlu diperbarui sudah digabung dengan susunan folder yang persis sama dengan `htdocs/` ke dalam satu file siap pakai: **`update/website-update.zip`**.

---

## 1. Cara Paling Mudah: Upload 1x via ZIP (Disarankan)

1. **Backup database terlebih dahulu** (lewat menu **Pengaturan → Backup Database SQL** atau phpMyAdmin), lalu simpan file backup di komputer pribadi (**jangan** taruh file `.sql` di dalam `htdocs`).
2. Buka **File Manager** hosting → masuk ke folder **`htdocs`** (tempat `index.php` dan `config.php` berada).
3. Unggah **`update/website-update.zip`** ke dalam `htdocs` → klik kanan **Extract / Unzip** (pilih *Overwrite / Replace existing files*).
4. **Hapus kembali file `website-update.zip`** dari `htdocs` setelah selesai diekstrak, lalu buka website dan tekan **`Ctrl + F5`** (hard refresh).

> **100% Aman untuk Data & Konfigurasi Lama:**
> Paket `website-update.zip` **TIDAK** berisi `config.php` maupun database/foto struk lama. Jadi pengaturan koneksi MySQL/SQLite serta seluruh data transaksi dan pengingat Anda tetap utuh.

---

## 2. Daftar Lengkap 21 Berkas di Dalam `website-update.zip`

Jika Anda ingin tahu persis skrip apa saja yang ikut diperbarui saat mengekstrak `website-update.zip` (atau jika File Manager hosting Anda tidak mendukung fitur Extract ZIP sehingga perlu upload dari folder `update/website/`):

### A. Folder Utama (`htdocs/`)
| Berkas di ZIP | Tujuan di Hosting | Fungsi Pembaruan |
| --- | --- | --- |
| `index.php` | `htdocs/index.php` | Halaman utama dashboard, CSP nonce, & versi cache aset terbaru (`?v=35` / `?v=20`) |
| `api.php` | `htdocs/api.php` | Seluruh endpoint API, anti-lockout login, otorisasi admin/anggota, & keamanan lampiran |
| `whatsapp.php` | `htdocs/whatsapp.php` | Endpoint webhook bot WhatsApp Fonnte (batas ukuran & validasi ketat) |
| `cron.php` | `htdocs/cron.php` | Endpoint pengingat otomatis & laporan bulanan (klaim atomik anti-dobel kirim) |
| `telegram-backup.php` | `htdocs/telegram-backup.php` | Endpoint backup otomatis ke Telegram (anti-replay signature sekali pakai & rate limit) |
| `security-contact.php` | `htdocs/security-contact.php` | Endpoint standar `/.well-known/security.txt` |
| `config.example.php` | `htdocs/config.example.php` | Contoh referensi opsi konfigurasi terbaru *(tidak menimpa `config.php` asli)* |
| `.htaccess` | `htdocs/.htaccess` | Security headers Apache, HSTS HTTPS, rewrite `security.txt`, & blokir file sensitif |

### B. Folder Modul PHP (`htdocs/includes/`)
| Berkas di ZIP | Tujuan di Hosting | Fungsi Pembaruan |
| --- | --- | --- |
| `includes/app.php` | `htdocs/includes/app.php` | Skema database otomatis, cookie `__Host-` HTTPS, enkripsi setting transparan, & pembersih EXIF |
| `includes/security.php` | `htdocs/includes/security.php` | Enkripsi AEAD (`AES-256-GCM`), anti-lockout login `(IP+akun)`, & validasi Host/Proxy |
| `includes/wa.php` | `htdocs/includes/wa.php` | Integrasi Fonnte, URL publik aman, & deduplikasi pesan atomik |
| `includes/google-calendar.php` | `htdocs/includes/google-calendar.php` | Integrasi OAuth & sinkronisasi agenda Google Kalender |
| `includes/.htaccess` | `htdocs/includes/.htaccess` | Menolak akses langsung dari browser ke folder `includes/` |

### C. Folder Tampilan & Skrip Frontend (`htdocs/assets/`)
| Berkas di ZIP | Tujuan di Hosting | Fungsi Pembaruan |
| --- | --- | --- |
| `assets/app.js` | `htdocs/assets/app.js` | Logika UI dashboard, laporan, pengingat, kontrol akses target/pengingat, & sanitasi CSV |
| `assets/style.css` | `htdocs/assets/style.css` | Desain tema terang, responsif mobile/desktop, & tata letak tombol aksi |
| `assets/importer.js` | `htdocs/assets/importer.js` | Pembaca file `.xlsx`/`.csv` dengan proteksi ZIP bomb & batas memori |
| `assets/clipboard-import.js` | `htdocs/assets/clipboard-import.js` | Fitur tempel cepat tabel transaksi/pengingat dari Excel/clipboard |
| `assets/calendar.js` | `htdocs/assets/calendar.js` | Pembuat tautan Google Calendar |
| `assets/brand.svg` | `htdocs/assets/brand.svg` | Ikon identitas aplikasi |

### D. Proteksi Folder Penyimpanan (`htdocs/storage/` & `htdocs/database/`)
| Berkas di ZIP | Tujuan di Hosting | Fungsi Pembaruan |
| --- | --- | --- |
| `storage/.htaccess` | `htdocs/storage/.htaccess` | Mencegah pengunduhan langsung database SQLite & file mentah di `storage/` |
| `database/.htaccess` | `htdocs/database/.htaccess` | Mencegah pengunduhan langsung file skema `.sql` bila folder `database/` ada di hosting |

---

## 3. Tambahan Konfigurasi di `htdocs/config.php` (Sangat Disarankan)

Agar token WhatsApp/Google di database ikut **terenkripsi otomatis** dan URL publik terkunci aman, buka file `htdocs/config.php` Anda (jangan ditimpa, cukup tambahkan baris berikut di dalam `return [ ... ];`):

```php
'public_base_url' => 'https://anggaran.page.gd', // sesuaikan dengan domain aktif Anda
'app_encryption_key' => 'ISI_DENGAN_TEKS_ACAK_MINIMAL_32_KARAKTER_DAN_SIMPAN_CADANGANNYA',
'security_contact' => 'mailto:email-aktif-anda@domain.com',
'security_txt_expires' => '2027-06-30T00:00:00Z',
```

> **Penting:** Simpan salinan nilai `app_encryption_key` di catatan pribadi/password manager Anda. Kunci tersebut diperlukan untuk membuka kembali token yang terenkripsi apabila suatu saat Anda memindahkan database ke hosting baru.

---

## 4. Pemeriksa Hasil Pemasangan (Opsional)

Jika ingin memastikan semua file terekstrak di folder yang tepat:
1. Pastikan Anda **sudah login sebagai Admin** di website.
2. Unggah file `update/cek-pasang.php` ke `htdocs/cek-pasang.php`, lalu buka `https://domain-anda/cek-pasang.php`.
3. Halaman akan menampilkan status **OK / PERIKSA / MASALAH** untuk setiap berkas.
4. Setelah semua berstatus **OK**, klik tombol **Hapus `cek-pasang.php` sekarang** di bagian bawah halaman.
