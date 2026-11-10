<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('X-Content-Type-Options: nosniff');

function telegram_backup_reject(int $status = 404): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $status === 500 ? 'Backup tidak dapat dibuat.' : 'Not found.';
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    telegram_backup_reject(404);
}

$config = app_config();
$secret = trim((string) ($config['telegram_backup_secret'] ?? ''));
$timestamp = trim((string) ($_SERVER['HTTP_X_BACKUP_TIMESTAMP'] ?? ''));
$signature = strtolower(trim((string) ($_SERVER['HTTP_X_BACKUP_SIGNATURE'] ?? '')));

if (strlen($secret) < 32 || !preg_match('/^\d{10}$/', $timestamp) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
    telegram_backup_reject();
}

if (abs(time() - (int) $timestamp) > 300) {
    telegram_backup_reject();
}

$expected = hash_hmac('sha256', "nightly-sql-backup\n" . $timestamp, $secret);
if (!hash_equals($expected, $signature)) {
    telegram_backup_reject();
}

try {
    $pdo = app_db();
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment');
    header('X-Backup-Format: rumahku-sql-v1');
    app_stream_sql_dump($pdo);
} catch (Throwable $error) {
    error_log('[Telegram DB backup] ' . $error->getMessage());
    telegram_backup_reject(500);
}
