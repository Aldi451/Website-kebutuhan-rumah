-- =====================================================================
--  Dashboard kebutuhan rumah - struktur database untuk SQLite
-- =====================================================================
--  Dipakai kalau kamu ingin memeriksa database preview lokal
--  (storage/rumahku.sqlite) memakai alat seperti "DB Browser for SQLite",
--  atau menjalankan proyek ini tanpa MySQL.
--
--  Aplikasi tetap membuat tabel ini otomatis; file ini untuk keperluan
--  dokumentasi dan pembuatan database manual.
--
--  Contoh menjalankan:  sqlite3 storage/rumahku.sqlite < database/schema-sqlite.sql
-- =====================================================================

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(40) NOT NULL UNIQUE,
    display_name VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(16) NOT NULL DEFAULT 'member',
    phone VARCHAR(60) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind VARCHAR(10) NOT NULL,
    title VARCHAR(100) NOT NULL,
    category VARCHAR(40) NOT NULL,
    amount INTEGER NOT NULL,
    transaction_date DATE NOT NULL,
    note TEXT NULL,
    user_id INTEGER NOT NULL,
    created_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS monthly_targets (
    month_key CHAR(7) NOT NULL PRIMARY KEY,
    amount INTEGER NOT NULL,
    updated_by INTEGER NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS reminders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(120) NOT NULL,
    category VARCHAR(40) NOT NULL DEFAULT 'Lainnya',
    reminder_at DATETIME NOT NULL,
    has_time INTEGER NOT NULL DEFAULT 1,  -- 0 = pengingat sepanjang hari (jam tidak ditentukan)
    note TEXT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'pending',
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    due_notified_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type VARCHAR(16) NOT NULL,
    entity_id INTEGER NOT NULL,
    mime VARCHAR(40) NOT NULL,
    file_name VARCHAR(140) NOT NULL DEFAULT '',
    byte_size INTEGER NOT NULL,
    width INTEGER NOT NULL DEFAULT 0,
    height INTEGER NOT NULL DEFAULT 0,
    image_data BLOB NOT NULL,
    uploaded_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS wa_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    phone VARCHAR(60) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL DEFAULT '',
    access VARCHAR(10) NOT NULL DEFAULT 'member',
    active INTEGER NOT NULL DEFAULT 1,
    user_id INTEGER NULL,
    last_message_at DATETIME NULL,
    created_at DATETIME NOT NULL
);

CREATE INDEX IF NOT EXISTS attachment_lookup ON attachments (entity_type, entity_id);
CREATE INDEX IF NOT EXISTS transaction_date_lookup ON transactions (transaction_date);
CREATE INDEX IF NOT EXISTS transaction_user_lookup ON transactions (user_id);
CREATE INDEX IF NOT EXISTS reminder_due_lookup ON reminders (status, reminder_at);
