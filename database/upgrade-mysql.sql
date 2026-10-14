-- =====================================================================
--  Pembaruan database untuk instalasi lama (MySQL / MariaDB)
-- =====================================================================
--  Jalankan file ini kalau database kamu sudah pernah dibuat oleh versi
--  aplikasi sebelumnya dan kamu ingin memastikan semua tabel/kolom baru
--  tersedia (termasuk tabel "attachments" untuk bukti gambar).
--
--  Semua perintah aman diulang: tabel dibuat hanya jika belum ada, dan
--  kolom ditambahkan hanya jika belum ada. Tidak ada data yang dihapus.
--
--  Cara pakai: phpMyAdmin -> pilih database rumah -> tab SQL -> tempel
--  isi file ini -> Go. (Aplikasi juga menjalankan pembaruan ini sendiri
--  secara otomatis setiap kali dibuka.)
-- =====================================================================

-- 1. Tabel bukti gambar (transaksi & pengingat) -----------------------
CREATE TABLE IF NOT EXISTS attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(16) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    mime VARCHAR(40) NOT NULL,
    file_name VARCHAR(140) NOT NULL DEFAULT '',
    byte_size INT UNSIGNED NOT NULL,
    width INT UNSIGNED NOT NULL DEFAULT 0,
    height INT UNSIGNED NOT NULL DEFAULT 0,
    image_data MEDIUMBLOB NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    KEY attachment_lookup (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Kolom pengingat yang dipakai notifikasi Telegram terjadwal -------
--    (abaikan pesan galat "Duplicate column name" bila kolomnya sudah ada)

ALTER TABLE reminders ADD COLUMN completed_at DATETIME NULL;
ALTER TABLE reminders ADD COLUMN due_notified_at DATETIME NULL;

-- 3. Indeks bantu laporan (abaikan bila sudah ada) --------------------
--    Berguna saat isi tabel sudah banyak.

ALTER TABLE transactions ADD KEY transaction_date_lookup (transaction_date);
ALTER TABLE transactions ADD KEY transaction_user_lookup (user_id);
ALTER TABLE reminders ADD KEY reminder_due_lookup (status, reminder_at);

-- Selesai. Cek di phpMyAdmin: tabel "attachments" harus muncul bersama
-- tabel transactions, reminders, monthly_targets, app_settings, users.
