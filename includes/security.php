<?php
declare(strict_types=1);

function app_valid_new_password(string $password): bool
{
    // PASSWORD_DEFAULT is currently bcrypt: avoid silent truncation beyond 72 bytes.
    return strlen($password) >= 12 && strlen($password) <= 72 && strpos($password, "\0") === false;
}

function app_csp_nonce(): string
{
    static $nonce = null;
    return $nonce ??= base64_encode(random_bytes(24));
}

/** Never trust X-Forwarded-Proto supplied by an arbitrary client. */
function app_request_is_https(?array $server = null): bool
{
    $server = $server ?? $_SERVER;
    return isset($server['HTTPS']) && in_array(strtolower((string) $server['HTTPS']), ['on', '1'], true);
}

function app_security_header_values(bool $preview, bool $https, int $hstsMaxAge): array
{
    $headers = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'Cache-Control' => 'no-store, private',
    ];
    $policy = "default-src 'self'; script-src 'self' 'nonce-" . app_csp_nonce() . "'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'";
    // Production uses only same-origin assets. Embedded WASM previews deliberately
    // omit framing/isolation restrictions that would break the Arena parent frame.
    if (!$preview) {
        $policy .= "; frame-ancestors 'self'";
        $headers['X-Frame-Options'] = 'SAMEORIGIN';
        $headers['Cross-Origin-Opener-Policy'] = 'same-origin';
        $headers['Cross-Origin-Resource-Policy'] = 'same-origin';
        $headers['Cross-Origin-Embedder-Policy'] = 'require-corp';
    }
    if ($https) {
        // Host only: no includeSubDomains/preload without an explicit domain audit.
        $headers['Strict-Transport-Security'] = 'max-age=' . max(0, min(63072000, $hstsMaxAge));
    }
    $headers['Content-Security-Policy'] = $policy;
    return $headers;
}

function app_security_headers(): void
{
    header_remove('X-Powered-By');
    $config = app_config();
    foreach (app_security_header_values(PHP_SAPI === 'wasm', app_request_is_https(), (int) ($config['hsts_max_age'] ?? 86400)) as $name => $value) {
        header($name . ': ' . $value);
    }
}

function app_allow_methods(array $allowed): void
{
    if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        app_json(['ok' => false, 'message' => 'Metode HTTP tidak diizinkan.'], 405);
    }
}

/** No invented contact details: publish only a valid owner-configured contact. */
function app_security_contact_text(array $config, int $now): ?string
{
    $contact = trim((string) ($config['security_contact'] ?? ''));
    $expires = trim((string) ($config['security_contact_expires'] ?? ''));
    if ($contact === '' || strlen($contact) > 500 || preg_match('/[\x00-\x20\x7f]/', $contact)) return null;
    if (str_starts_with($contact, 'mailto:')) {
        if (!filter_var(substr($contact, 7), FILTER_VALIDATE_EMAIL)) return null;
    } else {
        $url = parse_url($contact);
        if (!filter_var($contact, FILTER_VALIDATE_URL) || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass'])) return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $expires)) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $expires, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $expires || $date->getTimestamp() <= $now || $date->getTimestamp() > $now + 366 * 86400) return null;
    return "Contact: " . $contact . "\nExpires: " . $expires . "\nPreferred-Languages: id, en\n";
}

function app_bootstrap_admin(PDO $pdo): void
{
    $password = (string) (app_config()['admin_bootstrap_password'] ?? '');
    if (!app_valid_new_password($password)) return;
    $admins = $pdo->query("SELECT id, password_hash FROM users WHERE role = 'admin'")->fetchAll();
    if (!$admins) {
        $q = $pdo->prepare('INSERT INTO users (username, display_name, password_hash, role, created_at) VALUES (:username, :name, :hash, :role, :created)');
        $q->execute([':username' => 'ALDI', ':name' => 'Aldi', ':hash' => password_hash($password, PASSWORD_DEFAULT), ':role' => 'admin', ':created' => date('Y-m-d H:i:s')]);
        return;
    }
    // Recovery is deliberately limited to the legacy public password. Never reset a
    // customized password merely because an old bootstrap value remains in config.
    foreach ($admins as $admin) {
        if (password_verify('admin123', $admin['password_hash'])) {
            $q = $pdo->prepare('UPDATE users SET password_hash = :new WHERE id = :id AND password_hash = :old');
            $q->execute([':new' => password_hash($password, PASSWORD_DEFAULT), ':id' => $admin['id'], ':old' => $admin['password_hash']]);
        }
    }
}

function app_bind_auth_session(string $passwordHash): void
{
    $_SESSION['auth_fingerprint'] = hash('sha256', $passwordHash);
    $_SESSION['auth_started_at'] = time();
    $_SESSION['auth_last_seen'] = time();
    unset($_SESSION['google_oauth']);
}

function app_auth_session_valid(array $user, int $now): bool
{
    return isset($_SESSION['auth_fingerprint'], $_SESSION['auth_started_at'], $_SESSION['auth_last_seen'])
        && hash_equals(hash('sha256', $user['password_hash']), (string) $_SESSION['auth_fingerprint'])
        && $now - (int) $_SESSION['auth_started_at'] <= 43200
        && $now - (int) $_SESSION['auth_last_seen'] <= 1800;
}

/** Atomic fixed-window counters shared across PHP sessions, not a cookie-based lockout. */
function app_login_limit_hit(PDO $pdo, string $bucket, int $limit, int $now): int
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $pdo->exec('CREATE TABLE IF NOT EXISTS security_login_limits (bucket VARCHAR(64) NOT NULL PRIMARY KEY, attempts INTEGER NOT NULL, reset_at INTEGER NOT NULL)');
    $cleanup = $pdo->prepare('DELETE FROM security_login_limits WHERE reset_at < :expired');
    $cleanup->execute([':expired' => $now - 900]);
    $insert = $pdo->prepare(($mysql ? 'INSERT IGNORE' : 'INSERT OR IGNORE') . ' INTO security_login_limits (bucket, attempts, reset_at) VALUES (:bucket, 0, :reset)');
    $insert->execute([':bucket' => hash('sha256', $bucket), ':reset' => $now + 900]);
    $update = $pdo->prepare('UPDATE security_login_limits SET attempts = CASE WHEN reset_at <= :now1 THEN 1 ELSE attempts + 1 END, reset_at = CASE WHEN reset_at <= :now2 THEN :reset ELSE reset_at END WHERE bucket = :bucket');
    $update->execute([':now1' => $now, ':now2' => $now, ':reset' => $now + 900, ':bucket' => hash('sha256', $bucket)]);
    $read = $pdo->prepare('SELECT attempts, reset_at FROM security_login_limits WHERE bucket = :bucket');
    $read->execute([':bucket' => hash('sha256', $bucket)]);
    $row = $read->fetch();
    return (int) $row['attempts'] > $limit ? max(1, (int) $row['reset_at'] - $now) : 0;
}

function app_limit_login(PDO $pdo, string $username): void
{
    // Do not trust user-supplied X-Forwarded-For. Shared proxies may share this limit.
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $retry = app_login_limit_hit($pdo, 'ip:' . $ip, 30, time());
    if ($retry === 0) $retry = app_login_limit_hit($pdo, 'account:' . strtolower($username), 10, time());
    if ($retry > 0) {
        header('Retry-After: ' . $retry);
        app_json(['ok' => false, 'message' => 'Terlalu banyak percobaan login. Tunggu hingga 15 menit sebelum mencoba lagi.'], 429);
    }
}
