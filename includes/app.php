<?php
/** Shared configuration, session, database and utility functions. */
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'dsn' => null,
        'db_user' => '',
        'db_password' => '',
        'timezone' => 'Asia/Jakarta',
        'fonnte_token' => '',
        'fonnte_target' => '',
        'cron_secret' => '',
    ];

    $configFile = dirname(__DIR__) . '/config.php';
    if (is_file($configFile)) {
        $custom = require $configFile;
        if (is_array($custom)) {
            $config = array_replace($config, $custom);
        }
    }

    if (is_string($config['timezone']) && $config['timezone'] !== '') {
        date_default_timezone_set($config['timezone']);
    }
    if (!is_string($config['dsn']) || $config['dsn'] === '') {
        $config['dsn'] = 'sqlite:' . dirname(__DIR__) . '/storage/rumahku.sqlite';
    }

    return $config;
}

function app_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    session_name('rumahku_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function app_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    $dsn = (string) $config['dsn'];
    if (str_starts_with($dsn, 'sqlite:')) {
        $path = substr($dsn, 7);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Folder penyimpanan belum dapat dibuat.');
        }
    }

    $pdo = new PDO($dsn, (string) $config['db_user'], (string) $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if (str_starts_with($dsn, 'sqlite:')) {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    app_create_schema($pdo, str_starts_with($dsn, 'mysql:'));
    return $pdo;
}

function app_is_mysql(): bool
{
    return str_starts_with((string) app_config()['dsn'], 'mysql:');
}

/**
 * Single source of truth for the database structure.
 *
 * The same statements are used to build the schema automatically (see app_create_schema)
 * and to write the CREATE TABLE part of the SQL backup. The mirror of this list for manual
 * setup lives in database/schema-mysql.sql and database/schema-sqlite.sql.
 */
function app_schema_statements(bool $mysql): array
{
    $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $int = $mysql ? 'INT UNSIGNED NOT NULL' : 'INTEGER NOT NULL';
    $nullableInt = $mysql ? 'INT UNSIGNED NULL' : 'INTEGER NULL';
    $money = $mysql ? 'BIGINT UNSIGNED NOT NULL' : 'INTEGER NOT NULL';
    $blob = $mysql ? 'MEDIUMBLOB NOT NULL' : 'BLOB NOT NULL';
    $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $statements = [];

    $statements[] = "CREATE TABLE IF NOT EXISTS users (
        id {$id},
        username VARCHAR(40) NOT NULL UNIQUE,
        display_name VARCHAR(80) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(16) NOT NULL DEFAULT 'member',
        phone VARCHAR(60) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL
    ){$engine}";

    $statements[] = "CREATE TABLE IF NOT EXISTS transactions (
        id {$id},
        kind VARCHAR(10) NOT NULL,
        title VARCHAR(100) NOT NULL,
        category VARCHAR(40) NOT NULL,
        amount {$money},
        transaction_date DATE NOT NULL,
        note TEXT NULL,
        user_id {$int},
        created_at DATETIME NOT NULL" . ($mysql ? ",\n        KEY transaction_date_lookup (transaction_date),\n        KEY transaction_user_lookup (user_id)" : '') . "
    ){$engine}";

    $statements[] = "CREATE TABLE IF NOT EXISTS monthly_targets (
        month_key CHAR(7) NOT NULL PRIMARY KEY,
        amount {$money},
        updated_by {$int},
        updated_at DATETIME NOT NULL
    ){$engine}";

    // has_time = 1 bila pengguna menentukan jam; 0 bila pengingatnya "sepanjang hari"
    // (jamnya tetap diisi default supaya cron punya waktu kirim yang pasti).
    $flag = $mysql ? 'TINYINT(1) NOT NULL DEFAULT 1' : 'INTEGER NOT NULL DEFAULT 1';
    $statements[] = "CREATE TABLE IF NOT EXISTS reminders (
        id {$id},
        title VARCHAR(120) NOT NULL,
        category VARCHAR(40) NOT NULL DEFAULT 'Lainnya',
        reminder_at DATETIME NOT NULL,
        has_time {$flag},
        note TEXT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        created_by {$int},
        created_at DATETIME NOT NULL,
        completed_at DATETIME NULL,
        due_notified_at DATETIME NULL" . ($mysql ? ",\n        KEY reminder_due_lookup (status, reminder_at)" : '') . "
    ){$engine}";

    $statements[] = "CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at DATETIME NOT NULL
    ){$engine}";

    // Bukti transaksi/pengingat berupa gambar terkompres yang disimpan langsung di database.
    $statements[] = "CREATE TABLE IF NOT EXISTS attachments (
        id {$id},
        entity_type VARCHAR(16) NOT NULL,
        entity_id {$int},
        mime VARCHAR(40) NOT NULL,
        file_name VARCHAR(140) NOT NULL DEFAULT '',
        byte_size INT UNSIGNED NOT NULL,
        width INT UNSIGNED NOT NULL DEFAULT 0,
        height INT UNSIGNED NOT NULL DEFAULT 0,
        image_data {$blob},
        uploaded_by {$int},
        created_at DATETIME NOT NULL" . ($mysql ? ",\n        KEY attachment_lookup (entity_type, entity_id)" : '') . "
    ){$engine}";

    // Nomor WhatsApp yang boleh memakai bot laporan (lihat includes/wa.php).
    // access: 'admin' boleh melihat semua angka, 'member' hanya pengeluaran & target.
    $active = $mysql ? 'TINYINT(1) NOT NULL DEFAULT 1' : 'INTEGER NOT NULL DEFAULT 1';
    $statements[] = "CREATE TABLE IF NOT EXISTS wa_contacts (
        id {$id},
        phone VARCHAR(60) NOT NULL UNIQUE,
        name VARCHAR(80) NOT NULL DEFAULT '',
        access VARCHAR(10) NOT NULL DEFAULT 'member',
        active {$active},
        user_id {$nullableInt},
        last_message_at DATETIME NULL,
        created_at DATETIME NOT NULL
    ){$engine}";

    if (!$mysql) {
        $statements[] = 'CREATE INDEX IF NOT EXISTS attachment_lookup ON attachments (entity_type, entity_id)';
        $statements[] = 'CREATE INDEX IF NOT EXISTS transaction_date_lookup ON transactions (transaction_date)';
        $statements[] = 'CREATE INDEX IF NOT EXISTS transaction_user_lookup ON transactions (user_id)';
        $statements[] = 'CREATE INDEX IF NOT EXISTS reminder_due_lookup ON reminders (status, reminder_at)';
    }

    return $statements;
}

/**
 * Adds a column to an existing installation without touching the stored data.
 * Older copies of this app keep working after an update because of this step.
 */
function app_ensure_column(PDO $pdo, bool $mysql, string $table, string $column, string $definition): void
{
    try {
        $exists = false;
        if ($mysql) {
            $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
            $query->execute([':table' => $table, ':column' => $column]);
            $exists = (int) $query->fetchColumn() > 0;
        } else {
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $info) {
                if (strcasecmp((string) ($info['name'] ?? ''), $column) === 0) {
                    $exists = true;
                    break;
                }
            }
        }
        if (!$exists) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    } catch (Throwable $error) {
        error_log('[Keuangan rumah] pembaruan kolom ' . $table . '.' . $column . ' dilewati: ' . $error->getMessage());
    }
}

function app_create_schema(PDO $pdo, bool $mysql): void
{
    foreach (app_schema_statements($mysql) as $statement) {
        $pdo->exec($statement);
    }

    // Pembaruan otomatis untuk database yang dibuat oleh versi aplikasi sebelumnya.
    app_ensure_column($pdo, $mysql, 'reminders', 'completed_at', 'DATETIME NULL');
    app_ensure_column($pdo, $mysql, 'reminders', 'due_notified_at', 'DATETIME NULL');
    app_ensure_column($pdo, $mysql, 'reminders', 'has_time', $mysql ? 'TINYINT(1) NOT NULL DEFAULT 1' : 'INTEGER NOT NULL DEFAULT 1');
    app_ensure_column($pdo, $mysql, 'users', 'phone', \"VARCHAR(60) NOT NULL DEFAULT ''\");

    $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($adminCount === 0) {
        $insert = $pdo->prepare('INSERT INTO users (username, display_name, password_hash, role, created_at) VALUES (:username, :name, :hash, :role, :created)');
        $insert->execute([
            ':username' => 'ALDI',
            ':name' => 'Aldi',
            ':hash' => password_hash('admin123', PASSWORD_DEFAULT),
            ':role' => 'admin',
            ':created' => date('Y-m-d H:i:s'),
        ]);
    }
}

function app_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $query = app_db()->prepare('SELECT id, username, display_name, role, phone, created_at FROM users WHERE id = :id LIMIT 1');
    $query->execute([':id' => (int) $_SESSION['user_id']]);
    $user = $query->fetch();
    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }
    $user['id'] = (int) $user['id'];
    return $user;
}

function app_require_user(): array
{
    $user = app_user();
    if (!$user) {
        app_json(['ok' => false, 'message' => 'Sesi sudah berakhir. Silakan masuk lagi.'], 401);
    }
    return $user;
}

function app_require_admin(array $user): void
{
    if (($user['role'] ?? '') !== 'admin') {
        app_json(['ok' => false, 'message' => 'Menu ini hanya bisa diakses admin.'], 403);
    }
}

function app_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function app_setting(string $key, ?string $fallback = null): ?string
{
    $query = app_db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1');
    $query->execute([':key' => $key]);
    $value = $query->fetchColumn();
    return $value === false ? $fallback : (string) $value;
}

function app_set_setting(string $key, string $value): void
{
    $pdo = app_db();
    $query = $pdo->prepare('UPDATE app_settings SET setting_value = :value, updated_at = :updated WHERE setting_key = :key');
    $query->execute([':value' => $value, ':updated' => date('Y-m-d H:i:s'), ':key' => $key]);
    if ($query->rowCount() === 0) {
        $check = $pdo->prepare('SELECT COUNT(*) FROM app_settings WHERE setting_key = :key');
        $check->execute([':key' => $key]);
        if ((int) $check->fetchColumn() === 0) {
            $insert = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, :updated)');
            $insert->execute([':key' => $key, ':value' => $value, ':updated' => date('Y-m-d H:i:s')]);
        }
    }
}

function app_whatsapp_credentials(): array
{
    $config = app_config();
    return [
        'token' => app_setting('fonnte_token', (string) $config['fonnte_token']),
        'target' => app_setting('fonnte_target', (string) $config['fonnte_target']),
    ];
}

/** True inside the PHP WebAssembly build used by `npm start`; it has no internet access at all. */
function app_is_wasm_preview(): bool
{
    return PHP_SAPI === 'wasm';
}

/**
 * Explains a failed connection to Fonnte in plain Indonesian.
 * $code is the cURL error number (0 when unknown); $detail is the low-level message.
 */
function app_whatsapp_network_error(int $code, string $detail = '', string $token = ''): string
{
    // Never let a token from the request end up in a user-facing error message.
    $detail = $token !== '' ? str_replace($token, '***', $detail) : $detail;
    $detail = trim((string) preg_replace('/\s+/', ' ', $detail));
    $suffix = $detail !== '' ? " (Detail: {$detail})" : '';

    if (in_array($code, [5, 6], true) || preg_match('/getaddrinfo|name or service not known|could not resolve|name resolution|resolving timed out/i', $detail)) {
        return 'Server hosting tidak bisa menghubungi Fonnte: alamat api.fonnte.com tidak ditemukan oleh DNS hosting. '
            . 'Token belum sempat diperiksa, jadi isiannya belum tentu salah. '
            . 'Pastikan hosting mengizinkan koneksi keluar HTTPS; beberapa hosting gratis membatasi koneksi keluar. '
            . 'Lihat README bagian “Troubleshooting WhatsApp (Fonnte)”.' . $suffix;
    }
    if (in_array($code, [35, 51, 58, 60, 77], true) || preg_match('/ssl|certificate/i', $detail)) {
        return 'Server hosting gagal membuat koneksi aman (SSL) ke Fonnte. '
            . 'Biasanya sertifikat CA di server sudah usang, atau hosting memblokir/mengalihkan api.fonnte.com. '
            . 'Token belum sempat diperiksa.' . $suffix;
    }
    if (in_array($code, [7, 28], true) || preg_match('/refused|timed out|timeout|unreachable|no route/i', $detail)) {
        return 'Server hosting tidak berhasil tersambung ke Fonnte (koneksi ditolak atau waktu habis). '
            . 'Pastikan hosting mengizinkan koneksi keluar HTTPS (port 443) ke api.fonnte.com; hosting gratis sering membatasinya. '
            . 'Token belum sempat diperiksa.' . $suffix;
    }
    return 'Tidak bisa menghubungi Fonnte. Cek koneksi keluar (HTTPS) dari hosting.' . $suffix;
}

/** Menerjemahkan alasan penolakan Fonnte menjadi kalimat yang mudah dipahami. */
function app_whatsapp_reason_text(string $reason): string
{
    $normalized = strtolower(trim($reason));
    if ($normalized === '') {
        return 'Fonnte menolak permintaan tanpa penjelasan.';
    }
    if (str_contains($normalized, 'token invalid') || str_contains($normalized, 'invalid token')) {
        return 'Token Fonnte tidak valid. Salin ulang token perangkat dari menu Device di dashboard Fonnte.';
    }
    if (str_contains($normalized, 'unknown user')) {
        return 'Token Fonnte tidak dikenali. Pastikan yang dipakai adalah token perangkat, bukan token akun.';
    }
    if (str_contains($normalized, 'disconnect') || str_contains($normalized, 'not connect') || str_contains($normalized, 'must connect')) {
        return 'Perangkat WhatsApp di Fonnte belum terhubung. Buka dashboard Fonnte lalu sambungkan lagi lewat scan QR.';
    }
    if (str_contains($normalized, 'target required')) {
        return 'Nomor WhatsApp tujuan wajib diisi.';
    }
    if (str_contains($normalized, 'target invalid') || str_contains($normalized, 'invalid target')) {
        return 'Nomor WhatsApp tujuan tidak valid. Periksa formatnya, misalnya 08123456789.';
    }
    if (str_contains($normalized, 'quota')) {
        return 'Kuota Fonnte habis atau tidak mencukupi. Periksa paket di dashboard Fonnte.';
    }
    return 'Fonnte: ' . $reason;
}

/**
 * Memanggil API Fonnte (https://api.fonnte.com) lewat HTTPS.
 * Token dikirim pada header Authorization, bukan pada URL.
 *
 * @return array{ok: bool, description: string, data: array}
 */
function app_whatsapp_request(string $endpoint, string $token, array $payload = []): array
{
    $token = trim($token);
    if ($token === '' || strlen($token) < 8 || strlen($token) > 200 || preg_match('/\s/', $token)) {
        return ['ok' => false, 'description' => 'Format token Fonnte tidak valid.', 'data' => []];
    }
    if (app_is_wasm_preview()) {
        // Opening a socket inside PHP-WASM throws a JS exception that takes the whole preview server down.
        return ['ok' => false, 'description' => 'Preview lokal (npm start) tidak punya akses internet, jadi WhatsApp belum bisa dihubungi dari sini. Uji fitur ini setelah situs diunggah ke hosting yang mengizinkan koneksi keluar ke api.fonnte.com.', 'data' => []];
    }
    $url = 'https://api.fonnte.com/' . ltrim($endpoint, '/');
    $body = http_build_query($payload, '', '&', PHP_QUERY_RFC1738);
    $response = false;

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $token,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($curl);
        $curlErrno = curl_errno($curl);
        $curlError = curl_error($curl);
        curl_close($curl);
        if ($response === false) {
            return ['ok' => false, 'description' => app_whatsapp_network_error($curlErrno, $curlError, $token), 'data' => []];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: {$token}\r\nContent-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        error_clear_last();
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $lastError = error_get_last();
            return ['ok' => false, 'description' => app_whatsapp_network_error(0, (string) ($lastError['message'] ?? ''), $token), 'data' => []];
        }
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'description' => 'Fonnte mengirim respons yang tidak dikenali.', 'data' => []];
    }
    $ok = ($decoded['status'] ?? false) === true;
    $reason = trim((string) ($decoded['reason'] ?? $decoded['detail'] ?? $decoded['message'] ?? ''));
    return [
        'ok' => $ok,
        'description' => $ok ? ($reason !== '' ? $reason : 'Permintaan diterima Fonnte.') : app_whatsapp_reason_text($reason),
        'data' => $decoded,
    ];
}

/**
 * Merapikan nomor tujuan menjadi format 62..., atau membiarkan ID grup (@g.us).
 * Mengembalikan null bila formatnya tidak masuk akal.
 */
function app_normalize_whatsapp_target(string $target): ?string
{
    $target = trim($target);
    if ($target === '' || strlen($target) > 60) {
        return null;
    }
    if (preg_match('/^[0-9][0-9\-]{5,50}@g\.us$/', $target)) {
        return $target;
    }
    // Sebagian gateway mengirim nomor dengan akhiran @s.whatsapp.net / @c.us.
    $target = (string) preg_replace('/@(s\.whatsapp\.net|c\.us)$/i', '', $target);
    $digits = preg_replace('/[\s\-\.\(\)]/', '', $target);
    if (!is_string($digits)) {
        return null;
    }
    if (str_starts_with($digits, '+')) {
        $digits = substr($digits, 1);
    }
    if (str_starts_with($digits, '0')) {
        $digits = '62' . substr($digits, 1);
    } elseif (str_starts_with($digits, '8')) {
        $digits = '62' . $digits;
    }
    return preg_match('/^[1-9][0-9]{7,14}$/', $digits) ? $digits : null;
}

/**
 * Mengecek token sekaligus status perangkat WhatsApp di Fonnte.
 *
 * @return array ok, description, connected, device, device_status, name, quota, package, expired
 */
function app_whatsapp_device(string $token): array
{
    $result = app_whatsapp_request('device', $token);
    if (!($result['ok'] ?? false)) {
        return $result;
    }
    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    $status = strtolower(trim((string) ($data['device_status'] ?? '')));
    $connected = $status === 'connect';
    return [
        'ok' => true,
        'description' => $connected
            ? 'Perangkat WhatsApp terhubung dan siap mengirim pesan.'
            : 'Token valid, tetapi perangkat WhatsApp belum terhubung. Buka dashboard Fonnte lalu scan QR untuk menyambungkannya.',
        'data' => $data,
        'connected' => $connected,
        'device' => (string) ($data['device'] ?? ''),
        'device_status' => $status,
        'name' => (string) ($data['name'] ?? ''),
        'quota' => (string) ($data['quota'] ?? ''),
        'package' => (string) ($data['package'] ?? ''),
        'expired' => (string) ($data['expired'] ?? ''),
    ];
}

/**
 * Memeriksa apakah nomor terdaftar di WhatsApp lewat endpoint /validate.
 * Null berarti hasilnya tidak bisa dipastikan (mis. API sedang bermasalah),
 * sehingga pemanggil sebaiknya tidak memblokir pengguna.
 */
function app_whatsapp_number_registered(string $token, string $target): ?bool
{
    if (str_contains($target, '@g.us')) {
        return null;
    }
    $result = app_whatsapp_request('validate', $token, ['target' => $target, 'countryCode' => '62']);
    if (!($result['ok'] ?? false)) {
        return null;
    }
    $registered = $result['data']['registered'] ?? [];
    $notRegistered = $result['data']['not_registered'] ?? [];
    $matches = static function ($list) use ($target): bool {
        if (!is_array($list)) {
            return false;
        }
        foreach ($list as $value) {
            if ((string) $value === $target) {
                return true;
            }
        }
        return false;
    };
    if ($matches($notRegistered)) {
        return false;
    }
    if ($matches($registered)) {
        return true;
    }
    return null;
}

function app_send_whatsapp(string $text, ?string $target = null, ?string $token = null): array
{
    $credentials = app_whatsapp_credentials();
    $token = $token !== null && trim($token) !== '' ? trim($token) : $credentials['token'];
    $target = $target !== null && trim($target) !== '' ? trim($target) : $credentials['target'];
    if ($token === '' || $target === '') {
        return ['ok' => false, 'description' => 'Notifikasi WhatsApp (Fonnte) belum dihubungkan.', 'data' => []];
    }
    $payload = [
        'target' => $target,
        'message' => $text,
        // Jangan memakai kuota kalau perangkat sedang terputus.
        'connectOnly' => 'true',
    ];
    if (!str_contains($target, '@g.us')) {
        $payload['countryCode'] = '62';
    }
    $result = app_whatsapp_request('send', $token, $payload);
    if ($result['ok'] ?? false) {
        $result['description'] = 'Pesan WhatsApp masuk antrean pengiriman Fonnte.';
    }
    return $result;
}

function app_valid_month(string $month): bool
{
    return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month);
}

function app_valid_date(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(date_default_timezone_get()));
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function app_valid_datetime_local(string $datetime): ?string
{
    $normalized = str_replace('T', ' ', trim($datetime));
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized)) {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $normalized, new DateTimeZone(date_default_timezone_get()));
    if (!$parsed || $parsed->format('Y-m-d H:i') !== $normalized) {
        return null;
    }
    return $parsed->format('Y-m-d H:i:s');
}

/** Jam yang dipakai untuk pengingat tanpa jam (sepanjang hari). */
function app_default_reminder_time(): string
{
    return '09:00';
}

/** Memvalidasi jam `HH:MM` (detik opsional, seperti kiriman input `datetime-local`). */
function app_valid_time(string $time): ?string
{
    $normalized = trim($time);
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::(?:[0-5]\d))?$/', $normalized, $match)) {
        return null;
    }
    return $match[1] . ':' . $match[2];
}

/**
 * Menentukan waktu pengingat dari input.
 *
 * Format baru: `reminder_date` (wajib) + `reminder_time` (opsional). Kalau jamnya
 * dikosongkan, pengingat dianggap "sepanjang hari" dan dikirim pada jam default.
 * Format lama `reminder_at` (`YYYY-MM-DDTHH:MM`) tetap diterima untuk importer.
 *
 * @return array{at: string, has_time: int}|null null bila tanggal/jam tidak valid
 */
function app_resolve_reminder_when(array $input): ?array
{
    $date = trim((string) ($input['reminder_date'] ?? ''));
    if ($date !== '') {
        if (!app_valid_date($date)) {
            return null;
        }
        $time = trim((string) ($input['reminder_time'] ?? ''));
        if ($time === '') {
            return ['at' => $date . ' ' . app_default_reminder_time() . ':00', 'has_time' => 0];
        }
        $validTime = app_valid_time($time);
        if ($validTime === null) {
            return null;
        }
        return ['at' => $date . ' ' . $validTime . ':00', 'has_time' => 1];
    }

    $combined = app_valid_datetime_local((string) ($input['reminder_at'] ?? ''));
    if ($combined === null) {
        return null;
    }
    $hasTime = array_key_exists('has_time', $input) ? (int) $input['has_time'] : 1;
    return ['at' => $combined, 'has_time' => $hasTime === 0 ? 0 : 1];
}

/**
 * Menulis waktu pengingat untuk pesan WhatsApp: dengan jam bila penggunanya
 * mengisi jam, atau keterangan "sepanjang hari" bila jamnya dilewati.
 */
function app_reminder_when_text(string $reminderAt, int $hasTime = 1): string
{
    $timestamp = strtotime($reminderAt);
    if ($timestamp === false) {
        return $reminderAt;
    }
    return $hasTime === 1
        ? date('d M Y, H.i', $timestamp) . ' WIB'
        : date('d M Y', $timestamp) . ' (sepanjang hari · kabar dikirim sekitar ' . app_default_reminder_time() . ' WIB)';
}

/**
 * Menyusun isi pesan WhatsApp untuk satu pengingat.
 * Dipakai saat pengingat dibuat, dikirim manual, dan oleh cron saat jatuh tempo,
 * supaya bentuk kabarnya sama di mana pun.
 *
 * @param array $reminder baris pengingat (title, category, reminder_at, has_time, note, created_by_name)
 */
function app_reminder_message(array $reminder, string $heading = '🔔 Pengingat rumah'): string
{
    $title = (string) ($reminder['title'] ?? '');
    $category = (string) ($reminder['category'] ?? 'Lainnya');
    $when = app_reminder_when_text((string) ($reminder['reminder_at'] ?? ''), (int) ($reminder['has_time'] ?? 1));
    $author = (string) ($reminder['created_by_name'] ?? '') !== '' ? (string) $reminder['created_by_name'] : 'Anggota rumah';
    $note = trim((string) ($reminder['note'] ?? ''));
    return $heading . "\n\n" . $title . "\n" . $when . "\nKategori: " . $category . "\nDibuat oleh: " . $author
        . ($note !== '' ? "\n\nCatatan: " . $note : '');
}

/* ------------------------------------------------------------------ *
 * Bukti gambar (transaksi & pengingat)
 * ------------------------------------------------------------------ */

/** Batas ukuran gambar setelah dikompres di browser. */
function app_receipt_max_bytes(): int
{
    return 1_600_000;
}

/** Sisi terpanjang gambar yang disimpan di database. */
function app_receipt_max_side(): int
{
    return 1600;
}

/**
 * Membaca payload bukti dari input JSON. Mengembalikan null bila pengguna melewati
 * (skip) lampiran, dan melempar RuntimeException bila gambarnya tidak memenuhi syarat.
 */
function app_receipt_payload(array $input): ?array
{
    $raw = $input['receipt'] ?? null;
    if (!is_array($raw)) {
        return null;
    }
    $data = trim((string) ($raw['data'] ?? ''));
    if ($data === '') {
        return null;
    }
    if (preg_match('#^data:[^;]+;base64,#i', $data, $match)) {
        $data = substr($data, strlen($match[0]));
    }
    $data = (string) preg_replace('/\s+/', '', $data);
    if ($data === '') {
        return null;
    }
    if (strlen($data) > 6_500_000) {
        throw new RuntimeException('Gambar bukti terlalu besar dikirim ke server. Kompres ulang atau lewati bukti.');
    }
    $bytes = base64_decode($data, true);
    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('Gambar bukti tidak bisa dibaca. Coba pilih ulang fotonya.');
    }
    return app_prepare_receipt($bytes, (string) ($raw['name'] ?? ''));
}

/**
 * Memvalidasi gambar dan mengompresnya sekali lagi di server.
 * Kalau GD tersedia, semua gambar ditulis ulang sebagai JPEG (metadata/EXIF ikut hilang),
 * sehingga ukuran di database tetap kecil dan isinya bersih.
 */
function app_prepare_receipt(string $bytes, string $fileName = ''): array
{
    $limit = app_receipt_max_bytes();
    if (strlen($bytes) > $limit) {
        throw new RuntimeException('Ukuran gambar sekitar ' . number_format(strlen($bytes) / 1024, 0, ',', '.') . ' KB, sedangkan batasnya ' . number_format($limit / 1024, 0, ',', '.') . ' KB. Coba pilih foto lain atau lewati bukti.');
    }
    $info = @getimagesizefromstring($bytes);
    if (!is_array($info)) {
        throw new RuntimeException('File bukti bukan gambar yang dikenali (gunakan JPG, PNG, atau WEBP).');
    }
    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    $mime = (string) ($info['mime'] ?? '');
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        throw new RuntimeException('Format bukti harus JPG, PNG, atau WEBP.');
    }
    if ($width < 1 || $height < 1 || $width > 12_000 || $height > 12_000 || ($width * $height) > 40_000_000) {
        throw new RuntimeException('Dimensi gambar bukti tidak wajar. Pilih foto lain.');
    }

    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg') && function_exists('imagecreatetruecolor')) {
        $image = @imagecreatefromstring($bytes);
        if ($image !== false) {
            $maxSide = app_receipt_max_side();
            $scale = min(1.0, $maxSide / max($width, $height));
            if ($scale < 1.0) {
                $targetWidth = max(1, (int) round($width * $scale));
                $targetHeight = max(1, (int) round($height * $scale));
                $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
                imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
                imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($image), imagesy($image));
                imagedestroy($image);
                $image = $canvas;
                $width = $targetWidth;
                $height = $targetHeight;
            }
            $encoded = null;
            foreach ([78, 70, 62] as $quality) {
                ob_start();
                $ok = imagejpeg($image, null, $quality);
                $candidate = (string) ob_get_clean();
                if ($ok && $candidate !== '') {
                    $encoded = $candidate;
                    if (strlen($candidate) <= $limit) {
                        break;
                    }
                }
            }
            imagedestroy($image);
            if (is_string($encoded) && $encoded !== '' && strlen($encoded) <= $limit) {
                $bytes = $encoded;
                $mime = 'image/jpeg';
            } elseif (strlen($bytes) > $limit) {
                throw new RuntimeException('Gambar masih terlalu besar setelah dikompres. Pilih foto lain atau lewati bukti.');
            }
        }
    }

    $cleanName = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($fileName === '' ? 'bukti.jpg' : $fileName)) ?? 'bukti.jpg';
    return [
        'mime' => $mime,
        'bytes' => $bytes,
        'width' => $width,
        'height' => $height,
        'name' => substr($cleanName, 0, 140),
    ];
}

/** Menyimpan (atau mengganti) bukti milik satu transaksi/pengingat. */
function app_save_receipt(PDO $pdo, string $entityType, int $entityId, array $receipt, int $userId, string $now): int
{
    if (!in_array($entityType, ['transaction', 'reminder'], true)) {
        throw new RuntimeException('Jenis lampiran tidak dikenal.');
    }
    $delete = $pdo->prepare('DELETE FROM attachments WHERE entity_type = :type AND entity_id = :entity');
    $delete->execute([':type' => $entityType, ':entity' => $entityId]);

    $insert = $pdo->prepare('INSERT INTO attachments (entity_type, entity_id, mime, file_name, byte_size, width, height, image_data, uploaded_by, created_at)
        VALUES (:type, :entity, :mime, :name, :size, :width, :height, :image, :user, :created)');
    $insert->bindValue(':type', $entityType);
    $insert->bindValue(':entity', $entityId, PDO::PARAM_INT);
    $insert->bindValue(':mime', (string) $receipt['mime']);
    $insert->bindValue(':name', (string) $receipt['name']);
    $insert->bindValue(':size', strlen((string) $receipt['bytes']), PDO::PARAM_INT);
    $insert->bindValue(':width', (int) $receipt['width'], PDO::PARAM_INT);
    $insert->bindValue(':height', (int) $receipt['height'], PDO::PARAM_INT);
    $insert->bindValue(':image', (string) $receipt['bytes'], PDO::PARAM_LOB);
    $insert->bindValue(':user', $userId, PDO::PARAM_INT);
    $insert->bindValue(':created', $now);
    $insert->execute();
    return (int) $pdo->lastInsertId();
}

/** Melengkapi daftar transaksi/pengingat dengan informasi bukti gambar. */
function app_attach_receipts(PDO $pdo, array $rows, string $entityType, string $idKey = 'id'): array
{
    if ($rows === []) {
        return $rows;
    }
    $ids = [];
    foreach ($rows as $row) {
        if (isset($row[$idKey])) {
            $ids[] = (int) $row[$idKey];
        }
    }
    $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    $map = [];
    foreach (array_chunk($ids, 400) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
        $query = $pdo->prepare("SELECT id, entity_id, mime, file_name, byte_size, width, height, uploaded_by, created_at
            FROM attachments WHERE entity_type = ? AND entity_id IN ({$placeholders}) ORDER BY id DESC");
        $query->execute(array_merge([$entityType], $chunk));
        foreach ($query->fetchAll() as $row) {
            $entityId = (int) $row['entity_id'];
            if (isset($map[$entityId])) {
                continue;
            }
            $map[$entityId] = [
                'id' => (int) $row['id'],
                'mime' => (string) $row['mime'],
                'name' => (string) $row['file_name'],
                'bytes' => (int) $row['byte_size'],
                'width' => (int) $row['width'],
                'height' => (int) $row['height'],
                'uploaded_by' => (int) $row['uploaded_by'],
                'created_at' => (string) $row['created_at'],
                'url' => '/api.php?action=attachment&id=' . (int) $row['id'],
            ];
        }
    }
    foreach ($rows as &$row) {
        $entityId = (int) ($row[$idKey] ?? 0);
        $row['attachment'] = $map[$entityId] ?? null;
    }
    unset($row);
    return $rows;
}

/** Pemilik baris (transaction/reminder) untuk memeriksa izin. Null bila baris tidak ada. */
function app_entity_owner_id(PDO $pdo, string $entityType, int $entityId): ?int
{
    if ($entityType === 'transaction') {
        $query = $pdo->prepare('SELECT user_id FROM transactions WHERE id = :id LIMIT 1');
    } elseif ($entityType === 'reminder') {
        $query = $pdo->prepare('SELECT created_by FROM reminders WHERE id = :id LIMIT 1');
    } else {
        return null;
    }
    $query->execute([':id' => $entityId]);
    $owner = $query->fetchColumn();
    return $owner === false ? null : (int) $owner;
}

function app_can_manage_entity(array $user, ?int $ownerId): bool
{
    return ($user['role'] ?? '') === 'admin' || ($ownerId !== null && $ownerId === (int) $user['id']);
}

function app_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }
    return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
}

function app_format_amount(mixed $amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

/* ------------------------------------------------------------------ *
 * Ringkasan & cadangan (backup) database
 * ------------------------------------------------------------------ */

function app_database_tables(): array
{
    return ['users', 'transactions', 'monthly_targets', 'reminders', 'attachments', 'wa_contacts', 'app_settings'];
}

function app_database_summary(): array
{
    $pdo = app_db();
    $summary = [
        'driver' => app_is_mysql() ? 'MySQL / MariaDB' : 'SQLite',
        'tables' => [],
        'total_rows' => 0,
        'attachment_count' => 0,
        'attachment_bytes' => 0,
        'file' => '',
    ];
    foreach (app_database_tables() as $table) {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        $summary['tables'][$table] = $count;
        $summary['total_rows'] += $count;
    }
    $summary['attachment_count'] = (int) $pdo->query('SELECT COUNT(*) FROM attachments')->fetchColumn();
    $summary['attachment_bytes'] = (int) $pdo->query('SELECT COALESCE(SUM(byte_size), 0) FROM attachments')->fetchColumn();
    if (!app_is_mysql()) {
        $path = substr((string) app_config()['dsn'], 7);
        $root = dirname(__DIR__);
        $summary['file'] = str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : basename($path);
    }
    return $summary;
}

/**
 * Menulis file .sql berisi struktur tabel + isi database.
 * Gambar bukti disertakan sebagai nilai hex selama totalnya masih wajar.
 */
function app_stream_sql_dump(PDO $pdo): void
{
    $includeImages = false;
    $attachmentBytes = (int) $pdo->query('SELECT COALESCE(SUM(byte_size), 0) FROM attachments')->fetchColumn();
    if ($attachmentBytes > 0 && $attachmentBytes <= 6_000_000) {
        $includeImages = true;
    }

    echo "-- Cadangan database Dashboard kebutuhan rumah\n";
    echo '-- Dibuat: ' . date('Y-m-d H:i:s') . ' (' . date_default_timezone_get() . ")\n";
    echo "-- Format: SQL siap impor ke MySQL/MariaDB (phpMyAdmin > Import).\n";
    echo '-- Bukti gambar: ' . ($attachmentBytes === 0
        ? 'belum ada'
        : ($includeImages
            ? 'disertakan sebagai nilai hex (' . app_format_bytes($attachmentBytes) . ')'
            : 'TIDAK disertakan karena totalnya ' . app_format_bytes($attachmentBytes) . '; unduh gambarnya dari halaman bukti')) . "\n\n";
    echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach (app_schema_statements(true) as $statement) {
        echo $statement, ";\n\n";
    }

    foreach (app_database_tables() as $table) {
        $offset = 0;
        $batch = 100;
        $written = 0;
        while (true) {
            $query = $pdo->prepare("SELECT * FROM {$table} LIMIT {$batch} OFFSET {$offset}");
            $query->execute();
            $rows = $query->fetchAll();
            if ($rows === []) {
                break;
            }
            if ($written === 0) {
                echo "-- Data tabel {$table}\n";
            }
            $columns = array_keys($rows[0]);
            $columnList = implode(', ', array_map(static fn (string $name): string => '`' . $name . '`', $columns));
            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    if ($value === null) {
                        $values[] = 'NULL';
                        continue;
                    }
                    if (is_resource($value)) {
                        $value = stream_get_contents($value);
                    }
                    if (is_int($value) || is_float($value)) {
                        $values[] = (string) $value;
                        continue;
                    }
                    $text = (string) $value;
                    if ($table === 'attachments' && $column === 'image_data') {
                        $values[] = $includeImages ? "X'" . bin2hex($text) . "'" : "''";
                        continue;
                    }
                    $values[] = $pdo->quote(str_replace('\\', '\\\\', $text));
                }
                echo 'INSERT INTO `' . $table . '` (' . $columnList . ') VALUES (' . implode(', ', $values) . ");\n";
                $written++;
            }
            $offset += $batch;
        }
        if ($written > 0) {
            echo "\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
}

/* ------------------------------------------------------------------ *
 * Pengaturan penerima notifikasi (Fonnte) — batas device & pilihan user
 * ------------------------------------------------------------------ */

function app_wa_notify_max_devices_default(): int { return 5; }

function app_wa_notify_settings(): array
{
    $max = (int) app_setting('wa_notify_max_devices', (string) app_wa_notify_max_devices_default());
    if ($max < 1) $max = 1;
    if ($max > 20) $max = 20;

    $rawTargets = app_setting('wa_notify_targets', '[]');
    $targets = json_decode((string) $rawTargets, true);
    if (!is_array($targets)) $targets = [];
    $normalized = [];
    foreach ($targets as $item) {
        $phone = app_normalize_whatsapp_target(trim((string) $item));
        if ($phone !== null && !in_array($phone, $normalized, true)) $normalized[] = $phone;
    }

    $rawUserIds = app_setting('wa_notify_user_ids', '[]');
    $userIds = json_decode((string) $rawUserIds, true);
    if (!is_array($userIds)) $userIds = [];
    $cleanIds = [];
    foreach ($userIds as $uid) {
        $id = filter_var($uid, FILTER_VALIDATE_INT);
        if ($id && $id > 0) $cleanIds[] = (int) $id;
    }
    $cleanIds = array_values(array_unique($cleanIds));

    $notifyTypesRaw = app_setting('wa_notify_types', 'reminders,finance');
    $types = array_filter(array_map('trim', explode(',', (string) $notifyTypesRaw)));
    if ($types === []) $types = ['reminders', 'finance'];
    $allowedTypes = ['reminders', 'finance', 'monthly_report'];
    $types = array_values(array_intersect($types, $allowedTypes));
    if ($types === []) $types = ['reminders'];

    return [
        'max_devices' => $max,
        'targets' => $normalized,
        'user_ids' => $cleanIds,
        'types' => $types,
    ];
}

function app_wa_notify_targets(): array
{
    $creds = app_whatsapp_credentials();
    $primary = $creds['target'] !== '' ? app_normalize_whatsapp_target($creds['target']) : null;
    $settings = app_wa_notify_settings();
    $all = [];

    if ($primary !== null) $all[] = $primary;
    foreach ($settings['targets'] as $t) {
        if (!in_array($t, $all, true)) $all[] = $t;
    }
    // Tambahkan nomor HP dari user yang dipilih (jika user punya phone)
    if ($settings['user_ids'] !== []) {
        try {
            $pdo = app_db();
            $placeholders = implode(',', array_fill(0, count($settings['user_ids']), '?'));
            $query = $pdo->prepare("SELECT id, phone FROM users WHERE id IN ({$placeholders})");
            $query->execute($settings['user_ids']);
            foreach ($query->fetchAll() as $row) {
                $phone = app_normalize_whatsapp_target(trim((string) ($row['phone'] ?? '')));
                if ($phone !== null && !in_array($phone, $all, true)) $all[] = $phone;
            }
        } catch (Throwable $e) { /* abaikan jika kolom belum ada */ }
    }
    // Batasi sesuai max_devices
    if (count($all) > $settings['max_devices']) {
        $all = array_slice($all, 0, $settings['max_devices']);
    }
    return $all;
}

function app_wa_notify_save_settings(array $input): array
{
    $max = filter_var($input['max_devices'] ?? null, FILTER_VALIDATE_INT);
    if ($max === false || $max < 1 || $max > 20) {
        return ['ok' => false, 'message' => 'Batas perangkat harus angka 1–20.'];
    }
    $targetsInput = $input['targets'] ?? [];
    if (is_string($targetsInput)) {
        $targetsInput = preg_split('/[\\s,;\\n]+/', $targetsInput) ?: [];
    }
    if (!is_array($targetsInput)) $targetsInput = [];
    $normalizedTargets = [];
    foreach ($targetsInput as $raw) {
        $raw = trim((string) $raw);
        if ($raw === '') continue;
        $phone = app_normalize_whatsapp_target($raw);
        if ($phone === null) {
            return ['ok' => false, 'message' => 'Nomor tujuan tidak valid: ' . $raw . '. Contoh: 08123456789 atau 628123456789, atau ID grup berakhiran @g.us'];
        }
        if (!in_array($phone, $normalizedTargets, true)) $normalizedTargets[] = $phone;
    }
    if (count($normalizedTargets) > $max) {
        return ['ok' => false, 'message' => 'Jumlah nomor tujuan (' . count($normalizedTargets) . ') melebihi batas perangkat (' . $max . '). Kurangi nomor atau naikkan batas.'];
    }
    $userIdsInput = $input['user_ids'] ?? [];
    if (!is_array($userIdsInput)) $userIdsInput = [];
    $cleanUserIds = [];
    foreach ($userIdsInput as $uid) {
        $id = filter_var($uid, FILTER_VALIDATE_INT);
        if ($id && $id > 0) $cleanUserIds[] = (int) $id;
    }
    $cleanUserIds = array_values(array_unique($cleanUserIds));
    // Validasi user ada
    if ($cleanUserIds !== []) {
        $pdo = app_db();
        $placeholders = implode(',', array_fill(0, count($cleanUserIds), '?'));
        $query = $pdo->prepare("SELECT id FROM users WHERE id IN ({$placeholders})");
        $query->execute($cleanUserIds);
        $found = array_map(fn($r) => (int) $r['id'], $query->fetchAll());
        $missing = array_diff($cleanUserIds, $found);
        if ($missing !== []) {
            return ['ok' => false, 'message' => 'Beberapa user tidak ditemukan: ' . implode(', ', $missing)];
        }
        // Cek apakah user terpilih sudah punya nomor HP (peringatan tapi tetap simpan)
    }
    $typesInput = $input['types'] ?? ['reminders'];
    if (!is_array($typesInput)) $typesInput = [$typesInput];
    $allowedTypes = ['reminders', 'finance', 'monthly_report'];
    $types = array_values(array_intersect(array_map('strval', $typesInput), $allowedTypes));
    if ($types === []) $types = ['reminders'];

    // Simpan
    app_set_setting('wa_notify_max_devices', (string) $max);
    app_set_setting('wa_notify_targets', json_encode($normalizedTargets, JSON_UNESCAPED_UNICODE));
    app_set_setting('wa_notify_user_ids', json_encode($cleanUserIds, JSON_UNESCAPED_UNICODE));
    app_set_setting('wa_notify_types', implode(',', $types));

    return ['ok' => true, 'message' => 'Pengaturan penerima notifikasi disimpan. Maksimal ' . $max . ' perangkat, ' . count($normalizedTargets) . ' nomor tambahan + ' . count($cleanUserIds) . ' user terpilih.'];
}

function app_wa_send_to_targets(string $text, array $targets, ?string $token = null): array
{
    if ($targets === []) {
        // Fallback ke pengiriman single-target standar
        return app_send_whatsapp($text, null, $token);
    }
    $results = [];
    $sent = 0; $failed = 0;
    $descriptions = [];
    foreach ($targets as $target) {
        $res = app_send_whatsapp($text, $target, $token);
        $results[] = ['target' => $target, 'ok' => (bool) ($res['ok'] ?? false), 'description' => (string) ($res['description'] ?? '')];
        if ($res['ok'] ?? false) $sent++; else { $failed++; $descriptions[] = $target . ': ' . (string) ($res['description'] ?? 'gagal'); }
    }
    $overallOk = $sent > 0;
    return [
        'ok' => $overallOk,
        'sent' => $sent,
        'failed' => $failed,
        'results' => $results,
        'description' => $overallOk
            ? ($failed === 0 ? 'Notifikasi terkirim ke ' . $sent . ' perangkat.' : 'Terkirim ke ' . $sent . ' perangkat, ' . $failed . ' gagal: ' . implode('; ', array_slice($descriptions, 0, 2)))
            : 'Semua pengiriman gagal: ' . implode('; ', array_slice($descriptions, 0, 2)),
    ];
}

function app_send_whatsapp_broadcast(string $text, ?string $token = null): array
{
    $targets = app_wa_notify_targets();
    if ($targets === []) {
        return app_send_whatsapp($text, null, $token);
    }
    return app_wa_send_to_targets($text, $targets, $token);
}

function app_name_initials(string $name): string
{
    $words = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($words, 0, 2) as $word) {
        if ($word !== '') {
            $initials .= function_exists('mb_substr') ? mb_substr($word, 0, 1, 'UTF-8') : substr($word, 0, 1);
        }
    }
    return strtoupper($initials !== '' ? $initials : 'R');
}

require_once __DIR__ . '/wa.php';
