<?php
declare(strict_types=1);

// One household calendar, configured by an administrator. Secrets never enter API responses.
function app_gc_config(): array
{
    return json_decode(app_setting('google_calendar_config', '{}') ?? '{}', true) ?: [];
}
function app_gc_tokens(): array
{
    return json_decode(app_setting('google_calendar_tokens', '{}') ?? '{}', true) ?: [];
}
function app_gc_store(string $key, array $value): void
{
    app_set_setting('google_calendar_' . $key, json_encode($value, JSON_UNESCAPED_SLASHES));
}
function app_gc_status(): array
{
    $c = app_gc_config();
    return ['client_id' => $c['client_id'] ?? '', 'has_secret' => !empty($c['client_secret']),
        'redirect_uri' => $c['redirect_uri'] ?? '', 'minutes' => $c['minutes'] ?? 10,
        'enabled' => !empty($c['enabled']), 'connected' => !empty(app_gc_tokens()['refresh_token']),
        'last_error' => app_setting('google_calendar_error', '')];
}
function app_gc_http(string $url, array $body, string $bearer = ''): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('Hosting perlu mengaktifkan ekstensi PHP cURL.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $bearer === '' ? ['Content-Type: application/x-www-form-urlencoded'] : ['Content-Type: application/json', 'Authorization: Bearer ' . $bearer],
        CURLOPT_POSTFIELDS => $bearer === '' ? http_build_query($body) : json_encode($body)]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Tidak dapat menghubungi Google. Periksa koneksi hosting lalu coba lagi.');
    $data = json_decode($raw, true);
    if ($status === 409 && $bearer !== '') return ['already_exists' => true];
    if ($status < 200 || $status >= 300 || !is_array($data)) {
        if (($data['error'] ?? '') === 'invalid_grant') throw new RuntimeException('Izin Google kedaluwarsa/dicabut. Hubungkan ulang akun di Pengaturan.');
        throw new RuntimeException('Google menolak permintaan (HTTP ' . $status . '). Periksa kredensial, izin kalender, dan Google Calendar API di Pengaturan.');
    }
    return $data;
}
function app_gc_event(array $r, int $minutes): array
{
    $when = (string) $r['reminder_at'];
    if ((int) ($r['has_time'] ?? 1) === 0) $when = substr($when, 0, 10) . ' 09:00:00';
    $start = new DateTimeImmutable($when, new DateTimeZone(date_default_timezone_get()));
    return ['summary' => $r['title'], 'description' => trim(($r['category'] ?? '') . "\n" . ($r['note'] ?? '')),
        'start' => ['dateTime' => $start->format(DATE_RFC3339), 'timeZone' => date_default_timezone_get()],
        'end' => ['dateTime' => $start->modify('+30 minutes')->format(DATE_RFC3339), 'timeZone' => date_default_timezone_get()],
        'reminders' => ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => $minutes]]]];
}
function app_gc_create(int $id): array
{
    $c = app_gc_config();
    if (empty($c['enabled'])) return ['ok' => false, 'skipped' => true, 'message' => 'Google Kalender otomatis belum aktif.'];
    try {
        $q = app_db()->prepare('SELECT * FROM reminders WHERE id = :id');
        $q->execute([':id' => $id]);
        $r = $q->fetch();
        if (!$r || $r['status'] !== 'pending') throw new RuntimeException('Hanya pengingat aktif yang dapat dikirim.');
        $tokens = app_gc_tokens();
        if (empty($tokens['refresh_token'])) throw new RuntimeException('Hubungkan akun Google terlebih dahulu di Pengaturan.');
        $budget = app_notification_budget('google');
        if (!$budget['ok']) return $budget;
        $access = app_gc_http('https://oauth2.googleapis.com/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret']]);
        if (empty($access['access_token'])) throw new RuntimeException('Google tidak memberikan token akses. Hubungkan ulang akun.');
        // Stable, Google-compatible event ID makes retries safe after a timeout.
        $event = app_gc_event($r, (int) ($c['minutes'] ?? 10));
        $event['id'] = hash('sha256', ($c['namespace'] ?? '') . ':' . $id);
        app_gc_http('https://www.googleapis.com/calendar/v3/calendars/primary/events', $event, $access['access_token']);
        app_set_setting('google_calendar_error', '');
        return ['ok' => true, 'message' => 'Agenda tersimpan/sudah ada di Google Kalender.'];
    } catch (Throwable $e) {
        $message = $e instanceof RuntimeException ? $e->getMessage() : 'Integrasi Google Kalender gagal. Periksa konfigurasi hosting.';
        app_set_setting('google_calendar_error', $message);
        return ['ok' => false, 'message' => 'Reminder tetap tersimpan di website. ' . $message . ' Gunakan tombol kirim Google Kalender untuk mencoba lagi.'];
    }
}
function app_gc_routes(string $action, string $method, array $input, array $user): void
{
    if (strpos($action, 'google/') !== 0) return;
    if ($action === 'google/send' && $method === 'POST') {
        $id = (int) ($input['id'] ?? 0);
        if (!app_can_manage_entity($user, app_entity_owner_id(app_db(), 'reminder', $id))) app_json(['ok' => false, 'message' => 'Tidak berhak mengirim pengingat ini.'], 403);
        app_notification_reject_if_limited(app_notification_budget('google', 'reminder:' . $id));
        $result = app_gc_create($id);
        app_notification_reject_if_limited($result);
        app_json($result, $result['ok'] ? 200 : 422);
    }
    app_require_admin($user);
    if ($action === 'google/save' && $method === 'POST') {
        $old = app_gc_config();
        $c = ['client_id' => trim((string) ($input['client_id'] ?? '')),
            'client_secret' => trim((string) ($input['client_secret'] ?? '')) ?: ($old['client_secret'] ?? ''),
            'redirect_uri' => trim((string) ($input['redirect_uri'] ?? '')),
            'enabled' => !empty($input['enabled']), 'minutes' => (int) ($input['minutes'] ?? 10),
            'namespace' => $old['namespace'] ?? bin2hex(random_bytes(16))];
        $u = parse_url($c['redirect_uri']);
        if (!$c['client_id'] || !$c['client_secret'] || strlen($c['client_id']) > 300 || strlen($c['client_secret']) > 500 || !filter_var($c['redirect_uri'], FILTER_VALIDATE_URL) || ($u['scheme'] ?? '') !== 'https' || ($u['path'] ?? '') !== '/api.php' || ($u['query'] ?? '') !== 'action=google/callback' || isset($u['fragment']) || isset($u['user']) || !in_array($c['minutes'], [0,5,10,30,60,1440], true)) {
            app_json(['ok' => false, 'message' => 'Isi Client ID/Secret, alarm valid, dan URL callback HTTPS: https://domain-website/api.php?action=google/callback'], 422);
        }
        if (($old['client_id'] ?? '') !== $c['client_id'] || ($old['client_secret'] ?? '') !== $c['client_secret'] || ($old['redirect_uri'] ?? '') !== $c['redirect_uri']) app_gc_store('tokens', []);
        app_gc_store('config', $c);
        unset($_SESSION['google_oauth']);
        app_json(['ok' => true, 'message' => 'Konfigurasi disimpan. Hubungkan akun jika belum terhubung.']);
    }
    if ($action === 'google/connect' && $method === 'POST') {
        $c = app_gc_config();
        if (empty($c['client_id']) || empty($c['client_secret']) || empty($c['redirect_uri'])) app_json(['ok' => false, 'message' => 'Simpan konfigurasi dahulu.'], 422);
        $state = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $_SESSION['google_oauth'] = ['state' => $state, 'verifier' => $verifier, 'expires' => time() + 600, 'config' => hash('sha256', json_encode($c))];
        app_json(['ok' => true, 'url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $c['client_id'], 'redirect_uri' => $c['redirect_uri'], 'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar.events.owned', 'access_type' => 'offline', 'prompt' => 'consent',
            'state' => $state, 'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')])]);
    }
    if ($action === 'google/callback' && $method === 'GET') {
        $pending = $_SESSION['google_oauth'] ?? [];
        unset($_SESSION['google_oauth']);
        $c = app_gc_config();
        $message = 'Koneksi Google gagal. Coba hubungkan ulang dari Pengaturan.';
        try {
            if (empty($pending['state']) || !hash_equals($pending['state'], (string) ($_GET['state'] ?? '')) || ($pending['expires'] ?? 0) < time() || !hash_equals($pending['config'], hash('sha256', json_encode($c)))) throw new RuntimeException('Permintaan koneksi tidak valid/kedaluwarsa. Ulangi dari Pengaturan.');
            if (isset($_GET['error']) || empty($_GET['code'])) throw new RuntimeException('Izin Google dibatalkan. Ulangi dari Pengaturan jika ingin menghubungkan.');
            $t = app_gc_http('https://oauth2.googleapis.com/token', ['grant_type' => 'authorization_code', 'code' => (string) $_GET['code'], 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'redirect_uri' => $c['redirect_uri'], 'code_verifier' => $pending['verifier']]);
            if (empty($t['refresh_token']) || strpos($t['scope'] ?? '', 'https://www.googleapis.com/auth/calendar.events.owned') === false) throw new RuntimeException('Izin kalender/offline belum diberikan. Hubungkan ulang dan setujui izin kalender.');
            app_gc_store('tokens', ['refresh_token' => $t['refresh_token']]);
            app_set_setting('google_calendar_error', '');
            $message = 'Google Kalender terhubung. Kembali ke Pengaturan dan pastikan opsi otomatis aktif.';
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException ? $e->getMessage() : $message;
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        echo '<!doctype html><html lang="id"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Google Kalender</title><body><h1>Google Kalender</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><a href="/">Kembali ke website → Pengaturan</a></body></html>';
        exit;
    }
    if ($action === 'google/disconnect' && $method === 'POST') {
        // Local disconnect; Google account security page can revoke consent completely.
        app_gc_store('tokens', []);
        $c = app_gc_config(); $c['enabled'] = false; app_gc_store('config', $c);
        unset($_SESSION['google_oauth']);
        app_set_setting('google_calendar_error', '');
        app_json(['ok' => true, 'message' => 'Koneksi lokal dilepas. Agenda lama tetap ada; cabut izin aplikasi juga melalui keamanan akun Google bila diperlukan.']);
    }
    app_json(['ok' => false, 'message' => 'Aksi Google tidak tersedia.'], 404);
}
