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

/**
 * Never trust X-Forwarded-Proto/CF-Visitor from an arbitrary client unless
 * REMOTE_ADDR is explicitly listed in owner-configured trusted_proxy_ips.
 */
function app_request_is_https(?array $server = null, ?array $config = null): bool
{
    $server = $server ?? $_SERVER;
    $httpsRaw = isset($server['HTTPS']) ? strtolower(trim((string) $server['HTTPS'])) : '';
    if (in_array($httpsRaw, ['on', '1'], true)) {
        return true;
    }
    if ($httpsRaw === '' && (
        (string) ($server['SERVER_PORT'] ?? '') === '443'
        || strtolower((string) ($server['REQUEST_SCHEME'] ?? '')) === 'https'
    )) {
        return true;
    }
    $config = $config ?? (function_exists('app_config') ? app_config() : []);
    $trustedProxies = $config['trusted_proxy_ips'] ?? [];
    $remoteAddr = trim((string) ($server['REMOTE_ADDR'] ?? ''));
    if (is_array($trustedProxies) && $remoteAddr !== '' && in_array($remoteAddr, $trustedProxies, true)) {
        $forwardedProto = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        if ($forwardedProto === 'https') {
            return true;
        }
        $cfVisitor = json_decode((string) ($server['HTTP_CF_VISITOR'] ?? ''), true);
        if (is_array($cfVisitor) && strtolower((string) ($cfVisitor['scheme'] ?? '')) === 'https') {
            return true;
        }
    }
    return false;
}

/** Validate HTTP Host against RFC hostname / IPv4 / bracketed IPv6 + optional port. */
function app_validated_http_host(string $rawHost): string
{
    $host = trim($rawHost);
    if ($host === '' || strlen($host) > 253 || preg_match('/[\x00-\x20\x7f\/\\\\@?#]/', $host)) {
        return 'localhost';
    }
    if (str_starts_with($host, '[')) {
        if (!preg_match('/^\[([0-9a-fA-F:]{2,45})\](?::([1-9][0-9]{0,4}))?$/', $host, $m)) {
            return 'localhost';
        }
        if (!filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'localhost';
        }
        if (isset($m[2]) && $m[2] !== '' && (int) $m[2] > 65535) {
            return 'localhost';
        }
        return strtolower('[' . $m[1] . ']') . (isset($m[2]) && $m[2] !== '' ? ':' . $m[2] : '');
    }
    if (!preg_match('/^([A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*)(?::([1-9][0-9]{0,4}))?$/', $host, $m)) {
        return 'localhost';
    }
    if (isset($m[2]) && $m[2] !== '' && (int) $m[2] > 65535) {
        return 'localhost';
    }
    return strtolower($m[1]) . (isset($m[2]) && $m[2] !== '' ? ':' . $m[2] : '');
}

/** Safe base URL builder immune to Host header & X-Forwarded-Proto poisoning. */
function app_configured_base_url(?array $config = null, ?array $server = null): string
{
    $config = $config ?? (function_exists('app_config') ? app_config() : []);
    $server = $server ?? $_SERVER;
    $explicit = rtrim(trim((string) ($config['public_base_url'] ?? '')), '/');
    if ($explicit !== '' && strlen($explicit) <= 300 && !preg_match('/[\x00-\x20\x7f]/', $explicit)) {
        $parsed = parse_url($explicit);
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if (
            filter_var($explicit, FILTER_VALIDATE_URL)
            && in_array($scheme, ['https', 'http'], true)
            && isset($parsed['host'])
            && !isset($parsed['user'], $parsed['pass'], $parsed['query'], $parsed['fragment'])
        ) {
            return $explicit;
        }
    }
    $https = app_request_is_https($server, $config);
    $host = app_validated_http_host((string) ($server['HTTP_HOST'] ?? 'localhost'));
    $script = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? '/api.php'));
    $directory = rtrim((string) dirname($script), '/');
    if ($directory === '.' || $directory === '/' || preg_match('/[\x00-\x20\x7f]/', $directory)) {
        $directory = '';
    }
    return ($https ? 'https' : 'http') . '://' . $host . $directory;
}

/** Setting keys that hold credentials/secrets and should be encrypted at rest when configured. */
function app_sensitive_setting_keys(): array
{
    return ['fonnte_token', 'google_calendar_config', 'google_calendar_tokens', 'wa_webhook_secret'];
}

function app_encryption_key_bytes(?array $config = null): ?string
{
    $config = $config ?? (function_exists('app_config') ? app_config() : []);
    $secret = (string) ($config['app_encryption_key'] ?? '');
    if (strlen($secret) < 32 || strpos($secret, "\0") !== false) {
        return null;
    }
    return hash_hkdf('sha256', $secret, 32, 'rumahku-settings-aead-v1');
}

function app_encrypt_setting_value(string $settingKey, string $plaintext, ?array $config = null): string
{
    if ($plaintext === '' || str_starts_with($plaintext, 'enc:v1:') || !in_array($settingKey, app_sensitive_setting_keys(), true)) {
        return $plaintext;
    }
    $key = app_encryption_key_bytes($config);
    if ($key === null) {
        return $plaintext;
    }
    if (function_exists('openssl_encrypt')) {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $settingKey, 16);
        if ($cipher === false || strlen($tag) !== 16) {
            throw new RuntimeException('Gagal mengenkripsi pengaturan rahasia.');
        }
        return 'enc:v1:gcm:' . base64_encode($iv . $tag . $cipher);
    }
    if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $settingKey, $nonce, $key);
        return 'enc:v1:xchacha:' . base64_encode($nonce . $cipher);
    }
    throw new RuntimeException('Ekstensi kriptografi OpenSSL/Sodium diperlukan untuk app_encryption_key.');
}

function app_decrypt_setting_value(string $settingKey, string $stored, ?array $config = null): string
{
    if (!str_starts_with($stored, 'enc:v1:')) {
        return $stored;
    }
    $key = app_encryption_key_bytes($config);
    if ($key === null) {
        throw new RuntimeException('Pengaturan terenkripsi memerlukan app_encryption_key yang sesuai di config.php.');
    }
    if (str_starts_with($stored, 'enc:v1:gcm:')) {
        if (!function_exists('openssl_decrypt')) {
            throw new RuntimeException('Ekstensi PHP OpenSSL diperlukan untuk mendekripsi pengaturan.');
        }
        $raw = base64_decode(substr($stored, 11), true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('Format pengaturan terenkripsi tidak valid.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $settingKey);
        if ($plain === false) {
            throw new RuntimeException('Kunci enkripsi tidak cocok atau data pengaturan telah berubah.');
        }
        return $plain;
    }
    if (str_starts_with($stored, 'enc:v1:xchacha:')) {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
            throw new RuntimeException('Ekstensi PHP Sodium diperlukan untuk mendekripsi pengaturan.');
        }
        $nonceLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $raw = base64_decode(substr($stored, 15), true);
        if ($raw === false || strlen($raw) < $nonceLen + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new RuntimeException('Format pengaturan terenkripsi tidak valid.');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $nonceLen), $settingKey, substr($raw, 0, $nonceLen), $key);
        if ($plain === false) {
            throw new RuntimeException('Kunci enkripsi tidak cocok atau data pengaturan telah berubah.');
        }
        return $plain;
    }
    throw new RuntimeException('Versi enkripsi pengaturan tidak dikenali.');
}

function app_migrate_encrypted_settings(PDO $pdo, ?array $config = null): void
{
    if (app_encryption_key_bytes($config) === null) {
        return;
    }
    $keys = app_sensitive_setting_keys();
    $placeholders = implode(', ', array_fill(0, count($keys), '?'));
    $select = $pdo->prepare("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ({$placeholders})");
    $select->execute($keys);
    $update = $pdo->prepare('UPDATE app_settings SET setting_value = :enc WHERE setting_key = :key AND setting_value = :old');
    foreach ($select->fetchAll() as $row) {
        $k = (string) ($row['setting_key'] ?? '');
        $v = (string) ($row['setting_value'] ?? '');
        if ($v === '' || str_starts_with($v, 'enc:v1:')) {
            continue;
        }
        $update->execute([
            ':enc' => app_encrypt_setting_value($k, $v, $config),
            ':key' => $k,
            ':old' => $v,
        ]);
    }
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

function app_login_limit_peek(PDO $pdo, string $bucket, int $limit, int $now): int
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS security_login_limits (bucket VARCHAR(64) NOT NULL PRIMARY KEY, attempts INTEGER NOT NULL, reset_at INTEGER NOT NULL)');
    $read = $pdo->prepare('SELECT attempts, reset_at FROM security_login_limits WHERE bucket = :bucket');
    $read->execute([':bucket' => hash('sha256', $bucket)]);
    $row = $read->fetch();
    if (!$row || (int) $row['reset_at'] <= $now || (int) $row['attempts'] < $limit) {
        return 0;
    }
    return max(1, (int) $row['reset_at'] - $now);
}

function app_limit_login(PDO $pdo, string $username): void
{
    // Do not trust user-supplied X-Forwarded-For. Scope strict 10-attempt lockout
    // to (IP + account) so a remote attacker cannot permanently lock out the owner
    // simply by knowing the default admin username, while keeping a distributed cap.
    $now = time();
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $account = strtolower($username);
    $retry = app_login_limit_hit($pdo, 'ip:' . $ip, 30, $now);
    if ($retry === 0) $retry = app_login_limit_hit($pdo, 'ip_account:' . $ip . ':' . $account, 10, $now);
    if ($retry === 0) $retry = app_login_limit_peek($pdo, 'account_fail:' . $account, 60, $now);
    if ($retry > 0) {
        header('Retry-After: ' . $retry);
        app_json(['ok' => false, 'message' => 'Terlalu banyak percobaan login. Tunggu hingga 15 menit sebelum mencoba lagi.'], 429);
    }
}

function app_record_login_failure(PDO $pdo, string $username): void
{
    app_login_limit_hit($pdo, 'account_fail:' . strtolower($username), 60, time());
}

function app_clear_login_pair(PDO $pdo, string $username): void
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $bucket = hash('sha256', 'ip_account:' . $ip . ':' . strtolower($username));
    $del = $pdo->prepare('DELETE FROM security_login_limits WHERE bucket = :bucket');
    $del->execute([':bucket' => $bucket]);
}

/** Reserve one attempt atomically. Denials do not extend the cooldown. */
function app_notification_slot(PDO $pdo, string $bucket, int $limit, int $seconds, int $now): int
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $pdo->exec('CREATE TABLE IF NOT EXISTS security_notification_limits (bucket VARCHAR(64) NOT NULL PRIMARY KEY, used_count INTEGER NOT NULL, reset_at INTEGER NOT NULL)');
    $cleanup = $pdo->prepare('DELETE FROM security_notification_limits WHERE reset_at < :expired');
    $cleanup->execute([':expired' => $now - 900]);
    $key = hash('sha256', $bucket);
    $insert = $pdo->prepare(($mysql ? 'INSERT IGNORE' : 'INSERT OR IGNORE') . ' INTO security_notification_limits (bucket, used_count, reset_at) VALUES (:bucket, 0, :reset)');
    $insert->execute([':bucket' => $key, ':reset' => $now + $seconds]);
    $update = $pdo->prepare('UPDATE security_notification_limits SET used_count = CASE WHEN reset_at <= :now1 THEN 1 ELSE used_count + 1 END, reset_at = CASE WHEN reset_at <= :now2 THEN :reset ELSE reset_at END WHERE bucket = :bucket AND (reset_at <= :now3 OR used_count < :lim)');
    $update->execute([':now1' => $now, ':now2' => $now, ':now3' => $now, ':reset' => $now + $seconds, ':bucket' => $key, ':lim' => $limit]);
    if ($update->rowCount() === 1) return 0;
    $read = $pdo->prepare('SELECT reset_at FROM security_notification_limits WHERE bucket = :bucket');
    $read->execute([':bucket' => $key]);
    return max(1, (int) $read->fetchColumn() - $now);
}

/** All provider calls share persistent actor/household budgets, including cron. */
function app_notification_budget(string $channel, ?string $resource = null): array
{
    try {
        $pdo = app_db();
        $actor = !empty($_SESSION['user_id']) ? 'user:' . (int) $_SESSION['user_id'] : 'system';
        $buckets = $resource === null
            ? [[$channel . ':' . $actor, 30, 900], [$channel . ':household', 100, 900]]
            : [[$channel . ':resource:' . $resource, 1, 60]];
        foreach ($buckets as [$key, $limit, $seconds]) {
            $retry = app_notification_slot($pdo, $key, $limit, $seconds, time());
            if ($retry > 0) {
                $message = 'Batas pengiriman tercapai. Coba lagi dalam ' . $retry . ' detik. Data yang sudah disimpan tetap aman.';
                return ['ok' => false, 'rate_limited' => true, 'retry_after' => $retry, 'message' => $message, 'description' => $message, 'data' => []];
            }
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        // Fail closed: a broken quota store must not allow unlimited provider calls.
        error_log('[Notification limit] quota storage unavailable');
        $message = 'Pengiriman ditunda karena pembatas keamanan belum dapat diakses. Periksa database hosting.';
        return ['ok' => false, 'rate_limited' => true, 'retry_after' => 60, 'message' => $message, 'description' => $message, 'data' => []];
    }
}

function app_notification_reject_if_limited(array $result): void
{
    if (!empty($result['rate_limited'])) {
        header('Retry-After: ' . (int) $result['retry_after']);
        app_json(['ok' => false, 'message' => $result['description'], 'retry_after' => (int) $result['retry_after']], 429);
    }
}
