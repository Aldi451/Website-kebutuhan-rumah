<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
app_start_session();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = trim((string) ($_GET['action'] ?? ''));
$input = [];
if ($action === 'csrf' && $method === 'GET') {
    app_json(['ok' => true, 'csrf' => $_SESSION['csrf_token']]);
}

if ($method !== 'GET') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw === false ? '' : $raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    } elseif (isset($_POST) && is_array($_POST)) {
        $input = $_POST;
    }

    $givenToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['_csrf'] ?? '');
    if (!isset($_SESSION['csrf_token']) || $givenToken === '' || !hash_equals((string) $_SESSION['csrf_token'], $givenToken)) {
        app_json(['ok' => false, 'message' => 'Permintaan kedaluwarsa. Muat ulang halaman lalu coba lagi.'], 419);
    }
}

try {
    $pdo = app_db();
    $now = date('Y-m-d H:i:s');

    if ($action === 'login' && $method === 'POST') {
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        if ($username === '' || $password === '') {
            app_json(['ok' => false, 'message' => 'Nama pengguna dan kata sandi wajib diisi.'], 422);
        }
        $query = $pdo->prepare('SELECT id, username, display_name, password_hash, role FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1');
        $query->execute([':username' => $username]);
        $record = $query->fetch();
        if (!$record || !password_verify($password, (string) $record['password_hash'])) {
            app_json(['ok' => false, 'message' => 'Nama pengguna atau kata sandi kurang tepat.'], 401);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $record['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        app_json([
            'ok' => true,
            'csrf' => $_SESSION['csrf_token'],
            'user' => ['id' => (int) $record['id'], 'username' => $record['username'], 'name' => $record['display_name'], 'role' => $record['role']],
        ]);
    }

    if ($action === 'logout' && $method === 'POST') {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        app_json(['ok' => true, 'csrf' => $_SESSION['csrf_token']]);
    }

    if ($action === 'health' && $method === 'GET') {
        app_json(['ok' => true, 'app' => 'Saku Rumah']);
    }

    $user = app_require_user();

    if ($action === 'me' && $method === 'GET') {
        app_json(['ok' => true, 'user' => ['id' => $user['id'], 'username' => $user['username'], 'name' => $user['display_name'], 'role' => $user['role']], 'csrf' => $_SESSION['csrf_token']]);
    }

    if ($action === 'dashboard' && $method === 'GET') {
        $month = date('Y-m');
        $totals = $pdo->prepare('SELECT kind, COALESCE(SUM(amount), 0) AS total FROM transactions WHERE SUBSTR(transaction_date, 1, 7) = :month GROUP BY kind');
        $totals->execute([':month' => $month]);
        $byKind = ['income' => 0, 'expense' => 0];
        foreach ($totals->fetchAll() as $row) {
            $byKind[(string) $row['kind']] = (int) $row['total'];
        }
        $all = $pdo->query("SELECT kind, COALESCE(SUM(amount), 0) AS total FROM transactions GROUP BY kind")->fetchAll();
        $allByKind = ['income' => 0, 'expense' => 0];
        foreach ($all as $row) {
            $allByKind[(string) $row['kind']] = (int) $row['total'];
        }
        $targetQuery = $pdo->prepare('SELECT amount FROM monthly_targets WHERE month_key = :month LIMIT 1');
        $targetQuery->execute([':month' => $month]);
        $targetValue = $targetQuery->fetchColumn();
        $target = $targetValue === false ? 0 : (int) $targetValue;

        $chart = [];
        $chartQuery = $pdo->prepare('SELECT kind, COALESCE(SUM(amount), 0) AS total FROM transactions WHERE transaction_date >= :start AND transaction_date < :end GROUP BY kind');
        for ($offset = 5; $offset >= 0; $offset--) {
            $chartDate = new DateTimeImmutable('first day of this month', new DateTimeZone(date_default_timezone_get()));
            if ($offset > 0) {
                $chartDate = $chartDate->modify('-' . $offset . ' months');
            }
            $chartMonth = $chartDate->format('Y-m');
            $chartQuery->execute([
                ':start' => $chartDate->format('Y-m-01'),
                ':end' => $chartDate->modify('+1 month')->format('Y-m-01'),
            ]);
            $values = ['income' => 0, 'expense' => 0];
            foreach ($chartQuery->fetchAll() as $row) {
                $values[(string) $row['kind']] = (int) $row['total'];
            }
            $chart[] = ['month' => $chartMonth, 'label' => $chartDate->format('M'), 'income' => $values['income'], 'expense' => $values['expense']];
        }

        $recentQuery = $pdo->query('SELECT t.id, t.kind, t.title, t.category, t.amount, t.transaction_date, t.note, t.user_id, u.display_name AS created_by_name FROM transactions t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.transaction_date DESC, t.id DESC LIMIT 7');
        $reminderQuery = $pdo->prepare("SELECT r.id, r.title, r.category, r.reminder_at, r.note, r.status, r.created_by, u.display_name AS created_by_name FROM reminders r LEFT JOIN users u ON u.id = r.created_by WHERE r.status = 'pending' ORDER BY r.reminder_at ASC LIMIT 4");
        $reminderQuery->execute();
        $reminderCounts = $pdo->query("SELECT status, COUNT(*) AS total FROM reminders GROUP BY status")->fetchAll();
        $counts = ['pending' => 0, 'done' => 0];
        foreach ($reminderCounts as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        app_json([
            'ok' => true,
            'month' => $month,
            'summary' => [
                'balance' => $allByKind['income'] - $allByKind['expense'],
                'income' => $byKind['income'],
                'expense' => $byKind['expense'],
                'target' => $target,
                'target_progress' => $target > 0 ? min(100, (int) round($byKind['expense'] / $target * 100)) : 0,
                'all_income' => $allByKind['income'],
                'all_expense' => $allByKind['expense'],
            ],
            'chart' => $chart,
            'transactions' => array_map('app_clean_transaction', $recentQuery->fetchAll()),
            'reminders' => array_map('app_clean_reminder', $reminderQuery->fetchAll()),
            'reminder_counts' => $counts,
        ]);
    }

    if ($action === 'transactions' && $method === 'GET') {
        $month = trim((string) ($_GET['month'] ?? date('Y-m')));
        if (!app_valid_month($month)) {
            app_json(['ok' => false, 'message' => 'Format bulan tidak valid.'], 422);
        }
        $query = $pdo->prepare('SELECT t.id, t.kind, t.title, t.category, t.amount, t.transaction_date, t.note, t.user_id, t.created_at, u.display_name AS created_by_name FROM transactions t LEFT JOIN users u ON u.id = t.user_id WHERE SUBSTR(t.transaction_date, 1, 7) = :month ORDER BY t.transaction_date DESC, t.id DESC LIMIT 500');
        $query->execute([':month' => $month]);
        $targetQuery = $pdo->prepare('SELECT amount FROM monthly_targets WHERE month_key = :month LIMIT 1');
        $targetQuery->execute([':month' => $month]);
        $targetAmount = $targetQuery->fetchColumn();
        $summaryQuery = $pdo->prepare('SELECT kind, COALESCE(SUM(amount), 0) AS total FROM transactions WHERE SUBSTR(transaction_date, 1, 7) = :month GROUP BY kind');
        $summaryQuery->execute([':month' => $month]);
        $summary = ['income' => 0, 'expense' => 0];
        foreach ($summaryQuery->fetchAll() as $row) {
            $summary[(string) $row['kind']] = (int) $row['total'];
        }
        app_json([
            'ok' => true,
            'month' => $month,
            'target' => $targetAmount === false ? 0 : (int) $targetAmount,
            'summary' => $summary,
            'transactions' => array_map('app_clean_transaction', $query->fetchAll()),
        ]);
    }

    if ($action === 'transaction/create' && $method === 'POST') {
        $kind = (string) ($input['kind'] ?? '');
        $title = trim((string) ($input['title'] ?? ''));
        $category = trim((string) ($input['category'] ?? 'Lainnya'));
        $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_INT);
        $date = trim((string) ($input['date'] ?? ''));
        $note = trim((string) ($input['note'] ?? ''));
        if (!in_array($kind, ['income', 'expense'], true) || $title === '' || strlen($title) > 100 || $category === '' || strlen($category) > 40 || $amount === false || $amount < 1 || $amount > 999999999999 || !app_valid_date($date) || strlen($note) > 500) {
            app_json(['ok' => false, 'message' => 'Periksa lagi detail transaksi. Nominal harus lebih dari Rp 0.'], 422);
        }
        $insert = $pdo->prepare('INSERT INTO transactions (kind, title, category, amount, transaction_date, note, user_id, created_at) VALUES (:kind, :title, :category, :amount, :date, :note, :user, :created)');
        $insert->execute([
            ':kind' => $kind, ':title' => $title, ':category' => $category,
            ':amount' => $amount, ':date' => $date, ':note' => $note === '' ? null : $note,
            ':user' => $user['id'], ':created' => $now,
        ]);
        app_json(['ok' => true, 'message' => 'Transaksi berhasil disimpan.']);
    }

    if ($action === 'transaction/delete' && $method === 'POST') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            app_json(['ok' => false, 'message' => 'Transaksi tidak ditemukan.'], 422);
        }
        $query = $pdo->prepare('SELECT user_id FROM transactions WHERE id = :id');
        $query->execute([':id' => $id]);
        $owner = $query->fetchColumn();
        if ($owner === false) {
            app_json(['ok' => false, 'message' => 'Transaksi sudah tidak ada.'], 404);
        }
        if ($user['role'] !== 'admin' && (int) $owner !== $user['id']) {
            app_json(['ok' => false, 'message' => 'Kamu hanya bisa menghapus transaksi yang kamu catat.'], 403);
        }
        $delete = $pdo->prepare('DELETE FROM transactions WHERE id = :id');
        $delete->execute([':id' => $id]);
        app_json(['ok' => true, 'message' => 'Transaksi dihapus.']);
    }

    if ($action === 'target/save' && $method === 'POST') {
        $month = trim((string) ($input['month'] ?? ''));
        $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_INT);
        if (!app_valid_month($month) || $amount === false || $amount < 1 || $amount > 999999999999) {
            app_json(['ok' => false, 'message' => 'Bulan dan target harus diisi dengan benar.'], 422);
        }
        $update = $pdo->prepare('UPDATE monthly_targets SET amount = :amount, updated_by = :user, updated_at = :updated WHERE month_key = :month');
        $update->execute([':amount' => $amount, ':user' => $user['id'], ':updated' => $now, ':month' => $month]);
        if ($update->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM monthly_targets WHERE month_key = :month');
            $exists->execute([':month' => $month]);
            if ((int) $exists->fetchColumn() === 0) {
                $insert = $pdo->prepare('INSERT INTO monthly_targets (month_key, amount, updated_by, updated_at) VALUES (:month, :amount, :user, :updated)');
                $insert->execute([':month' => $month, ':amount' => $amount, ':user' => $user['id'], ':updated' => $now]);
            }
        }
        app_json(['ok' => true, 'message' => 'Target bulan ini berhasil diperbarui.']);
    }

    if ($action === 'reminders' && $method === 'GET') {
        $status = trim((string) ($_GET['status'] ?? 'all'));
        if (!in_array($status, ['all', 'pending', 'done'], true)) {
            $status = 'all';
        }
        if ($status === 'all') {
            $query = $pdo->query('SELECT r.id, r.title, r.category, r.reminder_at, r.note, r.status, r.created_by, r.created_at, r.completed_at, u.display_name AS created_by_name FROM reminders r LEFT JOIN users u ON u.id = r.created_by ORDER BY CASE WHEN r.status = \'pending\' THEN 0 ELSE 1 END, CASE WHEN r.status = \'pending\' THEN r.reminder_at END ASC, CASE WHEN r.status = \'done\' THEN r.completed_at END DESC LIMIT 300');
        } else {
            $query = $pdo->prepare('SELECT r.id, r.title, r.category, r.reminder_at, r.note, r.status, r.created_by, r.created_at, r.completed_at, u.display_name AS created_by_name FROM reminders r LEFT JOIN users u ON u.id = r.created_by WHERE r.status = :status ORDER BY reminder_at ASC LIMIT 300');
            $query->execute([':status' => $status]);
        }
        app_json(['ok' => true, 'reminders' => array_map('app_clean_reminder', $query->fetchAll())]);
    }

    if ($action === 'reminder/create' && $method === 'POST') {
        $title = trim((string) ($input['title'] ?? ''));
        $category = trim((string) ($input['category'] ?? 'Lainnya'));
        $reminderAt = app_valid_datetime_local((string) ($input['reminder_at'] ?? ''));
        $note = trim((string) ($input['note'] ?? ''));
        if ($title === '' || strlen($title) > 120 || $category === '' || strlen($category) > 40 || $reminderAt === null || strlen($note) > 500) {
            app_json(['ok' => false, 'message' => 'Isi judul dan waktu pengingat dengan benar.'], 422);
        }
        $insert = $pdo->prepare('INSERT INTO reminders (title, category, reminder_at, note, status, created_by, created_at) VALUES (:title, :category, :at, :note, :status, :user, :created)');
        $insert->execute([
            ':title' => $title, ':category' => $category, ':at' => $reminderAt,
            ':note' => $note === '' ? null : $note, ':status' => 'pending',
            ':user' => $user['id'], ':created' => $now,
        ]);
        $telegram = app_send_telegram("🔔 Pengingat baru • Saku Rumah\n\n{$title}\n" . date('d M Y, H.i', strtotime($reminderAt)) . " WIB\nKategori: {$category}\nDibuat oleh: {$user['display_name']}" . ($note !== '' ? "\n\nCatatan: {$note}" : ''));
        app_json([
            'ok' => true,
            'message' => 'Pengingat berhasil disimpan.',
            'telegram_sent' => (bool) ($telegram['ok'] ?? false),
            'telegram_message' => (string) ($telegram['description'] ?? ''),
        ]);
    }

    if ($action === 'reminder/toggle' && $method === 'POST') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            app_json(['ok' => false, 'message' => 'Pengingat tidak ditemukan.'], 422);
        }
        $query = $pdo->prepare('SELECT status FROM reminders WHERE id = :id');
        $query->execute([':id' => $id]);
        $status = $query->fetchColumn();
        if ($status === false) {
            app_json(['ok' => false, 'message' => 'Pengingat sudah tidak ada.'], 404);
        }
        $nextStatus = $status === 'done' ? 'pending' : 'done';
        $update = $pdo->prepare('UPDATE reminders SET status = :status, completed_at = :completed WHERE id = :id');
        $update->execute([':status' => $nextStatus, ':completed' => $nextStatus === 'done' ? $now : null, ':id' => $id]);
        app_json(['ok' => true, 'message' => $nextStatus === 'done' ? 'Pengingat ditandai selesai.' : 'Pengingat dikembalikan ke daftar aktif.']);
    }

    if ($action === 'reminder/delete' && $method === 'POST') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            app_json(['ok' => false, 'message' => 'Pengingat tidak ditemukan.'], 422);
        }
        $query = $pdo->prepare('SELECT created_by FROM reminders WHERE id = :id');
        $query->execute([':id' => $id]);
        $owner = $query->fetchColumn();
        if ($owner === false) {
            app_json(['ok' => false, 'message' => 'Pengingat sudah tidak ada.'], 404);
        }
        if ($user['role'] !== 'admin' && (int) $owner !== $user['id']) {
            app_json(['ok' => false, 'message' => 'Kamu hanya bisa menghapus pengingat yang kamu buat.'], 403);
        }
        $delete = $pdo->prepare('DELETE FROM reminders WHERE id = :id');
        $delete->execute([':id' => $id]);
        app_json(['ok' => true, 'message' => 'Pengingat dihapus.']);
    }

    if ($action === 'password/change' && $method === 'POST') {
        $current = (string) ($input['current_password'] ?? '');
        $new = (string) ($input['new_password'] ?? '');
        if (strlen($new) < 8 || strlen($new) > 200) {
            app_json(['ok' => false, 'message' => 'Kata sandi baru minimal 8 karakter.'], 422);
        }
        $query = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $query->execute([':id' => $user['id']]);
        $hash = $query->fetchColumn();
        if ($hash === false || !password_verify($current, (string) $hash)) {
            app_json(['ok' => false, 'message' => 'Kata sandi lama tidak sesuai.'], 422);
        }
        $update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $update->execute([':hash' => password_hash($new, PASSWORD_DEFAULT), ':id' => $user['id']]);
        app_json(['ok' => true, 'message' => 'Kata sandi berhasil diganti.']);
    }

    if ($action === 'users/list' && $method === 'GET') {
        app_require_admin($user);
        $query = $pdo->query('SELECT id, username, display_name, role, created_at FROM users ORDER BY CASE WHEN role = \'admin\' THEN 0 ELSE 1 END, display_name ASC');
        app_json(['ok' => true, 'users' => array_map(static function (array $row): array {
            return ['id' => (int) $row['id'], 'username' => $row['username'], 'name' => $row['display_name'], 'role' => $row['role'], 'created_at' => $row['created_at']];
        }, $query->fetchAll())]);
    }

    if ($action === 'users/create' && $method === 'POST') {
        app_require_admin($user);
        $username = trim((string) ($input['username'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username) || $name === '' || strlen($name) > 80 || strlen($password) < 8 || strlen($password) > 200) {
            app_json(['ok' => false, 'message' => 'Username 3–32 karakter, nama wajib diisi, dan password minimal 8 karakter.'], 422);
        }
        $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(:username)');
        $exists->execute([':username' => $username]);
        if ((int) $exists->fetchColumn() > 0) {
            app_json(['ok' => false, 'message' => 'Username tersebut sudah digunakan.'], 409);
        }
        $insert = $pdo->prepare('INSERT INTO users (username, display_name, password_hash, role, created_at) VALUES (:username, :name, :hash, :role, :created)');
        $insert->execute([
            ':username' => $username, ':name' => $name,
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => 'member', ':created' => $now,
        ]);
        app_json(['ok' => true, 'message' => 'Akun anggota berhasil dibuat.']);
    }

    if ($action === 'users/reset-password' && $method === 'POST') {
        app_require_admin($user);
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        $password = (string) ($input['password'] ?? '');
        if (!$id || $id < 1 || $id === $user['id'] || strlen($password) < 8 || strlen($password) > 200) {
            app_json(['ok' => false, 'message' => 'Pilih anggota dan kata sandi baru (minimal 8 karakter).'], 422);
        }
        $query = $pdo->prepare("SELECT id FROM users WHERE id = :id AND role = 'member'");
        $query->execute([':id' => $id]);
        if (!$query->fetchColumn()) {
            app_json(['ok' => false, 'message' => 'Akun anggota tidak ditemukan.'], 404);
        }
        $update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $update->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]);
        app_json(['ok' => true, 'message' => 'Kata sandi anggota berhasil diatur ulang.']);
    }

    if ($action === 'settings/get' && $method === 'GET') {
        $credentials = app_telegram_credentials();
        app_json([
            'ok' => true,
            'can_manage_telegram' => $user['role'] === 'admin',
            'telegram' => [
                'connected' => $credentials['token'] !== '' && $credentials['chat_id'] !== '',
                'chat_id' => $user['role'] === 'admin' ? $credentials['chat_id'] : '',
                'token_hint' => $user['role'] === 'admin' && $credentials['token'] !== '' ? '••••••••' . substr($credentials['token'], -5) : '',
            ],
        ]);
    }

    if ($action === 'telegram/discover' && $method === 'POST') {
        app_require_admin($user);
        $token = trim((string) ($input['token'] ?? ''));
        if ($token === '') {
            app_json(['ok' => false, 'message' => 'Masukkan token bot terlebih dahulu.'], 422);
        }
        $result = app_telegram_request('getUpdates', $token, ['limit' => 20, 'allowed_updates' => ['message']]);
        if (!($result['ok'] ?? false)) {
            app_json(['ok' => false, 'message' => (string) ($result['description'] ?? 'Telegram belum mengirim pesan dari chat ini.')], 422);
        }
        $updates = $result['result'] ?? [];
        $chat = null;
        foreach (array_reverse(is_array($updates) ? $updates : []) as $update) {
            $candidate = $update['message']['chat'] ?? $update['edited_message']['chat'] ?? null;
            if (is_array($candidate) && isset($candidate['id'])) {
                $chat = $candidate;
                break;
            }
        }
        if (!$chat) {
            app_json(['ok' => false, 'message' => 'Belum ada pesan dari bot. Buka chat bot Telegram lalu kirim /start, kemudian coba lagi.'], 404);
        }
        app_json(['ok' => true, 'chat_id' => (string) $chat['id'], 'chat_name' => trim((string) (($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? '')))]);
    }

    if ($action === 'telegram/save' && $method === 'POST') {
        app_require_admin($user);
        $currentCredentials = app_telegram_credentials();
        $token = trim((string) ($input['token'] ?? ''));
        $chatId = trim((string) ($input['chat_id'] ?? ''));
        if ($token === '') {
            $token = $currentCredentials['token'];
        }
        if ($chatId === '') {
            $chatId = $currentCredentials['chat_id'];
        }
        if ($token === '' || $chatId === '') {
            app_json(['ok' => false, 'message' => 'Token bot dan Chat ID harus diisi.'], 422);
        }
        $check = app_telegram_request('getMe', $token);
        if (!($check['ok'] ?? false)) {
            app_json(['ok' => false, 'message' => (string) ($check['description'] ?? 'Token bot tidak bisa diverifikasi.')], 422);
        }
        app_set_setting('telegram_bot_token', $token);
        app_set_setting('telegram_chat_id', $chatId);
        $botName = (string) ($check['result']['username'] ?? 'bot');
        $test = app_send_telegram("✅ Saku Rumah berhasil terhubung ke @{$botName}.\nNotifikasi akan dikirim saat pengingat baru dibuat.");
        app_json([
            'ok' => true,
            'message' => ($test['ok'] ?? false) ? "Bot @{$botName} tersambung. Pesan percobaan sudah dikirim." : "Token tersimpan, tetapi pesan percobaan gagal: " . (string) ($test['description'] ?? 'cek Chat ID dan izin bot.'),
            'telegram_sent' => (bool) ($test['ok'] ?? false),
        ]);
    }

    if ($action === 'telegram/test' && $method === 'POST') {
        app_require_admin($user);
        $test = app_send_telegram("✅ Ini pesan percobaan dari Saku Rumah.\nBot dan Chat ID kamu sudah terhubung.");
        if (!($test['ok'] ?? false)) {
            app_json(['ok' => false, 'message' => (string) ($test['description'] ?? 'Pesan tidak dapat dikirim.')], 422);
        }
        app_json(['ok' => true, 'message' => 'Pesan percobaan berhasil dikirim ke Telegram.']);
    }

    if ($action === 'telegram/disconnect' && $method === 'POST') {
        app_require_admin($user);
        $delete = $pdo->prepare("DELETE FROM app_settings WHERE setting_key IN ('telegram_bot_token', 'telegram_chat_id')");
        $delete->execute();
        app_json(['ok' => true, 'message' => 'Bot Telegram sudah dilepas.']);
    }

    app_json(['ok' => false, 'message' => 'Aksi tidak dikenal.'], 404);
} catch (Throwable $error) {
    error_log('[Saku Rumah] ' . $error->getMessage());
    app_json(['ok' => false, 'message' => 'Terjadi kendala di server. Periksa konfigurasi database lalu coba lagi.'], 500);
}

function app_clean_transaction(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['amount'] = (int) $row['amount'];
    $row['user_id'] = (int) $row['user_id'];
    return $row;
}

function app_clean_reminder(array $row): array
{
    $row['id'] = (int) $row['id'];
    $row['created_by'] = (int) $row['created_by'];
    return $row;
}
