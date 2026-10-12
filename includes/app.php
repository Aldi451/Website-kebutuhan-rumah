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
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
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

function app_create_schema(PDO $pdo, bool $mysql): void
{
    $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $int = $mysql ? 'INT UNSIGNED NOT NULL' : 'INTEGER NOT NULL';
    $money = $mysql ? 'BIGINT UNSIGNED NOT NULL' : 'INTEGER NOT NULL';
    $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id {$id},
        username VARCHAR(40) NOT NULL UNIQUE,
        display_name VARCHAR(80) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(16) NOT NULL DEFAULT 'member',
        created_at DATETIME NOT NULL
    ){$engine}");

    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id {$id},
        kind VARCHAR(10) NOT NULL,
        title VARCHAR(100) NOT NULL,
        category VARCHAR(40) NOT NULL,
        amount {$money},
        transaction_date DATE NOT NULL,
        note TEXT NULL,
        user_id {$int},
        created_at DATETIME NOT NULL
    ){$engine}");

    $pdo->exec("CREATE TABLE IF NOT EXISTS monthly_targets (
        month_key CHAR(7) NOT NULL PRIMARY KEY,
        amount {$money},
        updated_by {$int},
        updated_at DATETIME NOT NULL
    ){$engine}");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reminders (
        id {$id},
        title VARCHAR(120) NOT NULL,
        category VARCHAR(40) NOT NULL DEFAULT 'Lainnya',
        reminder_at DATETIME NOT NULL,
        note TEXT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        created_by {$int},
        created_at DATETIME NOT NULL,
        completed_at DATETIME NULL,
        due_notified_at DATETIME NULL
    ){$engine}");

    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at DATETIME NOT NULL
    ){$engine}");

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
    $query = app_db()->prepare('SELECT id, username, display_name, role, created_at FROM users WHERE id = :id LIMIT 1');
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

function app_telegram_credentials(): array
{
    $config = app_config();
    return [
        'token' => app_setting('telegram_bot_token', (string) $config['telegram_bot_token']),
        'chat_id' => app_setting('telegram_chat_id', (string) $config['telegram_chat_id']),
    ];
}

/** Calls the Telegram Bot API over HTTPS. */
function app_telegram_request(string $method, string $token, array $payload = []): array
{
    if (!preg_match('/^[0-9]+:[A-Za-z0-9_-]{20,}$/', $token)) {
        return ['ok' => false, 'description' => 'Format token bot tidak valid.'];
    }
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $response = false;

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        curl_close($curl);
        if ($response === false) {
            return ['ok' => false, 'description' => $curlError !== '' ? $curlError : 'Tidak bisa menghubungi Telegram.'];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return ['ok' => false, 'description' => 'Koneksi ke Telegram gagal. Cek koneksi keluar (HTTPS) dari hosting.'];
        }
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'description' => 'Telegram mengirim respons yang tidak dikenali.'];
    }
    return $decoded;
}

function app_send_telegram(string $text): array
{
    $credentials = app_telegram_credentials();
    if ($credentials['token'] === '' || $credentials['chat_id'] === '') {
        return ['ok' => false, 'description' => 'Bot Telegram belum dihubungkan.'];
    }
    return app_telegram_request('sendMessage', $credentials['token'], [
        'chat_id' => $credentials['chat_id'],
        'text' => $text,
        'disable_web_page_preview' => true,
    ]);
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

function app_format_amount(mixed $amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
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
