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
        app_json([
            'ok' => true,
            'message' => $receipt === null
                ? 'Transaksi berhasil disimpan.'
                : 'Transaksi dan bukti gambar (' . app_format_bytes((int) strlen((string) $receipt['bytes'])) . ') berhasil disimpan.',
            'has_receipt' => $receipt !== null,
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
            $query = $pdo->query('SELECT r.id, r.title, r.category, r.reminder_at, r.note, r.status, r.created_by, r.created_at, r.completed_at, u.display_name AS created_by_name FROM reminders r LEFT JOIN users u ON u.id = r.created_by ORDER BY CASE WHEN r.status = \'pending\' THEN 0 ELSE 1 END, CASE WHEN r.status = \'pending\' THEN r.reminder_at END ASC, CASE WHEN r.status = \'done\' THEN r.completed_at END DESC LIMIT 1000');
        } else {
            $query = $pdo->prepare('SELECT r.id, r.title, r.category, r.reminder_at, r.note, r.status, r.created_by, r.created_at, r.completed_at, u.display_name AS created_by_name FROM reminders r LEFT JOIN users u ON u.id = r.created_by WHERE r.status = :status ORDER BY reminder_at ASC LIMIT 1000');
            $query->execute([':status' => $status]);
        }
        app_json(['ok' => true, 'reminders' => array_map('app_clean_reminder', app_attach_receipts($pdo, $query->fetchAll(), 'reminder'))]);
    }

    if ($action === 'reminder/create' && $method === 'POST') {
        $title = trim((string) ($input['title'] ?? ''));
        $category = trim((string) ($input['category'] ?? 'Lainnya'));
        $reminderAt = app_valid_datetime_local((string) ($input['reminder_at'] ?? ''));
        $note = trim((string) ($input['note'] ?? ''));
        if ($title === '' || strlen($title) > 120 || $category === '' || strlen($category) > 40 || $reminderAt === null || strlen($note) > 500) {
            app_json(['ok' => false, 'message' => 'Isi judul dan waktu pengingat dengan benar.'], 422);
        }
        try {
            $receipt = app_receipt_payload($input);
        } catch (RuntimeException $error) {
            app_json(['ok' => false, 'message' => 'Bukti gambar belum bisa dipakai: ' . $error->getMessage()], 422);
        }
        $insert = $pdo->prepare('INSERT INTO reminders (title, category, reminder_at, note, status, created_by, created_at) VALUES (:title, :category, :at, :note, :status, :user, :created)');
        $pdo->beginTransaction();
        try {
            $insert->execute([
                ':title' => $title, ':category' => $category, ':at' => $reminderAt,
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
        $telegram = app_send_telegram("🔔 Pengingat rumah\n\n{$title}\n" . date('d M Y, H.i', strtotime($reminderAt)) . " WIB\nKategori: {$category}\nDibuat oleh: {$user['display_name']}" . ($note !== '' ? "\n\nCatatan: {$note}" : '') . ($receipt !== null ? "\n\nBukti gambar tersimpan di website." : ''));
        app_json([
            'ok' => true,
            'message' => $receipt === null ? 'Pengingat berhasil disimpan.' : 'Pengingat dan bukti gambarnya berhasil disimpan.',
            'telegram_sent' => (bool) ($telegram['ok'] ?? false),
            'telegram_message' => (string) ($telegram['description'] ?? ''),
            'has_receipt' => $receipt !== null,
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
            $reminderAt = app_valid_datetime_local((string) ($row['reminder_at'] ?? ''));
            $note = trim((string) ($row['note'] ?? ''));
            $problems = [];
            if ($title === '' || strlen($title) > 120) $problems[] = 'judul wajib diisi (maks. 120 karakter)';
            if ($category === '' || strlen($category) > 40) $problems[] = 'kategori tidak valid';
            if ($reminderAt === null) $problems[] = 'tanggal atau waktu tidak valid';
            if (strlen($note) > 500) $problems[] = 'catatan maksimal 500 karakter';
            if ($problems !== []) {
                $errors[] = ['row' => $rowNumber, 'message' => implode('; ', $problems)];
                continue;
            }
            $validRows[] = ['title' => $title, 'category' => $category, 'reminder_at' => $reminderAt, 'note' => $note];
        }
        if ($validRows === []) {
            app_json(['ok' => false, 'message' => 'Tidak ada pengingat valid untuk diimpor.', 'imported' => 0, 'skipped' => count($errors), 'errors' => array_slice($errors, 0, 20)], 422);
        }
        $insert = $pdo->prepare('INSERT INTO reminders (title, category, reminder_at, note, status, created_by, created_at) VALUES (:title, :category, :at, :note, :status, :user, :created)');
        $pdo->beginTransaction();
        try {
            foreach ($validRows as $row) {
                $insert->execute([
                    ':title' => $row['title'], ':category' => $row['category'], ':at' => $row['reminder_at'],
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
        app_json([
            'ok' => true,
            'can_manage_telegram' => $isAdmin,
            'telegram' => [
                'connected' => $credentials['token'] !== '' && $credentials['chat_id'] !== '',
                'chat_id' => $isAdmin ? $credentials['chat_id'] : '',
                'token_hint' => $isAdmin && $credentials['token'] !== '' ? '••••••••' . substr($credentials['token'], -5) : '',
            ],
            'database' => $database,
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
        $test = app_send_telegram("✅ Bot @{$botName} berhasil terhubung.\nNotifikasi akan dikirim saat pengingat baru dibuat.");
        app_json([
            'ok' => true,
            'message' => ($test['ok'] ?? false) ? "Bot @{$botName} tersambung. Pesan percobaan sudah dikirim." : "Token tersimpan, tetapi pesan percobaan gagal: " . (string) ($test['description'] ?? 'cek Chat ID dan izin bot.'),
            'telegram_sent' => (bool) ($test['ok'] ?? false),
        ]);
    }

    if ($action === 'telegram/test' && $method === 'POST') {
        app_require_admin($user);
        $test = app_send_telegram("✅ Pesan percobaan notifikasi pengingat rumah.\nBot dan Chat ID sudah terhubung.");
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
    return $row;
}
