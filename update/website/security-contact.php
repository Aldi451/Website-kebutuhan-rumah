<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
app_allow_methods(['GET', 'HEAD']);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

// Intentionally independent of the database and session: anyone must be able to
// discover the reporting contact, even when the app/database is unavailable.
$text = app_security_contact_text(app_config(), time());
if ($text === null) {
    http_response_code(503);
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo "Security reporting contact is not configured or has expired.\n";
    }
    exit;
}
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $text;
