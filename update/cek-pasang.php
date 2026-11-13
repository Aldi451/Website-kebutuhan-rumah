<?php
declare(strict_types=1);

/**
 * Pemeriksa pemasangan paket update "Import cepat dari clipboard".
 *
 * Cara pakai:
 *   1. Unggah file ini ke document root (htdocs) bersama file aplikasi.
 *   2. Buka https://domainmu/cek-pasang.php di browser.
 *   3. Ikuti saran yang muncul, lalu HAPUS file ini lagi.
 *
 * File ini hanya membaca nama & isi file (tidak menulis apa pun), kecuali bila
 * kamu sendiri membuka ?hapus=1 untuk menghapus file pemeriksa ini.
 */

if (isset($_GET['hapus'])) {
    $deleted = @unlink(__FILE__);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><title>Hapus pemeriksa</title>'
        . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:60px auto;padding:0 20px;line-height:1.7;color:#243a43">'
        . '<h1 style="font-size:20px">' . ($deleted ? 'File pemeriksa sudah dihapus.' : 'Gagal menghapus otomatis.') . '</h1>'
        . '<p>' . ($deleted
            ? 'Silakan tutup halaman ini dan buka website seperti biasa.'
            : 'Hapus manual file <code>cek-pasang.php</code> lewat File Manager hosting.') . '</p>'
        . '</body></html>';
    exit;
}

$root = __DIR__;

/** @return array{ok: bool, isi: string, ukuran: int} */
function periksa_file(string $path, int $batas = 400000): array
{
    if (!is_file($path)) {
        return ['ok' => false, 'isi' => '', 'ukuran' => 0];
    }
    $ukuran = (int) @filesize($path);
    $isi = (string) @file_get_contents($path, false, null, 0, $batas);
    return ['ok' => true, 'isi' => $isi, 'ukuran' => $ukuran];
}

/** Membaca nilai ?v= dari index.php untuk berkas aset tertentu. */
function versi_dirujuk(string $indexIsi, string $berkas): string
{
    if (preg_match('/' . preg_quote($berkas, '/') . '\?v=([0-9a-zA-Z._-]+)/', $indexIsi, $cocok) === 1) {
        return $cocok[1];
    }
    return '';
}

$hasil = [];
$tambah = static function (string $level, string $judul, string $detail, string $saran = '') use (&$hasil): void {
    $hasil[] = ['level' => $level, 'judul' => $judul, 'detail' => $detail, 'saran' => $saran];
};

/* ------------------------------------------------------------------ *
 * 1. index.php — penyebab paling sering: file app.js tertimpa ke sini
 * ------------------------------------------------------------------ */
$index = periksa_file($root . '/index.php');
$indexIsi = $index['isi'];
$indexMulaiPhp = str_contains($indexIsi, '<?php');
$indexTampakJs = (bool) preg_match('/^\s*(\(\(\)\s*=>|\(\s*function\s*\(|"use strict"|\'use strict\')/', $indexIsi);
$indexTampakCss = (bool) preg_match('/^\s*(\/\*|:root\s*\{|@media|html\s*\{)/', $indexIsi);

if (!$index['ok']) {
    $tambah('error', 'index.php tidak ditemukan',
        'Document root tidak memiliki index.php, jadi website tidak bisa dibuka.',
        'Unggah update/website/index.php ke htdocs/index.php (atau ekstrak website-update.zip di htdocs).');
} elseif (!$indexMulaiPhp && $indexTampakJs) {
    $tambah('error', 'index.php berisi kode JavaScript (app.js tertimpa ke index.php)',
        'Itulah sebabnya browser menampilkan tulisan "(() => { \'use strict\'; ..." seperti teks biasa, bukan halaman login.',
        'Unggah ulang update/website/index.php ke htdocs/index.php. Lalu pastikan app.js diunggah ke htdocs/assets/app.js — bukan ke index.php.');
} elseif (!$indexMulaiPhp && $indexTampakCss) {
    $tambah('error', 'index.php berisi kode CSS (style.css tertimpa ke index.php)',
        'Browser akan menampilkan kode CSS sebagai teks, bukan halaman website.',
        'Unggah ulang update/website/index.php ke htdocs/index.php, dan letakkan style.css di htdocs/assets/style.css.');
} elseif (!$indexMulaiPhp) {
    $tambah('error', 'index.php tidak berisi kode PHP',
        'File index.php tidak diawali <?php sehingga server hanya mengirim isinya sebagai teks.',
        'Unggah ulang update/website/index.php ke htdocs/index.php.');
} else {
    $versiApp = versi_dirujuk($indexIsi, 'app.js');
    $punyaClipboardRef = str_contains($indexIsi, 'clipboard-import.js');
    if (!$punyaClipboardRef) {
        $tambah('warning', 'index.php masih versi lama',
            'index.php belum memuat assets/clipboard-import.js, jadi panel import clipboard belum muncul.',
            'Unggah update/website/index.php ke htdocs/index.php.');
    } else {
        $tambah('ok', 'index.php versi baru sudah terpasang',
            'index.php memuat clipboard-import.js' . ($versiApp !== '' ? ' dan app.js?v=' . $versiApp : '') . '.');
    }
}

/* ------------------------------------------------------------------ *
 * 2. Folder assets dan empat berkasnya
 * ------------------------------------------------------------------ */
$assetsDir = $root . '/assets';
if (!is_dir($assetsDir)) {
    $tambah('error', 'Folder assets/ tidak ditemukan',
        'Semua berkas tampilan (app.js, style.css, clipboard-import.js, importer.js) harus berada di dalam folder assets/.',
        'Buat folder htdocs/assets, lalu unggah berkas dari update/website/assets/ ke dalamnya.');
}

$aset = [
    'app.js' => [
        'penanda' => ['IMPORT_PREVIEW_EDIT_LIMIT', 'read-import-clipboard'],
        'pesanLama' => 'app.js masih versi lama (belum punya panel import clipboard).',
    ],
    'clipboard-import.js' => [
        'penanda' => ['HouseholdClipboard'],
        'pesanLama' => 'clipboard-import.js tidak dikenali sebagai berkas fitur ini.',
    ],
    'style.css' => [
        'penanda' => ['.clipboard-import'],
        'pesanLama' => 'style.css masih versi lama (belum ada gaya panel clipboard).',
    ],
    'importer.js' => [
        'penanda' => ['HouseholdImport'],
        'pesanLama' => 'importer.js tidak dikenali.',
    ],
];

foreach ($aset as $nama => $aturan) {
    $path = $assetsDir . '/' . $nama;
    $berkas = periksa_file($path);
    if (!$berkas['ok']) {
        $tambah($nama === 'importer.js' ? 'warning' : 'error', 'assets/' . $nama . ' tidak ditemukan',
            'Browser meminta /assets/' . $nama . ' tetapi berkasnya tidak ada, sehingga tampilan atau fitur tidak jalan.',
            'Unggah update/website/assets/' . $nama . ' ke htdocs/assets/' . $nama . '.');
        continue;
    }
    $lengkap = true;
    foreach ($aturan['penanda'] as $penanda) {
        if (!str_contains($berkas['isi'], $penanda)) {
            $lengkap = false;
            break;
        }
    }
    if (!$lengkap) {
        $tambah('warning', 'assets/' . $nama . ' terdeteksi versi lama',
            $aturan['pesanLama'],
            'Unggah ulang update/website/assets/' . $nama . ' (timpa berkas lama), lalu muat ulang dengan Ctrl+F5.');
    } else {
        $tambah('ok', 'assets/' . $nama . ' sudah versi baru',
            'Ukuran ' . number_format($berkas['ukuran'] / 1024, 1, ',', '.') . ' KB dan berisi penanda fitur import clipboard.');
    }
}

/* ------------------------------------------------------------------ *
 * 3. Berkas yang salah tempat di root (kesalahan upload paling umum)
 * ------------------------------------------------------------------ */
$salahTempat = [];
foreach (['app.js', 'style.css', 'clipboard-import.js', 'importer.js', 'brand.svg'] as $nama) {
    if (is_file($root . '/' . $nama)) {
        $salahTempat[] = $nama;
    }
}
if ($salahTempat !== []) {
    $tambah('warning', 'Ada berkas aset di root, bukan di folder assets/',
        'Ditemukan: ' . implode(', ', $salahTempat) . '. Berkas ini tidak akan dipakai website.',
        'Pindahkan ke htdocs/assets/ lalu hapus salinan di root. Kalau index.php ikut tertimpa, unggah ulang update/website/index.php.');
}

if (is_file($root . '/website/index.php') || is_dir($root . '/update/website')) {
    $tambah('warning', 'Folder paket update ikut terunggah ke htdocs',
        'Folder update/ bersifat paket, bukan aplikasi. Isinya tidak boleh diakses dari web.',
        'Hapus folder update/ (atau website/) dari htdocs; cukup salin isi update/website/ ke htdocs.');
}

/* ------------------------------------------------------------------ *
 * 4. Berkas inti aplikasi
 * ------------------------------------------------------------------ */
foreach ([
    'api.php' => 'API aplikasi wajib ada agar penyimpanan data berjalan.',
    'includes/app.php' => 'Berkas inti aplikasi (skema database, sesi, helper).',
] as $nama => $keterangan) {
    if (!is_file($root . '/' . $nama)) {
        $tambah('error', $nama . ' tidak ditemukan', $keterangan, 'Unggah berkas ' . $nama . ' dari repository ke htdocs/' . $nama . '.');
    }
}

if (!is_file($root . '/config.php')) {
    $tambah('warning', 'config.php belum ada',
        'Tanpa config.php, aplikasi memakai SQLite di storage/. Pastikan folder storage/ bisa ditulis server.',
        'Salin config.example.php menjadi config.php lalu isi detail database MySQL bila memakai MySQL.');
} else {
    $tambah('ok', 'config.php tersedia', 'Detail database dibaca dari berkas ini.');
}

if (!is_dir($root . '/storage') || !is_writable($root . '/storage')) {
    $tambah('warning', 'Folder storage/ tidak ada atau tidak bisa ditulis',
        'Folder ini dibutuhkan untuk database SQLite bawaan (bila config.php tidak memakai MySQL).',
        'Buat folder storage/ dengan izin tulis (chmod 755/775), atau pakai MySQL lewat config.php.');
}

/* ------------------------------------------------------------------ *
 * 5. Lingkungan server
 * ------------------------------------------------------------------ */
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    $tambah('error', 'Versi PHP terlalu lama (' . PHP_VERSION . ')',
        'Aplikasi ini memerlukan PHP 8.0 atau lebih baru.',
        'Ubah versi PHP di panel hosting (InfinityFree: menu PHP Configuration) ke PHP 8.x.');
} else {
    $tambah('ok', 'Versi PHP ' . PHP_VERSION . ' memadai', 'Minimal PHP 8.0 diperlukan oleh aplikasi.');
}

$jumlahError = 0;
$jumlahWarning = 0;
foreach ($hasil as $baris) {
    if ($baris['level'] === 'error') {
        $jumlahError++;
    } elseif ($baris['level'] === 'warning') {
        $jumlahWarning++;
    }
}

if ($jumlahError === 0 && $jumlahWarning === 0) {
    $ringkasan = ['ok', 'Pemasangan sudah benar', 'Semua berkas fitur import clipboard ada di tempatnya. Buka Keuangan → Import Excel lalu tekan Ctrl+F5 bila tampilan masih lama.'];
} elseif ($jumlahError === 0) {
    $ringkasan = ['warning', 'Pemasangan hampir benar', 'Tidak ada masalah fatal, tetapi ada ' . $jumlahWarning . ' hal yang sebaiknya dirapikan di bawah.'];
} else {
    $ringkasan = ['error', 'Pemasangan belum lengkap', $jumlahError . ' masalah harus diperbaiki' . ($jumlahWarning > 0 ? ', plus ' . $jumlahWarning . ' peringatan' : '') . '. Ikuti saran berurutan di bawah.'];
}

$warna = [
    'ok' => ['#e8f6ef', '#2f8a6f', 'OK'],
    'warning' => ['#fff6df', '#a87528', 'PERIKSA'],
    'error' => ['#fff0eb', '#b45645', 'MASALAH'],
];
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cek pemasangan — import clipboard</title>
    <style>
        :root { color-scheme: light; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif; }
        body { margin: 0; padding: 28px 18px 60px; background: #f4f7f8; color: #243a43; }
        main { max-width: 780px; margin: 0 auto; }
        h1 { font-size: 20px; margin: 0 0 6px; }
        .sub { margin: 0 0 18px; color: #7c8b96; font-size: 13px; line-height: 1.6; }
        .ringkasan { display: flex; gap: 12px; align-items: flex-start; padding: 16px 18px; border-radius: 14px; border: 1px solid; margin-bottom: 18px; }
        .ringkasan strong { display: block; font-size: 15px; margin-bottom: 3px; }
        .ringkasan span { font-size: 13px; line-height: 1.6; }
        .item { display: grid; grid-template-columns: 78px 1fr; gap: 12px; padding: 14px 16px; margin-bottom: 10px; border: 1px solid #e8edf0; border-radius: 13px; background: #fff; }
        .badge { align-self: start; justify-self: start; padding: 4px 9px; border-radius: 999px; font-size: 10px; font-weight: 700; letter-spacing: .4px; }
        .item h2 { font-size: 13px; margin: 0 0 4px; }
        .item p { margin: 0; font-size: 12.5px; line-height: 1.6; color: #5b6b72; }
        .saran { margin-top: 7px; padding: 9px 11px; border-radius: 9px; background: #f6f9f9; color: #40565e; font-size: 12.5px; line-height: 1.6; }
        .saran b { color: #2f7f6a; }
        code { padding: 1px 5px; border-radius: 5px; background: #eef3f2; font-size: 12px; }
        .kaki { margin-top: 22px; padding: 14px 16px; border: 1px dashed #d9e3e0; border-radius: 12px; background: #fbfdfc; font-size: 12px; color: #7c8b96; line-height: 1.7; }
        .kaki a { color: #b45645; }
    </style>
</head>
<body>
<main>
    <h1>Cek pemasangan — import cepat dari clipboard</h1>
    <p class="sub">Halaman ini memeriksa apakah berkas paket update sudah berada di folder yang benar. Tidak ada data yang ditulis atau dikirim ke mana pun.</p>

    <div class="ringkasan" style="background: <?= $warna[$ringkasan[0]][0] ?>; border-color: <?= $warna[$ringkasan[0]][1] ?>33; color: <?= $warna[$ringkasan[0]][1] ?>">
        <div>
            <strong><?= htmlspecialchars($ringkasan[1], ENT_QUOTES, 'UTF-8') ?></strong>
            <span><?= htmlspecialchars($ringkasan[2], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>

    <?php foreach ($hasil as $baris): ?>
        <div class="item">
            <span class="badge" style="background: <?= $warna[$baris['level']][0] ?>; color: <?= $warna[$baris['level']][1] ?>"><?= $warna[$baris['level']][2] ?></span>
            <div>
                <h2><?= htmlspecialchars($baris['judul'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p><?= htmlspecialchars($baris['detail'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php if ($baris['saran'] !== ''): ?>
                    <div class="saran"><b>Perbaikan:</b> <?= htmlspecialchars($baris['saran'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="kaki">
        Setelah semua beres, hapus file pemeriksa ini: <a href="?hapus=1" onclick="return confirm('Hapus file cek-pasang.php dari hosting?')">hapus sekarang</a>.
        Folder yang diperiksa: <code><?= htmlspecialchars(basename($root), ENT_QUOTES, 'UTF-8') ?></code>.
        Butuh bantuan? Lihat <code>update/README.md</code> di repository.
    </div>
</main>
</body>
</html>
