<?php
/**
 * Salin file ini menjadi config.php di folder aplikasi, lalu isi detail database.
 * Jangan unggah config.php ke Git atau membagikan token bot kepada orang lain.
 */
return [
    // Contoh format database MySQL dari panel hosting (InfinityFree / cPanel):
    'dsn' => 'mysql:host=sqlXXX.infinityfree.com;dbname=if0_12345678_rumah;charset=utf8mb4',
    'db_user' => 'if0_12345678',
    'db_password' => 'GANTI_DENGAN_PASSWORD_DATABASE',

    // Zona waktu untuk tanggal transaksi dan reminder.
    'timezone' => 'Asia/Jakarta',

    // Opsional. Dapat dikosongkan dan diatur dari menu Pengaturan setelah login admin.
    'telegram_bot_token' => '',
    'telegram_chat_id' => '',

    // Rahasia acak minimal 32 karakter untuk cron.php.
    // Contoh: hasilkan dengan `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
    'cron_secret' => '',
];
