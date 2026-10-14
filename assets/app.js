(() => {
  'use strict';

  const boot = window.APP_BOOT || { user: null, csrf: '' };
  const appTimeZone = boot.timezone || 'Asia/Jakarta';
  const appRoot = document.getElementById('app');
  const modalRoot = document.getElementById('modal-root');
  const toastRoot = document.getElementById('toast-root');
  function readLoginTheme() {
    try { return window.localStorage.getItem('household-login-theme') === 'dark' ? 'dark' : 'light'; }
    catch { return 'light'; }
  }
  function syncBrowserThemeColor(theme) {
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.content = theme === 'dark' ? '#101923' : '#f8fafb';
  }
  const state = {
    user: boot.user,
    csrf: boot.csrf,
    loginTheme: readLoginTheme(),
    page: 'dashboard',
    dashboard: null,
    finance: null,
    financeMonth: currentMonth(),
    transactionFilter: 'all',
    reminders: null,
    reminderFilter: 'all',
    members: null,
    settings: null,
    report: null,
    reportKey: '',
    reportPeriod: 'month_to_date',
    reportCategory: '',
    reportKind: 'all',
    reportCompare: true,
    reportCustomStart: `${currentMonth()}-01`,
    reportCustomEnd: localDateValue(),
    importType: null,
    importPreview: null,
    modalReceipt: null,
    proofContext: null,
  };

  const ICONS = {
    home: '<path d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2h-4v-6H9v6H5a2 2 0 0 1-2-2z"/><path d="M9 21v-6h6v6"/>',
    wallet: '<rect x="3" y="5" width="18" height="15" rx="2"/><path d="M3 9h18M16 15h2"/><path d="M7 5V3h10v2"/>',
    bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.7a8 8 0 0 1-1.7 1l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.7-1l-1.7.7-1.4-2.4L5.3 15a8 8 0 0 1 0-2l-1.4-1.1 1.4-2.4 1.7.7a8 8 0 0 1 1.7-1L9 7.4h2.8l.3 1.8a8 8 0 0 1 1.7 1l1.7-.7 1.4 2.4-1.4 1.1a8 8 0 0 1-.1 2z"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    up: '<path d="M7 17 17 7M7 7h10v10"/>',
    down: '<path d="M7 7 17 17M17 7v10H7"/>',
    arrowRight: '<path d="M5 12h14M12 5l7 7-7 7"/>',
    arrowUp: '<path d="M12 19V5M5 12l7-7 7 7"/>',
    arrowDown: '<path d="M12 5v14m7-7-7 7-7-7"/>',
    calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
    target: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
    chart: '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-5 5"/>',
    check: '<path d="m5 12 4 4L19 6"/>',
    close: '<path d="m18 6-12 12M6 6l12 12"/>',
    trash: '<path d="M3 6h18M8 6V4h8v2m3 0-1 14H6L5 6m4 4v6m6-6v6"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    spark: '<path d="m12 3 1.9 5.8L20 11l-6.1 2.2L12 19l-1.9-5.8L4 11l6.1-2.2L12 3z"/><path d="m19 14 1 2.5 2.5 1-2.5 1L19 21l-1-2.5-2.5-1 2.5-1L19 14z"/>',
    shield: '<path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/><path d="m9 12 2 2 4-4"/>',
    logout: '<path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    send: '<path d="m22 2-7 20-4-9-9-4 20-7Z"/><path d="M22 2 11 13"/>',
    lock: '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
    more: '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
    receipt: '<path d="M4 3h16v18l-3-2-3 2-3-2-3 2-4-2z"/><path d="M8 8h8M8 12h8M8 16h4"/>',
    repeat: '<path d="m17 2 4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/>',
    moon: '<path d="M20.2 15.7A8.6 8.6 0 0 1 8.3 3.8a8.8 8.8 0 1 0 11.9 11.9Z"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/>',
    upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5M12 3v12"/>',
    camera: '<path d="M4 8h3l1.6-2.4h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="3.4"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/>',
    edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
  };

  const NAV_ITEMS = [
    { id: 'dashboard', label: 'Ringkasan', icon: 'home' },
    { id: 'finance', label: 'Keuangan', icon: 'wallet' },
    { id: 'report', label: 'Laporan', icon: 'chart' },
    { id: 'reminders', label: 'Pengingat', icon: 'bell' },
    { id: 'members', label: 'Anggota', icon: 'users', admin: true },
  ];

  function icon(name, extraClass = '') {
    return `<svg class="${extraClass}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name] || ICONS.spark}</svg>`;
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
  }

  function zonedDateParts(date = new Date()) {
    const parts = new Intl.DateTimeFormat('en-GB', {
      timeZone: appTimeZone, year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(date);
    return Object.fromEntries(parts.filter((part) => part.type !== 'literal').map((part) => [part.type, part.value]));
  }

  function currentMonth() {
    const parts = zonedDateParts();
    return `${parts.year}-${parts.month}`;
  }

  function localDateValue(date = new Date()) {
    const parts = zonedDateParts(date);
    return `${parts.year}-${parts.month}-${parts.day}`;
  }

  function localDateTimeValue(date = new Date()) {
    const parts = zonedDateParts(date);
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
  }

  function money(value) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value || 0)).replace(/\u00a0/g, ' ');
  }

  function monthLabel(value) {
    if (!value) return '';
    const [year, month] = value.split('-').map(Number);
    return new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(year, month - 1, 15)));
  }

  function dateLabel(value, options = { day: 'numeric', month: 'short', year: 'numeric' }) {
    if (!value) return '—';
    const [year, month, day] = String(value).slice(0, 10).split('-').map(Number);
    if (!year || !month || !day) return escapeHtml(value);
    return new Intl.DateTimeFormat('id-ID', { ...options, timeZone: 'UTC' }).format(new Date(Date.UTC(year, month - 1, day, 12)));
  }

  function dateTimeLabel(value) {
    if (!value) return '—';
    const [date, time = ''] = String(value).replace('T', ' ').split(' ');
    return `${dateLabel(date, { day: 'numeric', month: 'short', year: 'numeric' })}${time ? `, ${escapeHtml(time.slice(0, 5))}` : ''}`;
  }

  function initials(name) {
    return String(name || 'R').trim().split(/\s+/).slice(0, 2).map((part) => part.charAt(0)).join('').toUpperCase() || 'R';
  }

  function todayGreeting() {
    const hour = Number(zonedDateParts().hour);
    if (hour < 11) return 'Selamat pagi';
    if (hour < 15) return 'Selamat siang';
    if (hour < 18) return 'Selamat sore';
    return 'Selamat malam';
  }

  async function api(action, data, method = data === undefined ? 'GET' : 'POST') {
    const options = { method, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
    if (method !== 'GET') {
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CSRF-Token'] = state.csrf;
      options.body = JSON.stringify(data || {});
    }
    const [actionName, rawQuery = ''] = action.split('&', 2);
    const endpoint = `/api.php?action=${encodeURIComponent(actionName)}${rawQuery ? `&${rawQuery}` : ''}`;
    let response = await fetch(endpoint, options);

    // Refresh a stale token once, then transparently retry the user's action.
    if (response.status === 419 && method !== 'GET' && actionName !== 'csrf') {
      try {
        const csrfResponse = await fetch('/api.php?action=csrf', {
          method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' },
        });
        const csrfResult = await csrfResponse.json();
        if (csrfResponse.ok && csrfResult.ok && csrfResult.csrf) {
          state.csrf = csrfResult.csrf;
          options.headers['X-CSRF-Token'] = state.csrf;
          response = await fetch(endpoint, options);
        }
      } catch {
        // The response below will direct the user to reload if token recovery failed.
      }
    }

    let result;
    try { result = await response.json(); } catch { result = { ok: false, message: 'Respons server tidak terbaca.' }; }
    if (response.status === 401 && actionName !== 'login') {
      state.user = null;
      state.dashboard = null;
      render();
    }
    if (!response.ok || result.ok === false) {
      const message = response.status === 419
        ? 'Sesi belum tersinkron. Muat ulang halaman lalu coba sekali lagi.'
        : result.message || `Permintaan gagal (${response.status}).`;
      throw new Error(message);
    }
    return result;
  }

  function showToast(message, type = 'success') {
    const node = document.createElement('div');
    node.className = `toast${type === 'error' ? ' error' : ''}`;
    node.innerHTML = `<span class="toast-icon">${icon(type === 'error' ? 'info' : 'check')}</span><span>${escapeHtml(message)}</span>`;
    toastRoot.appendChild(node);
    // Long explanations (such as network errors) need more reading time than a short confirmation.
    window.setTimeout(() => node.remove(), Math.min(14000, Math.max(4400, String(message).length * 55)));
  }

  function showFormError(form, message) {
    const error = form.querySelector('[data-form-error]');
    if (error) {
      error.textContent = message;
      error.hidden = !message;
    } else if (message) {
      showToast(message, 'error');
    }
  }

  function setFormBusy(form, busy, busyText = 'Menyimpan…') {
    const button = form.querySelector('[type="submit"]');
    if (!button) return;
    if (busy) {
      button.dataset.originalHtml = button.innerHTML;
      button.disabled = true;
      button.textContent = busyText;
    } else {
      button.disabled = false;
      button.innerHTML = button.dataset.originalHtml || 'Simpan';
    }
  }

  // Menjalankan aksi tombol (kirim Telegram, dsb.) dengan status sibuk + penanganan galat yang seragam.
  async function withBusyButton(button, task, busyText = '') {
    if (!button) { await task(); return; }
    const original = button.innerHTML;
    button.disabled = true;
    if (busyText) button.textContent = busyText;
    try {
      await task();
    } catch (error) {
      showToast(error.message, 'error');
    } finally {
      button.disabled = false;
      button.innerHTML = original;
    }
  }

  function openModal(title, subtitle, body, { widthClass = '' } = {}) {
    modalRoot.innerHTML = `
      <div class="modal-backdrop" data-modal-backdrop>
        <section class="modal-card ${widthClass}" role="dialog" aria-modal="true" aria-labelledby="modal-title">
          <header class="modal-head">
            <div><h2 id="modal-title">${escapeHtml(title)}</h2><p>${escapeHtml(subtitle || '')}</p></div>
            <button class="modal-close" type="button" data-action="close-modal" aria-label="Tutup">${icon('close')}</button>
          </header>
          ${body}
        </section>
      </div>`;
    const firstField = modalRoot.querySelector('input, select, textarea, button');
    if (firstField) window.setTimeout(() => firstField.focus(), 50);
  }

  function closeModal() {
    modalRoot.innerHTML = '';
    state.modalReceipt = null;
    state.proofContext = null;
  }

  /* ---------------------------------------------------------------- *
   * Bukti gambar: kompres di browser, lalu simpan ke database
   * ---------------------------------------------------------------- */

  const RECEIPT_LIMITS = {
    maxSide: 1600,          // sisi terpanjang gambar yang disimpan
    quality: 0.72,          // kualitas awal JPEG
    maxBytes: 550 * 1024,   // target ukuran akhir per bukti
    maxSourceBytes: 25 * 1024 * 1024,
  };

  // Jam yang dipakai server untuk pengingat tanpa jam (sepanjang hari).
  const DEFAULT_REMINDER_TIME = '09:00';

  function formatFileSize(bytes) {
    const value = Math.max(0, Number(bytes || 0));
    if (value < 1024) return `${value} B`;
    if (value < 1024 * 1024) return `${Math.round(value / 1024)} KB`;
    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
  }

  function loadImageFile(file) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const image = new Image();
      image.onload = () => { URL.revokeObjectURL(url); resolve(image); };
      image.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Gambar tidak bisa dibaca. Coba foto lain atau lewati bukti.')); };
      image.src = url;
    });
  }

  function canvasToBlob(canvas, quality) {
    return new Promise((resolve) => {
      if (typeof canvas.toBlob !== 'function') { resolve(null); return; }
      canvas.toBlob((blob) => resolve(blob || null), 'image/jpeg', quality);
    });
  }

  function scaleToCanvas(source, width, height) {
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width));
    canvas.height = Math.max(1, Math.round(height));
    const context = canvas.getContext('2d');
    if (!context) throw new Error('Browser ini tidak mendukung pengompresan gambar.');
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(source, 0, 0, canvas.width, canvas.height);
    return canvas;
  }

  function blobToBase64(blob) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result || '').split(',')[1] || '');
      reader.onerror = () => reject(new Error('Gambar gagal diproses di browser.'));
      reader.readAsDataURL(blob);
    });
  }

  // Foto dari HP itu besar (2–8 MB). Di sini gambar diperkecil & dikompres dulu,
  // baru dikirim ke server, jadi database tetap ringan dan kuota hemat.
  async function compressReceiptImage(file) {
    if (!file) throw new Error('Belum ada gambar yang dipilih.');
    if (!/^image\//i.test(file.type || '')) throw new Error('File bukti harus berupa gambar (JPG, PNG, atau WEBP).');
    if (file.size > RECEIPT_LIMITS.maxSourceBytes) throw new Error(`Ukuran foto asli ${formatFileSize(file.size)}; maksimal 25 MB.`);
    const image = await loadImageFile(file);
    const width = image.naturalWidth || image.width;
    const height = image.naturalHeight || image.height;
    if (!width || !height) throw new Error('Ukuran gambar tidak terbaca.');
    const scale = Math.min(1, RECEIPT_LIMITS.maxSide / Math.max(width, height));
    let canvas = scaleToCanvas(image, width * scale, height * scale);
    let quality = RECEIPT_LIMITS.quality;
    let blob = await canvasToBlob(canvas, quality);
    let attempt = 0;
    while ((!blob || blob.size > RECEIPT_LIMITS.maxBytes) && attempt < 9) {
      attempt += 1;
      if (quality > 0.45) {
        quality = Math.max(0.45, quality - 0.09);
        blob = await canvasToBlob(canvas, quality);
        continue;
      }
      canvas = scaleToCanvas(canvas, canvas.width * 0.8, canvas.height * 0.8);
      blob = await canvasToBlob(canvas, quality);
    }
    if (!blob) throw new Error('Gambar gagal dikompres di browser ini. Coba browser lain atau lewati bukti.');
    if (blob.size > RECEIPT_LIMITS.maxBytes) {
      throw new Error(`Gambar masih ${formatFileSize(blob.size)} setelah dikompres. Pilih foto lain atau lewati bukti.`);
    }
    const data = await blobToBase64(blob);
    return {
      data,
      mime: 'image/jpeg',
      width: canvas.width,
      height: canvas.height,
      name: `${String(file.name || 'bukti').replace(/\.[^.]+$/, '').slice(0, 80) || 'bukti'}.jpg`,
      originalSize: file.size,
      size: blob.size,
      previewUrl: URL.createObjectURL(blob),
    };
  }

  function receiptPayload() {
    const receipt = state.modalReceipt;
    if (!receipt || !receipt.data) return null;
    return { data: receipt.data, mime: receipt.mime, width: receipt.width, height: receipt.height, name: receipt.name };
  }

  function receiptFieldMarkup(inputId, existing = null) {
    const existingNote = existing
      ? `<p class="form-note receipt-existing">${icon('camera')} Bukti sudah tersimpan (${escapeHtml(formatFileSize(existing.bytes))}${existing.width ? ` · ${Number(existing.width)}×${Number(existing.height)} px` : ''}). Pilih foto baru untuk menggantinya, atau lewati kolom ini untuk membiarkan bukti yang lama.</p>`
      : '';
    return `
      <div class="field full-span receipt-field" data-receipt-field>
        <label for="${inputId}">Bukti / foto <span class="text-muted" style="font-weight:400">(opsional, boleh dilewati)</span></label>
        ${existingNote}
        <div class="receipt-empty" data-receipt-empty>
          <label class="receipt-drop" for="${inputId}">
            <input class="receipt-input" id="${inputId}" type="file" accept="image/*" data-receipt-input>
            <span class="receipt-drop-icon">${icon('camera')}</span>
            <span class="receipt-drop-copy"><strong>${existing ? 'Ganti foto bukti' : 'Pilih foto bukti'}</strong><small>Foto nota, struk, atau barangnya</small></span>
          </label>
        </div>
        <div class="receipt-preview" data-receipt-preview hidden>
          <img class="receipt-thumb" data-receipt-thumb alt="Pratinjau bukti">
          <span class="receipt-preview-copy"><strong data-receipt-name>bukti.jpg</strong><small data-receipt-meta></small></span>
          <button class="btn btn-secondary btn-small" type="button" data-action="clear-receipt">Hapus gambar</button>
        </div>
        <p class="form-note receipt-note" data-receipt-note>Gambar dikecilkan & dikompres dulu di HP ini (maksimal 1600 px) sebelum masuk ke database. Tidak ada foto? Cukup simpan tanpa bukti.</p>
      </div>`;
  }

  function paintReceiptPreview(root) {
    const scope = root || modalRoot;
    const preview = scope.querySelector('[data-receipt-preview]');
    const empty = scope.querySelector('[data-receipt-empty]');
    const receipt = state.modalReceipt;
    if (!preview) return;
    if (!receipt) {
      preview.hidden = true;
      if (empty) empty.hidden = false;
      return;
    }
    preview.hidden = false;
    if (empty) empty.hidden = true;
    const thumb = preview.querySelector('[data-receipt-thumb]');
    const name = preview.querySelector('[data-receipt-name]');
    const meta = preview.querySelector('[data-receipt-meta]');
    if (thumb) thumb.src = receipt.previewUrl;
    if (name) name.textContent = receipt.name;
    if (meta) meta.textContent = `${formatFileSize(receipt.size)} · ${receipt.width}×${receipt.height} px`;
  }

  function setReceiptNote(root, text) {
    const note = (root || modalRoot).querySelector('[data-receipt-note]') || modalRoot.querySelector('[data-proof-note]');
    if (note) note.textContent = text;
  }

  async function handleReceiptFile(input) {
    const root = input.closest('[data-receipt-field]') || modalRoot;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    setReceiptNote(root, `Mengompres ${formatFileSize(file.size)}…`);
    try {
      const receipt = await compressReceiptImage(file);
      state.modalReceipt = receipt;
      paintReceiptPreview(root);
      setReceiptNote(root, `Foto ${formatFileSize(receipt.originalSize)} → ${formatFileSize(receipt.size)} setelah dikompres (${receipt.width}×${receipt.height} px). Ini yang dikirim ke database.`);
    } catch (error) {
      state.modalReceipt = null;
      paintReceiptPreview(root);
      setReceiptNote(root, error.message);
      showToast(error.message, 'error');
    }
  }

  function clearReceipt(input) {
    const root = input.closest('[data-receipt-field]') || modalRoot;
    state.modalReceipt = null;
    paintReceiptPreview(root);
    setReceiptNote(root, 'Gambar dilepas. Kolom bukti boleh dikosongkan — simpan seperti biasa.');
    const saveButton = modalRoot.querySelector('[data-action="save-proof"]');
    if (saveButton) saveButton.disabled = true;
  }

  function findEntityRecord(entityType, id) {
    const target = Number(id);
    const pools = entityType === 'reminder'
      ? [state.reminders?.reminders, state.dashboard?.reminders]
      : [state.finance?.transactions, state.dashboard?.transactions];
    for (const pool of pools) {
      const found = (pool || []).find((row) => Number(row.id) === target);
      if (found) return found;
    }
    return null;
  }

  function canManageEntity(entityType, record) {
    if (state.user?.role === 'admin') return true;
    if (!record) return false;
    return entityType === 'reminder'
      ? Number(record.created_by) === Number(state.user?.id)
      : Number(record.user_id) === Number(state.user?.id);
  }

  function proofButton(entityType, record, { compact = false, alwaysShow = false } = {}) {
    const attachment = record?.attachment || null;
    const canManage = canManageEntity(entityType, record);
    if (!attachment && !alwaysShow && !canManage) return '';
    const label = attachment ? 'Lihat bukti gambar' : 'Tambah bukti gambar';
    return `<button class="proof-button${attachment ? ' has-proof' : ''}${compact ? ' compact' : ''}" type="button" data-action="open-proof" data-entity="${entityType}" data-id="${Number(record?.id)}" aria-label="${label}" title="${label}">${icon('camera')}${compact ? '' : `<span>${attachment ? 'Bukti' : 'Tambah bukti'}</span>`}</button>`;
  }

  function openProofModal(entityType, entityId) {
    const record = findEntityRecord(entityType, entityId) || { id: Number(entityId) };
    const attachment = record.attachment || null;
    const canManage = canManageEntity(entityType, record);
    const isReminder = entityType === 'reminder';
    state.modalReceipt = null;
    state.proofContext = { entityType, entityId: Number(entityId), replace: !!attachment };
    const meta = attachment
      ? `${formatFileSize(attachment.bytes)} · ${attachment.width}×${attachment.height} px${attachment.created_at ? ` · ${dateTimeLabel(attachment.created_at)}` : ''}`
      : '';
    const uploadControl = (inputId) => `
      <div class="receipt-empty" data-receipt-empty>
        <label class="receipt-drop" for="${inputId}">
          <input class="receipt-input" id="${inputId}" type="file" accept="image/*" data-proof-input>
          <span class="receipt-drop-icon">${icon('camera')}</span>
          <span class="receipt-drop-copy"><strong>${attachment ? 'Ganti foto bukti' : 'Pilih foto bukti'}</strong><small>Otomatis dikompres sebelum dikirim</small></span>
        </label>
      </div>
      <div class="receipt-preview" data-receipt-preview hidden>
        <img class="receipt-thumb" data-receipt-thumb alt="Pratinjau bukti">
        <span class="receipt-preview-copy"><strong data-receipt-name>bukti.jpg</strong><small data-receipt-meta></small></span>
        <button class="btn btn-secondary btn-small" type="button" data-action="clear-receipt">Hapus gambar</button>
      </div>`;
    const body = attachment
      ? `<div class="modal-body proof-body">
          <figure class="proof-figure"><img class="proof-image" src="${escapeHtml(attachment.url)}" alt="Bukti gambar" loading="lazy"></figure>
          <p class="proof-meta">${escapeHtml(meta)}</p>
          ${canManage ? `${uploadControl('proof-file')}<a class="btn btn-secondary" href="${escapeHtml(attachment.url)}&download=1" download>${icon('download')} Unduh gambar</a>` : ''}
          <p class="proof-note" data-proof-note>${canManage ? 'Pilih foto baru untuk mengganti bukti yang tersimpan.' : 'Hanya pembuat catatan atau admin yang bisa mengubah bukti ini.'}</p>
          <div class="modal-footer"><button class="btn btn-secondary" type="button" data-action="close-modal">Tutup</button>${canManage ? `<button class="btn btn-danger" type="button" data-action="delete-proof" data-id="${Number(attachment.id)}">${icon('trash')} Hapus bukti</button>` : ''}</div>
        </div>`
      : `<div class="modal-body proof-body">
          <div class="proof-empty"><span class="proof-empty-icon">${icon('camera')}</span><strong>Belum ada bukti gambar</strong><p>Bukti bersifat opsional. Kalau tidak ada foto, catatan tetap tersimpan seperti biasa.</p></div>
          ${canManage ? `${uploadControl('proof-file')}<p class="proof-note" data-proof-note>Foto akan dikompres di HP ini sebelum dikirim ke database.</p>
          <div class="modal-footer"><button class="btn btn-secondary" type="button" data-action="close-modal">Tutup</button><button class="btn" type="button" data-action="save-proof" data-entity="${escapeHtml(entityType)}" data-id="${Number(entityId)}" disabled>${icon('check')} Simpan bukti</button></div>`
            : `<p class="proof-note">Hanya pembuat catatan atau admin yang bisa menambahkan bukti.</p><div class="modal-footer"><button class="btn btn-secondary" type="button" data-action="close-modal">Tutup</button></div>`}
        </div>`;
    openModal(isReminder ? 'Bukti pengingat' : 'Bukti transaksi', isReminder ? 'Simpan foto pendukung untuk pengingat ini.' : 'Foto nota, struk, atau transfer sebagai bukti catatan ini.', body, { widthClass: 'modal-proof' });
  }

  async function refreshAfterEntityChange(context = {}) {
    state.dashboard = null;
    if (!context.entityType || context.entityType === 'transaction') {
      state.finance = null;
      state.report = null;
      state.reportKey = '';
    }
    if (!context.entityType || context.entityType === 'reminder') state.reminders = null;
    await refreshPage(true);
  }

  async function handleProofFile(input) {
    const context = state.proofContext || {};
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    setReceiptNote(modalRoot, `Mengompres ${formatFileSize(file.size)}…`);
    let receipt;
    try {
      receipt = await compressReceiptImage(file);
    } catch (error) {
      setReceiptNote(modalRoot, error.message);
      showToast(error.message, 'error');
      return;
    }
    state.modalReceipt = receipt;
    paintReceiptPreview(modalRoot);
    setReceiptNote(modalRoot, `Foto ${formatFileSize(receipt.originalSize)} → ${formatFileSize(receipt.size)} setelah dikompres.`);
    const saveButton = modalRoot.querySelector('[data-action="save-proof"]');
    if (!context.replace && saveButton) {
      saveButton.disabled = false;
      return;
    }
    await uploadReceipt(context.entityType, context.entityId);
  }

  // Saat mengedit catatan, bukti baru dikirim terpisah: kalau unggahannya gagal,
  // perubahannya tetap tersimpan dan bukti yang lama tidak hilang.
  async function replaceReceipt(entityType, entityId, receipt) {
    if (!receipt) return '';
    try {
      await api('attachment/save', { entity_type: entityType, entity_id: Number(entityId), receipt });
      return ` Bukti baru ikut tersimpan (${formatFileSize(receipt.size)}).`;
    } catch (error) {
      return ` Catatan tersimpan, tetapi bukti gambar gagal diganti: ${error.message}`;
    }
  }

  async function uploadReceipt(entityType, entityId, button = null) {
    const receipt = receiptPayload();
    if (!receipt) {
      showToast('Pilih foto bukti terlebih dahulu.', 'error');
      return;
    }
    if (button) button.disabled = true;
    setReceiptNote(modalRoot, 'Mengunggah bukti ke database…');
    try {
      const result = await api('attachment/save', { entity_type: entityType, entity_id: Number(entityId), receipt });
      showToast(result.message);
      closeModal();
      await refreshAfterEntityChange({ entityType });
    } catch (error) {
      setReceiptNote(modalRoot, error.message);
      showToast(error.message, 'error');
      if (button) button.disabled = false;
    }
  }

  async function deleteProof(id) {
    if (!window.confirm('Hapus bukti gambar ini? Catatan keuangannya tetap tersimpan.')) return;
    try {
      const result = await api('attachment/delete', { id: Number(id) });
      showToast(result.message);
      const context = state.proofContext || {};
      closeModal();
      await refreshAfterEntityChange(context);
    } catch (error) {
      showToast(error.message, 'error');
    }
  }

  function downloadImportTemplate(type) {
    const columns = type === 'reminders'
      ? ['Judul', 'Tanggal', 'Jam', 'Kategori', 'Catatan']
      : ['Jenis', 'Nama transaksi', 'Nominal', 'Tanggal', 'Kategori', 'Catatan'];
    const csv = `\uFEFF${columns.map((value) => `"${value.replace(/"/g, '""')}"`).join(';')}\r\n`;
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = type === 'reminders' ? 'template-pengingat.csv' : 'template-keuangan.csv';
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function openImportModal(type) {
    if (!['transactions', 'reminders'].includes(type)) return;
    state.importType = type;
    state.importPreview = null;
    const reminders = type === 'reminders';
    const instructions = reminders
      ? 'Kolom wajib: Judul dan Tanggal. Kolom Jam opsional (format 09:30); biarkan kosong bila pengingatnya cukup per tanggal — kabarnya dikirim sekitar 09:00.'
      : 'Kolom wajib: Jenis (Pemasukan/Pengeluaran), Nama transaksi, Nominal, dan Tanggal (YYYY-MM-DD).';
    openModal(reminders ? 'Import pengingat' : 'Import keuangan', 'Unggah banyak baris sekaligus dari Excel atau CSV.', `
      <div class="modal-body import-modal-body">
        <div class="import-instructions"><p>${instructions}</p><p>Baris pertama harus berisi judul kolom. Workbook dibaca dari sheet pertama, maksimal 500 baris per file; tanggal boleh berformat YYYY-MM-DD atau DD/MM/YYYY. File .xls lama simpan ulang sebagai .xlsx.</p></div>
        <button class="btn btn-secondary import-template-button" type="button" data-action="download-import-template" data-import-type="${type}">${icon('receipt')} Unduh template CSV</button>
        <label class="import-upload-box" for="import-file"><input class="import-file-input" id="import-file" type="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" data-import-file><span class="import-upload-mark">${icon('upload')}</span><span class="import-upload-copy"><strong>Pilih file Excel atau CSV</strong><small data-import-file-name>Format .xlsx atau .csv · maksimal 10 MB</small></span></label>
        <p class="import-duplicate-note">Setiap baris ditambahkan sebagai catatan baru; unggah file yang sama lagi bisa membuat data ganda.${reminders ? ' Pengingat impor tidak mengirim Telegram satu per satu; notifikasi jadwal mengikuti cron.' : ''}</p>
        <p class="error-text import-error" data-import-error hidden></p>
        <div class="import-preview" data-import-preview hidden></div>
        <div class="modal-footer"><button class="btn btn-secondary" type="button" data-action="close-modal">Batal</button><button class="btn" type="button" data-action="confirm-import" disabled>Import</button></div>
      </div>`, { widthClass: 'modal-import' });
  }

  function normalizeImportHeader(value) {
    return String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '');
  }

  function importColumn(headers, aliases) {
    const normalized = headers.map(normalizeImportHeader);
    for (const alias of aliases) {
      const index = normalized.indexOf(normalizeImportHeader(alias));
      if (index >= 0) return index;
    }
    return -1;
  }

  function importCell(row, index) {
    return index >= 0 ? (row.cells[index] ?? '') : '';
  }

  function excelSerialParts(serial) {
    if (!Number.isFinite(serial) || serial < 20000 || serial > 2958465) return null;
    const wholeDays = Math.floor(serial);
    let minutes = Math.round((serial - wholeDays) * 1440);
    const date = new Date(Date.UTC(1899, 11, 30) + wholeDays * 86400000);
    if (minutes >= 1440) {
      date.setUTCDate(date.getUTCDate() + 1);
      minutes = 0;
    }
    return { date: date.toISOString().slice(0, 10), time: minutes ? `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}` : '' };
  }

  function buildDateParts(year, month, day, hour = 0, minute = 0) {
    year = Number(year); month = Number(month); day = Number(day); hour = Number(hour); minute = Number(minute);
    if (year < 1900 || year > 9999 || month < 1 || month > 12 || day < 1 || day > 31 || hour < 0 || hour > 23 || minute < 0 || minute > 59) return null;
    const date = new Date(Date.UTC(year, month - 1, day));
    if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return null;
    return { date: date.toISOString().slice(0, 10), time: `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}` };
  }

  function parseImportDate(value) {
    if (typeof value === 'number') return excelSerialParts(value);
    const text = String(value ?? '').trim();
    if (!text) return null;
    if (/^\d+(?:\.\d+)?$/.test(text)) {
      const serial = excelSerialParts(Number(text));
      if (serial) return serial;
    }
    let match = /^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})(?:[T ](\d{1,2}):(\d{2})(?::\d{2})?)?$/.exec(text);
    if (match) {
      const parts = buildDateParts(match[1], match[2], match[3], match[4] || 0, match[5] || 0);
      if (parts && !match[4]) parts.time = '';
      return parts;
    }
    match = /^(\d{1,2})[/. -](\d{1,2})[/. -](\d{2,4})(?:[T ](\d{1,2}):(\d{2})(?::\d{2})?)?$/.exec(text);
    if (match) {
      let year = Number(match[3]);
      if (year < 100) year += year < 50 ? 2000 : 1900;
      const parts = buildDateParts(year, match[2], match[1], match[4] || 0, match[5] || 0);
      if (parts && !match[4]) parts.time = '';
      return parts;
    }
    const parsed = Date.parse(text);
    if (!Number.isNaN(parsed)) {
      const date = new Date(parsed);
      const parts = buildDateParts(date.getFullYear(), date.getMonth() + 1, date.getDate(), date.getHours(), date.getMinutes());
      if (parts && !/[T ]\d{1,2}:\d{2}/.test(text)) parts.time = '';
      return parts;
    }
    return null;
  }

  function parseImportTime(value) {
    if (typeof value === 'number' && value >= 0 && value < 1) {
      const minutes = Math.round(value * 1440) % 1440;
      return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
    }
    const text = String(value ?? '').trim();
    if (!text) return '';
    const match = /^(\d{1,2})(?:[:.](\d{2}))?(?:[:.]\d{2})?\s*(am|pm)?$/i.exec(text);
    if (!match) return '';
    let hour = Number(match[1]);
    const minute = Number(match[2] || 0);
    const suffix = (match[3] || '').toLowerCase();
    if (suffix) {
      if (hour < 1 || hour > 12) return '';
      if (suffix === 'pm' && hour < 12) hour += 12;
      if (suffix === 'am' && hour === 12) hour = 0;
    }
    if (hour > 23 || minute > 59) return '';
    return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
  }

  function parseImportAmount(value) {
    if (typeof value === 'number') return Number.isFinite(value) ? Math.round(value) : NaN;
    let text = String(value ?? '').trim().replace(/[^0-9,.-]/g, '');
    if (!text) return NaN;
    const negative = text.startsWith('-');
    text = text.replace(/-/g, '');
    const comma = text.lastIndexOf(',');
    const dot = text.lastIndexOf('.');
    const lastSeparator = Math.max(comma, dot);
    if (lastSeparator >= 0) {
      const trailing = text.length - lastSeparator - 1;
      const separators = (text.match(/[,.]/g) || []).length;
      if (trailing > 0 && trailing <= 2 && (separators === 1 || (comma >= 0 && dot >= 0))) {
        text = `${text.slice(0, lastSeparator).replace(/[,.]/g, '')}.${text.slice(lastSeparator + 1)}`;
      } else {
        text = text.replace(/[,.]/g, '');
      }
    }
    const amount = Number(text);
    return Number.isFinite(amount) ? Math.round(negative ? -amount : amount) : NaN;
  }

  function prepareImportRows(type, workbookRows) {
    const headerPosition = workbookRows.findIndex((row) => row.cells.some((cell) => String(cell ?? '').trim() !== ''));
    if (headerPosition < 0) throw new Error('File belum berisi judul kolom.');
    const headers = workbookRows[headerPosition].cells;
    const rows = workbookRows.slice(headerPosition + 1).filter((row) => row.cells.some((cell) => String(cell ?? '').trim() !== ''));
    if (!rows.length) throw new Error('File belum berisi baris data.');
    if (rows.length > 500) throw new Error('Maksimal 500 baris data per file.');

    const valid = [];
    const invalid = [];
    if (type === 'transactions') {
      const indexes = {
        kind: importColumn(headers, ['jenis', 'tipe', 'jenis transaksi', 'type', 'kind']),
        title: importColumn(headers, ['nama transaksi', 'transaksi', 'judul', 'nama', 'title']),
        amount: importColumn(headers, ['nominal', 'jumlah', 'amount', 'total', 'nilai']),
        date: importColumn(headers, ['tanggal transaksi', 'tanggal', 'date', 'tgl', 'transaction date']),
        category: importColumn(headers, ['kategori', 'category']),
        note: importColumn(headers, ['catatan', 'note', 'keterangan', 'deskripsi']),
      };
      const missing = Object.entries({ kind: 'Jenis', title: 'Nama transaksi', amount: 'Nominal', date: 'Tanggal' }).filter(([key]) => indexes[key] < 0).map(([, label]) => label);
      if (missing.length) throw new Error(`Kolom wajib belum ditemukan: ${missing.join(', ')}. Gunakan template agar formatnya sesuai.`);
      for (const row of rows) {
        const kindText = normalizeImportHeader(importCell(row, indexes.kind));
        const kind = ['pemasukan', 'income', 'masuk', 'uangmasuk'].includes(kindText) ? 'income'
          : ['pengeluaran', 'expense', 'keluar', 'uangkeluar'].includes(kindText) ? 'expense' : '';
        const title = String(importCell(row, indexes.title) ?? '').trim();
        const category = String(importCell(row, indexes.category) || 'Lainnya').trim();
        const amount = parseImportAmount(importCell(row, indexes.amount));
        const date = parseImportDate(importCell(row, indexes.date))?.date || '';
        const note = String(importCell(row, indexes.note) ?? '').trim();
        const reasons = [];
        if (!kind) reasons.push('Jenis harus Pemasukan atau Pengeluaran');
        if (!title || title.length > 100) reasons.push('Nama transaksi wajib diisi (maks. 100 karakter)');
        if (!Number.isInteger(amount) || amount < 1 || amount > 999999999999) reasons.push('Nominal tidak valid');
        if (!date) reasons.push('Tanggal tidak valid');
        if (!category || category.length > 40) reasons.push('Kategori maksimal 40 karakter');
        if (note.length > 500) reasons.push('Catatan maksimal 500 karakter');
        if (reasons.length) invalid.push({ row: row.number, reason: reasons.join('; ') });
        else valid.push({ row: row.number, data: { kind, title, category, amount, date, note } });
      }
    } else {
      const indexes = {
        title: importColumn(headers, ['judul', 'pengingat', 'nama agenda', 'agenda', 'title', 'nama']),
        dateTime: importColumn(headers, ['tanggal dan waktu', 'tanggal waktu', 'tanggal jam', 'waktu pengingat', 'reminder at', 'reminder_at', 'datetime', 'date and time']),
        date: importColumn(headers, ['tanggal', 'date', 'tgl']),
        time: importColumn(headers, ['jam', 'time']),
        timeCombined: importColumn(headers, ['waktu']),
        category: importColumn(headers, ['kategori', 'category']),
        note: importColumn(headers, ['catatan', 'note', 'keterangan', 'detail']),
      };
      if (indexes.title < 0) throw new Error('Kolom Judul belum ditemukan. Gunakan template pengingat.');
      if (indexes.dateTime < 0 && indexes.date < 0 && indexes.timeCombined < 0) throw new Error('Kolom Tanggal belum ditemukan. Gunakan template pengingat.');
      for (const row of rows) {
        const title = String(importCell(row, indexes.title) ?? '').trim();
        const combinedDate = indexes.dateTime >= 0 ? importCell(row, indexes.dateTime)
          : indexes.date >= 0 ? importCell(row, indexes.date) : importCell(row, indexes.timeCombined);
        const dateParts = parseImportDate(combinedDate);
        const separateTime = indexes.time >= 0 ? parseImportTime(importCell(row, indexes.time)) : '';
        // Jam opsional: baris tanpa jam diimpor sebagai pengingat sepanjang hari.
        const time = separateTime || dateParts?.time || '';
        const reminderDate = dateParts?.date || '';
        const category = String(importCell(row, indexes.category) || 'Lainnya').trim();
        const note = String(importCell(row, indexes.note) ?? '').trim();
        const reasons = [];
        if (!title || title.length > 120) reasons.push('Judul wajib diisi (maks. 120 karakter)');
        if (!reminderDate) reasons.push('Tanggal tidak valid');
        if (!category || category.length > 40) reasons.push('Kategori maksimal 40 karakter');
        if (note.length > 500) reasons.push('Catatan maksimal 500 karakter');
        if (reasons.length) invalid.push({ row: row.number, reason: reasons.join('; ') });
        else valid.push({ row: row.number, data: { title, category, reminder_date: reminderDate, reminder_time: time, note } });
      }
    }
    return { valid, invalid };
  }

  function reminderImportLabel(data) {
    const date = dateLabel(data.reminder_date, { day: 'numeric', month: 'short', year: 'numeric' });
    return data.reminder_time ? `${date}, ${data.reminder_time}` : `${date} · seharian`;
  }

  function renderImportPreview() {
    const previewNode = modalRoot.querySelector('[data-import-preview]');
    const errorNode = modalRoot.querySelector('[data-import-error]');
    const confirmButton = modalRoot.querySelector('[data-action="confirm-import"]');
    const fileNameNode = modalRoot.querySelector('[data-import-file-name]');
    const preview = state.importPreview;
    if (!previewNode || !preview) return;
    previewNode.hidden = false;
    if (errorNode) errorNode.hidden = true;
    if (fileNameNode) fileNameNode.textContent = preview.fileName;
    const reminders = preview.type === 'reminders';
    const sample = preview.valid.slice(0, 5).map((item) => reminders
      ? `<tr><td>${escapeHtml(reminderImportLabel(item.data))}</td><td>${escapeHtml(item.data.title)}</td><td>${escapeHtml(item.data.category)}</td></tr>`
      : `<tr><td>${escapeHtml(dateLabel(item.data.date, { day: 'numeric', month: 'short', year: 'numeric' }))}</td><td>${item.data.kind === 'income' ? 'Masuk' : 'Keluar'}</td><td>${escapeHtml(item.data.title)}</td><td>${escapeHtml(money(item.data.amount))}</td></tr>`).join('');
    const headers = reminders ? '<th>Tanggal & jam</th><th>Judul</th><th>Kategori</th>' : '<th>Tanggal</th><th>Jenis</th><th>Transaksi</th><th>Nominal</th>';
    const invalidList = preview.invalid.slice(0, 5).map((item) => `<li>Baris ${Number(item.row)}: ${escapeHtml(item.reason)}</li>`).join('');
    previewNode.innerHTML = `<div class="import-result-line"><strong>${preview.valid.length} baris siap</strong><span>${preview.invalid.length ? `${preview.invalid.length} dilewati` : 'Data terlihat valid'}</span></div>${sample ? `<div class="import-table-wrap"><table class="import-table"><thead><tr>${headers}</tr></thead><tbody>${sample}</tbody></table></div>` : '<p class="import-no-valid">Belum ada baris valid untuk diimpor.</p>'}${invalidList ? `<details class="import-invalid"><summary>Lihat contoh baris yang dilewati</summary><ul>${invalidList}</ul></details>` : ''}`;
    if (confirmButton) {
      confirmButton.disabled = preview.valid.length === 0;
      confirmButton.textContent = preview.valid.length ? `Import ${preview.valid.length} baris` : 'Import';
    }
  }

  async function handleImportFile(file) {
    const errorNode = modalRoot.querySelector('[data-import-error]');
    const previewNode = modalRoot.querySelector('[data-import-preview]');
    const confirmButton = modalRoot.querySelector('[data-action="confirm-import"]');
    const fileNameNode = modalRoot.querySelector('[data-import-file-name]');
    state.importPreview = null;
    if (previewNode) { previewNode.hidden = true; previewNode.innerHTML = ''; }
    if (errorNode) { errorNode.hidden = true; errorNode.textContent = ''; }
    if (confirmButton) { confirmButton.disabled = true; confirmButton.textContent = 'Membaca…'; }
    if (fileNameNode && file) fileNameNode.textContent = `${file.name} · membaca isi file…`;
    try {
      if (!window.HouseholdImport) throw new Error('Pembaca file belum siap. Muat ulang halaman lalu coba lagi.');
      const workbookRows = await window.HouseholdImport.read(file);
      const prepared = prepareImportRows(state.importType, workbookRows);
      state.importPreview = { ...prepared, type: state.importType, fileName: file.name };
      renderImportPreview();
    } catch (error) {
      if (errorNode) { errorNode.textContent = error.message || 'File tidak dapat dibaca.'; errorNode.hidden = false; }
      if (fileNameNode) fileNameNode.textContent = file ? file.name : 'Pilih file Excel atau CSV';
      if (confirmButton) { confirmButton.disabled = true; confirmButton.textContent = 'Import'; }
    }
  }

  async function confirmImport(button) {
    const preview = state.importPreview;
    if (!preview || !preview.valid.length) return;
    button.disabled = true;
    button.textContent = 'Mengimpor…';
    const errorNode = modalRoot.querySelector('[data-import-error]');
    if (errorNode) errorNode.hidden = true;
    try {
      const action = preview.type === 'reminders' ? 'reminders/import' : 'transactions/import';
      const result = await api(action, { rows: preview.valid.map((item) => ({ ...item.data, source_row: item.row })) });
      const type = preview.type;
      closeModal();
      state.importPreview = null;
      state.dashboard = null;
      if (type === 'reminders') {
        state.reminders = null;
        state.reminderFilter = 'all';
      } else {
        state.finance = null;
        state.report = null;
        state.reportKey = '';
        state.transactionFilter = 'all';
        const latestDate = preview.valid.reduce((latest, item) => item.data.date > latest ? item.data.date : latest, '');
        if (latestDate) state.financeMonth = latestDate.slice(0, 7);
      }
      await refreshPage(true);
      const imported = Number(result.imported || 0);
      const skipped = Number(result.skipped || 0) + preview.invalid.length;
      showToast(`${imported} ${type === 'reminders' ? 'pengingat' : 'transaksi'} berhasil diimpor${skipped ? `; ${skipped} baris dilewati` : ''}.`, skipped ? 'error' : 'success');
    } catch (error) {
      if (errorNode) { errorNode.textContent = error.message || 'Data gagal diimpor.'; errorNode.hidden = false; }
      button.disabled = false;
      button.textContent = `Import ${preview.valid.length} baris`;
    }
  }

  function emptyState(title, description, glyph = 'spark') {
    return `<div class="empty-state"><span class="empty-illustration">${icon(glyph)}</span><strong>${escapeHtml(title)}</strong><p>${escapeHtml(description)}</p></div>`;
  }

  function pageTitle(page = state.page) {
    return ({ dashboard: 'Ringkasan', finance: 'Keuangan', report: 'Laporan keuangan', reminders: 'Pengingat', members: 'Anggota rumah', settings: 'Pengaturan' })[page] || 'Ringkasan';
  }

  function brandMarkup() {
    return `<a class="brand" href="#" data-page="dashboard" aria-label="Ringkasan"><span class="brand-symbol" aria-hidden="true">${icon('home')}</span></a>`;
  }

  function navLink(item, mobile = false) {
    if (item.admin && state.user?.role !== 'admin') return '';
    const count = item.id === 'reminders' ? Number(state.dashboard?.reminder_counts?.pending || 0) : 0;
    return `<button class="nav-link${state.page === item.id ? ' active' : ''}" type="button" data-page="${item.id}" aria-current="${state.page === item.id ? 'page' : 'false'}">${icon(item.icon)}<span>${item.label}</span>${count > 0 && !mobile ? `<span class="nav-count">${count > 9 ? '9+' : count}</span>` : ''}${count > 0 && mobile ? `<span class="nav-count">${count > 9 ? '9+' : count}</span>` : ''}</button>`;
  }

  function renderLogin() {
    const isNight = state.loginTheme === 'dark';
    syncBrowserThemeColor(state.loginTheme);
    appRoot.innerHTML = `
      <main class="login-page" data-theme="${isNight ? 'dark' : 'light'}">
        <button class="login-theme-toggle" type="button" data-action="toggle-login-theme" aria-label="${isNight ? 'Gunakan mode biasa' : 'Gunakan mode malam'}" aria-pressed="${isNight}" title="${isNight ? 'Gunakan mode biasa' : 'Gunakan mode malam'}">${icon(isNight ? 'sun' : 'moon')}<span>${isNight ? 'Mode biasa' : 'Mode malam'}</span></button>
        <section class="login-card" aria-labelledby="login-title">
          <h1 id="login-title" class="sr-only">Masuk</h1>
          <p class="login-caption">Masuk untuk melanjutkan.</p>
          <form class="login-form" data-form="login" autocomplete="on">
            <div class="field"><label for="login-username">User ID</label><input class="input" id="login-username" name="username" type="text" placeholder="Masukkan User ID" autocomplete="username" autocapitalize="none" required></div>
            <div class="field"><label for="login-password">Password</label><input class="input" id="login-password" name="password" type="password" placeholder="Masukkan password" autocomplete="current-password" required></div>
            <p class="error-text" data-form-error hidden></p>
            <button class="btn login-submit" type="submit">Login</button>
          </form>
        </section>
      </main>`;
  }

  function setLoginTheme(theme) {
    state.loginTheme = theme === 'dark' ? 'dark' : 'light';
    syncBrowserThemeColor(state.loginTheme);
    try { window.localStorage.setItem('household-login-theme', state.loginTheme); } catch { /* optional preference */ }
    const loginPage = appRoot.querySelector('.login-page');
    if (!loginPage) return;
    const isNight = state.loginTheme === 'dark';
    loginPage.dataset.theme = state.loginTheme;
    const button = loginPage.querySelector('[data-action="toggle-login-theme"]');
    if (button) {
      button.innerHTML = `${icon(isNight ? 'sun' : 'moon')}<span>${isNight ? 'Mode biasa' : 'Mode malam'}</span>`;
      button.setAttribute('aria-label', isNight ? 'Gunakan mode biasa' : 'Gunakan mode malam');
      button.setAttribute('aria-pressed', String(isNight));
      button.title = isNight ? 'Gunakan mode biasa' : 'Gunakan mode malam';
    }
  }

  function renderShell() {
    syncBrowserThemeColor('light');
    const dateText = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: appTimeZone }).format(new Date());
    const mobileItems = NAV_ITEMS.filter((item) => !item.admin || state.user?.role === 'admin').filter((item) => item.id !== 'members' || state.user?.role === 'admin');
    const mobileColumns = mobileItems.length;
    appRoot.innerHTML = `
      <div class="app-shell">
        <aside class="sidebar">
          ${brandMarkup()}
          <p class="side-label">Menu utama</p>
          <nav class="nav-list" aria-label="Navigasi utama">${NAV_ITEMS.map((item) => navLink(item)).join('')}<button class="nav-link${state.page === 'settings' ? ' active' : ''}" type="button" data-page="settings">${icon('settings')}<span>Pengaturan</span></button></nav>
          <div class="sidebar-spacer"></div>
          <div class="sidebar-tip"><span class="tip-spark">${icon('spark')}</span><strong>Rumah lebih terencana</strong><p>Catat sedikit demi sedikit. Biar akhir bulan nggak kaget.</p></div>
          <div class="sidebar-user"><span class="avatar">${escapeHtml(initials(state.user?.name))}</span><span class="user-meta"><strong>${escapeHtml(state.user?.name || 'Pengguna')}</strong><span>${state.user?.role === 'admin' ? 'Admin rumah' : 'Anggota rumah'}</span></span><button class="icon-button" data-action="logout" type="button" aria-label="Keluar">${icon('logout')}</button></div>
        </aside>
        <main class="main-area">
          <header class="topbar"><div class="topbar-left"><div><div class="topbar-kicker">Selamat datang di ruangmu</div><div class="topbar-title">${pageTitle()}</div></div></div><div class="topbar-actions"><span class="topbar-date">${escapeHtml(dateText)}</span><button class="icon-button" type="button" data-page="reminders" aria-label="Lihat pengingat">${icon('bell')}</button><button class="topbar-profile" type="button" data-page="settings"><span class="avatar avatar-lime">${escapeHtml(initials(state.user?.name))}</span><span>${escapeHtml(state.user?.name || 'Pengguna')}</span></button></div></header>
          <header class="mobile-topbar">${brandMarkup()}<div class="mobile-topbar-right"><button class="icon-button" type="button" data-page="reminders" aria-label="Lihat pengingat">${icon('bell')}</button><button class="topbar-profile" type="button" data-page="settings" aria-label="Pengaturan akun"><span class="avatar avatar-lime">${escapeHtml(initials(state.user?.name))}</span></button><button class="icon-button" type="button" data-action="logout" aria-label="Keluar">${icon('logout')}</button></div></header>
          <section class="page-content" id="page-content"><div class="skeleton" style="height:150px"></div></section>
        </main>
        <nav class="mobile-nav" style="--mobile-nav-columns:${mobileColumns}" aria-label="Navigasi mobile">${mobileItems.map((item) => navLink(item, true)).join('')}</nav>
      </div>`;
  }

  function loadingMarkup() {
    return `<div class="page-heading"><div><span class="eyebrow"><i class="eyebrow-dot"></i> rumah lebih tertata</span><h1>${escapeHtml(pageTitle())}</h1><p>Sebentar, kami siapkan ringkasanmu…</p></div></div><div class="grid stats-grid"><div class="card skeleton" style="height:130px"></div><div class="card skeleton" style="height:130px"></div><div class="card skeleton" style="height:130px"></div></div><div class="card skeleton" style="height:250px"></div>`;
  }

  async function render() {
    if (!state.user) {
      renderLogin();
      return;
    }
    if (state.page === 'members' && state.user.role !== 'admin') state.page = 'dashboard';
    renderShell();
    await refreshPage(true);
  }

  async function refreshPage(showLoading = false) {
    const content = document.getElementById('page-content');
    if (!content || !state.user) return;
    if (showLoading || !cacheReadyForPage()) content.innerHTML = loadingMarkup();
    try {
      if (state.page === 'dashboard') {
        state.dashboard = await api('dashboard');
      } else if (state.page === 'finance') {
        state.finance = await api(`transactions&month=${encodeURIComponent(state.financeMonth)}`);
      } else if (state.page === 'report') {
        const reportKey = reportRequestKey();
        state.report = await api(`report&${reportKey}`);
        state.reportKey = reportKey;
      } else if (state.page === 'reminders') {
        state.reminders = await api('reminders');
      } else if (state.page === 'members') {
        state.members = await api('users/list');
      } else if (state.page === 'settings') {
        state.settings = await api('settings/get');
      }
      if (state.dashboard && state.page !== 'dashboard') {
        // The compact reminder count in navigation should stay current without an extra request.
      }
      renderPageContent();
    } catch (error) {
      if (state.user) {
        content.innerHTML = `<div class="page-heading"><div><span class="eyebrow"><i class="eyebrow-dot"></i> ada kendala</span><h1>${escapeHtml(pageTitle())}</h1><p>${escapeHtml(error.message)}</p></div><button class="btn btn-secondary" data-action="retry" type="button">Coba lagi</button></div>`;
      }
    }
  }

  function reportRequestKey() {
    const params = new URLSearchParams({
      period: state.reportPeriod,
      kind: state.reportKind,
      compare: state.reportCompare ? '1' : '0',
    });
    if (state.reportCategory) params.set('category', state.reportCategory);
    if (state.reportPeriod === 'custom') {
      params.set('start', state.reportCustomStart);
      params.set('end', state.reportCustomEnd);
    }
    return params.toString();
  }

  function cacheReadyForPage() {
    return (state.page === 'dashboard' && !!state.dashboard) ||
      (state.page === 'finance' && !!state.finance && state.finance.month === state.financeMonth) ||
      (state.page === 'report' && !!state.report && state.reportKey === reportRequestKey()) ||
      (state.page === 'reminders' && !!state.reminders) ||
      (state.page === 'members' && !!state.members) ||
      (state.page === 'settings' && !!state.settings);
  }

  function syncReminderBadges() {
    const reminderRows = state.reminders?.reminders;
    const pending = state.dashboard?.reminder_counts?.pending ?? (Array.isArray(reminderRows) ? reminderRows.filter((item) => item.status === 'pending').length : 0);
    appRoot.querySelectorAll('.nav-link[data-page="reminders"]').forEach((button) => {
      let badge = button.querySelector('.nav-count');
      if (pending > 0 && !badge) {
        badge = document.createElement('span');
        badge.className = 'nav-count';
        button.appendChild(badge);
      } else if (pending === 0 && badge) {
        badge.remove();
      }
      if (badge) badge.textContent = pending > 9 ? '9+' : String(pending);
    });
  }

  function renderPageContent() {
    const content = document.getElementById('page-content');
    if (!content || !state.user) return;
    syncReminderBadges();
    if (state.page === 'dashboard') content.innerHTML = dashboardMarkup();
    else if (state.page === 'finance') content.innerHTML = financeMarkup();
    else if (state.page === 'report') content.innerHTML = reportMarkup();
    else if (state.page === 'reminders') content.innerHTML = remindersMarkup();
    else if (state.page === 'members') content.innerHTML = membersMarkup();
    else if (state.page === 'settings') content.innerHTML = settingsMarkup();
    bindPageLocalEvents(content);
  }

  function bindPageLocalEvents(content) {
    content.querySelectorAll('[data-kind-filter]').forEach((button) => button.addEventListener('click', () => {
      state.transactionFilter = button.dataset.kindFilter;
      renderPageContent();
    }));
    content.querySelectorAll('[data-reminder-filter]').forEach((button) => button.addEventListener('click', () => {
      state.reminderFilter = button.dataset.reminderFilter;
      refreshPage(true);
    }));
    const monthInput = content.querySelector('[data-month-filter]');
    if (monthInput) monthInput.addEventListener('change', () => {
      state.financeMonth = monthInput.value || currentMonth();
      state.finance = null;
      refreshPage(true);
    });
    content.querySelectorAll('[data-report-filter]').forEach((control) => control.addEventListener('change', () => {
      const filter = control.dataset.reportFilter;
      if (filter === 'period') state.reportPeriod = control.value;
      else if (filter === 'category') state.reportCategory = control.value;
      else if (filter === 'kind') state.reportKind = control.value;
      else if (filter === 'compare') state.reportCompare = control.checked;
      else if (filter === 'start') state.reportCustomStart = control.value;
      else if (filter === 'end') state.reportCustomEnd = control.value;

      const errorNode = content.querySelector('[data-report-error]');
      if (state.reportPeriod === 'custom' && (!state.reportCustomStart || !state.reportCustomEnd || state.reportCustomStart > state.reportCustomEnd)) {
        if (errorNode) {
          errorNode.textContent = !state.reportCustomStart || !state.reportCustomEnd
            ? 'Lengkapi tanggal awal dan akhir.'
            : 'Tanggal akhir tidak boleh sebelum tanggal awal.';
          errorNode.hidden = false;
        }
        return;
      }
      if (errorNode) errorNode.hidden = true;
      state.report = null;
      state.reportKey = '';
      refreshPage(true);
    }));
  }

  function dashboardMarkup() {
    const data = state.dashboard || {};
    const summary = data.summary || {};
    const monthName = monthLabel(data.month || currentMonth());
    const pending = Number(data.reminder_counts?.pending || 0);
    const target = Number(summary.target || 0);
    const spent = Number(summary.expense || 0);
    const progress = target ? Math.min(100, Number(summary.target_progress || 0)) : 0;
    const remaining = Math.max(0, target - spent);
    const chart = Array.isArray(data.chart) ? data.chart : [];
    const maxBar = Math.max(1, ...chart.flatMap((item) => [Number(item.income), Number(item.expense)]));
    const transactionRows = (data.transactions || []).map((item) => transactionRow(item, true)).join('');
    const reminderRows = (data.reminders || []).map((item) => dashboardReminderRow(item)).join('');
    const activePageHeading = `
      <div class="page-heading">
        <div><span class="eyebrow"><i class="eyebrow-dot"></i> ringkasan rumah</span><h1>${todayGreeting()}, ${escapeHtml((state.user?.name || 'kamu').split(' ')[0])} 👋</h1><p>Ini ringkasan keuangan dan agenda rumahmu untuk ${escapeHtml(monthName)}.</p></div>
        <div class="heading-actions"><button class="btn btn-secondary" type="button" data-action="open-transaction" data-kind="income">${icon('plus')} Catat transaksi</button><button class="btn" type="button" data-action="open-reminder">${icon('plus')} Buat pengingat</button></div>
      </div>`;
    const chartBars = chart.map((item) => {
      const incomeHeight = Math.max(0, Math.min(100, Number(item.income || 0) / maxBar * 100));
      const expenseHeight = Math.max(0, Math.min(100, Number(item.expense || 0) / maxBar * 100));
      const isCurrent = item.month === (data.month || currentMonth());
      const chartLabel = item.month ? new Intl.DateTimeFormat('id-ID', { month: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(Number(item.month.slice(0, 4)), Number(item.month.slice(5, 7)) - 1, 15))) : item.label;
      return `<div class="chart-month${isCurrent ? ' current' : ''}"><div class="chart-tooltip">${escapeHtml(chartLabel)} · ${money(item.income)} masuk · ${money(item.expense)} keluar</div><div class="chart-bars"><span class="chart-bar" style="height:${incomeHeight}%"></span><span class="chart-bar expense" style="height:${expenseHeight}%"></span></div><span class="chart-label">${escapeHtml(chartLabel)}</span></div>`;
    }).join('');

    return `${activePageHeading}
      <div class="grid stats-grid">
        <article class="card stat-card stat-card-primary"><div class="stat-top"><span class="stat-label">Saldo tercatat</span><span class="stat-icon">${icon('wallet')}</span></div><div class="stat-value">${money(summary.balance || 0)}</div><div class="stat-helper">Akumulasi dari seluruh catatan uang masuk dan keluar</div></article>
        <article class="card stat-card"><div class="stat-top"><span class="stat-label">Uang masuk · bulan ini</span><span class="stat-icon">${icon('arrowDown')}</span></div><div class="stat-value">${money(summary.income || 0)}</div><div class="stat-helper"><span class="stat-inline">${icon('calendar')} ${escapeHtml(monthName)}</span></div></article>
        <article class="card stat-card"><div class="stat-top"><span class="stat-label">Uang keluar · bulan ini</span><span class="stat-icon">${icon('arrowUp')}</span></div><div class="stat-value">${money(summary.expense || 0)}</div><div class="stat-helper"><span class="stat-inline">${icon('calendar')} ${escapeHtml(monthName)}</span></div></article>
      </div>
      <div class="grid dashboard-grid">
        <article class="card cashflow-card"><div class="card-head"><div><h2 class="card-title">Arus kas rumah</h2><p class="card-subtitle">Perbandingan uang masuk dan keluar, 6 bulan terakhir</p></div><button class="btn-link" type="button" data-page="finance">Lihat detail ${icon('arrowRight')}</button></div><div class="chart-legend"><span class="legend-item"><i class="legend-dot"></i> Uang masuk</span><span class="legend-item"><i class="legend-dot expense"></i> Uang keluar</span></div><div class="chart-area" role="img" aria-label="Grafik arus kas enam bulan">${chartBars || emptyState('Belum ada data', 'Transaksi yang kamu catat akan muncul di grafik ini.', 'chart')}</div></article>
        <div class="side-column">
          <article class="card target-card"><div class="target-head"><div><h2 class="card-title">Target pengeluaran</h2><p class="card-subtitle">${escapeHtml(monthName)}</p></div><span class="target-glyph">${icon('target')}</span></div>${target ? `<div class="target-amounts"><strong>${money(spent)}</strong><span>dari ${money(target)}</span></div><div class="target-progress"><span style="width:${progress}%"></span></div><div class="target-meta"><span>${spent > target ? 'Target terlampaui' : `${money(remaining)} tersisa`}</span><strong>${progress}%</strong></div>` : `<div class="target-empty">Pasang batas belanja bulanan supaya pengeluaran lebih terarah.</div><button class="btn-link" type="button" data-page="finance" style="margin-top:11px">Atur target ${icon('arrowRight')}</button>`}</article>
          <article class="card quick-card"><div class="card-head" style="padding:0"><div><h2 class="card-title">Catat cepat</h2><p class="card-subtitle">Biar nggak lupa nanti</p></div></div><div class="quick-actions"><button class="quick-action" type="button" data-action="open-transaction" data-kind="income"><span class="quick-action-icon">${icon('arrowDown')}</span><span>Uang<br>masuk</span></button><button class="quick-action" type="button" data-action="open-transaction" data-kind="expense"><span class="quick-action-icon">${icon('arrowUp')}</span><span>Uang<br>keluar</span></button><button class="quick-action" type="button" data-action="open-reminder"><span class="quick-action-icon">${icon('bell')}</span><span>Pengingat<br>baru</span></button><button class="quick-action" type="button" data-page="settings"><span class="quick-action-icon">${icon('settings')}</span><span>Atur<br>akun</span></button></div></article>
        </div>
      </div>
      <div class="grid lower-grid">
        <article class="card list-card"><div class="card-head"><div><h2 class="card-title">Transaksi terbaru</h2><p class="card-subtitle">Catatan keuangan bersama</p></div><button class="btn-link" type="button" data-page="finance">Semua transaksi ${icon('arrowRight')}</button></div><div class="transaction-list">${transactionRows || emptyState('Belum ada transaksi', 'Tambahkan catatan pertama untuk mulai melihat arus kas rumah.', 'receipt')}</div></article>
        <article class="card list-card"><div class="card-head"><div><h2 class="card-title">Pengingat mendatang</h2><p class="card-subtitle">${pending} agenda masih aktif</p></div><button class="btn-link" type="button" data-page="reminders">Lihat semua ${icon('arrowRight')}</button></div><div class="reminder-list">${reminderRows || emptyState('Agenda masih kosong', 'Buat pengingat kecil agar hal penting tidak terlewat.', 'bell')}</div></article>
      </div>`;
  }

  function transactionRow(item, compact = false) {
    const income = item.kind === 'income';
    const owner = Number(item.user_id) === Number(state.user?.id);
    const canDelete = state.user?.role === 'admin' || owner;
    const proof = item.attachment ? proofButton('transaction', item, { compact: true }) : '';
    return `<div class="transaction-row">
      <span class="transaction-glyph${income ? '' : ' expense'}">${icon(income ? 'arrowDown' : 'arrowUp')}</span>
      <span class="transaction-main"><strong>${escapeHtml(item.title)}</strong><small>${escapeHtml(item.category)} · ${escapeHtml(dateLabel(item.transaction_date, { day: 'numeric', month: 'short' }))}${!compact && item.created_by_name ? ` · ${escapeHtml(item.created_by_name)}` : ''}</small></span>
      ${proof}
      <span class="transaction-amount${income ? '' : ' expense'}">${income ? '+' : '−'} ${money(item.amount)}</span>
    </div>`;
  }

  function dashboardReminderRow(item) {
    const date = String(item.reminder_at || '').slice(0, 10);
    const [year, month, day] = date.split('-');
    const shortMonth = month ? new Intl.DateTimeFormat('id-ID', { month: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(Number(year), Number(month) - 1, 15))) : '';
    const allDay = Number(item.has_time ?? 1) === 0;
    const time = allDay ? 'Seharian' : String(item.reminder_at || '').slice(11, 16);
    const proof = item.attachment ? proofButton('reminder', item, { compact: true }) : '';
    return `<div class="reminder-row"><span class="date-tile"><small>${escapeHtml(shortMonth)}</small><strong>${escapeHtml(day || '—')}</strong></span><span class="reminder-main"><strong>${escapeHtml(item.title)}</strong><small>${escapeHtml(time)}${item.category ? ` · ${escapeHtml(item.category)}` : ''}</small></span>${proof}</div>`;
  }

  function financeMarkup() {
    const data = state.finance || { month: state.financeMonth, transactions: [], summary: { income: 0, expense: 0 }, target: 0 };
    const month = data.month || state.financeMonth;
    const records = (data.transactions || []).filter((item) => state.transactionFilter === 'all' || item.kind === state.transactionFilter);
    const rows = records.map((item) => {
      const income = item.kind === 'income';
      const canManage = canManageEntity('transaction', item);
      return `<tr><td><span class="table-title">${escapeHtml(item.title)}</span><span class="table-subtitle">${escapeHtml(item.note || item.created_by_name || ' ')}</span></td><td>${escapeHtml(item.category)}</td><td class="table-date">${escapeHtml(dateLabel(item.transaction_date, { day: 'numeric', month: 'short', year: 'numeric' }))}</td><td class="table-amount ${income ? 'income' : 'expense'}">${income ? '+' : '−'} ${money(item.amount)}</td><td class="table-actions-cell">${proofButton('transaction', item, { compact: true, alwaysShow: canManage })}${canManage ? `<button class="table-action edit" type="button" data-action="edit-transaction" data-id="${Number(item.id)}" aria-label="Ubah transaksi" title="Ubah transaksi">${icon('edit')}</button><button class="table-action" type="button" data-action="delete-transaction" data-id="${Number(item.id)}" aria-label="Hapus transaksi" title="Hapus transaksi">${icon('trash')}</button>` : ''}</td></tr>`;
    }).join('');
    const target = Number(data.target || 0);
    const spent = Number(data.summary?.expense || 0);
    const progress = target ? Math.min(100, Math.round(spent / target * 100)) : 0;
    return `<div class="page-heading"><div><span class="eyebrow"><i class="eyebrow-dot"></i> uang rumah</span><h1>Keuangan</h1><p>Semua uang masuk dan keluar, dicatat tanpa ribet.</p></div><div class="heading-actions finance-import-actions"><button class="btn btn-secondary" type="button" data-action="open-import" data-import-type="transactions">${icon('upload')} Import Excel</button><button class="btn btn-secondary" type="button" data-action="open-transaction" data-kind="income">${icon('plus')} Uang masuk</button><button class="btn" type="button" data-action="open-transaction" data-kind="expense">${icon('plus')} Uang keluar</button></div></div>
      <div class="finance-filter" style="margin:-5px 0 16px"><label class="sr-only" for="finance-month">Pilih bulan</label><input class="month-input" type="month" id="finance-month" data-month-filter value="${escapeHtml(month)}"><span class="text-muted" style="font-size:10px">${escapeHtml(monthLabel(month))}</span></div>
      <div class="grid finance-top-grid">
        <article class="card finance-metric"><span class="stat-label"><span class="legend-dot"></span> Total uang masuk</span><div class="stat-value" style="color:#328269">${money(data.summary?.income || 0)}</div></article>
        <article class="card finance-metric"><span class="stat-label"><span class="legend-dot expense"></span> Total uang keluar</span><div class="stat-value" style="color:#bd6e5c">${money(data.summary?.expense || 0)}</div></article>
        <article class="card finance-metric"><span class="stat-label">${icon('repeat')} Selisih bulan ini</span><div class="stat-value">${money(Number(data.summary?.income || 0) - Number(data.summary?.expense || 0))}</div></article>
      </div>
      <div class="grid finance-layout">
        <article class="card list-card"><div class="card-head" style="padding-bottom:14px"><div><h2 class="card-title">Catatan transaksi</h2><p class="card-subtitle">${records.length} transaksi · ${escapeHtml(monthLabel(month))}</p></div><div class="filter-tabs"><button class="filter-tab${state.transactionFilter === 'all' ? ' active' : ''}" type="button" data-kind-filter="all">Semua</button><button class="filter-tab${state.transactionFilter === 'income' ? ' active' : ''}" type="button" data-kind-filter="income">Masuk</button><button class="filter-tab${state.transactionFilter === 'expense' ? ' active' : ''}" type="button" data-kind-filter="expense">Keluar</button></div></div>
        ${records.length ? `<div class="table-wrap"><table class="data-table"><thead><tr><th>Transaksi</th><th>Kategori</th><th>Tanggal</th><th>Nominal</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>` : emptyState('Belum ada transaksi', 'Pilih bulan lain atau tambahkan transaksi pertamamu.', 'receipt')}</article>
        <aside class="card target-edit-card"><div class="card-head"><div><h2 class="card-title">Target pengeluaran</h2><p class="card-subtitle">Batas belanja untuk ${escapeHtml(monthLabel(month))}</p></div><span class="target-glyph">${icon('target')}</span></div>
          ${target ? `<div class="target-amounts"><strong>${money(spent)}</strong><span>dari ${money(target)}</span></div><div class="target-progress"><span style="width:${progress}%"></span></div><div class="target-meta"><span>${spent <= target ? `${money(target - spent)} masih tersedia` : `${money(spent - target)} di atas target`}</span><strong>${progress}%</strong></div>` : `<div class="target-empty">Tentukan batas pengeluaran agar rencana bulanan lebih terukur.</div>`}
          <form class="target-input-row" data-form="target"><input type="hidden" name="month" value="${escapeHtml(month)}"><div class="field"><label for="target-amount">Nominal target bulan ini</label><div class="input-wrap"><input class="input" id="target-amount" name="amount" type="number" min="1" step="1000" value="${target || ''}" placeholder="Contoh: 5000000" required></div></div><button class="btn btn-soft" type="submit" style="width:100%;margin-top:11px">${target ? 'Perbarui target' : 'Simpan target'}</button><p class="target-footnote">Persentase target dihitung dari jumlah uang keluar yang dicatat bulan ini.</p></form>
        </aside>
      </div>`;
  }

  function reportRangeLabel(range) {
    if (!range?.start || !range?.end) return '—';
    const start = dateLabel(range.start, { day: 'numeric', month: 'short', year: 'numeric' });
    const end = dateLabel(range.end, { day: 'numeric', month: 'short', year: 'numeric' });
    return range.start === range.end ? start : `${start} – ${end}`;
  }

  function reportPercentChange(current, previous) {
    const currentValue = Number(current || 0);
    const previousValue = Number(previous || 0);
    if (previousValue === 0) return currentValue === 0 ? '—' : 'Baru';
    const percent = (currentValue - previousValue) / Math.abs(previousValue) * 100;
    const prefix = percent > 0 ? '+' : '';
    return `${prefix}${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(percent)}%`;
  }

  function signedMoney(value) {
    const amount = Number(value || 0);
    return `${amount > 0 ? '+' : amount < 0 ? '−' : ''}${money(Math.abs(amount))}`;
  }

  function reportCategoryValue(item, kind) {
    if (!item) return 0;
    if (kind === 'income') return Number(item.income || 0);
    return Number(item.expense || 0);
  }

  function reportShare(part, total) {
    const amount = Number(part || 0);
    const whole = Number(total || 0);
    if (whole <= 0 || amount <= 0) return '0%';
    return `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(amount / whole * 100)}%`;
  }

  function reportInsightItems(data) {
    const items = [];
    const summary = data.summary || {};
    const previous = data.previous_summary;
    const currentPeriod = data.current_period || {};
    const days = Number(currentPeriod.days || 0);
    const activeDays = Number(currentPeriod.active_days || 0);
    const categories = Array.isArray(data.categories) ? data.categories : [];
    const kind = state.reportKind === 'income' ? 'income' : 'expense';
    const kindLabel = kind === 'income' ? 'uang masuk' : 'uang keluar';
    if (state.reportCompare && previous) {
      const movers = categories.map((item) => {
        const currentValue = reportCategoryValue(item, kind);
        const previousValue = reportCategoryValue(item.previous, kind);
        return { name: item.category || 'Tanpa kategori', currentValue, previousValue, difference: currentValue - previousValue };
      }).filter((item) => item.difference !== 0);
      const up = [...movers].sort((a, b) => b.difference - a.difference)[0];
      const down = [...movers].sort((a, b) => a.difference - b.difference)[0];
      if (up && up.difference > 0) items.push(`${up.name} paling naik: ${signedMoney(up.difference)} (${reportPercentChange(up.currentValue, up.previousValue)}) dibanding periode sepadan.`);
      if (down && down.difference < 0 && (!up || down.name !== up.name)) items.push(`${down.name} paling turun: ${signedMoney(down.difference)} (${reportPercentChange(down.currentValue, down.previousValue)}).`);
    }
    if (data.weekday_summary && kind === 'expense') {
      const weekdaySpend = Number(data.weekday_summary.expense || 0);
      const todaySpend = Number(summary.expense || 0);
      items.push(`Hari yang sama minggu lalu ${money(weekdaySpend)}; hari ini ${money(todaySpend)} (${reportPercentChange(todaySpend, weekdaySpend)}).`);
    }
    const projection = data.projection;
    if (projection && kind !== 'income') {
      items.push(`Kalau laju ${money(projection.daily_expense)} per hari berlanjut, uang keluar bulan ini sekitar ${money(projection.projected_expense)}${projection.target ? ` dari target ${money(projection.target)}` : ''}.`);
    }
    if (days > 3 && activeDays < Math.ceil(days * 0.5)) {
      items.push(`Baru ${activeDays} dari ${days} hari ada catatan. Rata-rata harian bisa terasa lebih kecil jika banyak hari belum dicatat.`);
    }
    const total = kind === 'income' ? Number(summary.income || 0) : Number(summary.expense || 0);
    const top = [...categories].sort((a, b) => reportCategoryValue(b, kind) - reportCategoryValue(a, kind))[0];
    if (top && total > 0 && !state.reportCategory) {
      items.push(`${top.category} mengisi ${reportShare(reportCategoryValue(top, kind), total)} ${kindLabel} pada periode ini.`);
    }
    return items.slice(0, 4);
  }

  function reportComparisonMetric(label, current, previous, formatter = money, invert = false) {
    const currentValue = Number(current || 0);
    const previousValue = Number(previous || 0);
    const difference = currentValue - previousValue;
    const trend = difference === 0 ? 'flat' : ((difference > 0) === !invert) ? 'down' : 'up';
    const formattedDifference = formatter === money
      ? signedMoney(difference)
      : `${difference > 0 ? '+' : difference < 0 ? '−' : ''}${new Intl.NumberFormat('id-ID').format(Math.abs(difference))}`;
    return `<article class="report-comparison-metric"><span>${escapeHtml(label)}</span><strong class="${trend}">${formattedDifference}</strong><small>${formatter(previousValue)} → ${formatter(currentValue)} <b>${escapeHtml(reportPercentChange(currentValue, previousValue))}</b></small></article>`;
  }

  function reportMarkup() {
    const data = state.report || {};
    const summary = data.summary || { income: 0, expense: 0, net: 0, count: 0 };
    const previous = data.previous_summary;
    const currentPeriod = data.current_period || {};
    const previousPeriod = data.previous_period;
    const categoryOptions = Array.isArray(data.category_options) ? [...data.category_options] : [];
    if (state.reportCategory && !categoryOptions.includes(state.reportCategory)) categoryOptions.push(state.reportCategory);
    categoryOptions.sort((a, b) => a.localeCompare(b, 'id'));
    const categoryOptionMarkup = categoryOptions.map((category) => `<option value="${escapeHtml(category)}"${state.reportCategory === category ? ' selected' : ''}>${escapeHtml(category)}</option>`).join('');
    const customRangeMarkup = state.reportPeriod === 'custom' ? `<div class="report-custom-range"><div class="field"><label for="report-start">Dari tanggal</label><input class="input" id="report-start" type="date" data-report-filter="start" value="${escapeHtml(state.reportCustomStart)}" required></div><span class="report-range-separator">sampai</span><div class="field"><label for="report-end">Sampai tanggal</label><input class="input" id="report-end" type="date" data-report-filter="end" value="${escapeHtml(state.reportCustomEnd)}" required></div></div>` : '';
    const currentRange = reportRangeLabel(currentPeriod);
    const previousRange = reportRangeLabel(previousPeriod);
    const averageDailySpend = Number(currentPeriod.days) > 0 ? Number(summary.expense || 0) / Number(currentPeriod.days) : 0;
    const previousAverageDailySpend = Number(previousPeriod?.days) > 0 ? Number(previous?.expense || 0) / Number(previousPeriod.days) : 0;
    const measureKind = state.reportKind === 'income' ? 'income' : 'expense';
    const measureTotal = measureKind === 'income' ? Number(summary.income || 0) : Number(summary.expense || 0);
    const categories = (Array.isArray(data.categories) ? [...data.categories] : []).sort((a, b) => reportCategoryValue(b, measureKind) - reportCategoryValue(a, measureKind));
    const categoryMax = Math.max(1, ...categories.map((item) => reportCategoryValue(item, measureKind)));
    const categoryRows = categories.map((item) => {
      const currentValue = reportCategoryValue(item, measureKind);
      const previousValue = reportCategoryValue(item.previous, measureKind);
      const width = Math.max(3, Math.min(100, currentValue / categoryMax * 100));
      const categoryName = item.category || 'Tanpa kategori';
      const share = reportShare(currentValue, measureTotal);
      const extraKind = state.reportKind === 'all' && Number(item.income || 0) > 0 ? ` · masuk ${money(item.income)}` : '';
      const previousDetail = state.reportCompare
        ? `<small>${share} porsi · sebelumnya ${money(previousValue)} <b>${signedMoney(currentValue - previousValue)} · ${escapeHtml(reportPercentChange(currentValue, previousValue))}</b></small>`
        : `<small>${share} porsi · ${Number(item.count || 0)} transaksi${extraKind}</small>`;
      return `<button class="report-category-row" type="button" data-action="report-pick-category" data-category="${escapeHtml(categoryName)}"><div class="report-category-main"><div class="report-category-name"><strong>${escapeHtml(categoryName)}</strong><span>${Number(item.count || 0)} transaksi${extraKind}</span></div><div class="report-category-bar"><span style="width:${width}%"></span></div></div><div class="report-category-amount"><strong>${money(currentValue)}</strong>${previousDetail}</div></button>`;
    }).join('');
    const categoryMeasureLabel = measureKind === 'income' ? 'Uang masuk' : 'Uang keluar';
    const weekdayMarkup = data.weekday_summary ? `<article class="report-comparison-metric"><span>Vs hari yang sama minggu lalu</span><strong class="${Number(summary.expense || 0) > Number(data.weekday_summary.expense || 0) ? 'up' : Number(summary.expense || 0) < Number(data.weekday_summary.expense || 0) ? 'down' : 'flat'}">${signedMoney(Number(summary.expense || 0) - Number(data.weekday_summary.expense || 0))}</strong><small>${money(data.weekday_summary.expense || 0)} → ${money(summary.expense || 0)} <b>${escapeHtml(reportPercentChange(summary.expense, data.weekday_summary.expense))}</b></small></article>` : '';
    const comparisonMarkup = state.reportCompare && previous ? `<section class="card report-comparison-card"><div class="card-head"><div><h2 class="card-title">Perbandingan periode</h2><p class="card-subtitle">${escapeHtml(currentRange)} <span>vs</span> ${escapeHtml(previousRange)} · ${Number(currentPeriod.days || 0)} hari dibanding ${Number(previousPeriod?.days || 0)} hari</p></div><span class="report-comparison-icon">${icon('repeat')}</span></div><div class="report-comparison-grid">${reportComparisonMetric('Uang masuk', summary.income, previous.income)}${reportComparisonMetric('Uang keluar', summary.expense, previous.expense, money, true)}${reportComparisonMetric('Selisih tercatat', summary.net, previous.net)}${reportComparisonMetric('Jumlah transaksi', summary.count, previous.count, (value) => new Intl.NumberFormat('id-ID').format(value))}${weekdayMarkup}</div><p class="report-accuracy-note">${state.reportPeriod === 'custom' ? 'Rentang pembanding memakai jumlah hari yang sama tepat sebelum tanggal awal.' : 'Perbandingan memakai rentang yang sepadan; bulan/tahun berjalan dibanding sampai tanggal yang sama.'} Rata-rata uang keluar per hari kalender: <strong>${money(averageDailySpend)}</strong> (sebelumnya ${money(previousAverageDailySpend)}). Catatan ada di ${Number(currentPeriod.active_days || 0)} dari ${Number(currentPeriod.days || 0)} hari.</p></section>` : '';
    const insightItems = reportInsightItems(data);
    const insightMarkup = insightItems.length ? `<section class="card report-insight-card"><div class="card-head"><div><h2 class="card-title">Yang perlu dilihat</h2><p class="card-subtitle">Ringkasan otomatis dari porsi kategori, perbandingan, dan kelengkapan catatan</p></div><span class="report-comparison-icon">${icon('spark')}</span></div><ul class="report-insight-list">${insightItems.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul></section>` : '';
    const projection = data.projection;
    const projectionMarkup = projection ? `<section class="card report-projection-card"><div class="card-head"><div><h2 class="card-title">Proyeksi sisa bulan</h2><p class="card-subtitle">${projection.days_elapsed} hari berjalan · ${projection.days_remaining} hari tersisa</p></div><span class="report-comparison-icon">${icon('target')}</span></div><div class="report-projection-grid"><article><span>Laju harian</span><strong>${money(projection.daily_expense)}</strong></article><article><span>Perkiraan akhir bulan</span><strong>${money(projection.projected_expense)}</strong></article><article><span>${projection.target ? 'Sisa vs target' : 'Target bulan ini'}</span><strong>${projection.target ? money(projection.target - projection.projected_expense) : 'Belum dipasang'}</strong></article></div><p class="report-accuracy-note">Proyeksi memakai rata-rata uang keluar per hari kalender sampai hari ini, bukan hanya hari yang ada transaksinya.</p></section>` : '';
    const highlights = Array.isArray(data.highlights) ? data.highlights : [];
    const highlightMarkup = highlights.length ? `<section class="card report-highlight-card"><div class="card-head"><div><h2 class="card-title">Transaksi terbesar</h2><p class="card-subtitle">Membantu melihat lonjakan yang menggerakkan total</p></div><span class="report-category-icon">${icon('receipt')}</span></div><div class="report-highlight-list">${highlights.map((item) => `<div class="report-highlight-row"><span class="transaction-glyph${item.kind === 'income' ? '' : ' expense'}">${icon(item.kind === 'income' ? 'arrowDown' : 'arrowUp')}</span><span class="transaction-main"><strong>${escapeHtml(item.title)}</strong><small>${escapeHtml(item.category)} · ${escapeHtml(dateLabel(item.transaction_date, { day: 'numeric', month: 'short' }))}</small></span><span class="transaction-amount${item.kind === 'income' ? '' : ' expense'}">${item.kind === 'income' ? '+' : '−'} ${money(item.amount)}</span></div>`).join('')}</div></section>` : '';

    return `<div class="page-heading report-heading"><div><span class="eyebrow"><i class="eyebrow-dot"></i> analisis rumah</span><h1>Laporan keuangan</h1><p>Telusuri uang masuk dan keluar berdasarkan kategori, lalu bandingkan dengan periode yang sepadan.</p></div><div class="heading-actions"><button class="btn btn-secondary" type="button" data-action="download-report"${data.summary ? '' : ' disabled'}>${icon('download')} Unduh CSV</button></div></div>
      <section class="card report-filter-card"><div class="report-filter-grid"><div class="field"><label for="report-period">Periode laporan</label><select class="select" id="report-period" data-report-filter="period"><option value="today"${state.reportPeriod === 'today' ? ' selected' : ''}>Hari ini</option><option value="month_to_date"${state.reportPeriod === 'month_to_date' ? ' selected' : ''}>Bulan ini sampai hari ini</option><option value="last_month"${state.reportPeriod === 'last_month' ? ' selected' : ''}>Bulan lalu</option><option value="year_to_date"${state.reportPeriod === 'year_to_date' ? ' selected' : ''}>Tahun ini sampai hari ini</option><option value="last_year"${state.reportPeriod === 'last_year' ? ' selected' : ''}>Tahun lalu</option><option value="custom"${state.reportPeriod === 'custom' ? ' selected' : ''}>Rentang tanggal khusus</option></select></div><div class="field"><label for="report-kind">Jenis transaksi</label><select class="select" id="report-kind" data-report-filter="kind"><option value="all"${state.reportKind === 'all' ? ' selected' : ''}>Semua jenis</option><option value="income"${state.reportKind === 'income' ? ' selected' : ''}>Uang masuk</option><option value="expense"${state.reportKind === 'expense' ? ' selected' : ''}>Uang keluar</option></select></div><div class="field"><label for="report-category">Kategori</label><select class="select" id="report-category" data-report-filter="category"><option value="">Semua kategori</option>${categoryOptionMarkup}</select></div></div>${customRangeMarkup}<div class="report-filter-footer"><label class="report-compare-toggle"><input type="checkbox" data-report-filter="compare"${state.reportCompare ? ' checked' : ''}><span class="report-toggle-track" aria-hidden="true"></span><span>Bandingkan dengan periode sebelumnya</span></label><span class="report-period-meta">${escapeHtml(currentPeriod.label || 'Memuat periode…')} · ${escapeHtml(currentRange)}${state.reportCompare && previousPeriod ? ` <span>dibanding</span> ${escapeHtml(previousRange)}` : ''}</span></div><p class="error-text report-filter-error" data-report-error hidden></p></section>
      <div class="grid report-summary-grid"><article class="card report-stat-card"><span class="report-stat-label"><i class="legend-dot"></i> Uang masuk</span><strong>${money(summary.income)}</strong><small>${Number(summary.income_count || 0)} transaksi masuk</small></article><article class="card report-stat-card"><span class="report-stat-label"><i class="legend-dot expense"></i> Uang keluar</span><strong>${money(summary.expense)}</strong><small>Rata-rata ${money(averageDailySpend)} / hari kalender</small></article><article class="card report-stat-card"><span class="report-stat-label">Selisih tercatat</span><strong class="${Number(summary.net || 0) < 0 ? 'negative' : ''}">${money(summary.net)}</strong><small>Masuk dikurangi keluar</small></article><article class="card report-stat-card"><span class="report-stat-label">Hari tercatat</span><strong>${Number(currentPeriod.active_days || 0)}</strong><small>dari ${Number(currentPeriod.days || 0)} hari periode · ${Number(summary.count || 0)} transaksi</small></article></div>
      ${insightMarkup}
      ${comparisonMarkup}
      ${projectionMarkup}
      <section class="card report-category-card"><div class="card-head"><div><h2 class="card-title">Rekap per kategori</h2><p class="card-subtitle">${escapeHtml(categoryMeasureLabel)} · porsi terhadap total${state.reportCategory ? ` · ${escapeHtml(state.reportCategory)}` : ''}${state.reportCompare ? ' · dibanding periode sepadan' : ''} · ketuk kategori untuk menyaring</p></div><span class="report-category-icon">${icon('chart')}</span></div><div class="report-category-list">${categoryRows || emptyState('Belum ada transaksi di periode ini', 'Coba ubah periode atau kategori, atau catat transaksi baru.', 'receipt')}</div><p class="report-accuracy-note">Kategori yang penulisannya beda huruf (Bensin/bensin) digabung. Rekap memakai uang keluar, kecuali filter uang masuk. Akurasi mengikuti tanggal yang dicatat.</p></section>
      ${highlightMarkup}`;
  }

  function downloadReportCsv() {
    const data = state.report;
    if (!data?.summary) return;
    const current = data.summary;
    const previous = data.previous_summary;
    const comparing = state.reportCompare && previous;
    const headers = ['Kategori', 'Jumlah transaksi', 'Uang masuk', 'Uang keluar', 'Selisih tercatat', 'Porsi keluar'];
    if (comparing) headers.push('Transaksi pembanding', 'Masuk pembanding', 'Keluar pembanding', 'Selisih pembanding');
    const rows = [
      ['Periode laporan', data.current_period?.label || ''],
      ['Tanggal saat ini', reportRangeLabel(data.current_period)],
      ...(comparing ? [['Tanggal pembanding', reportRangeLabel(data.previous_period)]] : []),
      ['Jenis transaksi', state.reportKind === 'all' ? 'Semua jenis' : state.reportKind === 'income' ? 'Uang masuk' : 'Uang keluar'],
      ['Kategori filter', state.reportCategory || 'Semua kategori'],
      [],
      headers,
    ];
    for (const item of data.categories || []) {
      const prior = item.previous || { income: 0, expense: 0, count: 0 };
      const row = [item.category || 'Tanpa kategori', Number(item.count || 0), Number(item.income || 0), Number(item.expense || 0), Number(item.income || 0) - Number(item.expense || 0), reportShare(item.expense, current.expense)];
      if (comparing) row.push(Number(prior.count || 0), Number(prior.income || 0), Number(prior.expense || 0), Number(prior.income || 0) - Number(prior.expense || 0));
      rows.push(row);
    }
    const totalRow = ['TOTAL', Number(current.count || 0), Number(current.income || 0), Number(current.expense || 0), Number(current.net || 0)];
    if (comparing) totalRow.push(Number(previous.count || 0), Number(previous.income || 0), Number(previous.expense || 0), Number(previous.net || 0));
    rows.push(totalRow);
    const csv = `\uFEFF${rows.map((row) => row.map((value) => {
      let text = String(value ?? '');
      if (typeof value !== 'number' && /^[\s]*[=+\-@]/.test(text)) text = `'${text}`;
      return `"${text.replace(/"/g, '""')}"`;
    }).join(';')).join('\r\n')}`;
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `laporan-keuangan-${state.reportPeriod}-${localDateValue()}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function remindersMarkup() {
    const data = state.reminders || { reminders: [] };
    const allReminders = data.reminders || [];
    const pending = allReminders.filter((item) => item.status === 'pending').length;
    const done = allReminders.filter((item) => item.status === 'done').length;
    const reminders = state.reminderFilter === 'all' ? allReminders : allReminders.filter((item) => item.status === state.reminderFilter);
    const total = allReminders.length;
    const rows = reminders.map((item) => reminderItem(item)).join('');
    const tab = (id, label, count) => `<button class="filter-tab${state.reminderFilter === id ? ' active' : ''}" type="button" data-reminder-filter="${id}">${label}${count !== null ? ` · ${count}` : ''}</button>`;
    return `<div class="page-heading"><div><span class="eyebrow"><i class="eyebrow-dot"></i> agenda rumah</span><h1>Pengingat</h1><p>Biar hal kecil yang penting nggak kelewat.</p></div><div class="heading-actions"><button class="btn btn-secondary" type="button" data-action="open-import" data-import-type="reminders">${icon('upload')} Import Excel</button><button class="btn" type="button" data-action="open-reminder">${icon('plus')} Buat pengingat</button></div></div>
      <div class="grid reminder-summary-grid">
        <article class="card reminder-stat"><span class="reminder-stat-icon">${icon('bell')}</span><span><strong>${pending}</strong><span>Pengingat aktif</span></span></article>
        <article class="card reminder-stat"><span class="reminder-stat-icon blue">${icon('calendar')}</span><span><strong>${reminders.filter((item) => item.status === 'pending' && String(item.reminder_at) >= `${localDateValue()} 00:00:00`).length}</strong><span>Agenda berikutnya</span></span></article>
        <article class="card reminder-stat"><span class="reminder-stat-icon coral">${icon('check')}</span><span><strong>${done}</strong><span>Selesai · ${total} total</span></span></article>
      </div>
      <article class="card"><div class="reminder-toolbar"><div><h2 class="card-title">Daftar pengingat</h2><p class="card-subtitle">Agenda yang dibuat keluarga · bisa diubah, dikirim ulang ke Telegram, atau ditandai selesai</p></div><div class="filter-tabs">${tab('all', 'Semua', null)}${tab('pending', 'Aktif', pending)}${tab('done', 'Selesai', done)}</div></div><div class="reminder-list-page">${rows || emptyState(state.reminderFilter === 'done' ? 'Belum ada yang selesai' : 'Belum ada pengingat', 'Tambahkan agenda seperti ganti oli, bayar tagihan, atau servis rumah.', 'bell')}</div></article>`;
  }

  function reminderItem(item) {
    const dateTime = String(item.reminder_at || '').replace('T', ' ');
    const date = dateTime.slice(0, 10);
    const allDay = Number(item.has_time ?? 1) === 0;
    const time = allDay ? '' : dateTime.slice(11, 16);
    const [year, month, day] = date.split('-').map(Number);
    const monthShort = month ? new Intl.DateTimeFormat('id-ID', { month: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(year, month - 1, 15))) : '';
    const isDone = item.status === 'done';
    // Pengingat sepanjang hari baru disebut lewat jadwal setelah tanggalnya berlalu.
    const isOverdue = !isDone && (allDay ? date < localDateValue() : `${date} ${time}` < `${localDateValue()} ${localDateTimeValue().slice(11)}`);
    const canManage = canManageEntity('reminder', item);
    const proof = proofButton('reminder', item, { compact: true, alwaysShow: canManage });
    const thumb = item.attachment ? `<button class="reminder-thumb" type="button" data-action="open-proof" data-entity="reminder" data-id="${Number(item.id)}" aria-label="Lihat bukti gambar"><img src="${escapeHtml(item.attachment.url)}" alt="Bukti ${escapeHtml(item.title)}" loading="lazy"></button>` : '';
    const sendButton = `<button class="send-button" type="button" data-action="send-reminder" data-id="${Number(item.id)}" aria-label="Kirim pengingat ini ke Telegram sekarang" title="Kirim ke Telegram sekarang">${icon('send')}</button>`;
    const editButton = canManage ? `<button class="edit-button" type="button" data-action="edit-reminder" data-id="${Number(item.id)}" aria-label="Ubah pengingat" title="Ubah pengingat">${icon('edit')}</button>` : '';
    return `<div class="reminder-item"><span class="reminder-date-box"><small>${escapeHtml(monthShort)}</small><strong>${escapeHtml(day || '—')}</strong><span${allDay ? ' class="all-day"' : ''}>${escapeHtml(allDay ? 'Seharian' : time)}</span></span>${thumb}<span class="reminder-copy"><h3>${escapeHtml(item.title)}</h3>${item.note ? `<p>${escapeHtml(item.note)}</p>` : ''}<span class="reminder-meta"><span class="tag">${escapeHtml(item.category || 'Lainnya')}</span><span class="byline">Dibuat ${escapeHtml(item.created_by_name || 'anggota rumah')}</span>${allDay ? '<span class="status-pill status-allday">Tanpa jam</span>' : ''}${isDone ? '<span class="status-pill status-done">Selesai</span>' : isOverdue ? '<span class="status-pill status-overdue">Lewat jadwal</span>' : '<span class="status-pill">Akan datang</span>'}</span></span><span class="reminder-actions">${proof}${sendButton}${editButton}<button class="check-button" type="button" data-action="toggle-reminder" data-id="${Number(item.id)}" aria-label="${isDone ? 'Aktifkan lagi' : 'Tandai selesai'}">${icon(isDone ? 'repeat' : 'check')}</button>${canManage ? `<button class="delete-button" type="button" data-action="delete-reminder" data-id="${Number(item.id)}" aria-label="Hapus pengingat">${icon('trash')}</button>` : ''}</span></div>`;
  }

  function membersMarkup() {
    const members = state.members?.users || [];
    const rows = members.map((user) => `<tr><td><span class="member-cell"><span class="avatar${user.role === 'admin' ? ' avatar-lime' : ''}">${escapeHtml(initials(user.name))}</span><span><span class="table-title">${escapeHtml(user.name)}</span><span class="table-subtitle">@${escapeHtml(user.username)}</span></span></span></td><td><span class="role-pill${user.role === 'admin' ? ' admin' : ''}">${user.role === 'admin' ? 'Admin' : 'Anggota'}</span></td><td class="table-date">${escapeHtml(dateLabel(String(user.created_at || '').slice(0, 10), { day: 'numeric', month: 'short', year: 'numeric' }))}</td><td>${user.role === 'member' ? `<button class="btn btn-secondary btn-small" type="button" data-action="reset-member-password" data-id="${Number(user.id)}" data-name="${escapeHtml(user.name)}">Atur ulang sandi</button>` : '<span class="text-muted" style="font-size:9px">Akun utama</span>'}</td></tr>`).join('');
    return `<div class="page-heading"><div><span class="eyebrow"><i class="eyebrow-dot"></i> akses keluarga</span><h1>Anggota rumah</h1><p>Kelola siapa saja yang bisa mencatat keuangan dan agenda bersama.</p></div><div class="heading-actions"><button class="btn" type="button" data-action="open-user">${icon('plus')} Tambah anggota</button></div></div>
      <div class="grid members-grid"><article class="card list-card"><div class="card-head" style="padding-bottom:14px"><div><h2 class="card-title">Akun yang terdaftar</h2><p class="card-subtitle">${members.length} akun · catatan dibagikan bersama</p></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Nama pengguna</th><th>Peran</th><th>Bergabung</th><th></th></tr></thead><tbody>${rows}</tbody></table></div></article><aside class="card members-note"><span class="target-glyph">${icon('shield')}</span><h3>Data satu rumah</h3><p>Anggota yang kamu tambahkan akan berbagi daftar transaksi, target pengeluaran, dan pengingat yang sama. Setiap catatan tetap menampilkan siapa yang membuatnya.</p><button class="btn-link" type="button" data-page="settings" style="margin-top:13px">Buka pengaturan keamanan ${icon('arrowRight')}</button></aside></div>`;
  }

  function settingsMarkup() {
    const telegram = state.settings?.telegram || { connected: false, chat_id: '', token_hint: '' };
    const isAdmin = state.user?.role === 'admin';
    const database = state.settings?.database || null;
    const backupPanel = database ? `
        <section class="card settings-card settings-backup"><div class="card-head"><div><h2 class="card-title">Backup &amp; database</h2><p class="settings-description">Semua catatan tersimpan permanen di database ${escapeHtml(database.driver)}.</p></div><span class="target-glyph">${icon('download')}</span></div>
          <div class="backup-stats">
            <article><span>Transaksi</span><strong>${Number(database.tables?.transactions || 0)}</strong></article>
            <article><span>Pengingat</span><strong>${Number(database.tables?.reminders || 0)}</strong></article>
            <article><span>Bukti gambar</span><strong>${Number(database.attachment_count || 0)}</strong></article>
            <article><span>Ukuran bukti</span><strong>${escapeHtml(database.attachment_size || '0 B')}</strong></article>
          </div>
          <p class="settings-description backup-note">${database.file ? `File database lokal: <code>${escapeHtml(database.file)}</code>. ` : ''}Total ${Number(database.total_rows || 0)} baris data siap dicek kapan saja. Unduh file <code>.sql</code> untuk menyimpan salinan atau memindahkannya ke hosting lain.</p>
          <div class="settings-actions"><a class="btn" href="/api.php?action=backup/download" download>${icon('download')} Unduh backup .sql</a></div>
          <div class="security-tip"><strong>Catatan:</strong> file backup memuat seluruh catatan keuangan, pengingat, dan gambar bukti. Simpan di tempat aman dan jangan dibagikan. Struktur tabel untuk phpMyAdmin juga tersedia di folder <code>database/</code>.</div>
        </section>` : '';
    const telegramPanel = isAdmin ? `
        <section class="card settings-card"><div class="card-head"><div><h2 class="card-title">Notifikasi Telegram</h2><p class="settings-description">Kabar langsung saat pengingat dibuat.</p></div><span class="target-glyph">${icon('send')}</span></div><div class="connection-banner${telegram.connected ? '' : ' disconnected'}"><i class="connection-led"></i><span>${telegram.connected ? `Bot tersambung${telegram.token_hint ? ` · ${escapeHtml(telegram.token_hint)}` : ''}` : 'Belum tersambung · pengingat tetap disimpan di website'}</span></div><form data-form="telegram-save" class="form-grid"><div class="field full-span"><label for="telegram-token">Token bot</label><input class="input" id="telegram-token" name="token" type="password" autocomplete="off" placeholder="${telegram.connected ? 'Kosongkan untuk memakai token tersimpan' : 'Contoh: 123456789:AA...'}"></div><div class="field full-span"><label for="telegram-chat-id">Chat ID</label><input class="input" id="telegram-chat-id" name="chat_id" type="text" inputmode="numeric" value="${escapeHtml(telegram.chat_id || '')}" placeholder="Kirim /start ke bot, lalu cari Chat ID"></div><p class="form-note full-span">Token hanya disimpan di server dan tidak pernah dikirim kembali ke browser.</p><div class="full-span"><p class="error-text" data-form-error hidden></p><div class="settings-actions"><button class="btn" type="submit">${icon('check')} Simpan & hubungkan</button>${telegram.connected ? `<button class="btn btn-secondary" type="button" data-action="telegram-test">${icon('send')} Tes bot</button><button class="btn btn-secondary" type="button" data-action="reminder-test">${icon('bell')} Tes kirim pengingat</button><button class="btn btn-danger" type="button" data-action="telegram-disconnect">Lepas bot</button>` : ''}<button class="btn btn-secondary" type="button" data-action="telegram-discover">Cari Chat ID</button></div></div></form><ol class="steps-list"><li>Buat bot baru di <strong>@BotFather</strong> Telegram, lalu salin token-nya.</li><li>Buka chat bot buatanmu, kirim <code>/start</code>, masukkan token di atas, lalu pilih <strong>Cari Chat ID</strong>.</li><li>Simpan koneksi. Pesan percobaan dan kabar pengingat akan dikirim ke Telegram.</li><li>Uji bentuk kabarnya dengan <strong>Tes kirim pengingat</strong>. Untuk pengingat tertentu, pakai ikon kirim pada daftar <strong>Pengingat</strong> supaya langsung diteruskan ke Telegram.</li></ol><div class="security-tip"><strong>Notifikasi tepat waktu:</strong> untuk kabar otomatis saat tanggal/jam jatuh tempo, aktifkan tugas terjadwal ke <code>/cron.php?key=...</code> setiap 5–15 menit. Panduannya ada di README. Kalau hosting belum menyediakan cron, kirim manual dari daftar pengingat.</div></section>` : `
        <section class="card settings-card"><div class="card-head"><div><h2 class="card-title">Notifikasi Telegram</h2><p class="settings-description">Status koneksi bot untuk pengingat rumah.</p></div><span class="target-glyph">${icon('send')}</span></div><div class="connection-banner${telegram.connected ? '' : ' disconnected'}"><i class="connection-led"></i><span>${telegram.connected ? 'Bot rumah sudah tersambung.' : 'Bot Telegram belum tersambung.'}</span></div><p class="settings-description" style="margin-top:13px">Pengaturan bot dikelola admin rumah. Kamu tetap bisa membuat pengingat; semuanya terlihat di daftar bersama.</p></section>`;
    return `<div class="page-heading"><div><span class="eyebrow"><i class="eyebrow-dot"></i> kelola akun</span><h1>Pengaturan</h1><p>Jaga akun tetap aman${isAdmin ? ' dan sambungkan pengingat ke Telegram' : ''}.</p></div></div>
      <div class="grid settings-grid"${isAdmin ? '' : ' style="grid-template-columns:minmax(0,680px)"'}>
        <section class="card settings-card"><div class="card-head"><div><h2 class="card-title">Keamanan akun</h2><p class="settings-description">Sedang masuk sebagai <strong>${escapeHtml(state.user?.name)}</strong> (@${escapeHtml(state.user?.username)}).</p></div><span class="target-glyph">${icon('lock')}</span></div><form data-form="password-change" class="form-grid"><div class="field full-span"><label for="current-password">Kata sandi saat ini</label><input class="input" id="current-password" name="current_password" type="password" autocomplete="current-password" required></div><div class="field full-span"><label for="new-password">Kata sandi baru</label><input class="input" id="new-password" name="new_password" type="password" minlength="8" autocomplete="new-password" placeholder="Minimal 8 karakter" required></div><div class="field full-span"><label for="confirm-password">Ulangi kata sandi baru</label><input class="input" id="confirm-password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required></div><div class="full-span"><p class="error-text" data-form-error hidden></p><button class="btn btn-soft" type="submit">${icon('shield')} Ganti kata sandi</button></div></form><div class="security-tip"><strong>Saran:</strong> gunakan kata sandi unik dan jangan membagikan akun admin. Kata sandi disimpan dalam bentuk hash.</div></section>
        ${telegramPanel}
        ${backupPanel}
      </div>`;
  }

  function openTransactionModal(kind = 'expense', record = null) {
    const editing = !!record;
    const selected = editing ? (record.kind === 'income' ? 'income' : 'expense') : (kind === 'income' ? 'income' : 'expense');
    const baseCategories = selected === 'income' ? ['Gaji', 'Usaha', 'Bonus', 'Hadiah', 'Lainnya'] : ['Belanja bulanan', 'Tagihan', 'Transportasi', 'Perawatan', 'Kesehatan', 'Kebutuhan rumah', 'Lainnya'];
    // Kategori dari file impor bisa di luar daftar; tetap ditampilkan agar tidak hilang saat diedit.
    const currentCategory = editing ? String(record.category || '') : '';
    const categories = currentCategory && !baseCategories.includes(currentCategory) ? [...baseCategories, currentCategory] : baseCategories;
    const categoryOptions = categories.map((name) => `<option value="${escapeHtml(name)}"${name === currentCategory ? ' selected' : ''}>${escapeHtml(name)}</option>`).join('');
    openModal(
      editing ? (selected === 'income' ? 'Ubah uang masuk' : 'Ubah uang keluar') : (selected === 'income' ? 'Catat uang masuk' : 'Catat uang keluar'),
      editing ? 'Perbaiki nominal, tanggal, atau catatannya tanpa perlu menghapus lalu mencatat ulang.' : 'Biar arus kas rumah tetap kebaca dan nggak mengandalkan ingatan.',
      `
      <form class="modal-body" data-form="transaction"${editing ? ' data-mode="edit"' : ''}><div class="form-grid">
        ${editing ? `<input type="hidden" name="id" value="${Number(record.id)}">` : ''}
        <div class="field full-span"><label for="tx-kind">Jenis transaksi</label><select class="select" id="tx-kind" name="kind" data-transaction-kind><option value="expense"${selected === 'expense' ? ' selected' : ''}>Uang keluar</option><option value="income"${selected === 'income' ? ' selected' : ''}>Uang masuk</option></select></div>
        <div class="field full-span"><label for="tx-title">Nama transaksi</label><input class="input" id="tx-title" name="title" type="text" maxlength="100" value="${escapeHtml(editing ? record.title : '')}" placeholder="Contoh: Belanja mingguan" required></div>
        <div class="field"><label for="tx-amount">Nominal</label><input class="input" id="tx-amount" name="amount" type="number" min="1" step="1000" value="${editing ? Number(record.amount) : ''}" placeholder="150000" required></div>
        <div class="field"><label for="tx-category">Kategori</label><select class="select" id="tx-category" name="category">${categoryOptions}</select></div>
        <div class="field full-span"><label for="tx-date">Tanggal</label><input class="input" id="tx-date" name="date" type="date" value="${escapeHtml(editing ? String(record.transaction_date || '').slice(0, 10) : localDateValue())}" required></div>
        <div class="field full-span"><label for="tx-note">Catatan <span class="text-muted" style="font-weight:400">(opsional)</span></label><textarea class="textarea" id="tx-note" name="note" maxlength="500" placeholder="Tambahkan detail kalau perlu…">${escapeHtml(editing ? record.note || '' : '')}</textarea></div>
        ${receiptFieldMarkup('tx-receipt', editing ? record.attachment : null)}
      </div><p class="error-text" data-form-error hidden></p><div class="modal-footer"><button class="btn btn-secondary" type="button" data-action="close-modal">Batal</button><button class="btn" type="submit">${icon('check')} ${editing ? 'Simpan perubahan' : 'Simpan transaksi'}</button></div></form>`);
  }

  function openReminderModal(record = null) {
    const editing = !!record;
    const later = new Date(Date.now() + 60 * 60 * 1000);
    later.setMinutes(Math.ceil(later.getMinutes() / 5) * 5, 0, 0);
    const source = String(record?.reminder_at || '').replace('T', ' ');
    // Jam bersifat opsional: pengingat lama tanpa jam (has_time = 0) dibuka dengan kolom jam kosong.
    const storedTime = editing ? (Number(record.has_time ?? 1) === 0 ? '' : source.slice(11, 16)) : localDateTimeValue(later).slice(11);
    const dateValue = editing ? source.slice(0, 10) : localDateValue(later);
    const baseCategories = ['Perawatan', 'Tagihan', 'Kesehatan', 'Belanja', 'Rumah', 'Lainnya'];
    const currentCategory = editing ? String(record.category || 'Lainnya') : 'Perawatan';
    const categoryOptions = (baseCategories.includes(currentCategory) ? baseCategories : [...baseCategories, currentCategory])
      .map((name) => `<option value="${escapeHtml(name)}"${name === currentCategory ? ' selected' : ''}>${escapeHtml(name)}</option>`).join('');
    openModal(
      editing ? 'Ubah pengingat' : 'Buat pengingat',
      editing ? 'Geser tanggal/jamnya atau perbaiki isinya; jadwal Telegram mengikuti waktu yang baru.' : 'Tulis agenda rumah, pilih tanggalnya, lalu tambahkan jam kalau memang perlu.',
      `
      <form class="modal-body" data-form="reminder"${editing ? ' data-mode="edit"' : ''}><div class="form-grid">
        ${editing ? `<input type="hidden" name="id" value="${Number(record.id)}">` : ''}
        <div class="field full-span"><label for="reminder-title">Apa yang perlu diingat?</label><input class="input" id="reminder-title" name="title" type="text" maxlength="120" value="${escapeHtml(editing ? record.title : '')}" placeholder="Contoh: Ganti oli motor" required></div>
        <div class="field"><label for="reminder-date">Tanggal</label><input class="input" id="reminder-date" name="reminder_date" type="date" value="${escapeHtml(dateValue)}" required></div>
        <div class="field"><label for="reminder-time">Jam <span class="text-muted" style="font-weight:400">(opsional)</span></label>
          <div class="time-input-row"><input class="input" id="reminder-time" name="reminder_time" type="time" value="${escapeHtml(storedTime)}" data-reminder-time aria-describedby="reminder-time-note"><button class="btn btn-secondary btn-small" type="button" data-action="clear-reminder-time"${storedTime ? '' : ' hidden'}>Tanpa jam</button></div>
          <p class="form-note" id="reminder-time-note" data-reminder-time-note></p>
        </div>
        <div class="field full-span"><label for="reminder-category">Kategori</label><select class="select" id="reminder-category" name="category">${categoryOptions}</select></div>
        <div class="field full-span"><label for="reminder-note">Catatan tambahan <span class="text-muted" style="font-weight:400">(opsional)</span></label><textarea class="textarea" id="reminder-note" name="note" maxlength="500" placeholder="Contoh: Bengkel langganan dekat rumah">${escapeHtml(editing ? record.note || '' : '')}</textarea></div>
        ${receiptFieldMarkup('reminder-receipt', editing ? record.attachment : null)}
      </div><p class="form-note">Pengingat akan terlihat oleh semua anggota rumah. Jika bot Telegram terhubung, bot juga menerima notifikasi.</p><p class="error-text" data-form-error hidden></p><div class="modal-footer"><button class="btn btn-secondary" type="button" data-action="close-modal">Batal</button><button class="btn" type="submit">${icon('bell')} ${editing ? 'Simpan perubahan' : 'Simpan pengingat'}</button></div></form>`);
    syncReminderTimeHint();
  }

  // Menjelaskan apa arti kolom jam yang kosong, supaya tidak ada yang bingung.
  function syncReminderTimeHint() {
    const input = modalRoot.querySelector('[data-reminder-time]');
    if (!input) return;
    const time = input.value;
    const note = modalRoot.querySelector('[data-reminder-time-note]');
    if (note) {
      note.textContent = time
        ? `Kabar pengingat dikirim sekitar jam ${time} WIB pada tanggal itu.`
        : `Tanpa jam: dianggap pengingat sepanjang hari, kabarnya dikirim sekitar ${DEFAULT_REMINDER_TIME} WIB.`;
    }
    const clearButton = modalRoot.querySelector('[data-action="clear-reminder-time"]');
    if (clearButton) clearButton.hidden = !time;
  }

  function openUserModal() {
    openModal('Tambah anggota rumah', 'Buat akun untuk orang rumah yang ikut mencatat bersama.', `
      <form class="modal-body" data-form="user-create"><div class="form-grid">
        <div class="field"><label for="new-user-name">Nama tampilan</label><input class="input" id="new-user-name" name="name" maxlength="80" placeholder="Contoh: Budi" required></div>
        <div class="field"><label for="new-user-username">Username</label><input class="input" id="new-user-username" name="username" maxlength="32" pattern="[A-Za-z0-9._-]{3,32}" autocapitalize="none" placeholder="budi.rumah" required></div>
        <div class="field full-span"><label for="new-user-password">Kata sandi awal</label><input class="input" id="new-user-password" name="password" type="password" minlength="8" autocomplete="new-password" placeholder="Minimal 8 karakter" required></div>
      </div><p class="form-note">Akun baru langsung bisa melihat dan menambahkan catatan bersama. Berikan kata sandi awal secara pribadi.</p><p class="error-text" data-form-error hidden></p><div class="modal-footer"><button class="btn btn-secondary" type="button" data-action="close-modal">Batal</button><button class="btn" type="submit">${icon('plus')} Buat akun</button></div></form>`);
  }

  function openResetPasswordModal(id, name) {
    openModal('Atur ulang kata sandi', `Buat kata sandi sementara baru untuk ${name}.`, `
      <form class="modal-body" data-form="reset-member-password"><input type="hidden" name="id" value="${Number(id)}"><div class="field"><label for="reset-password">Kata sandi baru</label><input class="input" id="reset-password" name="password" type="password" minlength="8" autocomplete="new-password" placeholder="Minimal 8 karakter" required></div><p class="form-note">Beritahu kata sandi baru langsung kepada anggota tersebut.</p><p class="error-text" data-form-error hidden></p><div class="modal-footer"><button class="btn btn-secondary" type="button" data-action="close-modal">Batal</button><button class="btn" type="submit">Simpan kata sandi</button></div></form>`);
  }

  function navigate(page) {
    if (page === 'members' && state.user?.role !== 'admin') {
      showToast('Menu anggota hanya tersedia untuk admin.', 'error');
      return;
    }
    if (!['dashboard', 'finance', 'report', 'reminders', 'members', 'settings'].includes(page)) return;
    state.page = page;
    render();
  }

  async function deleteTransaction(id) {
    if (!window.confirm('Hapus catatan transaksi ini? Tindakan ini tidak bisa dibatalkan.')) return;
    try {
      const result = await api('transaction/delete', { id: Number(id) });
      showToast(result.message);
      state.dashboard = null;
      state.finance = null;
      state.report = null;
      state.reportKey = '';
      await refreshPage(true);
    } catch (error) { showToast(error.message, 'error'); }
  }

  async function deleteReminder(id) {
    if (!window.confirm('Hapus pengingat ini?')) return;
    try {
      const result = await api('reminder/delete', { id: Number(id) });
      showToast(result.message);
      state.dashboard = null;
      state.reminders = null;
      await refreshPage(true);
    } catch (error) { showToast(error.message, 'error'); }
  }

  async function toggleReminder(id) {
    try {
      const result = await api('reminder/toggle', { id: Number(id) });
      showToast(result.message);
      state.dashboard = null;
      state.reminders = null;
      await refreshPage(true);
    } catch (error) { showToast(error.message, 'error'); }
  }

  // Meneruskan satu pengingat ke Telegram sekarang: untuk menguji notifikasi
  // atau menyampaikan pengingat yang cron-nya belum sempat jalan.
  async function sendReminderNow(button) {
    await withBusyButton(button, async () => {
      const result = await api('reminder/send', { id: Number(button.dataset.id) });
      showToast(result.message);
    });
  }

  async function doLogout() {
    try {
      const result = await api('logout', {});
      state.csrf = result.csrf;
    } catch {
      window.location.reload();
      return;
    }
    state.user = null;
    state.dashboard = null;
    state.finance = null;
    state.report = null;
    state.reportKey = '';
    state.reminders = null;
    state.members = null;
    state.settings = null;
    render();
  }

  document.addEventListener('click', async (event) => {
    const pageButton = event.target.closest('[data-page]');
    if (pageButton) {
      event.preventDefault();
      navigate(pageButton.dataset.page);
      return;
    }
    const actionButton = event.target.closest('[data-action]');
    if (!actionButton) {
      if (event.target.matches('[data-modal-backdrop]')) closeModal();
      return;
    }
    const action = actionButton.dataset.action;
    if (action === 'toggle-login-theme') setLoginTheme(state.loginTheme === 'dark' ? 'light' : 'dark');
    else if (action === 'open-import') openImportModal(actionButton.dataset.importType);
    else if (action === 'download-import-template') downloadImportTemplate(actionButton.dataset.importType);
    else if (action === 'confirm-import') await confirmImport(actionButton);
    else if (action === 'download-report') downloadReportCsv();
    else if (action === 'report-pick-category') {
      const category = actionButton.dataset.category || '';
      state.reportCategory = state.reportCategory === category ? '' : category;
      state.report = null;
      state.reportKey = '';
      refreshPage(true);
    }
    else if (action === 'close-modal') closeModal();
    else if (action === 'clear-receipt') clearReceipt(actionButton);
    else if (action === 'open-proof') openProofModal(actionButton.dataset.entity || 'transaction', Number(actionButton.dataset.id));
    else if (action === 'save-proof') await uploadReceipt(actionButton.dataset.entity || 'transaction', Number(actionButton.dataset.id), actionButton);
    else if (action === 'delete-proof') await deleteProof(actionButton.dataset.id);
    else if (action === 'open-transaction') openTransactionModal(actionButton.dataset.kind || 'expense');
    else if (action === 'open-reminder') openReminderModal();
    else if (action === 'edit-transaction') {
      const record = findEntityRecord('transaction', actionButton.dataset.id);
      if (!record) { showToast('Data transaksi belum termuat. Muat ulang halaman lalu coba lagi.', 'error'); return; }
      openTransactionModal(record.kind, record);
    }
    else if (action === 'edit-reminder') {
      const record = findEntityRecord('reminder', actionButton.dataset.id);
      if (!record) { showToast('Data pengingat belum termuat. Muat ulang halaman lalu coba lagi.', 'error'); return; }
      openReminderModal(record);
    }
    else if (action === 'send-reminder') await sendReminderNow(actionButton);
    else if (action === 'clear-reminder-time') {
      const timeInput = modalRoot.querySelector('[data-reminder-time]');
      if (timeInput) { timeInput.value = ''; syncReminderTimeHint(); }
    }
    else if (action === 'reminder-test') await withBusyButton(actionButton, async () => {
      const result = await api('reminder/test', {});
      showToast(result.message);
    }, 'Mengirim…');
    else if (action === 'open-user') openUserModal();
    else if (action === 'logout') doLogout();
    else if (action === 'retry') refreshPage(true);
    else if (action === 'delete-transaction') deleteTransaction(actionButton.dataset.id);
    else if (action === 'delete-reminder') deleteReminder(actionButton.dataset.id);
    else if (action === 'toggle-reminder') toggleReminder(actionButton.dataset.id);
    else if (action === 'reset-member-password') openResetPasswordModal(actionButton.dataset.id, actionButton.dataset.name || 'anggota');
    else if (action === 'telegram-test') {
      actionButton.disabled = true;
      try { const result = await api('telegram/test', {}); showToast(result.message); }
      catch (error) { showToast(error.message, 'error'); }
      finally { actionButton.disabled = false; }
    } else if (action === 'telegram-discover') {
      const token = document.getElementById('telegram-token')?.value.trim();
      if (!token) { showToast('Masukkan token bot dahulu, lalu pilih Cari Chat ID.', 'error'); return; }
      actionButton.disabled = true;
      const label = actionButton.textContent;
      actionButton.textContent = 'Mencari…';
      try {
        const result = await api('telegram/discover', { token });
        const chatInput = document.getElementById('telegram-chat-id');
        if (chatInput) chatInput.value = result.chat_id;
        showToast(`Chat ID ditemukan${result.chat_name ? ` · ${result.chat_name}` : ''}.`);
      } catch (error) { showToast(error.message, 'error'); }
      finally { actionButton.disabled = false; actionButton.textContent = label; }
    } else if (action === 'telegram-disconnect') {
      if (!window.confirm('Lepas koneksi bot Telegram? Pengingat tetap tersimpan di website.')) return;
      try {
        const result = await api('telegram/disconnect', {});
        showToast(result.message);
        state.settings = null;
        await refreshPage(true);
      } catch (error) { showToast(error.message, 'error'); }
    }
  });

  document.addEventListener('change', async (event) => {
    if (event.target.matches('[data-import-file]')) {
      await handleImportFile(event.target.files?.[0]);
      return;
    }
    if (event.target.matches('[data-receipt-input]')) {
      await handleReceiptFile(event.target);
      return;
    }
    if (event.target.matches('[data-proof-input]')) {
      await handleProofFile(event.target);
      return;
    }
    if (event.target.matches('[data-reminder-time]')) {
      syncReminderTimeHint();
      return;
    }
    if (!event.target.matches('[data-transaction-kind]')) return;
    const category = document.getElementById('tx-category');
    if (!category) return;
    const options = event.target.value === 'income' ? ['Gaji', 'Usaha', 'Bonus', 'Hadiah', 'Lainnya'] : ['Belanja bulanan', 'Tagihan', 'Transportasi', 'Perawatan', 'Kesehatan', 'Kebutuhan rumah', 'Lainnya'];
    // Kategori yang sedang terpilih tetap dipertahankan walau jenisnya diganti.
    const current = category.value;
    if (current && !options.includes(current)) options.push(current);
    category.innerHTML = options.map((value) => `<option value="${escapeHtml(value)}"${value === current ? ' selected' : ''}>${escapeHtml(value)}</option>`).join('');
  });

  document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-form]');
    if (!form) return;
    event.preventDefault();
    const data = Object.fromEntries(new FormData(form).entries());
    const kind = form.dataset.form;
    showFormError(form, '');
    setFormBusy(form, true);
    try {
      if (kind === 'login') {
        const result = await api('login', data);
        state.user = result.user;
        state.csrf = result.csrf;
        state.page = 'dashboard';
        showToast(`Halo, ${result.user.name}! Selamat datang.`);
        await render();
      } else if (kind === 'transaction') {
        const recordId = form.dataset.mode === 'edit' ? Number(data.id || 0) : 0;
        const receipt = receiptPayload();
        const result = recordId
          ? await api('transaction/update', { id: recordId, kind: data.kind, title: data.title, category: data.category, amount: Number(data.amount), date: data.date, note: data.note || '' })
          : await api('transaction/create', { ...data, amount: Number(data.amount), receipt });
        const receiptNote = recordId ? await replaceReceipt('transaction', recordId, receipt) : '';
        closeModal();
        showToast(result.message + receiptNote);
        state.dashboard = null;
        state.finance = null;
        state.report = null;
        state.reportKey = '';
        // Transaksi yang dipindah ke bulan lain langsung ditampilkan tanpa perlu mencari manual.
        if (recordId && result.month && state.financeMonth !== result.month) state.financeMonth = result.month;
        await refreshPage(true);
      } else if (kind === 'reminder') {
        const recordId = form.dataset.mode === 'edit' ? Number(data.id || 0) : 0;
        const receipt = receiptPayload();
        const payload = {
          title: data.title,
          category: data.category,
          reminder_date: data.reminder_date,
          reminder_time: data.reminder_time || '',
          note: data.note || '',
        };
        const result = recordId
          ? await api('reminder/update', { id: recordId, ...payload })
          : await api('reminder/create', { ...payload, receipt });
        const receiptNote = recordId ? await replaceReceipt('reminder', recordId, receipt) : '';
        closeModal();
        showToast(recordId
          ? result.message + receiptNote
          : `${result.message}${result.telegram_sent ? ' Kabarnya juga dikirim ke Telegram.' : ' Telegram belum terhubung atau pesan belum terkirim.'}${receiptNote}`);
        state.dashboard = null;
        state.reminders = null;
        await refreshPage(true);
      } else if (kind === 'target') {
        const result = await api('target/save', { month: data.month, amount: Number(data.amount) });
        showToast(result.message);
        state.dashboard = null;
        state.finance = null;
        await refreshPage(true);
      } else if (kind === 'user-create') {
        const result = await api('users/create', data);
        closeModal();
        showToast(result.message);
        state.members = null;
        await refreshPage(true);
      } else if (kind === 'reset-member-password') {
        const result = await api('users/reset-password', { id: Number(data.id), password: data.password });
        closeModal();
        showToast(result.message);
        state.members = null;
        await refreshPage(true);
      } else if (kind === 'password-change') {
        if (data.new_password !== data.confirm_password) throw new Error('Ulangan kata sandi baru belum cocok.');
        const result = await api('password/change', { current_password: data.current_password, new_password: data.new_password });
        form.reset();
        showToast(result.message);
      } else if (kind === 'telegram-save') {
        const result = await api('telegram/save', { token: data.token, chat_id: data.chat_id });
        showToast(result.message, result.telegram_sent ? 'success' : 'error');
        state.settings = null;
        await refreshPage(true);
      }
    } catch (error) {
      showFormError(form, error.message);
    } finally {
      if (document.body.contains(form)) setFormBusy(form, false);
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modalRoot.innerHTML !== '') closeModal();
  });

  render();
})();
