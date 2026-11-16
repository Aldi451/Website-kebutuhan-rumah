<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ *
 * Bot WhatsApp (Fonnte)
 *
 * Isi file ini:
 *  - daftar nomor yang diizinkan memakai bot (tabel wa_contacts),
 *  - penjawab pertanyaan laporan berbahasa Indonesia,
 *  - laporan bulanan otomatis yang dikirim cron di akhir bulan.
 * ------------------------------------------------------------------ */

function app_wa_months(): array
{
    return [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
}

function app_wa_rupiah(int $amount): string
{
    return ($amount < 0 ? '-Rp' : 'Rp') . number_format(abs($amount), 0, ',', '.');
}

function app_wa_month_label(string $monthKey): string
{
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $monthKey, $match)) {
        return $monthKey;
    }
    $months = app_wa_months();
    return $months[(int) $match[2]] . ' ' . $match[1];
}

function app_wa_period_for_month(string $monthKey): array
{
    $start = $monthKey . '-01';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $start, new DateTimeZone(date_default_timezone_get()));
    if (!$date) {
        $date = new DateTimeImmutable('first day of this month', new DateTimeZone(date_default_timezone_get()));
    }
    return [
        'kind' => 'month',
        'key' => $date->format('Y-m'),
        'label' => app_wa_month_label($date->format('Y-m')),
        'start' => $date->format('Y-m-01'),
        'end' => $date->modify('+1 month')->format('Y-m-01'),
    ];
}

function app_wa_period_for_year(int $year): array
{
    return [
        'kind' => 'year',
        'key' => (string) $year,
        'label' => 'Tahun ' . $year,
        'start' => sprintf('%04d-01-01', $year),
        'end' => sprintf('%04d-01-01', $year + 1),
    ];
}

/**
 * Membaca periode dari kalimat pengguna.
 * Mendukung: bulan ini, bulan lalu, nama bulan (+tahun), 2026-10, tahun ini, tahun 2025.
 * Null berarti tidak ada periode yang disebutkan.
 */
function app_wa_parse_period(string $text, ?DateTimeImmutable $now = null): ?array
{
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    $text = ' ' . preg_replace('/\s+/', ' ', strtolower(trim($text))) . ' ';

    if (preg_match('/\bbulan\s+(lalu|kemarin|sebelumnya)\b/', $text)) {
        return app_wa_period_for_month($now->modify('first day of last month')->format('Y-m'));
    }
    if (preg_match('/\bbulan\s+(ini|sekarang|berjalan)\b/', $text)) {
        return app_wa_period_for_month($now->format('Y-m'));
    }
    if (preg_match('/\btahun\s+(lalu|kemarin|sebelumnya)\b/', $text)) {
        return app_wa_period_for_year((int) $now->format('Y') - 1);
    }
    if (preg_match('/\btahun\s+(ini|sekarang|berjalan)\b/', $text)) {
        return app_wa_period_for_year((int) $now->format('Y'));
    }

    $months = app_wa_months();
    foreach ($months as $number => $name) {
        if (!preg_match('/\b' . strtolower($name) . '\b/', $text)) {
            continue;
        }
        $year = null;
        if (preg_match('/\b(20\d{2})\b/', $text, $match)) {
            $year = (int) $match[1];
        } else {
            $year = (int) $now->format('Y');
            // "laporan desember" di bulan Januari biasanya berarti Desember tahun lalu.
            if ($number > (int) $now->format('n')) {
                $year--;
            }
        }
        return app_wa_period_for_month(sprintf('%04d-%02d', $year, $number));
    }

    if (preg_match('/\b(20\d{2})-(0[1-9]|1[0-2])\b/', $text, $match)) {
        return app_wa_period_for_month($match[1] . '-' . $match[2]);
    }
    if (preg_match('/\btahun\s+(20\d{2})\b/', $text, $match)) {
        return app_wa_period_for_year((int) $match[1]);
    }
    if (preg_match('/^\s*(20\d{2})\s*$/', $text, $match)) {
        return app_wa_period_for_year((int) $match[1]);
    }
    return null;
}

/**
 * Total uang masuk/keluar pada rentang tanggal, opsional untuk satu kategori.
 *
 * @return array{income:int, expense:int, count:int}
 */
function app_wa_totals(PDO $pdo, string $start, string $end, ?string $category = null): array
{
    $sql = 'SELECT kind, COUNT(*) AS total_rows, COALESCE(SUM(amount), 0) AS total_amount
        FROM transactions WHERE transaction_date >= :start AND transaction_date < :end';
    $params = [':start' => $start, ':end' => $end];
    if ($category !== null && $category !== '') {
        $sql .= ' AND LOWER(category) = LOWER(:category)';
        $params[':category'] = $category;
    }
    $sql .= ' GROUP BY kind';
    $query = $pdo->prepare($sql);
    $query->execute($params);
    $totals = ['income' => 0, 'expense' => 0, 'count' => 0, 'income_count' => 0, 'expense_count' => 0];
    foreach ($query->fetchAll() as $row) {
        $kind = (string) $row['kind'];
        if ($kind === 'income' || $kind === 'expense') {
            $totals[$kind] = (int) $row['total_amount'];
            $totals[$kind . '_count'] = (int) $row['total_rows'];
        }
        $totals['count'] += (int) $row['total_rows'];
    }
    return $totals;
}

/** @return array<int, array{category:string, total:int}> */
function app_wa_top_categories(PDO $pdo, string $start, string $end, int $limit = 3): array
{
    $query = $pdo->prepare("SELECT category, COALESCE(SUM(amount), 0) AS total
        FROM transactions
        WHERE kind = 'expense' AND transaction_date >= :start AND transaction_date < :end
        GROUP BY category ORDER BY total DESC LIMIT " . max(1, min(10, $limit)));
    $query->execute([':start' => $start, ':end' => $end]);
    $rows = [];
    foreach ($query->fetchAll() as $row) {
        $rows[] = ['category' => (string) $row['category'], 'total' => (int) $row['total']];
    }
    return $rows;
}

function app_wa_target(PDO $pdo, string $monthKey): int
{
    $query = $pdo->prepare('SELECT amount FROM monthly_targets WHERE month_key = :month LIMIT 1');
    $query->execute([':month' => $monthKey]);
    $value = $query->fetchColumn();
    return $value === false ? 0 : (int) $value;
}

/** Semua kategori yang pernah dipakai, untuk mengenali "kategori tagihan" di chat. */
function app_wa_categories(PDO $pdo): array
{
    $query = $pdo->query('SELECT DISTINCT category FROM transactions ORDER BY category ASC LIMIT 300');
    return array_values(array_filter(array_map(static fn ($row) => trim((string) $row['category']), $query->fetchAll())));
}

function app_wa_match_category(string $text, array $categories): ?string
{
    $normalized = ' ' . preg_replace('/\s+/', ' ', strtolower($text)) . ' ';
    $best = null;
    foreach ($categories as $category) {
        $needle = strtolower(trim($category));
        if ($needle === '') {
            continue;
        }
        if (preg_match('/' . preg_quote($needle, '/') . '/u', $normalized)) {
            if ($best === null || strlen($category) > strlen($best)) {
                $best = $category;
            }
        }
    }
    return $best;
}

/* ------------------------------------------------------------------ *
 * Daftar nomor yang boleh memakai bot
 * ------------------------------------------------------------------ */

function app_wa_contacts(bool $onlyActive = false): array
{
    $sql = 'SELECT id, phone, name, access, active, user_id, last_message_at, created_at FROM wa_contacts'
        . ($onlyActive ? ' WHERE active = 1' : '')
        . " ORDER BY CASE WHEN access = 'admin' THEN 0 ELSE 1 END, name ASC, id ASC";
    return app_db()->query($sql)->fetchAll();
}

function app_wa_contact_by_phone(string $phone): ?array
{
    $phone = trim($phone);
    if ($phone === '') {
        return null;
    }
    $query = app_db()->prepare('SELECT id, phone, name, access, active, user_id, last_message_at, created_at FROM wa_contacts WHERE phone = :phone LIMIT 1');
    $query->execute([':phone' => $phone]);
    $contact = $query->fetch();
    return $contact ?: null;
}

/**
 * Menambah atau memperbarui satu nomor.
 *
 * @return array{ok:bool, message:string, id?:int}
 */
function app_wa_save_contact(array $input): array
{
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $phone = app_normalize_whatsapp_target((string) ($input['phone'] ?? ''));
    $name = trim((string) ($input['name'] ?? ''));
    $access = strtolower(trim((string) ($input['access'] ?? 'member')));
    $active = (int) ($input['active'] ?? 1) === 0 ? 0 : 1;
    if ($phone === null) {
        return ['ok' => false, 'message' => 'Nomor WhatsApp tidak valid. Contoh: 08123456789 atau 628123456789.'];
    }
    if (strlen($name) > 80) {
        return ['ok' => false, 'message' => 'Nama maksimal 80 karakter.'];
    }
    if (!in_array($access, ['admin', 'member'], true)) {
        return ['ok' => false, 'message' => 'Hak akses harus admin atau member.'];
    }
    $pdo = app_db();
    $existing = null;
    if ($id && $id > 0) {
        $query = $pdo->prepare('SELECT id FROM wa_contacts WHERE id = :id');
        $query->execute([':id' => $id]);
        $existing = $query->fetchColumn();
        if ($existing === false) {
            return ['ok' => false, 'message' => 'Nomor tersebut sudah tidak ada.'];
        }
    } else {
        $duplicate = app_wa_contact_by_phone($phone);
        if ($duplicate) {
            $existing = (int) $duplicate['id'];
        }
    }

    // Nomor tidak boleh dipakai dua baris sekaligus.
    $taken = app_wa_contact_by_phone($phone);
    if ($taken && (int) $taken['id'] !== (int) $existing) {
        return ['ok' => false, 'message' => 'Nomor ' . $phone . ' sudah terdaftar atas nama ' . (($taken['name'] ?? '') !== '' ? $taken['name'] : 'nomor lain') . '.'];
    }

    if ($existing) {
        $update = $pdo->prepare('UPDATE wa_contacts SET phone = :phone, name = :name, access = :access, active = :active WHERE id = :id');
        $update->execute([
            ':phone' => $phone, ':name' => $name, ':access' => $access,
            ':active' => $active, ':id' => (int) $existing,
        ]);
        return ['ok' => true, 'message' => 'Nomor bot diperbarui.', 'id' => (int) $existing];
    }

    $insert = $pdo->prepare('INSERT INTO wa_contacts (phone, name, access, active, user_id, last_message_at, created_at) VALUES (:phone, :name, :access, :active, NULL, NULL, :created)');
    $insert->execute([
        ':phone' => $phone, ':name' => $name, ':access' => $access,
        ':active' => $active, ':created' => date('Y-m-d H:i:s'),
    ]);
    return ['ok' => true, 'message' => 'Nomor ' . $phone . ' sekarang boleh memakai bot WhatsApp.', 'id' => (int) $pdo->lastInsertId()];
}

function app_wa_delete_contact(int $id): bool
{
    $delete = app_db()->prepare('DELETE FROM wa_contacts WHERE id = :id');
    $delete->execute([':id' => $id]);
    return $delete->rowCount() > 0;
}

function app_wa_toggle_contact(int $id): ?bool
{
    $pdo = app_db();
    $query = $pdo->prepare('SELECT active FROM wa_contacts WHERE id = :id');
    $query->execute([':id' => $id]);
    $active = $query->fetchColumn();
    if ($active === false) {
        return null;
    }
    $next = (int) $active === 1 ? 0 : 1;
    $update = $pdo->prepare('UPDATE wa_contacts SET active = :active WHERE id = :id');
    $update->execute([':active' => $next, ':id' => $id]);
    return $next === 1;
}

function app_wa_touch_contact(int $id): void
{
    $update = app_db()->prepare('UPDATE wa_contacts SET last_message_at = :at WHERE id = :id');
    $update->execute([':at' => date('Y-m-d H:i:s'), ':id' => $id]);
}

/* ------------------------------------------------------------------ *
 * Alamat webhook & pengaturan laporan bulanan
 * ------------------------------------------------------------------ */

function app_public_base_url(): string
{
    $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if ($host === '') {
        $host = 'localhost';
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/api.php'));
    $directory = rtrim((string) dirname($script), '/');
    if ($directory === '.' || $directory === '/') {
        $directory = '';
    }
    return ($https ? 'https' : 'http') . '://' . $host . $directory;
}

function app_wa_webhook_secret(): string
{
    $secret = (string) app_setting('wa_webhook_secret', '');
    if (strlen($secret) < 32) {
        $secret = bin2hex(random_bytes(20));
        app_set_setting('wa_webhook_secret', $secret);
    }
    return $secret;
}

function app_wa_reset_webhook_secret(): string
{
    $secret = bin2hex(random_bytes(20));
    app_set_setting('wa_webhook_secret', $secret);
    return $secret;
}

function app_wa_webhook_url(): string
{
    return app_public_base_url() . '/whatsapp.php?key=' . rawurlencode(app_wa_webhook_secret());
}

/** @return array{enabled:bool, day:string, time:string} */
function app_wa_monthly_settings(): array
{
    $day = (string) app_setting('wa_monthly_day', 'last');
    if ($day !== 'last' && (!ctype_digit($day) || (int) $day < 1 || (int) $day > 28)) {
        $day = 'last';
    }
    $time = (string) app_setting('wa_monthly_time', '20:00');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        $time = '20:00';
    }
    return [
        'enabled' => app_setting('wa_monthly_enabled', '0') === '1',
        'day' => $day,
        'time' => $time,
    ];
}

/** @return array{ok:bool, message:string} */
function app_wa_save_monthly_settings(bool $enabled, string $day, string $time): array
{
    $day = $day === 'last' ? 'last' : trim($day);
    if ($day !== 'last' && (!ctype_digit($day) || (int) $day < 1 || (int) $day > 28)) {
        return ['ok' => false, 'message' => 'Tanggal kirim harus “hari terakhir” atau angka 1–28.'];
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($time))) {
        return ['ok' => false, 'message' => 'Jam kirim harus berformat HH:MM, misalnya 20:00.'];
    }
    app_set_setting('wa_monthly_enabled', $enabled ? '1' : '0');
    app_set_setting('wa_monthly_day', $day);
    app_set_setting('wa_monthly_time', trim($time));
    return ['ok' => true, 'message' => $enabled ? 'Laporan bulanan otomatis diaktifkan.' : 'Laporan bulanan otomatis dimatikan.'];
}

/**
 * Menentukan laporan mana yang jatuh tempo sekarang.
 *
 * - day = last  → rekap bulan berjalan pada hari terakhir bulan itu
 *   (plus susulan tanggal 1–3 bila cron sempat terlewat).
 * - day = 1..28 → rekap bulan lalu pada tanggal tersebut.
 */
function app_wa_monthly_due_period(?DateTimeImmutable $now = null): ?array
{
    $settings = app_wa_monthly_settings();
    if (!$settings['enabled']) {
        return null;
    }
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    [$hour, $minute] = array_map('intval', explode(':', $settings['time']));

    if ($settings['day'] === 'last') {
        $lastDay = $now->modify('last day of this month')->setTime($hour, $minute);
        if ($now >= $lastDay) {
            return app_wa_period_for_month($now->format('Y-m'));
        }
        // Cron mungkin tidak sempat berjalan di hari terakhir; kirim susulan awal bulan.
        if ((int) $now->format('j') <= 3) {
            return app_wa_period_for_month($now->modify('first day of last month')->format('Y-m'));
        }
        return null;
    }

    $due = $now->setDate((int) $now->format('Y'), (int) $now->format('n'), (int) $settings['day'])->setTime($hour, $minute);
    if ($now < $due) {
        return null;
    }
    return app_wa_period_for_month($now->modify('first day of last month')->format('Y-m'));
}

/* ------------------------------------------------------------------ *
 * Penyusun pesan laporan
 * ------------------------------------------------------------------ */

function app_wa_target_line(PDO $pdo, string $monthKey, int $expense, bool $currentMonth = true): string
{
    $target = app_wa_target($pdo, $monthKey);
    if ($target <= 0) {
        return '🎯 Target pengeluaran: belum diatur untuk ' . ($currentMonth ? 'bulan ini' : 'bulan tersebut') . ".\n"
            . 'Admin bisa mengaturnya di menu Keuangan → target bulanan.';
    }
    $percent = (int) round($expense / $target * 100);
    if ($expense > $target) {
        return '🎯 Target pengeluaran: ' . app_wa_rupiah($target) . "\n"
            . '⚠️ Melebihi target — minus ' . app_wa_rupiah($expense - $target) . ' (' . $percent . '% dari target).';
    }
    if ($expense === $target) {
        return '🎯 Target pengeluaran: ' . app_wa_rupiah($target) . "\n"
            . '✅ Pas di target — pengeluaran sama persis dengan target.';
    }
    return '🎯 Target pengeluaran: ' . app_wa_rupiah($target) . "\n"
        . '✅ Masih sesuai target — sisa ' . app_wa_rupiah($target - $expense) . ' (' . $percent . '% dari target).';
}

function app_wa_category_lines(PDO $pdo, string $start, string $end, int $expense, int $limit = 3): string
{
    $categories = app_wa_top_categories($pdo, $start, $end, $limit);
    if ($categories === []) {
        return '';
    }
    $lines = ["\nPengeluaran terbesar:"];
    $index = 1;
    foreach ($categories as $row) {
        $share = $expense > 0 ? ' (' . (int) round($row['total'] / $expense * 100) . '%)' : '';
        $lines[] = $index . '. ' . $row['category'] . ' — ' . app_wa_rupiah($row['total']) . $share;
        $index++;
    }
    return implode("\n", $lines) . "\n";
}

/**
 * Laporan satu periode (bulan/tahun), bisa difilter kategori.
 * $full false = tampilan anggota: hanya pengeluaran dan target.
 */
function app_wa_report_text(PDO $pdo, array $period, ?string $category, bool $full, string $heading = ''): string
{
    $totals = app_wa_totals($pdo, $period['start'], $period['end'], $category);
    $base = $heading !== '' ? $heading : '📊 Laporan';
    if ($category !== null && $category !== '') {
        $title = $base . ' kategori ' . $category . "\n" . $period['label'];
    } else {
        $title = $base . ' ' . $period['label'];
    }
    $lines = [$title, ''];
    $lines[] = 'Jumlah catatan: ' . ($full ? $totals['count'] : $totals['expense_count']);

    $isCategory = $category !== null && $category !== '';
    if ($full) {
        $lines[] = 'Uang masuk: ' . app_wa_rupiah($totals['income']);
        $lines[] = 'Uang keluar: ' . app_wa_rupiah($totals['expense']);
        if (!$isCategory) {
            if ($period['kind'] === 'month') {
                $isCurrentMonth = $period['key'] === (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->format('Y-m');
                $lines[] = ($isCurrentMonth ? 'Sisa bulan ini: ' : 'Sisa bulan tersebut: ') . app_wa_rupiah($totals['income'] - $totals['expense']);
            } else {
                $lines[] = 'Selisih: ' . app_wa_rupiah($totals['income'] - $totals['expense']);
            }
        }
    } else {
        $lines[] = ($isCategory ? 'Total pengeluaran kategori ini: ' : 'Total pengeluaran: ') . app_wa_rupiah($totals['expense']);
    }

    if (!$isCategory) {
        if ($period['kind'] === 'month') {
            $isCurrentMonth = $period['key'] === (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->format('Y-m');
            $lines[] = '';
            $lines[] = app_wa_target_line($pdo, $period['key'], $totals['expense'], $isCurrentMonth);
        }
        $lines[] = app_wa_category_lines($pdo, $period['start'], $period['end'], $totals['expense']);
    } else {
        $allExpense = app_wa_totals($pdo, $period['start'], $period['end'])['expense'];
        $share = $allExpense > 0 ? (int) round($totals['expense'] / $allExpense * 100) : 0;
        $lines[] = 'Porsi dari total pengeluaran: ' . $share . '%';
    }

    if ($period['kind'] === 'month') {
        $previous = DateTimeImmutable::createFromFormat('!Y-m-d', $period['start'], new DateTimeZone(date_default_timezone_get()));
        if ($previous) {
            $previousStart = $previous->modify('-1 month')->format('Y-m-01');
            $previousEnd = $period['start'];
            $previousTotals = app_wa_totals($pdo, $previousStart, $previousEnd, $category);
            if ($previousTotals['expense'] > 0) {
                $difference = $totals['expense'] - $previousTotals['expense'];
                $percent = (int) round(abs($difference) / $previousTotals['expense'] * 100);
                $lines[] = 'Dibanding bulan lalu: '
                    . ($difference === 0 ? 'sama besar' : ($difference > 0 ? 'naik ' : 'turun ') . app_wa_rupiah(abs($difference)) . ' (' . $percent . '%)');
            }
        }
    }

    return rtrim(implode("\n", $lines));
}

function app_wa_target_text(PDO $pdo, ?string $monthKey, bool $full): string
{
    $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    $period = $monthKey !== null ? app_wa_period_for_month($monthKey) : app_wa_period_for_month($now->format('Y-m'));
    $totals = app_wa_totals($pdo, $period['start'], $period['end']);
    $target = app_wa_target($pdo, $period['key']);
    $lines = ['🎯 Target ' . $period['label'], ''];
    if ($target <= 0) {
        $lines[] = 'Target pengeluaran ' . $period['label'] . ' belum diatur.';
        $lines[] = 'Admin bisa mengaturnya di menu Keuangan → target bulanan.';
        $lines[] = 'Pengeluaran tercatat sejauh ini: ' . app_wa_rupiah($totals['expense']) . '.';
        return implode("\n", $lines);
    }
    $percent = (int) round($totals['expense'] / $target * 100);
    $lines[] = 'Target: ' . app_wa_rupiah($target);
    $lines[] = 'Terpakai: ' . app_wa_rupiah($totals['expense']) . ' (' . $percent . '%)';
    $lines[] = 'Sisa target: ' . ($totals['expense'] > $target ? '-' . app_wa_rupiah($totals['expense'] - $target) : app_wa_rupiah($target - $totals['expense']));
    $lines[] = '';
    $lines[] = $totals['expense'] > $target
        ? '⚠️ Status: MELEBIHI TARGET (minus ' . app_wa_rupiah($totals['expense'] - $target) . ').'
        : '✅ Status: masih sesuai target.';
    if ($full) {
        $lines[] = 'Uang masuk: ' . app_wa_rupiah($totals['income']);
        $lines[] = 'Sisa uang bulan ini: ' . app_wa_rupiah($totals['income'] - $totals['expense']);
    }
    $lines[] = '';
    $categoryLines = app_wa_category_lines($pdo, $period['start'], $period['end'], $totals['expense'], 5);
    $lines[] = $categoryLines !== '' ? trim($categoryLines) : 'Belum ada pengeluaran tercatat pada periode ini.';
    return implode("\n", $lines);
}

function app_wa_balance_text(PDO $pdo): string
{
    $totals = app_wa_totals($pdo, '1970-01-01', '2999-12-31');
    $lines = [
        '💰 Saldo keseluruhan',
        '',
        'Uang masuk: ' . app_wa_rupiah($totals['income']),
        'Uang keluar: ' . app_wa_rupiah($totals['expense']),
        'Saldo: ' . app_wa_rupiah($totals['income'] - $totals['expense']),
        '',
        'Jumlah catatan: ' . $totals['count'],
    ];
    return implode("\n", $lines);
}

function app_wa_recent_text(PDO $pdo, bool $full, int $limit = 5): string
{
    $limit = max(1, min(10, $limit));
    $sql = "SELECT kind, title, category, amount, transaction_date FROM transactions"
        . ($full ? '' : " WHERE kind = 'expense'")
        . ' ORDER BY transaction_date DESC, id DESC LIMIT :limit';
    $query = $pdo->prepare($sql);
    $query->bindValue(':limit', $limit, PDO::PARAM_INT);
    $query->execute();
    $rows = $query->fetchAll();
    if ($rows === []) {
        return $full ? 'Belum ada transaksi yang tercatat.' : 'Belum ada pengeluaran yang tercatat.';
    }
    $lines = [$full ? '🧾 ' . $limit . ' transaksi terakhir' : '🧾 ' . $limit . ' pengeluaran terakhir', ''];
    $index = 1;
    foreach ($rows as $row) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $row['transaction_date']);
        $dateLabel = $date ? $date->format('d/m/Y') : (string) $row['transaction_date'];
        $kindLabel = (string) $row['kind'] === 'income' ? 'masuk' : 'keluar';
        $lines[] = $index . '. ' . $dateLabel . ' · ' . $row['title'] . ' (' . $row['category'] . ')'
            . ' — ' . $kindLabel . ' ' . app_wa_rupiah((int) $row['amount']);
        $index++;
    }
    return implode("\n", $lines);
}

function app_wa_top_expense_text(PDO $pdo, array $period, ?string $category, int $limit = 5): string
{
    $limit = max(1, min(10, $limit));
    $params = [':start' => $period['start'], ':end' => $period['end'], ':limit' => $limit];
    $sql = "SELECT title, category, amount, transaction_date FROM transactions
        WHERE kind = 'expense' AND transaction_date >= :start AND transaction_date < :end";
    if ($category !== null && $category !== '') {
        $sql .= ' AND LOWER(category) = LOWER(:category)';
        $params[':category'] = $category;
    }
    $sql .= ' ORDER BY amount DESC, transaction_date DESC LIMIT :limit';
    $query = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $query->bindValue($key, $value, $key === ':limit' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $query->execute();
    $rows = $query->fetchAll();
    $title = '🏆 ' . $limit . ' pengeluaran terbesar ' . $period['label'] . ($category !== null ? ' · ' . $category : '');
    if ($rows === []) {
        return $title . "\n\nBelum ada pengeluaran tercatat pada periode ini.";
    }
    $lines = [$title, ''];
    $index = 1;
    foreach ($rows as $row) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $row['transaction_date']);
        $lines[] = $index . '. ' . ($date ? $date->format('d/m') : (string) $row['transaction_date'])
            . ' · ' . $row['title'] . ' — ' . app_wa_rupiah((int) $row['amount']);
        $index++;
    }
    return implode("\n", $lines);
}

function app_wa_help_text(array $contact): string
{
    $lines = [
        '🤖 Bot rumah siap membantu.',
        '',
        'Contoh pertanyaan:',
        '• laporan — rekap bulan ini',
        '• laporan bulan lalu',
        '• laporan oktober 2025',
        '• laporan tahun ini',
        '• pengeluaran kategori tagihan bulan ini',
        '• target bulan ini',
        '• 5 pengeluaran terbesar bulan ini',
        '• transaksi terakhir',
    ];
    if (($contact['access'] ?? 'member') === 'admin') {
        $lines[] = '• saldo — saldo keseluruhan';
    } else {
        $lines[] = '';
        $lines[] = 'Akses kamu: anggota. Bot menampilkan total pengeluaran dan target. Untuk saldo atau uang masuk, hubungi admin rumah.';
    }
    $lines[] = '';
    $lines[] = 'Ketik pertanyaanmu langsung, tanpa tanda kutip.';
    return implode("\n", $lines);
}

function app_wa_unknown_text(string $phone): string
{
    return "Halo! Nomor ini belum terdaftar di bot rumah.\n\n"
        . 'Nomor kamu: ' . $phone . "\n\n"
        . "Minta admin rumah menambahkan nomor tersebut di:\n"
        . "Website → Pengaturan → Bot WhatsApp → Nomor yang boleh bertanya.\n\n"
        . "Setelah ditambahkan, kamu bisa bertanya seperti:\n"
        . "• laporan\n• laporan bulan lalu\n• target bulan ini";
}

/** Maksimal 10 balasan untuk nomor tak dikenal per jam, supaya kuota tidak terbuang. */
function app_wa_unknown_reply_allowed(): bool
{
    $hour = date('Y-m-d H');
    if ((string) app_setting('wa_unknown_hour', '') !== $hour) {
        app_set_setting('wa_unknown_hour', $hour);
        app_set_setting('wa_unknown_count', '1');
        return true;
    }
    $count = (int) app_setting('wa_unknown_count', '0');
    if ($count >= 10) {
        return false;
    }
    app_set_setting('wa_unknown_count', (string) ($count + 1));
    return true;
}

/** Menolak pemrosesan ganda saat Fonnte mengulang kiriman webhook yang sama. */
function app_wa_is_duplicate(array $payload): bool
{
    $hash = hash('sha256', json_encode([
        (string) ($payload['sender'] ?? ''),
        (string) ($payload['member'] ?? ''),
        (string) ($payload['message'] ?? ''),
        (string) ($payload['timestamp'] ?? ''),
        (string) ($payload['inboxid'] ?? ''),
    ]));
    $lastHash = (string) app_setting('wa_last_payload_hash', '');
    $lastAt = (int) app_setting('wa_last_payload_at', '0');
    if ($lastHash === $hash && (time() - $lastAt) < 120) {
        return true;
    }
    app_set_setting('wa_last_payload_hash', $hash);
    app_set_setting('wa_last_payload_at', (string) time());
    return false;
}

/* ------------------------------------------------------------------ *
 * Penjawab pertanyaan
 * ------------------------------------------------------------------ */

function app_wa_reply(PDO $pdo, array $contact, string $message, bool $explainUnknown = true): string
{
    $full = strtolower((string) ($contact['access'] ?? 'member')) === 'admin';
    $text = strtolower(trim($message));
    $text = (string) preg_replace('/[^\p{L}\p{N}\s\-:\.@\/]/u', ' ', $text);
    $text = trim((string) preg_replace('/\s+/', ' ', $text));

    if ($text === '' || preg_match('/^(halo|hai|hi|hello|pagi|siang|sore|malam|assalamualaikum|salam|menu|bantuan|help|panduan|contoh|\?)$/u', $text)) {
        return app_wa_help_text($contact);
    }
    if (preg_match('/\b(bantuan|help|panduan|menu|contoh|perintah|command)\b/u', $text)) {
        return app_wa_help_text($contact);
    }

    $period = app_wa_parse_period($text);
    $category = app_wa_match_category($text, app_wa_categories($pdo));
    $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    $currentPeriod = app_wa_period_for_month($now->format('Y-m'));

    if (preg_match('/\bsaldo\b/u', $text)) {
        if (!$full) {
            return app_wa_member_denied('saldo keseluruhan');
        }
        return app_wa_balance_text($pdo);
    }

    if (preg_match('/\b(target|budget|anggaran)\b/u', $text)) {
        $targetMonth = ($period !== null && $period['kind'] === 'month') ? $period['key'] : null;
        return app_wa_target_text($pdo, $targetMonth, $full);
    }

    // Jumlah baris pada "5 terbesar" / "3 terakhir" mengikuti angka yang ditulis.
    $limit = 5;
    if (preg_match('/\b(\d{1,2})\b/u', $text, $countMatch)) {
        $limit = max(1, min(10, (int) $countMatch[1]));
    }

    if (preg_match('/\b(terbesar|tertinggi|paling besar|top)\b/u', $text)) {
        return app_wa_top_expense_text($pdo, $period ?? $currentPeriod, $category, $limit);
    }

    if (preg_match('/\b(terakhir|terbaru|terakhir kali|recent)\b/u', $text)) {
        return app_wa_recent_text($pdo, $full, $limit);
    }

    $isReport = (bool) preg_match('/\b(laporan|rekap|report|ringkasan|rincian|pengeluaran|belanja|keluar|pemasukan|masuk|income|gaji|kategori|uang|minus|boros|hemat)\b/u', $text);
    if ($isReport || $period !== null) {
        if (!$full && preg_match('/\b(pemasukan|masuk|income|gaji)\b/u', $text) && !preg_match('/\b(pengeluaran|belanja|keluar)\b/u', $text)) {
            return app_wa_member_denied('uang masuk');
        }
        $reportPeriod = $period ?? $currentPeriod;
        return app_wa_report_text($pdo, $reportPeriod, $category, $full, '📊 Laporan');
    }

    // Pengguna menulis kategori saja, mis. "tagihan" atau "tagihan bulan lalu".
    if ($category !== null) {
        return app_wa_report_text($pdo, $period ?? $currentPeriod, $category, $full, '📊 Laporan');
    }

    if ($period !== null) {
        return app_wa_report_text($pdo, $period, null, $full, '📊 Laporan');
    }

    if (!$explainUnknown) {
        // Di grup, pesan obrolan biasa tidak perlu dibalas supaya tidak berisik.
        return '';
    }
    return "Maaf, pertanyaannya belum saya pahami.\n\n"
        . app_wa_help_text($contact);
}

function app_wa_member_denied(string $what): string
{
    return '🔒 Maaf, ' . $what . " hanya bisa dilihat oleh nomor dengan akses admin.\n\n"
        . 'Hubungi admin rumah untuk mengubah akses nomor kamu di menu Pengaturan → Bot WhatsApp.';
}

/* ------------------------------------------------------------------ *
 * Laporan bulanan otomatis
 * ------------------------------------------------------------------ */

/**
 * Daftar tujuan laporan bulanan beserta tingkat aksesnya.
 * Nomor utama (target notifikasi) menerima laporan lengkap; nomor anggota
 * hanya menerima pengeluaran dan status target.
 *
 * @return array<int, array{target:string, full:bool}>
 */
function app_wa_report_recipients(): array
{
    $targets = [];
    $mainTarget = trim(app_whatsapp_credentials()['target']);
    if ($mainTarget !== '') {
        $targets[$mainTarget] = true;
    }
    foreach (app_wa_contacts(true) as $contact) {
        $phone = trim((string) $contact['phone']);
        if ($phone === '') {
            continue;
        }
        if (!isset($targets[$phone])) {
            $targets[$phone] = strtolower((string) $contact['access']) === 'admin';
        }
    }
    $recipients = [];
    foreach ($targets as $target => $full) {
        $recipients[] = ['target' => (string) $target, 'full' => (bool) $full];
    }
    return $recipients;
}

/** @return array{sent:int, failed:int, recipients:int, message:string} */
function app_wa_send_monthly_report(string $monthKey, bool $force = false): array
{
    $pdo = app_db();
    $period = app_wa_period_for_month($monthKey);
    $fullText = app_wa_report_text($pdo, $period, null, true, '📅 Laporan bulanan')
        . "\n\nDikirim otomatis dari Dashboard kebutuhan rumah.";
    $limitedText = app_wa_report_text($pdo, $period, null, false, '📅 Laporan bulanan')
        . "\n\nDikirim otomatis dari Dashboard kebutuhan rumah.";
    $recipients = app_wa_report_recipients();
    if ($recipients === []) {
        return ['sent' => 0, 'failed' => 0, 'recipients' => 0, 'message' => 'Belum ada nomor tujuan. Hubungkan Fonnte atau tambahkan nomor bot lebih dulu.'];
    }
    $sent = 0;
    $failed = 0;
    $failures = [];
    foreach ($recipients as $recipient) {
        $result = app_send_whatsapp($recipient['full'] ? $fullText : $limitedText, $recipient['target']);
        if ($result['ok'] ?? false) {
            $sent++;
        } else {
            $failed++;
            $failures[] = $recipient['target'] . ': ' . (string) ($result['description'] ?? 'gagal');
        }
    }
    // Kiriman manual tidak menandai jadwal otomatis, supaya rekap akhir bulan
    // tetap terkirim walau admin pernah mencoba mengirim lebih awal.
    if ($sent > 0 && !$force) {
        app_set_setting('wa_monthly_last_key', $period['key']);
        app_set_setting('wa_monthly_last_at', date('Y-m-d H:i:s'));
    }
    $message = 'Laporan ' . $period['label'] . ' terkirim ke ' . $sent . ' dari ' . count($recipients) . ' tujuan.';
    if ($failed > 0) {
        $message .= ' ' . $failed . ' gagal: ' . implode('; ', array_slice($failures, 0, 2));
    }
    return ['sent' => $sent, 'failed' => $failed, 'recipients' => count($recipients), 'message' => $message];
}
