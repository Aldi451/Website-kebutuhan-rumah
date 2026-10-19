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
        app_json(['ok' => true, 'app' => 'Keuangan rumah']);
    }

    $user = app_require_user();

    // Mengirim gambar bukti apa adanya (bukan JSON). Dipakai oleh <img> di halaman bukti.
    if ($action === 'attachment' && $method === 'GET') {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Bukti tidak ditemukan.';
            exit;
        }
        $query = $pdo->prepare('SELECT mime, byte_size, image_data, file_name FROM attachments WHERE id = :id LIMIT 1');
        $query->execute([':id' => $id]);
        $record = $query->fetch();
        if (!$record) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Bukti tidak ditemukan.';
            exit;
        }
        $data = $record['image_data'];
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }
        $data = (string) $data;
        if ($data === '') {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Isi gambar tidak tersimpan pada cadangan ini.';
            exit;
        }
        $download = (string) ($_GET['download'] ?? '') === '1';
        header('Content-Type: ' . ((string) $record['mime'] !== '' ? (string) $record['mime'] : 'image/jpeg'));
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: private, max-age=120, no-transform');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="bukti-' . $id . '.jpg"');
        echo $data;
        exit;
    }

    // Cadangan database dalam bentuk file .sql (khusus admin).
    if ($action === 'backup/download' && $method === 'GET') {
        app_require_admin($user);
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="backup-rumah-' . date('Y-m-d-His') . '.sql"');
        header('Cache-Control: no-store, max-age=0');
        app_stream_sql_dump($pdo);
        exit;
    }

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
        $reminderQuery = $pdo->prepare("SELECT r.id, r.title, r.category, r.reminder_at, r.has_time, r.note, r.status, r.created_by, u.display_name AS created_by_name FROM reminders r LEFT JOIN users u ON u.id = r.created_by WHERE r.status = 'pending' ORDER BY r.reminder_at ASC LIMIT 4");
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
            'transactions' => array_map('app_clean_transaction', app_attach_receipts($pdo, $recentQuery->fetchAll(), 'transaction')),
            'reminders' => array_map('app_clean_reminder', app_attach_receipts($pdo, $reminderQuery->fetchAll(), 'reminder')),
            'reminder_counts' => $counts,
        ]);
    }

    if ($action === 'transactions' && $method === 'GET') {
        $month = trim((string) ($_GET['month'] ?? date('Y-m')));
        if (!app_valid_month($month)) {
            app_json(['ok' => false, 'message' => 'Format bulan tidak valid.'], 422);
        }
        $query = $pdo->prepare('SELECT t.id, t.kind, t.title, t.category, t.amount, t.transaction_date, t.note, t.user_id, t.created_at, u.display_name AS created_by_name FROM transactions t LEFT JOIN users u ON u.id = t.user_id WHERE SUBSTR(t.transaction_date, 1, 7) = :month ORDER BY t.transaction_date DESC, t.id DESC LIMIT 1000');
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
            'transactions' => array_map('app_clean_transaction', app_attach_receipts($pdo, $query->fetchAll(), 'transaction')),
        ]);
    }

    if ($action === 'report' && $method === 'GET') {
        $period = trim((string) ($_GET['period'] ?? 'month_to_date'));
        $allowedPeriods = ['today', 'month_to_date', 'last_month', 'year_to_date', 'last_year', 'custom'];
        if (!in_array($period, $allowedPeriods, true)) {
            app_json(['ok' => false, 'message' => 'Periode laporan tidak dikenali.'], 422);
        }
        $kind = trim((string) ($_GET['kind'] ?? 'all'));
        if (!in_array($kind, ['all', 'income', 'expense'], true)) {
            app_json(['ok' => false, 'message' => 'Jenis transaksi tidak dikenali.'], 422);
        }
        $category = trim((string) ($_GET['category'] ?? ''));
        if (strlen($category) > 40) {
            app_json(['ok' => false, 'message' => 'Nama kategori terlalu panjang.'], 422);
        }
        $compare = (string) ($_GET['compare'] ?? '1') !== '0';
        $timezone = new DateTimeZone(date_default_timezone_get());
        $today = new DateTimeImmutable('today', $timezone);
        $year = (int) $today->format('Y');
        $currentStart = $today;
        $currentEnd = $today->modify('+1 day');
        $comparisonStart = null;
        $comparisonEnd = null;
        $periodLabel = '';
        $comparisonLabel = '';

        if ($period === 'today') {
            $comparisonStart = $today->modify('-1 day');
            $comparisonEnd = $today;
            $periodLabel = 'Hari ini';
            $comparisonLabel = 'Kemarin';
        } elseif ($period === 'month_to_date') {
            $currentStart = $today->modify('first day of this month');
            $currentEnd = $today->modify('+1 day');
            $comparisonStart = $currentStart->modify('-1 month');
            $comparisonDay = min((int) $today->format('j'), (int) $comparisonStart->format('t'));
            $comparisonEnd = $comparisonStart->setDate(
                (int) $comparisonStart->format('Y'),
                (int) $comparisonStart->format('n'),
                $comparisonDay
            )->modify('+1 day');
            $periodLabel = 'Bulan ini sampai hari ini';
            $comparisonLabel = 'Bulan lalu sampai tanggal yang sepadan';
        } elseif ($period === 'last_month') {
            $currentStart = $today->modify('first day of last month');
            $currentEnd = $today->modify('first day of this month');
            $comparisonStart = $currentStart->modify('-1 month');
            $comparisonEnd = $currentStart;
            $periodLabel = 'Bulan lalu';
            $comparisonLabel = 'Dua bulan lalu';
        } elseif ($period === 'year_to_date') {
            $currentStart = $today->setDate($year, 1, 1);
            $currentEnd = $today->modify('+1 day');
            $previousYearStart = $today->setDate($year - 1, 1, 1);
            $comparisonStart = $previousYearStart;
            $comparisonMonthStart = $previousYearStart->setDate($year - 1, (int) $today->format('n'), 1);
            $comparisonDay = min((int) $today->format('j'), (int) $comparisonMonthStart->format('t'));
            $comparisonEnd = $comparisonMonthStart->setDate($year - 1, (int) $today->format('n'), $comparisonDay)->modify('+1 day');
            $periodLabel = 'Tahun ini sampai hari ini';
            $comparisonLabel = 'Tahun lalu sampai tanggal yang sepadan';
        } elseif ($period === 'last_year') {
            $currentStart = $today->setDate($year - 1, 1, 1);
            $currentEnd = $today->setDate($year, 1, 1);
            $comparisonStart = $today->setDate($year - 2, 1, 1);
            $comparisonEnd = $currentStart;
            $periodLabel = 'Tahun lalu';
            $comparisonLabel = 'Dua tahun lalu';
        } else {
            $startText = trim((string) ($_GET['start'] ?? ''));
            $endText = trim((string) ($_GET['end'] ?? ''));
            if (!app_valid_date($startText) || !app_valid_date($endText)) {
                app_json(['ok' => false, 'message' => 'Tanggal awal dan akhir laporan wajib diisi dengan benar.'], 422);
            }
            $currentStart = new DateTimeImmutable($startText, $timezone);
            $inclusiveEnd = new DateTimeImmutable($endText, $timezone);
            if ($inclusiveEnd < $currentStart) {
                app_json(['ok' => false, 'message' => 'Tanggal akhir tidak boleh sebelum tanggal awal.'], 422);
            }
            $currentEnd = $inclusiveEnd->modify('+1 day');
            $duration = (int) $currentStart->diff($currentEnd)->days;
            if ($duration < 1 || $duration > 3660) {
                app_json(['ok' => false, 'message' => 'Rentang laporan maksimal 10 tahun.'], 422);
            }
            $comparisonEnd = $currentStart;
            $comparisonStart = $currentStart->sub(new DateInterval('P' . $duration . 'D'));
            $periodLabel = 'Rentang khusus';
            $comparisonLabel = 'Rentang sebelumnya dengan durasi yang sama';
        }

        $filterSql = '';
        $filterParams = [];
        if ($category !== '') {
            $filterSql .= ' AND LOWER(category) = LOWER(:category)';
            $filterParams[':category'] = $category;
        }
        if ($kind !== 'all') {
            $filterSql .= ' AND kind = :kind';
            $filterParams[':kind'] = $kind;
        }

        $bindRange = static function (array $filterParams, DateTimeImmutable $start, DateTimeImmutable $end): array {
            $params = [':start' => $start->format('Y-m-d'), ':end' => $end->format('Y-m-d')];
            foreach ($filterParams as $key => $value) {
                $params[$key] = $value;
            }
            return $params;
        };

        $summaryStatement = $pdo->prepare("SELECT kind, COUNT(*) AS records, COALESCE(SUM(amount), 0) AS total FROM transactions WHERE transaction_date >= :start AND transaction_date < :end{$filterSql} GROUP BY kind");
        $summaryFor = static function (DateTimeImmutable $start, DateTimeImmutable $end) use ($summaryStatement, $filterParams, $bindRange): array {
            $summaryStatement->execute($bindRange($filterParams, $start, $end));
            $summary = ['income' => 0, 'expense' => 0, 'income_count' => 0, 'expense_count' => 0, 'count' => 0];
            foreach ($summaryStatement->fetchAll() as $row) {
                $rowKind = (string) $row['kind'];
                if (!in_array($rowKind, ['income', 'expense'], true)) {
                    continue;
                }
                $summary[$rowKind] = (int) $row['total'];
                $summary[$rowKind . '_count'] = (int) $row['records'];
                $summary['count'] += (int) $row['records'];
            }
            $summary['net'] = $summary['income'] - $summary['expense'];
            return $summary;
        };

        $categoryStatement = $pdo->prepare("SELECT category, COUNT(*) AS records, COALESCE(SUM(CASE WHEN kind = 'income' THEN amount ELSE 0 END), 0) AS income, COALESCE(SUM(CASE WHEN kind = 'expense' THEN amount ELSE 0 END), 0) AS expense FROM transactions WHERE transaction_date >= :start AND transaction_date < :end{$filterSql} GROUP BY category");
        $mergeCategories = static function (array $rows): array {
            $merged = [];
            foreach ($rows as $row) {
                $label = trim((string) ($row['category'] ?? ''));
                $key = strtolower($label);
                if ($key === '') {
                    $key = 'tanpa kategori';
                    $label = 'Tanpa kategori';
                }
                if (!isset($merged[$key])) {
                    $merged[$key] = ['category' => $label, 'income' => 0, 'expense' => 0, 'count' => 0];
                } elseif (strlen($label) > strlen((string) $merged[$key]['category'])) {
                    $merged[$key]['category'] = $label;
                }
                $merged[$key]['income'] += (int) $row['income'];
                $merged[$key]['expense'] += (int) $row['expense'];
                $merged[$key]['count'] += (int) $row['count'];
            }
            return $merged;
        };
        $categoriesFor = static function (DateTimeImmutable $start, DateTimeImmutable $end) use ($categoryStatement, $filterParams, $bindRange, $mergeCategories): array {
            $categoryStatement->execute($bindRange($filterParams, $start, $end));
            $rows = [];
            foreach ($categoryStatement->fetchAll() as $row) {
                $rows[] = [
                    'category' => (string) $row['category'],
                    'income' => (int) $row['income'],
                    'expense' => (int) $row['expense'],
                    'count' => (int) $row['records'],
                ];
            }
            return $mergeCategories($rows);
        };

        $activeDaysStatement = $pdo->prepare("SELECT COUNT(DISTINCT transaction_date) FROM transactions WHERE transaction_date >= :start AND transaction_date < :end{$filterSql}");
        $activeDaysFor = static function (DateTimeImmutable $start, DateTimeImmutable $end) use ($activeDaysStatement, $filterParams, $bindRange): int {
            $activeDaysStatement->execute($bindRange($filterParams, $start, $end));
            return (int) $activeDaysStatement->fetchColumn();
        };

        $largestStatement = $pdo->prepare("SELECT id, kind, title, category, amount, transaction_date FROM transactions WHERE transaction_date >= :start AND transaction_date < :end{$filterSql} ORDER BY amount DESC, transaction_date DESC, id DESC LIMIT 5");
        $largestFor = static function (DateTimeImmutable $start, DateTimeImmutable $end) use ($largestStatement, $filterParams, $bindRange): array {
            $largestStatement->execute($bindRange($filterParams, $start, $end));
            $items = [];
            foreach ($largestStatement->fetchAll() as $row) {
                $items[] = [
                    'id' => (int) $row['id'],
                    'kind' => (string) $row['kind'],
                    'title' => (string) $row['title'],
                    'category' => (string) $row['category'],
                    'amount' => (int) $row['amount'],
                    'transaction_date' => (string) $row['transaction_date'],
                ];
            }
            return $items;
        };

        $currentSummary = $summaryFor($currentStart, $currentEnd);
        $currentCategories = $categoriesFor($currentStart, $currentEnd);
        $previousSummary = $compare ? $summaryFor($comparisonStart, $comparisonEnd) : null;
        $previousCategories = $compare ? $categoriesFor($comparisonStart, $comparisonEnd) : [];
        $categoryKeys = array_values(array_unique(array_merge(array_keys($currentCategories), array_keys($previousCategories))));
        usort($categoryKeys, static fn (string $a, string $b): int => strcasecmp($a, $b));
        $categoryRows = [];
        foreach ($categoryKeys as $key) {
            $current = $currentCategories[$key] ?? ['income' => 0, 'expense' => 0, 'count' => 0];
            $previous = $previousCategories[$key] ?? ['income' => 0, 'expense' => 0, 'count' => 0];
            $categoryRows[] = [
                'category' => $currentCategories[$key]['category'] ?? $previousCategories[$key]['category'] ?? $key,
                'income' => (int) ($current['income'] ?? 0),
                'expense' => (int) ($current['expense'] ?? 0),
                'count' => (int) ($current['count'] ?? 0),
                'previous' => $compare ? ['income' => (int) ($previous['income'] ?? 0), 'expense' => (int) ($previous['expense'] ?? 0), 'count' => (int) ($previous['count'] ?? 0)] : null,
            ];
        }

        $optionRows = $pdo->query("SELECT DISTINCT category FROM transactions WHERE TRIM(category) <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
        $categoryOptions = [];
        $seenOptions = [];
        foreach ($optionRows as $option) {
            $label = trim((string) $option);
            $key = strtolower($label);
            if ($key === '' || isset($seenOptions[$key])) {
                continue;
            }
            $seenOptions[$key] = true;
            $categoryOptions[] = $label;
        }

        $rangeData = static function (DateTimeImmutable $start, DateTimeImmutable $end, string $label): array {
            $days = (int) $start->diff($end)->days;
            return [
                'label' => $label,
                'start' => $start->format('Y-m-d'),
                'end' => $end->modify('-1 day')->format('Y-m-d'),
                'days' => $days,
            ];
        };

        $currentPeriod = $rangeData($currentStart, $currentEnd, $periodLabel);
        $currentPeriod['active_days'] = $activeDaysFor($currentStart, $currentEnd);
        $previousPeriod = null;
        if ($compare) {
            $previousPeriod = $rangeData($comparisonStart, $comparisonEnd, $comparisonLabel);
            $previousPeriod['active_days'] = $activeDaysFor($comparisonStart, $comparisonEnd);
        }

        $weekdayPeriod = null;
        $weekdaySummary = null;
        if ($period === 'today') {
            $weekdayStart = $today->modify('-7 days');
            $weekdayEnd = $weekdayStart->modify('+1 day');
            $weekdayPeriod = $rangeData($weekdayStart, $weekdayEnd, 'Hari yang sama minggu lalu');
            $weekdaySummary = $summaryFor($weekdayStart, $weekdayEnd);
        }

        $projection = null;
        if ($period === 'month_to_date') {
            $daysInMonth = (int) $today->format('t');
            $elapsed = max(1, (int) $currentPeriod['days']);
            $daily = (int) round($currentSummary['expense'] / $elapsed);
            $monthKey = $today->format('Y-m');
            $targetQuery = $pdo->prepare('SELECT amount FROM monthly_targets WHERE month_key = :month LIMIT 1');
            $targetQuery->execute([':month' => $monthKey]);
            $targetValue = $targetQuery->fetchColumn();
            $projection = [
                'days_elapsed' => $elapsed,
                'days_in_month' => $daysInMonth,
                'days_remaining' => max(0, $daysInMonth - $elapsed),
                'daily_expense' => $daily,
                'projected_expense' => $daily * $daysInMonth,
                'target' => $targetValue === false ? 0 : (int) $targetValue,
            ];
        }

        app_json([
            'ok' => true,
            'filters' => ['period' => $period, 'kind' => $kind, 'category' => $category, 'compare' => $compare],
            'current_period' => $currentPeriod,
            'previous_period' => $previousPeriod,
            'weekday_period' => $weekdayPeriod,
            'summary' => $currentSummary,
            'previous_summary' => $previousSummary,
            'weekday_summary' => $weekdaySummary,
            'projection' => $projection,
            'highlights' => $largestFor($currentStart, $currentEnd),
            'categories' => $categoryRows,
            'category_options' => $categoryOptions,
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
        try {
            // Bukti gambar boleh dilewati (null) dan diproses lebih dulu supaya gambarnya tervalidasi sebelum disimpan.
            $receipt = app_receipt_payload($input);
        } catch (RuntimeException $error) {
            app_json(['ok' => false, 'message' => 'Bukti gambar belum bisa dipakai: ' . $error->getMessage()], 422);
        }
        $insert = $pdo->prepare('INSERT INTO transactions (kind, title, category, amount, transaction_date, note, user_id, created_at) VALUES (:kind, :title, :category, :amount, :date, :note, :user, :created)');
        $pdo->beginTransaction();
        try {
            $insert->execute([
                ':kind' => $kind, ':title' => $title, ':category' => $category,
                ':amount' => $amount, ':date' => $date, ':note' => $note === '' ? null : $note,
                ':user' => $user['id'], ':created' => $now,
            ]);
            $transactionId = (int) $pdo->lastInsertId();
            if ($receipt !== null) {
                app_save_receipt($pdo, 'transaction', $transactionId, $receipt, (int) $user['id'], $now);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        // Kirim notifikasi keuangan jika diaktifkan di pengaturan
        $notifyTypes = app_wa_notify_settings()['types'] ?? ['reminders'];
        if (in_array('finance', $notifyTypes, true) && $kind) {
            $financeMsg = '💰 Transaksi baru • ' . ($kind === 'income' ? 'Uang masuk' : 'Uang keluar') . "\n"
                . $title . ' • ' . app_format_amount($amount) . "\n"
                . 'Kategori: ' . $category . ' • ' . $date . "\n"
                . 'Oleh: ' . $user['display_name']
                . ($note !== '' ? "\nCatatan: " . $note : '')
                . ($receipt !== null ? "\nBukti gambar tersimpan di website." : '');
            try { app_send_whatsapp_broadcast($financeMsg); } catch (Throwable $e) { /* jangan gagalkan transaksi */ }
        }
        app_json([
            'ok' => true,
            'message' => $receipt === null
                ? 'Transaksi berhasil disimpan.'
                : 'Transaksi dan bukti gambar (' . app_format_bytes((int) strlen((string) $receipt['bytes'])) . ') berhasil disimpan.',
            'has_receipt' => $receipt !== null,
        ]);
    }

    // Mengubah transaksi yang sudah tercatat: jenis, nama, kategori, nominal, tanggal, catatan.
    if ($action === 'transaction/update' && $method === 'POST') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            app_json(['ok' => false, 'message' => 'Transaksi tidak ditemukan.'], 422);
        }
        $owner = app_entity_owner_id($pdo, 'transaction', (int) $id);
        if ($owner === null) {
            app_json(['ok' => false, 'message' => 'Transaksi sudah tidak ada.'], 404);
        }
        if (!app_can_manage_entity($user, $owner)) {
            app_json(['ok' => false, 'message' => 'Kamu hanya bisa mengubah transaksi yang kamu catat.'], 403);
        }
        $kind = (string) ($input['kind'] ?? '');
        $title = trim((string) ($input['title'] ?? ''));
        $category = trim((string) ($input['category'] ?? 'Lainnya'));
        $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_INT);
        $date = trim((string) ($input['date'] ?? ''));
        $note = trim((string) ($input['note'] ?? ''));
        if (!in_array($kind, ['income', 'expense'], true) || $title === '' || strlen($title) > 100 || $category === '' || strlen($category) > 40 || $amount === false || $amount < 1 || $amount > 999999999999 || !app_valid_date($date) || strlen($note) > 500) {
            app_json(['ok' => false, 'message' => 'Periksa lagi detail transaksi. Nominal harus lebih dari Rp 0.'], 422);
        }
        $update = $pdo->prepare('UPDATE transactions SET kind = :kind, title = :title, category = :category, amount = :amount, transaction_date = :date, note = :note WHERE id = :id');
        $update->execute([
            ':kind' => $kind, ':title' => $title, ':category' => $category,
            ':amount' => $amount, ':date' => $date, ':note' => $note === '' ? null : $note, ':id' => $id,
        ]);
        app_json([
            'ok' => true,
            'message' => 'Transaksi berhasil diperbarui.',
            'transaction_date' => $date,
            'month' => substr($date, 0, 7),
        ]);
    }

    if ($action === 'transactions/import' && $method === 'POST') {
        $rows = $input['rows'] ?? null;
        if (!is_array($rows) || $rows === [] || count($rows) > 500) {
            app_json(['ok' => false, 'message' => 'Pilih 1–500 baris transaksi untuk diimpor.'], 422);
        }
        $validRows = [];
        $errors = [];
        foreach ($rows as $index => $row) {
            $rowNumber = filter_var(is_array($row) ? ($row['source_row'] ?? null) : null, FILTER_VALIDATE_INT);
            if ($rowNumber === false || $rowNumber < 1) $rowNumber = $index + 2;
            if (!is_array($row)) {
                $errors[] = ['row' => $rowNumber, 'message' => 'Baris tidak terbaca.'];
                continue;
            }
            $kind = trim((string) ($row['kind'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            $category = trim((string) ($row['category'] ?? 'Lainnya'));
            $amount = filter_var($row['amount'] ?? null, FILTER_VALIDATE_INT);
            $date = trim((string) ($row['date'] ?? ''));
            $note = trim((string) ($row['note'] ?? ''));
            $problems = [];
            if (!in_array($kind, ['income', 'expense'], true)) $problems[] = 'jenis tidak valid';
            if ($title === '' || strlen($title) > 100) $problems[] = 'nama transaksi wajib diisi (maks. 100 karakter)';
            if ($category === '' || strlen($category) > 40) $problems[] = 'kategori tidak valid';
            if ($amount === false || $amount < 1 || $amount > 999999999999) $problems[] = 'nominal tidak valid';
            if (!app_valid_date($date)) $problems[] = 'tanggal tidak valid';
            if (strlen($note) > 500) $problems[] = 'catatan maksimal 500 karakter';
            if ($problems !== []) {
                $errors[] = ['row' => $rowNumber, 'message' => implode('; ', $problems)];
                continue;
            }
            $validRows[] = ['kind' => $kind, 'title' => $title, 'category' => $category, 'amount' => $amount, 'date' => $date, 'note' => $note];
        }
        if ($validRows === []) {
            app_json(['ok' => false, 'message' => 'Tidak ada transaksi valid untuk diimpor.', 'imported' => 0, 'skipped' => count($errors), 'errors' => array_slice($errors, 0, 20)], 422);
        }
        $insert = $pdo->prepare('INSERT INTO transactions (kind, title, category, amount, transaction_date, note, user_id, created_at) VALUES (:kind, :title, :category, :amount, :date, :note, :user, :created)');
        $pdo->beginTransaction();
        try {
            foreach ($validRows as $row) {
                $insert->execute([
                    ':kind' => $row['kind'], ':title' => $row['title'], ':category' => $row['category'],
                    ':amount' => $row['amount'], ':date' => $row['date'], ':note' => $row['note'] === '' ? null : $row['note'],
                    ':user' => $user['id'], ':created' => $now,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        app_json(['ok' => true, 'message' => 'Transaksi berhasil diimpor.', 'imported' => count($validRows), 'skipped' => count($errors), 'errors' => array_slice($errors, 0, 20)]);
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
        $dropReceipt = $pdo->prepare("DELETE FROM attachments WHERE entity_type = 'transaction' AND entity_id = :id");
        $dropReceipt->execute([':id' => $id]);
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
            $query = $pdo->query('SELECT r.id, r.title, r.category, r.reminder_at, r.has_time, r.note, r.status, r.created_by, r.created_at, r.completed_at, u.display_name AS created_by_name FROM reminders r LEFT JOIN users u ON u.id = r.created_by ORDER BY CASE WHEN r.status = \'pending\' THEN 0 ELSE 1 END, CASE WHEN r.status = \'pending\' THEN r.reminder_at END ASC, CASE WHEN r.status = \'done\' THEN r.completed_at END DESC LIMIT 1000');
        } else {
            $query = $pdo->prepare('SELECT r.id, r.title, r.category, r.reminder_at, r.has_time, r.note, r.status, r.created_by, r.created_at, r.completed_at, u.display_name AS created_by_name FROM reminders r LEFT JOIN users u ON u.id = r.created_by WHERE r.status = :status ORDER BY reminder_at ASC LIMIT 1000');
            $query->execute([':status' => $status]);
        }
        app_json(['ok' => true, 'reminders' => array_map('app_clean_reminder', app_attach_receipts($pdo, $query->fetchAll(), 'reminder'))]);
    }

    if ($action === 'reminder/create' && $method === 'POST') {
        $title = trim((string) ($input['title'] ?? ''));
        $category = trim((string) ($input['category'] ?? 'Lainnya'));
        // Jam bersifat opsional: tanggal saja disimpan sebagai pengingat "sepanjang hari".
        $when = app_resolve_reminder_when($input);
        $note = trim((string) ($input['note'] ?? ''));
        if ($title === '' || strlen($title) > 120 || $category === '' || strlen($category) > 40 || $when === null || strlen($note) > 500) {
            app_json(['ok' => false, 'message' => 'Isi judul dan tanggal pengingat dengan benar.'], 422);
        }
        $reminderAt = $when['at'];
        $hasTime = (int) $when['has_time'];
        try {
            $receipt = app_receipt_payload($input);
        } catch (RuntimeException $error) {
            app_json(['ok' => false, 'message' => 'Bukti gambar belum bisa dipakai: ' . $error->getMessage()], 422);
        }
        $insert = $pdo->prepare('INSERT INTO reminders (title, category, reminder_at, has_time, note, status, created_by, created_at) VALUES (:title, :category, :at, :has_time, :note, :status, :user, :created)');
        $pdo->beginTransaction();
        try {
            $insert->execute([
                ':title' => $title, ':category' => $category, ':at' => $reminderAt, ':has_time' => $hasTime,
                ':note' => $note === '' ? null : $note, ':status' => 'pending',
                ':user' => $user['id'], ':created' => $now,
            ]);
            $reminderId = (int) $pdo->lastInsertId();
            if ($receipt !== null) {
                app_save_receipt($pdo, 'reminder', $reminderId, $receipt, (int) $user['id'], $now);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        $whatsapp = app_send_whatsapp_broadcast(app_reminder_message([
            'title' => $title, 'category' => $category, 'reminder_at' => $reminderAt, 'has_time' => $hasTime,
            'note' => $note, 'created_by_name' => $user['display_name'],
        ]) . ($receipt !== null ? "\n\nBukti gambar tersimpan di website." : ''));
        // Hormati pengaturan jenis notifikasi
        $notifyTypes = app_wa_notify_settings()['types'] ?? ['reminders'];
        if (!in_array('reminders', $notifyTypes, true)) {
            $whatsapp = ['ok' => true, 'description' => 'Notifikasi pengingat dimatikan di pengaturan.'];
        }
        app_json([
            'ok' => true,
            'message' => trim(($receipt === null ? 'Pengingat berhasil disimpan.' : 'Pengingat dan bukti gambarnya berhasil disimpan.')
                . ($hasTime === 0 ? ' Tanpa jam khusus, kabarnya dikirim sekitar ' . app_default_reminder_time() . '.' : '')
                . (($whatsapp['ok'] ?? false) ? ' (' . (string) ($whatsapp['description'] ?? '') . ')' : '')),
            'whatsapp_sent' => (bool) ($whatsapp['ok'] ?? false),
            'whatsapp_message' => (string) ($whatsapp['description'] ?? ''),
            'whatsapp_sent_count' => (int) ($whatsapp['sent'] ?? 0),
            'has_receipt' => $receipt !== null,
            'has_time' => $hasTime,
        ]);
    }

    if ($action === 'reminders/import' && $method === 'POST') {
        $rows = $input['rows'] ?? null;
        if (!is_array($rows) || $rows === [] || count($rows) > 500) {
            app_json(['ok' => false, 'message' => 'Pilih 1–500 baris pengingat untuk diimpor.'], 422);
        }
        $validRows = [];
        $errors = [];
        foreach ($rows as $index => $row) {
            $rowNumber = filter_var(is_array($row) ? ($row['source_row'] ?? null) : null, FILTER_VALIDATE_INT);
            if ($rowNumber === false || $rowNumber < 1) $rowNumber = $index + 2;
            if (!is_array($row)) {
                $errors[] = ['row' => $rowNumber, 'message' => 'Baris tidak terbaca.'];
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            $category = trim((string) ($row['category'] ?? 'Lainnya'));
            // Jam boleh kosong pada file impor: barisnya menjadi pengingat "sepanjang hari".
            $when = app_resolve_reminder_when(is_array($row) ? $row : []);
            $note = trim((string) ($row['note'] ?? ''));
            $problems = [];
            if ($title === '' || strlen($title) > 120) $problems[] = 'judul wajib diisi (maks. 120 karakter)';
            if ($category === '' || strlen($category) > 40) $problems[] = 'kategori tidak valid';
            if ($when === null) $problems[] = 'tanggal atau waktu tidak valid';
            if (strlen($note) > 500) $problems[] = 'catatan maksimal 500 karakter';
            if ($problems !== []) {
                $errors[] = ['row' => $rowNumber, 'message' => implode('; ', $problems)];
                continue;
            }
            $validRows[] = ['title' => $title, 'category' => $category, 'reminder_at' => $when['at'], 'has_time' => (int) $when['has_time'], 'note' => $note];
        }
        if ($validRows === []) {
            app_json(['ok' => false, 'message' => 'Tidak ada pengingat valid untuk diimpor.', 'imported' => 0, 'skipped' => count($errors), 'errors' => array_slice($errors, 0, 20)], 422);
        }
        $insert = $pdo->prepare('INSERT INTO reminders (title, category, reminder_at, has_time, note, status, created_by, created_at) VALUES (:title, :category, :at, :has_time, :note, :status, :user, :created)');
        $pdo->beginTransaction();
        try {
            foreach ($validRows as $row) {
                $insert->execute([
                    ':title' => $row['title'], ':category' => $row['category'], ':at' => $row['reminder_at'], ':has_time' => $row['has_time'],
                    ':note' => $row['note'] === '' ? null : $row['note'], ':status' => 'pending',
                    ':user' => $user['id'], ':created' => $now,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        app_json(['ok' => true, 'message' => 'Pengingat berhasil diimpor.', 'imported' => count($validRows), 'skipped' => count($errors), 'errors' => array_slice($errors, 0, 20)]);
    }

    // Mengubah pengingat yang sudah ada: judul, tanggal, jam (opsional), kategori, catatan.
    if ($action === 'reminder/update' && $method === 'POST') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            app_json(['ok' => false, 'message' => 'Pengingat tidak ditemukan.'], 422);
        }
        $existingQuery = $pdo->prepare('SELECT reminder_at, status, due_notified_at FROM reminders WHERE id = :id LIMIT 1');
        $existingQuery->execute([':id' => $id]);
        $existing = $existingQuery->fetch();
        if (!$existing) {
            app_json(['ok' => false, 'message' => 'Pengingat sudah tidak ada.'], 404);
        }
        if (!app_can_manage_entity($user, app_entity_owner_id($pdo, 'reminder', (int) $id))) {
            app_json(['ok' => false, 'message' => 'Kamu hanya bisa mengubah pengingat yang kamu buat.'], 403);
        }
        $title = trim((string) ($input['title'] ?? ''));
        $category = trim((string) ($input['category'] ?? 'Lainnya'));
        $when = app_resolve_reminder_when($input);
        $note = trim((string) ($input['note'] ?? ''));
        if ($title === '' || strlen($title) > 120 || $category === '' || strlen($category) > 40 || $when === null || strlen($note) > 500) {
            app_json(['ok' => false, 'message' => 'Isi judul dan tanggal pengingat dengan benar.'], 422);
        }
        $reminderAt = $when['at'];
        $hasTime = (int) $when['has_time'];
        // Kalau waktunya dipindah ke masa depan, cron boleh mengirim kabar lagi pada jadwal baru.
        // Kalau masih di masa lalu, status "sudah dikabari" dibiarkan supaya tidak mengirim ulang.
        $timeChanged = (string) $existing['reminder_at'] !== $reminderAt;
        $resetNotify = $timeChanged && $reminderAt > $now && (string) $existing['status'] === 'pending';
        $update = $pdo->prepare('UPDATE reminders SET title = :title, category = :category, reminder_at = :at, has_time = :has_time, note = :note' . ($resetNotify ? ', due_notified_at = NULL' : '') . ' WHERE id = :id');
        $update->execute([
            ':title' => $title, ':category' => $category, ':at' => $reminderAt, ':has_time' => $hasTime,
            ':note' => $note === '' ? null : $note, ':id' => $id,
        ]);
        app_json([
            'ok' => true,
            'message' => $hasTime === 0
                ? 'Pengingat diperbarui (sepanjang hari, kabar dikirim sekitar ' . app_default_reminder_time() . ').'
                : 'Pengingat berhasil diperbarui.',
            'has_time' => $hasTime,
            'renotified' => $resetNotify,
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
        $dropReceipt = $pdo->prepare("DELETE FROM attachments WHERE entity_type = 'reminder' AND entity_id = :id");
        $dropReceipt->execute([':id' => $id]);
        $delete = $pdo->prepare('DELETE FROM reminders WHERE id = :id');
        $delete->execute([':id' => $id]);
        app_json(['ok' => true, 'message' => 'Pengingat dihapus.']);
    }

    // Kirim kabar satu pengingat ke WhatsApp secara manual: untuk menguji notifikasi
    // atau menyampaikan ulang pengingat yang cron-nya belum jalan.
    if ($action === 'reminder/send' && $method === 'POST') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            app_json(['ok' => false, 'message' => 'Pengingat tidak ditemukan.'], 422);
        }
        $query = $pdo->prepare('SELECT r.id, r.title, r.category, r.reminder_at, r.has_time, r.note, r.status, r.due_notified_at, u.display_name AS created_by_name
            FROM reminders r LEFT JOIN users u ON u.id = r.created_by WHERE r.id = :id LIMIT 1');
        $query->execute([':id' => $id]);
        $reminder = $query->fetch();
        if (!$reminder) {
            app_json(['ok' => false, 'message' => 'Pengingat sudah tidak ada.'], 404);
        }
        $result = app_send_whatsapp_broadcast(app_reminder_message($reminder, '🔔 Pengingat rumah • dikirim manual oleh ' . (string) $user['display_name']));
        if (!($result['ok'] ?? false)) {
            app_json(['ok' => false, 'message' => (string) ($result['description'] ?? 'Pesan tidak dapat dikirim.')], 422);
        }
        // Pengingat yang sudah jatuh tempo dianggap sudah dikabari, supaya cron tidak mengirim dua kali.
        $markedNotified = false;
        if ((string) $reminder['status'] === 'pending' && $reminder['due_notified_at'] === null && (string) $reminder['reminder_at'] <= $now) {
            $update = $pdo->prepare('UPDATE reminders SET due_notified_at = :notified WHERE id = :id AND due_notified_at IS NULL');
            $update->execute([':notified' => $now, ':id' => $id]);
            $markedNotified = true;
        }
        app_json([
            'ok' => true,
            'message' => 'Kabar pengingat “' . (string) $reminder['title'] . '” dikirim ke WhatsApp.',
            'marked_notified' => $markedNotified,
        ]);
    }

    // Uji bentuk pesan pengingat tanpa membuat catatan baru (untuk admin, dari menu Pengaturan).
    if ($action === 'reminder/test' && $method === 'POST') {
        app_require_admin($user);
        $sample = [
            'title' => 'Contoh pengingat: bayar tagihan listrik',
            'category' => 'Tagihan',
            'reminder_at' => date('Y-m-d H:i:s', strtotime('+2 hours')),
            'has_time' => 1,
            'note' => 'Ini pesan uji coba dari menu Pengaturan. Pengingat sungguhan dikirim otomatis saat waktunya tiba.',
            'created_by_name' => (string) $user['display_name'],
        ];
        $result = app_send_whatsapp_broadcast(app_reminder_message($sample, '🧪 Tes kirim pengingat'));
        if (!($result['ok'] ?? false)) {
            app_json(['ok' => false, 'message' => (string) ($result['description'] ?? 'Pesan tidak dapat dikirim.')], 422);
        }
        $sent = (int) ($result['sent'] ?? 1);
        app_json(['ok' => true, 'message' => 'Contoh pesan pengingat berhasil dikirim ke ' . $sent . ' perangkat WhatsApp.']);
    }

    // Menambah atau mengganti bukti gambar pada transaksi/pengingat yang sudah ada.
    if ($action === 'attachment/save' && $method === 'POST') {
        $entityType = (string) ($input['entity_type'] ?? '');
        $entityId = filter_var($input['entity_id'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($entityType, ['transaction', 'reminder'], true) || !$entityId || $entityId < 1) {
            app_json(['ok' => false, 'message' => 'Bukti harus dipasang pada transaksi atau pengingat yang jelas.'], 422);
        }
        $owner = app_entity_owner_id($pdo, $entityType, (int) $entityId);
        if ($owner === null) {
            app_json(['ok' => false, 'message' => 'Catatan yang mau diberi bukti sudah tidak ada.'], 404);
        }
        if (!app_can_manage_entity($user, $owner)) {
            app_json(['ok' => false, 'message' => 'Kamu hanya bisa menambah bukti pada catatanmu sendiri.'], 403);
        }
        try {
            $receipt = app_receipt_payload($input);
        } catch (RuntimeException $error) {
            app_json(['ok' => false, 'message' => 'Bukti gambar belum bisa dipakai: ' . $error->getMessage()], 422);
        }
        if ($receipt === null) {
            app_json(['ok' => false, 'message' => 'Pilih foto bukti terlebih dahulu.'], 422);
        }
        $pdo->beginTransaction();
        try {
            $attachmentId = app_save_receipt($pdo, $entityType, (int) $entityId, $receipt, (int) $user['id'], $now);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        app_json([
            'ok' => true,
            'message' => 'Bukti gambar (' . app_format_bytes((int) strlen((string) $receipt['bytes'])) . ') berhasil disimpan.',
            'attachment' => [
                'id' => $attachmentId,
                'mime' => (string) $receipt['mime'],
                'bytes' => strlen((string) $receipt['bytes']),
                'width' => (int) $receipt['width'],
                'height' => (int) $receipt['height'],
                'url' => '/api.php?action=attachment&id=' . $attachmentId,
            ],
        ]);
    }

    if ($action === 'attachment/delete' && $method === 'POST') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            app_json(['ok' => false, 'message' => 'Bukti tidak ditemukan.'], 422);
        }
        $query = $pdo->prepare('SELECT entity_type, entity_id, uploaded_by FROM attachments WHERE id = :id LIMIT 1');
        $query->execute([':id' => $id]);
        $record = $query->fetch();
        if (!$record) {
            app_json(['ok' => false, 'message' => 'Bukti sudah tidak ada.'], 404);
        }
        $owner = app_entity_owner_id($pdo, (string) $record['entity_type'], (int) $record['entity_id']);
        if ((int) $record['uploaded_by'] !== (int) $user['id'] && !app_can_manage_entity($user, $owner)) {
            app_json(['ok' => false, 'message' => 'Kamu hanya bisa menghapus bukti milik catatanmu sendiri.'], 403);
        }
        $delete = $pdo->prepare('DELETE FROM attachments WHERE id = :id');
        $delete->execute([':id' => $id]);
        app_json(['ok' => true, 'message' => 'Bukti gambar dihapus. Catatan keuangannya tetap tersimpan.']);
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
        $query = $pdo->query('SELECT id, username, display_name, role, phone, created_at FROM users ORDER BY CASE WHEN role = \'admin\' THEN 0 ELSE 1 END, display_name ASC');
        app_json(['ok' => true, 'users' => array_map(static function (array $row): array {
            return ['id' => (int) $row['id'], 'username' => $row['username'], 'name' => $row['display_name'], 'role' => $row['role'], 'phone' => (string) ($row['phone'] ?? ''), 'created_at' => $row['created_at']];
        }, $query->fetchAll())]);
    }

    if ($action === 'users/create' && $method === 'POST') {
        app_require_admin($user);
        $username = trim((string) ($input['username'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $phoneRaw = trim((string) ($input['phone'] ?? ''));
        $phone = $phoneRaw !== '' ? app_normalize_whatsapp_target($phoneRaw) : null;
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username) || $name === '' || strlen($name) > 80 || strlen($password) < 8 || strlen($password) > 200) {
            app_json(['ok' => false, 'message' => 'Username 3–32 karakter, nama wajib diisi, dan password minimal 8 karakter.'], 422);
        }
        if ($phoneRaw !== '' && $phone === null) {
            app_json(['ok' => false, 'message' => 'Nomor HP tidak valid. Contoh: 08123456789'], 422);
        }
        $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(:username)');
        $exists->execute([':username' => $username]);
        if ((int) $exists->fetchColumn() > 0) {
            app_json(['ok' => false, 'message' => 'Username tersebut sudah digunakan.'], 409);
        }
        $insert = $pdo->prepare('INSERT INTO users (username, display_name, password_hash, role, phone, created_at) VALUES (:username, :name, :hash, :role, :phone, :created)');
        $insert->execute([
            ':username' => $username, ':name' => $name,
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => 'member', ':phone' => $phone ?? '', ':created' => $now,
        ]);
        app_json(['ok' => true, 'message' => 'Akun anggota berhasil dibuat.' . ($phone ? ' Nomor HP tersimpan.' : '')]);
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
        $credentials = app_whatsapp_credentials();
        $isAdmin = $user['role'] === 'admin';
        $database = null;
        if ($isAdmin) {
            $summary = app_database_summary();
            $database = [
                'driver' => $summary['driver'],
                'file' => $summary['file'],
                'total_rows' => $summary['total_rows'],
                'attachment_count' => $summary['attachment_count'],
                'attachment_size' => app_format_bytes((int) $summary['attachment_bytes']),
                'tables' => $summary['tables'],
            ];
        }
        $whatsappBot = null;
        if ($isAdmin) {
            $contacts = [];
            foreach (app_wa_contacts() as $contact) {
                $contacts[] = [
                    'id' => (int) $contact['id'],
                    'phone' => (string) $contact['phone'],
                    'name' => (string) $contact['name'],
                    'access' => (string) $contact['access'],
                    'active' => (int) $contact['active'] === 1,
                    'last_message_at' => $contact['last_message_at'] !== null ? (string) $contact['last_message_at'] : '',
                ];
            }
            $monthly = app_wa_monthly_settings();
            $whatsappBot = [
                'webhook_url' => app_wa_webhook_url(),
                'contacts' => $contacts,
                'monthly' => [
                    'enabled' => $monthly['enabled'],
                    'day' => $monthly['day'],
                    'time' => $monthly['time'],
                    'last_key' => (string) app_setting('wa_monthly_last_key', ''),
                    'last_label' => (string) app_setting('wa_monthly_last_key', '') !== '' ? app_wa_month_label((string) app_setting('wa_monthly_last_key', '')) : '',
                    'last_at' => (string) app_setting('wa_monthly_last_at', ''),
                ],
            ];
        }
        // Pengaturan penerima notifikasi (batas device & pilihan user)
        $notify = null;
        $notifyPreview = null;
        $houseUsers = null;
        if ($isAdmin) {
            $notify = app_wa_notify_settings();
            $notifyPreview = app_wa_notify_targets();
            // Daftar anggota rumah beserta nomor HP untuk dipilih
            $list = $pdo->query('SELECT id, username, display_name, phone FROM users ORDER BY display_name ASC')->fetchAll();
            $houseUsers = array_map(static fn(array $r) => [
                'id' => (int) $r['id'],
                'username' => (string) $r['username'],
                'name' => (string) $r['display_name'],
                'phone' => (string) ($r['phone'] ?? ''),
                'phone_normalized' => app_normalize_whatsapp_target((string) ($r['phone'] ?? '')),
            ], $list);
        }
        app_json([
            'ok' => true,
            'can_manage_whatsapp' => $isAdmin,
            'whatsapp' => [
                'connected' => $credentials['token'] !== '' && $credentials['target'] !== '',
                'target' => $isAdmin ? $credentials['target'] : '',
                'token_hint' => $isAdmin && $credentials['token'] !== '' ? '••••••••' . substr($credentials['token'], -4) : '',
            ],
            'whatsapp_bot' => $whatsappBot,
            'whatsapp_notify' => $notify,
            'whatsapp_notify_targets' => $notifyPreview,
            'house_users' => $houseUsers,
            'database' => $database,
        ]);
    }

    // Memeriksa token Fonnte dan status perangkat WhatsApp tanpa menyimpan apa pun.
    if ($action === 'whatsapp/device' && $method === 'POST') {
        app_require_admin($user);
        $token = trim((string) ($input['token'] ?? ''));
        if ($token === '') {
            $token = app_whatsapp_credentials()['token'];
        }
        if ($token === '') {
            app_json(['ok' => false, 'message' => 'Masukkan token perangkat Fonnte terlebih dahulu.'], 422);
        }
        $device = app_whatsapp_device($token);
        if (!($device['ok'] ?? false)) {
            app_json(['ok' => false, 'message' => (string) ($device['description'] ?? 'Perangkat tidak bisa diperiksa.')], 422);
        }
        $label = (string) ($device['device'] ?? '');
        $message = (string) ($device['description'] ?? '');
        if ($device['connected'] ?? false) {
            $details = [];
            if ($device['name'] !== '') {
                $details[] = 'nama perangkat: ' . $device['name'];
            }
            if ($device['quota'] !== '') {
                $details[] = 'sisa kuota: ' . $device['quota'];
            }
            if ($device['package'] !== '') {
                $details[] = 'paket: ' . $device['package'];
            }
            $message = 'Perangkat WhatsApp ' . ($label !== '' ? $label . ' ' : '') . 'terhubung.'
                . ($details !== [] ? ' (' . implode(' · ', $details) . ')' : '');
        }
        app_json([
            'ok' => true,
            'connected' => (bool) ($device['connected'] ?? false),
            'device' => $label,
            'device_status' => (string) ($device['device_status'] ?? ''),
            'quota' => (string) ($device['quota'] ?? ''),
            'package' => (string) ($device['package'] ?? ''),
            'message' => $message,
        ]);
    }

    if ($action === 'whatsapp/save' && $method === 'POST') {
        app_require_admin($user);
        $currentCredentials = app_whatsapp_credentials();
        $token = trim((string) ($input['token'] ?? ''));
        $targetInput = trim((string) ($input['target'] ?? ''));
        if ($token === '') {
            $token = $currentCredentials['token'];
        }
        if ($targetInput === '') {
            $targetInput = $currentCredentials['target'];
        }
        if ($token === '' || $targetInput === '') {
            app_json(['ok' => false, 'message' => 'Token perangkat Fonnte dan nomor WhatsApp tujuan harus diisi.'], 422);
        }
        $device = app_whatsapp_device($token);
        if (!($device['ok'] ?? false)) {
            app_json(['ok' => false, 'message' => (string) ($device['description'] ?? 'Token Fonnte tidak bisa diverifikasi.')], 422);
        }
        if (!($device['connected'] ?? false)) {
            $deviceNumber = (string) ($device['device'] ?? '');
            app_json([
                'ok' => false,
                'message' => 'Token valid, tetapi perangkat WhatsApp'
                    . ($deviceNumber !== '' ? ' ' . $deviceNumber : '')
                    . ' belum terhubung. Buka dashboard Fonnte lalu scan QR untuk menyambungkannya, kemudian simpan lagi.',
            ], 422);
        }
        $target = app_normalize_whatsapp_target($targetInput);
        if ($target === null) {
            app_json(['ok' => false, 'message' => 'Nomor WhatsApp tujuan tidak valid. Contoh: 08123456789 atau 628123456789.'], 422);
        }
        if (app_whatsapp_number_registered($token, $target) === false) {
            app_json(['ok' => false, 'message' => 'Nomor ' . $target . ' tidak terdaftar di WhatsApp. Periksa nomornya lalu coba lagi.'], 422);
        }
        app_set_setting('fonnte_token', $token);
        app_set_setting('fonnte_target', $target);
        $deviceNumber = app_normalize_whatsapp_target((string) ($device['device'] ?? ''));
        if ($deviceNumber !== null) {
            app_set_setting('wa_device_number', $deviceNumber);
        }
        // Bersihkan pengaturan bot Telegram dari versi lama supaya token lamanya tidak tertinggal.
        $delete = $pdo->prepare("DELETE FROM app_settings WHERE setting_key IN ('telegram_bot_token', 'telegram_chat_id')");
        $delete->execute();
        $deviceLabel = (string) ($device['device'] ?? '');
        $test = app_send_whatsapp("✅ Notifikasi WhatsApp rumah berhasil terhubung.\nKabar pengingat akan dikirim ke nomor ini.");
        app_json([
            'ok' => true,
            'message' => ($test['ok'] ?? false)
                ? 'Perangkat WhatsApp' . ($deviceLabel !== '' ? ' ' . $deviceLabel : '') . ' tersambung. Pesan percobaan sudah dikirim.'
                : 'Token dan nomor tersimpan, tetapi pesan percobaan gagal: ' . (string) ($test['description'] ?? 'periksa koneksi perangkat Fonnte.'),
            'whatsapp_sent' => (bool) ($test['ok'] ?? false),
            'target' => $target,
        ]);
    }

    if ($action === 'whatsapp/test' && $method === 'POST') {
        app_require_admin($user);
        $test = app_send_whatsapp("✅ Pesan percobaan notifikasi pengingat rumah.\nPerangkat Fonnte dan nomor tujuan sudah terhubung.");
        if (!($test['ok'] ?? false)) {
            app_json(['ok' => false, 'message' => (string) ($test['description'] ?? 'Pesan tidak dapat dikirim.')], 422);
        }
        app_json(['ok' => true, 'message' => 'Pesan percobaan berhasil dikirim ke WhatsApp.']);
    }

    if ($action === 'whatsapp/contact/save' && $method === 'POST') {
        app_require_admin($user);
        $result = app_wa_save_contact([
            'id' => $input['id'] ?? null,
            'phone' => $input['phone'] ?? '',
            'name' => $input['name'] ?? '',
            'access' => $input['access'] ?? 'member',
            'active' => $input['active'] ?? 1,
        ]);
        if (!($result['ok'] ?? false)) {
            app_json(['ok' => false, 'message' => (string) ($result['message'] ?? 'Nomor tidak bisa disimpan.')], 422);
        }
        app_json(['ok' => true, 'message' => (string) $result['message'], 'id' => (int) ($result['id'] ?? 0)]);
    }

    if ($action === 'whatsapp/contact/toggle' && $method === 'POST') {
        app_require_admin($user);
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            app_json(['ok' => false, 'message' => 'Nomor bot tidak ditemukan.'], 422);
        }
        $active = app_wa_toggle_contact((int) $id);
        if ($active === null) {
            app_json(['ok' => false, 'message' => 'Nomor bot sudah tidak ada.'], 404);
        }
        app_json(['ok' => true, 'message' => $active ? 'Nomor diaktifkan kembali.' : 'Akses nomor dinonaktifkan.']);
    }

    if ($action === 'whatsapp/contact/delete' && $method === 'POST') {
        app_require_admin($user);
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1 || !app_wa_delete_contact((int) $id)) {
            app_json(['ok' => false, 'message' => 'Nomor bot tidak ditemukan.'], 404);
        }
        app_json(['ok' => true, 'message' => 'Nomor dihapus dari daftar bot.']);
    }

    if ($action === 'whatsapp/monthly/save' && $method === 'POST') {
        app_require_admin($user);
        $enabled = (string) ($input['enabled'] ?? '0') === '1';
        $result = app_wa_save_monthly_settings($enabled, trim((string) ($input['day'] ?? 'last')), trim((string) ($input['time'] ?? '20:00')));
        if (!($result['ok'] ?? false)) {
            app_json(['ok' => false, 'message' => (string) $result['message']], 422);
        }
        app_json(['ok' => true, 'message' => (string) $result['message']]);
    }

    // Kirim laporan satu bulan sekarang juga ke semua nomor tujuan.
    if ($action === 'whatsapp/report/send' && $method === 'POST') {
        app_require_admin($user);
        $month = trim((string) ($input['month'] ?? ''));
        if ($month === '') {
            $month = date('Y-m');
        }
        if (!app_valid_month($month)) {
            app_json(['ok' => false, 'message' => 'Format bulan harus YYYY-MM, misalnya 2026-10.'], 422);
        }
        $result = app_wa_send_monthly_report($month, true);
        app_json([
            'ok' => (int) ($result['sent'] ?? 0) > 0,
            'message' => (string) ($result['message'] ?? 'Laporan tidak terkirim.'),
            'sent' => (int) ($result['sent'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'recipients' => (int) ($result['recipients'] ?? 0),
        ], (int) ($result['sent'] ?? 0) > 0 ? 200 : 422);
    }

    if ($action === 'whatsapp/webhook/reset' && $method === 'POST') {
        app_require_admin($user);
        app_wa_reset_webhook_secret();
        app_json(['ok' => true, 'message' => 'Kunci webhook diganti. Tempel alamat baru di dashboard Fonnte.', 'webhook_url' => app_wa_webhook_url()]);
    }

    // Simpan pengaturan penerima notifikasi: batas device & pilihan user yang dapat notif
    if ($action === 'whatsapp/notify/save' && $method === 'POST') {
        app_require_admin($user);
        // Input bisa berupa array targets/user_ids/types atau JSON string
        $payload = $input;
        // Normalisasi: frontend mengirim { max_devices, targets, user_ids, types }
        if (isset($payload['targets']) && is_string($payload['targets'])) {
            $payload['targets'] = json_decode($payload['targets'], true) ?? preg_split('/[\\s,;\\n]+/', $payload['targets']);
        }
        $result = app_wa_notify_save_settings([
            'max_devices' => $payload['max_devices'] ?? $payload['maxDevices'] ?? 5,
            'targets' => $payload['targets'] ?? $payload['extraTargets'] ?? [],
            'user_ids' => $payload['user_ids'] ?? $payload['userIds'] ?? [],
            'types' => $payload['types'] ?? ['reminders', 'finance'],
        ]);
        if (!($result['ok'] ?? false)) {
            app_json(['ok' => false, 'message' => (string) $result['message']], 422);
        }
        app_json(['ok' => true, 'message' => (string) $result['message'], 'settings' => app_wa_notify_settings(), 'targets_preview' => app_wa_notify_targets()]);
    }

    // Update nomor HP milik anggota rumah (untuk penerima notifikasi)
    if ($action === 'users/phone/save' && $method === 'POST') {
        app_require_admin($user);
        $id = filter_var($input['id'] ?? $input['user_id'] ?? null, FILTER_VALIDATE_INT);
        $phoneRaw = trim((string) ($input['phone'] ?? ''));
        if (!$id || $id < 1) app_json(['ok' => false, 'message' => 'Pilih anggota rumah yang akan diatur nomornya.'], 422);
        $query = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $query->execute([':id' => $id]);
        if (!$query->fetchColumn()) app_json(['ok' => false, 'message' => 'Anggota tidak ditemukan.'], 404);
        if ($phoneRaw === '') {
            $update = $pdo->prepare('UPDATE users SET phone = :phone WHERE id = :id');
            $update->execute([':phone' => '', ':id' => $id]);
            app_json(['ok' => true, 'message' => 'Nomor HP dihapus. User ini tidak akan menerima notifikasi via HP.']);
        }
        $phone = app_normalize_whatsapp_target($phoneRaw);
        if ($phone === null) app_json(['ok' => false, 'message' => 'Format nomor HP tidak valid. Contoh: 08123456789'], 422);
        $update = $pdo->prepare('UPDATE users SET phone = :phone WHERE id = :id');
        $update->execute([':phone' => $phone, ':id' => $id]);
        app_json(['ok' => true, 'message' => 'Nomor HP disimpan: ' . $phone]);
    }

    // Tes kirim ke semua penerima notifikasi (untuk cek multi-device)
    if ($action === 'whatsapp/notify/test' && $method === 'POST') {
        app_require_admin($user);
        $text = trim((string) ($input['message'] ?? ''));
        if ($text === '') $text = '🧪 Tes notifikasi multi-perangkat: jika kamu menerima ini, pengaturan penerima notifikasi sudah benar.';
        $result = app_send_whatsapp_broadcast($text);
        if (!($result['ok'] ?? false)) app_json(['ok' => false, 'message' => (string) ($result['description'] ?? 'Gagal mengirim')], 422);
        app_json(['ok' => true, 'message' => (string) $result['description'], 'sent' => (int) ($result['sent'] ?? 0), 'failed' => (int) ($result['failed'] ?? 0)]);
    }

    if ($action === 'whatsapp/disconnect' && $method === 'POST') {
        app_require_admin($user);
        $delete = $pdo->prepare("DELETE FROM app_settings WHERE setting_key IN ('fonnte_token', 'fonnte_target', 'telegram_bot_token', 'telegram_chat_id')");
        $delete->execute();
        app_json(['ok' => true, 'message' => 'Koneksi WhatsApp (Fonnte) sudah dilepas.']);
    }

    app_json(['ok' => false, 'message' => 'Aksi tidak dikenal.'], 404);
} catch (Throwable $error) {
    error_log('[Keuangan rumah] ' . $error->getMessage());
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
    // has_time 0 = pengingat sepanjang hari (jam tidak ditentukan pengguna).
    $row['has_time'] = isset($row['has_time']) && (int) $row['has_time'] === 0 ? 0 : 1;
    return $row;
}
