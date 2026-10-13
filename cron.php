<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

try {
    $expected = (string) (app_config()['cron_secret'] ?? '');
    $provided = (string) ($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '');
    if (strlen($expected) < 32 || $provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo "Akses ditolak.\n";
        exit;
    }

    $pdo = app_db();
    $now = date('Y-m-d H:i:s');
    $query = $pdo->prepare("SELECT r.id, r.title, r.category, r.reminder_at, r.note, u.display_name AS created_by_name
        FROM reminders r LEFT JOIN users u ON u.id = r.created_by
        WHERE r.status = 'pending' AND r.reminder_at <= :now AND r.due_notified_at IS NULL
        ORDER BY r.reminder_at ASC LIMIT 50");
    $query->execute([':now' => $now]);
    $reminders = $query->fetchAll();

    $sent = 0;
    $failed = 0;
    foreach ($reminders as $reminder) {
        $message = "⏰ Pengingat rumah • waktunya dicek\n\n" . $reminder['title'] . "\n" . date('d M Y, H.i', strtotime((string) $reminder['reminder_at'])) . " WIB\nKategori: " . $reminder['category'] . "\nDibuat oleh: " . ($reminder['created_by_name'] ?: 'Anggota rumah') . ((string) ($reminder['note'] ?? '') !== '' ? "\n\nCatatan: " . $reminder['note'] : '');
        $result = app_send_telegram($message);
        if ($result['ok'] ?? false) {
            $update = $pdo->prepare('UPDATE reminders SET due_notified_at = :notified WHERE id = :id AND due_notified_at IS NULL');
            $update->execute([':notified' => $now, ':id' => (int) $reminder['id']]);
            $sent++;
        } else {
            $failed++;
        }
    }

    echo "Pemeriksaan pengingat selesai. Terkirim: {$sent}; belum terkirim: {$failed}.\n";
} catch (Throwable $error) {
    error_log('[Pengingat cron] ' . $error->getMessage());
    http_response_code(500);
    echo "Pemeriksaan reminder gagal. Periksa konfigurasi database dan server.\n";
}
