<?php
/**
 * Salin file ini menjadi config.php di folder aplikasi, lalu isi detail database.
 * Jangan unggah config.php ke Git atau membagikan token Fonnte kepada orang lain.
 *
 * Tabel database dibuat & diperbarui otomatis saat aplikasi pertama dibuka
 * (lihat includes/app.php). Kalau ingin membuatnya manual lewat phpMyAdmin,
 * impor file di folder database/: schema-mysql.sql (baru) atau
 * upgrade-mysql.sql (untuk database yang sudah ada).
 */
return [
    // Contoh format database MySQL dari panel hosting (InfinityFree / cPanel):
    'dsn' => 'mysql:host=sqlXXX.infinityfree.com;dbname=if0_12345678_rumah;charset=utf8mb4',
    'db_user' => 'if0_12345678',
    'db_password' => 'GANTI_DENGAN_PASSWORD_DATABASE',

    // Wajib pada instalasi baru, atau untuk mengganti password admin bawaan lama.
    // Isi password UNIK 12–72 byte di config.php (jangan di file contoh/repository).
    // Setelah berhasil login, hapus nilai ini; password tersimpan sebagai hash di database.
    // Tidak akan mengganti password akun yang sebelumnya sudah dikustomisasi.
    'admin_bootstrap_password' => '',

    // HSTS hanya dikirim pada HTTPS asli. Awali 1 hari; naikkan ke 31536000
    // setelah SSL dan redirect HTTPS hosting terverifikasi. 0 untuk mencabut HSTS.
    // Tidak mengaktifkan includeSubDomains/preload. Jangan percaya header proxy publik.
    'hsts_max_age' => 86400,

    // Kontak keamanan PUBLIK untuk /.well-known/security.txt (RFC 9116).
    // mailto:alamat-yang-kamu-pantau atau URL HTTPS formulir pelaporan milikmu.
    // Wajib isi keduanya: kontak nyata dan expiry UTC masa depan (maksimal 1 tahun).
    // Jika kosong/invalid/kedaluwarsa, endpoint mengembalikan 503, bukan kontak palsu.
    'security_contact' => '',
    'security_contact_expires' => '',

    // Zona waktu untuk tanggal transaksi dan reminder.
    'timezone' => 'Asia/Jakarta',

    // Opsional. Notifikasi WhatsApp lewat Fonnte (https://fonnte.com).
    // Dapat dikosongkan dan diatur dari menu Pengaturan setelah login admin.
    'fonnte_token' => '',
    'fonnte_target' => '',

    // Secret HMAC untuk Worker backup Telegram eksternal (minimal 32 karakter acak).
    // Isi hanya di config.php hosting; nilainya harus sama dengan secret di Cloudflare Worker.
    'telegram_backup_secret' => '',

    // Rahasia acak minimal 32 karakter untuk cron.php.
    // Contoh: hasilkan dengan `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
    'cron_secret' => '',
];
