/* Import cepat dari copy-paste Excel / Google Sheets (clipboard).
 *
 * Modul mandiri tanpa dependensi. Tugasnya hanya satu: mengubah teks tempelan
 * menjadi baris { number, cells } yang siap diolah app.js.
 *
 * Format yang didukung:
 *   1. Tanpa judul kolom, urutan tetap 5 kolom:
 *      Tanggal | Jenis | Kategori | Jumlah (Rp) | Keterangan
 *   2. Dengan judul kolom (baris pertama berisi nama kolom) — dikenali otomatis.
 * Pemisah yang didukung: tab (Excel/Sheets), titik koma, garis tegak, koma,
 * atau dua spasi/lebih (hasil copy dari tampilan sel).
 */
(() => {
  'use strict';

  const MAX_ROWS = 500;
  const MAX_TEXT_LENGTH = 500000;

  // Urutan kolom resmi pada panduan import clipboard.
  const COLUMNS = [
    { key: 'date', label: 'Tanggal', sample: '2026-07-30 / 30/07/2026' },
    { key: 'kind', label: 'Jenis', sample: 'Masuk / Keluar' },
    { key: 'category', label: 'Kategori', sample: 'Makanan / Payroll dll' },
    { key: 'amount', label: 'Jumlah (Rp)', sample: '50000 / 50.000' },
    { key: 'title', label: 'Keterangan', sample: 'Makan siang / Gaji' },
  ];

  // Kata yang lazim muncul sebagai judul kolom pada baris pertama tempelan.
  const HEADER_WORDS = new Set([
    'tanggal', 'tgl', 'date', 'tanggaltransaksi', 'transactiondate', 'waktu', 'datetime',
    'jenis', 'tipe', 'type', 'kind', 'transactiontype', 'jenistransaksi', 'masukkeluar',
    'kategori', 'category', 'kategoritransaksi',
    'jumlah', 'jumlahrp', 'nominal', 'amount', 'total', 'nilai',
    'keterangan', 'catatan', 'note', 'notes', 'deskripsi', 'description', 'memo',
    'nama', 'namatransaksi', 'transaksi', 'title', 'judul',
  ]);

  function normalize(value) {
    return String(value ?? '')
      .replace(/\u00a0/g, ' ')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]/g, '');
  }

  function unquote(value) {
    let text = String(value ?? '').trim();
    // Excel menandai sel berformat teks dengan apostrof di depan ('25/02/2026).
    if (text.startsWith("'")) text = text.slice(1).trim();
    if (text.length >= 2 && text.startsWith('"') && text.endsWith('"')) {
      return text.slice(1, -1).replace(/""/g, '"').trim();
    }
    return text;
  }

  // Memecah satu baris menjadi sel berdasarkan pemisah yang paling mungkin.
  function splitCells(line) {
    if (line.includes('\t')) return splitQuoted(line, '\t');
    if (line.includes(';')) return splitQuoted(line, ';');
    if (line.includes('|')) return splitQuoted(line, '|');
    if (/\s{2,}/.test(line)) return line.split(/\s{2,}/).map(unquote);
    // Koma hanya dipakai jika jelas membentuk banyak kolom,
    // supaya nominal seperti "50,000" tidak ikut terpecah.
    if ((line.match(/,/g) || []).length >= 4) return splitQuoted(line, ',');
    return [unquote(line)];
  }

  function splitQuoted(line, delimiter) {
    const cells = [];
    let field = '';
    let quoted = false;
    for (let index = 0; index < line.length; index += 1) {
      const char = line[index];
      if (quoted) {
        if (char === '"' && line[index + 1] === '"') { field += '"'; index += 1; }
        else if (char === '"') quoted = false;
        else field += char;
      } else if (char === '"' && field.trim() === '') {
        quoted = true;
        field = '';
      } else if (char === delimiter) {
        cells.push(field.trim());
        field = '';
      } else {
        field += char;
      }
    }
    cells.push(field.trim());
    return cells.map(unquote);
  }

  function looksLikeHeader(cells) {
    const matches = cells.filter((cell) => HEADER_WORDS.has(normalize(cell))).length;
    return matches >= 2;
  }

  function parse(text) {
    const source = String(text ?? '').replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n');
    if (source.length > MAX_TEXT_LENGTH) {
      throw new Error('Teks terlalu panjang. Tempel maksimal 500 baris sekaligus.');
    }
    const rows = [];
    let columns = 0;
    source.split('\n').forEach((rawLine, index) => {
      const line = rawLine.replace(/\u00a0/g, ' ').trim();
      if (!line) return;
      const cells = splitCells(line).map((cell) => String(cell ?? '').trim());
      if (!cells.some((cell) => cell !== '')) return;
      columns = Math.max(columns, cells.length);
      rows.push({ number: index + 1, cells });
    });
    if (!rows.length) throw new Error('Belum ada data yang bisa dibaca. Salin baris dari Excel lalu tempel di sini.');
    const hasHeader = looksLikeHeader(rows[0].cells);
    const dataRows = hasHeader ? rows.length - 1 : rows.length;
    if (dataRows < 1) throw new Error('Tempelan hanya berisi judul kolom. Sertakan baris datanya juga.');
    if (dataRows > MAX_ROWS) throw new Error(`Maksimal ${MAX_ROWS} baris per import. Tempel sebagian dulu, lalu ulangi untuk sisanya.`);
    return { rows, hasHeader, dataRows, columns, maxRows: MAX_ROWS };
  }

  function todayIso() {
    const now = new Date();
    const pad = (value) => String(value).padStart(2, '0');
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
  }

  // Contoh format siap tempel: 5 kolom, tanpa judul kolom, dipisah tab.
  function sampleText() {
    const date = todayIso();
    return [
      [date, 'Keluar', 'Makanan', '25000', 'Makan siang'].join('\t'),
      [date, 'Masuk', 'Payroll', '5000000', 'Gaji bulanan'].join('\t'),
    ].join('\n');
  }

  const api = { columns: COLUMNS, maxRows: MAX_ROWS, parse, sampleText, splitCells };

  if (typeof window !== 'undefined') window.HouseholdClipboard = api;
  else if (typeof module !== 'undefined' && module.exports) module.exports = api;
})();
