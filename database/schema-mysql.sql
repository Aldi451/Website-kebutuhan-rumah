-- =====================================================================
--  Dashboard kebutuhan rumah - struktur database untuk MySQL / MariaDB
-- =====================================================================
--  Kapan file ini dipakai?
--  * Aplikasi SEBENARNYA sudah membuat tabel di bawah ini secara otomatis
--    saat pertama kali dibuka (lihat includes/app.php -> app_schema_statements()).
--  * File ini disediakan kalau kamu ingin membuat/memperbarui tabel secara
--    manual dari phpMyAdmin (tab "Import" atau "SQL").
--  * Semua perintah memakai "IF NOT EXISTS", jadi aman dijalankan berulang
--    dan tidak menghapus data yang sudah ada.
--
--  Cara pakai di InfinityFree / cPanel:
--  1. Buka phpMyAdmin -> pilih database rumah -> tab Import -> pilih file ini.
--  2. Atau tempel isi file ini ke tab SQL, lalu klik Go.
-- =====================================================================

SET NAMES utf8mb4;

-- Akun admin & anggota rumah -----------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(40) NOT NULL UNIQUE,
    display_name VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(16) NOT NULL DEFAULT 'member',
    phone VARCHAR(60) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uang masuk & uang keluar -------------------------------------------
CREATE TABLE IF NOT EXISTS transactions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(10) NOT NULL,
    title VARCHAR(100) NOT NULL,
    category VARCHAR(40) NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    transaction_date DATE NOT NULL,
    note TEXT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    KEY transaction_date_lookup (transaction_date),
    KEY transaction_user_lookup (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Target pengeluaran per bulan ---------------------------------------
CREATE TABLE IF NOT EXISTS monthly_targets (
    month_key CHAR(7) NOT NULL PRIMARY KEY,
    amount BIGINT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pengingat keluarga --------------------------------------------------
CREATE TABLE IF NOT EXISTS reminders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    category VARCHAR(40) NOT NULL DEFAULT 'Lainnya',
    reminder_at DATETIME NOT NULL,
    has_time TINYINT(1) NOT NULL DEFAULT 1,  -- 0 = pengingat sepanjang hari (jam tidak ditentukan)
    note TEXT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'pending',
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    due_notified_at DATETIME NULL,
    KEY reminder_due_lookup (status, reminder_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pengaturan aplikasi (token Fonnte / notifikasi WhatsApp, dsb.) ------------
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bukti gambar (uang masuk/keluar & pengingat) ------------------------
-- Gambar sudah dikompres di browser dan di server sebelum masuk ke sini,
-- rata-rata hanya puluhan sampai ratusan KB per bukti.
CREATE TABLE IF NOT EXISTS attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(16) NOT NULL,      -- 'transaction' atau 'reminder'
    entity_id INT UNSIGNED NOT NULL,       -- id baris transaksi/pengingat
    mime VARCHAR(40) NOT NULL,             -- contoh: image/jpeg
    file_name VARCHAR(140) NOT NULL DEFAULT '',
    byte_size INT UNSIGNED NOT NULL,
    width INT UNSIGNED NOT NULL DEFAULT 0,
    height INT UNSIGNED NOT NULL DEFAULT 0,
    image_data MEDIUMBLOB NOT NULL,        -- isi gambar
    uploaded_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    KEY attachment_lookup (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nomor WhatsApp yang boleh memakai bot laporan ----------------------
-- access: 'admin' = boleh melihat semua angka, 'member' = pengeluaran & target.
CREATE TABLE IF NOT EXISTS wa_contacts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(60) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL DEFAULT '',
    access VARCHAR(10) NOT NULL DEFAULT 'member',
    active TINYINT(1) NOT NULL DEFAULT 1,
    user_id INT UNSIGNED NULL,
    last_message_at DATETIME NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Akun admin awal tidak ditulis di sini dengan sengaja.
-- Saat tabel "users" masih kosong, aplikasi otomatis membuat akun:
--   Username: ALDI · Password: admin123  (ganti segera setelah login).
-- Password disimpan sebagai hash, jadi tidak bisa ditulis manual di file SQL.
