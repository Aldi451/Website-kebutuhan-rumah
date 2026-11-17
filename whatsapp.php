<?php
declare(strict_types=1);

/**
 * Webhook pesan masuk dari Fonnte.
 *
 * Cara pakai:
 * 1. Login admin → Pengaturan → Bot WhatsApp → salin "Alamat webhook".
 * 2. Buka dashboard Fonnte → Device → Edit → tempel alamat itu di kolom Webhook.
 * 3. Simpan. Setiap pesan masuk akan dijawab bot sesuai daftar nomor yang diizinkan.
 *
 * Alamat ini memakai kunci rahasia (?key=...) yang dibuat otomatis dan bisa
 * diganti dari menu Pengaturan.
 */

require_once __DIR__ . '/includes/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function app_wa_webhook_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // Reading the endpoint must not create/reset a webhook credential.
    $expected = (string) app_setting('wa_webhook_secret', '');
} catch (Throwable $error) {
    error_log('[Bot WhatsApp] unable to read webhook configuration');
    app_wa_webhook_json(['ok' => false, 'message' => 'Konfigurasi webhook belum dapat diakses.'], 503);
}
$providedRaw = $_GET['key'] ?? $_SERVER['HTTP_X_WEBHOOK_KEY'] ?? '';
$provided = is_string($providedRaw) ? $providedRaw : '';
if (strlen($expected) < 32 || $provided === '' || !hash_equals($expected, $provided)) {
    app_wa_webhook_json(['ok' => false, 'message' => 'Kunci webhook tidak valid.'], 403);
}
app_allow_methods(['GET', 'POST']);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    app_wa_webhook_json(['ok' => true, 'message' => 'Webhook bot WhatsApp aktif. Fonnte harus mengirim POST ke alamat ini.']);
}

try {
    $maxBody = 64 * 1024;
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) app_wa_webhook_json(['ok' => false, 'message' => 'Payload webhook terlalu besar.'], 413);
    $raw = file_get_contents('php://input', false, null, 0, $maxBody + 1);
    if (is_string($raw) && strlen($raw) > $maxBody) app_wa_webhook_json(['ok' => false, 'message' => 'Payload webhook terlalu besar.'], 413);
    $payload = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    if (!is_array($payload) || $payload === []) {
        app_wa_webhook_json(['ok' => false, 'message' => 'Isi webhook kosong.'], 422);
    }

    foreach (['sender' => 80, 'member' => 80, 'device' => 80, 'type' => 40, 'message' => 4096, 'text' => 4096, 'timestamp' => 80, 'inboxid' => 200] as $field => $limit) {
        if (isset($payload[$field]) && ((!is_string($payload[$field]) && !is_int($payload[$field])) || strlen((string) $payload[$field]) > $limit)) {
            app_wa_webhook_json(['ok' => false, 'message' => 'Format field webhook tidak valid.'], 422);
        }
    }

    $sender = trim((string) ($payload['sender'] ?? ''));
    $member = trim((string) ($payload['member'] ?? ''));
    $message = trim((string) ($payload['message'] ?? $payload['text'] ?? ''));
    $device = trim((string) ($payload['device'] ?? ''));
    $type = strtolower(trim((string) ($payload['type'] ?? 'text')));
    if ($sender === '') {
        app_wa_webhook_json(['ok' => false, 'message' => 'Nomor pengirim tidak ada pada isi webhook.'], 422);
    }

    // Jangan pernah menjawab pesan yang dikirim perangkat bot sendiri.
    $storedDevice = trim((string) app_setting('wa_device_number', ''));
    $deviceNumber = app_normalize_whatsapp_target($device !== '' ? $device : $storedDevice);
    $senderPhone = app_normalize_whatsapp_target($sender);
    $memberPhone = app_normalize_whatsapp_target($member !== '' ? $member : $sender);
    if ($deviceNumber !== null && ($senderPhone === $deviceNumber || $memberPhone === $deviceNumber)) {
        app_wa_webhook_json(['ok' => true, 'ignored' => 'pesan dari perangkat bot sendiri']);
    }
    if (app_wa_is_duplicate($payload)) {
        app_wa_webhook_json(['ok' => true, 'ignored' => 'pesan sudah pernah diproses']);
    }

    $isGroup = str_contains($sender, '@g.us');
    $person = $isGroup ? $memberPhone : $senderPhone;
    $replyTarget = $isGroup ? $sender : ($senderPhone ?? $sender);

    // Pesan grup hanya dilayani kalau grupnya sudah didaftarkan admin, supaya
    // bot tidak membalas di grup yang tidak dikenal. Hak akses mengikuti grup
    // (bukan nomor pribadi) agar laporan admin tidak bocor ke anggota grup.
    if ($isGroup) {
        $contact = app_wa_contact_by_phone($sender);
        if ($contact === null) {
            app_wa_webhook_json(['ok' => true, 'ignored' => 'grup belum terdaftar']);
        }
    } else {
        $contact = $person !== null ? app_wa_contact_by_phone($person) : null;
    }

    if ($contact === null) {
        $notice = '';
        if (!$isGroup && $person !== null) {
            $notice = app_wa_unknown_text($person);
            if (app_wa_unknown_reply_allowed()) {
                app_send_whatsapp($notice, $replyTarget);
            }
        }
        $response = ['ok' => true, 'authorized' => false];
        app_wa_webhook_json($response);
    }
    if ((int) $contact['active'] !== 1) {
        $notice = !$isGroup ? 'Akses bot untuk nomor ini sedang dinonaktifkan admin rumah. Hubungi admin untuk mengaktifkannya kembali.' : '';
        if ($notice !== '') {
            app_send_whatsapp($notice, $replyTarget);
        }
        $response = ['ok' => true, 'authorized' => false, 'active' => false];
        app_wa_webhook_json($response);
    }

    app_wa_touch_contact((int) $contact['id']);

    if ($message === '') {
        if ($isGroup) {
            app_wa_webhook_json(['ok' => true, 'ignored' => 'pesan grup tanpa teks']);
        }
        $reply = 'Bot hanya bisa membaca pesan teks. Coba ketik "laporan" atau "bantuan".';
        if (in_array($type, ['image', 'video', 'audio', 'document', 'file', 'sticker'], true)) {
            $reply = 'Lampiran belum bisa dibaca bot. Ketik pertanyaan dalam bentuk teks, misalnya "laporan bulan ini".';
        }
        $sendResult = app_send_whatsapp($reply, $replyTarget);
        $response = ['ok' => true, 'replied' => (bool) ($sendResult['ok'] ?? false)];
        app_wa_webhook_json($response);
    }
    if ((function_exists('mb_strlen') ? mb_strlen($message) : strlen($message)) > 500) {
        if ($isGroup) {
            app_wa_webhook_json(['ok' => true, 'ignored' => 'pesan grup terlalu panjang']);
        }
        $reply = 'Pertanyaan terlalu panjang. Tulis singkat saja, misalnya "laporan bulan ini" atau "target".';
        $sendResult = app_send_whatsapp($reply, $replyTarget);
        $response = ['ok' => true, 'replied' => (bool) ($sendResult['ok'] ?? false), 'reason' => 'pesan terlalu panjang'];
        app_wa_webhook_json($response);
    }

    $reply = app_wa_reply(app_db(), $contact, $message, !$isGroup);
    if ($reply === '') {
        app_wa_webhook_json(['ok' => true, 'authorized' => true, 'replied' => false, 'reason' => 'tidak ada perintah yang cocok']);
    }
    $result = app_send_whatsapp($reply, $replyTarget);
    $response = [
        'ok' => true,
        'authorized' => true,
        'replied' => (bool) ($result['ok'] ?? false),
        'send_message' => (string) ($result['description'] ?? ''),
    ];
    // Never echo report contents to the HTTP caller, even for legacy debug=1.
    // The webhook secret authenticates the integration, not a dashboard admin.
    app_wa_webhook_json($response);
} catch (Throwable $error) {
    error_log('[Bot WhatsApp] ' . $error->getMessage());
    app_wa_webhook_json(['ok' => false, 'message' => 'Terjadi kendala saat memproses pesan.'], 200);
}
