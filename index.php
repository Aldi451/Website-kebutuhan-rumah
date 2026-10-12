<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
app_start_session();
try {
    app_db();
    $user = app_user();
    $adminHashQuery = app_db()->prepare("SELECT password_hash FROM users WHERE username = 'ALDI' LIMIT 1");
    $adminHashQuery->execute();
    $adminHash = $adminHashQuery->fetchColumn();
    $adminUsesDefaultPassword = $adminHash !== false && password_verify('admin123', (string) $adminHash);
    $boot = [
        'user' => $user ? ['id' => $user['id'], 'username' => $user['username'], 'name' => $user['display_name'], 'role' => $user['role']] : null,
        'csrf' => $_SESSION['csrf_token'],
        'showInitialCredentials' => !$user && $adminUsesDefaultPassword,
        'initialCredentialHint' => !$user && $adminUsesDefaultPassword ? 'Akun admin awal: ALDI / admin123. Setelah masuk, segera ganti kata sandi di Pengaturan.' : '',
        'timezone' => date_default_timezone_get(),
        'appName' => 'RumaRapi',
    ];
} catch (Throwable $error) {
    error_log('[RumaRapi] ' . $error->getMessage());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RumaRapi belum siap</title><body style="font-family:system-ui,sans-serif;background:#f4f7f8;color:#243a43;padding:4rem 1.5rem"><main style="max-width:560px;margin:auto;background:white;border:1px solid #e8edf0;border-radius:18px;padding:2rem"><h1 style="font-size:1.4rem">Database belum siap</h1><p style="line-height:1.7;color:#687980">RumaRapi belum bisa mengakses database. Periksa pengaturan di <code>config.php</code>, pastikan detail MySQL benar dan user database punya izin membuat tabel, lalu muat ulang halaman.</p></main></body></html>';
    exit;
}
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f6f8fb">
    <meta name="description" content="RumaRapi — catatan keuangan rumah, target bulanan, dan pengingat keluarga dalam satu tempat.">
    <title>RumaRapi — Keuangan rumah lebih tertata</title>
    <link rel="icon" type="image/svg+xml" href="/assets/brand.svg">
    <link rel="stylesheet" href="/assets/style.css?v=1">
    <script>
        window.RUMARAPI_BOOT = <?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script defer src="/assets/app.js?v=1"></script>
</head>
<body>
    <div id="app" aria-live="polite">
        <div class="initial-loader"><span class="loader-mark">R</span><span>Menyiapkan RumaRapi…</span></div>
    </div>
    <div id="modal-root"></div>
    <div id="toast-root" class="toast-root" aria-live="assertive" aria-atomic="true"></div>
</body>
</html>
