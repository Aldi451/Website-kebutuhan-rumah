(() => {
  'use strict';

  const decoder = new TextDecoder();
  const MAX_FILE_BYTES = 10 * 1024 * 1024;
  const MAX_XML_BYTES = 20 * 1024 * 1024;

  function fail(message) {
    throw new Error(message);
  }

  function parseCsv(text) {
    text = String(text || '').replace(/^\uFEFF/, '');
    const firstLine = text.split(/\r?\n/, 1)[0] || '';
    const counts = [',', ';', '\t'].map((delimiter) => {
      let count = 0;
      let insideQuotes = false;
      for (let i = 0; i < firstLine.length; i += 1) {
        if (firstLine[i] === '"') {
          if (insideQuotes && firstLine[i + 1] === '"') i += 1;
          else insideQuotes = !insideQuotes;
        } else if (!insideQuotes && firstLine[i] === delimiter) count += 1;
      }
      return count;
    });
    const delimiter = [',', ';', '\t'][counts.indexOf(Math.max(...counts))] || ',';
    const rows = [];
    let cells = [];
    let field = '';
    let quoted = false;
    let rowNumber = 1;

    for (let i = 0; i < text.length; i += 1) {
      const char = text[i];
      if (quoted) {
        if (char === '"' && text[i + 1] === '"') {
          field += '"';
          i += 1;
        } else if (char === '"') {
          quoted = false;
        } else {
          field += char;
        }
      } else if (char === '"' && field === '') {
        quoted = true;
      } else if (char === delimiter) {
        cells.push(field.trim());
        field = '';
      } else if (char === '\n' || char === '\r') {
        if (char === '\r' && text[i + 1] === '\n') i += 1;
        cells.push(field.trim());
        rows.push({ number: rowNumber, cells });
        rowNumber += 1;
        cells = [];
        field = '';
      } else {
        field += char;
      }
    }
    if (field !== '' || cells.length) {
      cells.push(field.trim());
      rows.push({ number: rowNumber, cells });
    }
    return rows;
  }

  function findEndOfCentralDirectory(view) {
    const start = Math.max(0, view.byteLength - 22 - 0xffff);
    for (let offset = view.byteLength - 22; offset >= start; offset -= 1) {
      if (view.getUint32(offset, true) === 0x06054b50) return offset;
    }
    return -1;
  }

  function zipDirectory(buffer) {
    const view = new DataView(buffer);
    const eocd = findEndOfCentralDirectory(view);
    if (eocd < 0) fail('File Excel tidak terbaca. Coba simpan ulang sebagai .xlsx.');
    const disk = view.getUint16(eocd + 4, true);
    const directoryDisk = view.getUint16(eocd + 6, true);
    const entryCount = view.getUint16(eocd + 10, true);
    let cursor = view.getUint32(eocd + 16, true);
    if (disk !== 0 || directoryDisk !== 0 || entryCount === 0xffff || cursor === 0xffffffff) {
      fail('Workbook Excel ini memakai format ZIP yang belum didukung.');
    }

    const entries = new Map();
    for (let i = 0; i < entryCount; i += 1) {
      if (cursor + 46 > view.byteLength || view.getUint32(cursor, true) !== 0x02014b50) {
        fail('Struktur file Excel tidak valid.');
      }
      const flags = view.getUint16(cursor + 8, true);
      const method = view.getUint16(cursor + 10, true);
      const compressedSize = view.getUint32(cursor + 20, true);
      const uncompressedSize = view.getUint32(cursor + 24, true);
      const nameLength = view.getUint16(cursor + 28, true);
      const extraLength = view.getUint16(cursor + 30, true);
      const commentLength = view.getUint16(cursor + 32, true);
      const localOffset = view.getUint32(cursor + 42, true);
      const nameStart = cursor + 46;
      const next = nameStart + nameLength + extraLength + commentLength;
      if (next > view.byteLength || compressedSize === 0xffffffff || uncompressedSize === 0xffffffff || localOffset === 0xffffffff) {
        fail('Workbook Excel ini terlalu besar atau memakai ZIP64.');
      }
      const name = decoder.decode(new Uint8Array(buffer, nameStart, nameLength));
      if (name && !(flags & 1)) entries.set(name, { method, compressedSize, uncompressedSize, localOffset });
      cursor = next;
    }
    return entries;
  }

  async function unzipEntry(buffer, entry) {
    if (!entry || entry.uncompressedSize > MAX_XML_BYTES) fail('Bagian di dalam file Excel terlalu besar.');
    const view = new DataView(buffer);
    const offset = entry.localOffset;
    if (offset + 30 > view.byteLength || view.getUint32(offset, true) !== 0x04034b50) fail('Bagian file Excel tidak valid.');
    const nameLength = view.getUint16(offset + 26, true);
    const extraLength = view.getUint16(offset + 28, true);
    const start = offset + 30 + nameLength + extraLength;
    const end = start + entry.compressedSize;
    if (end > view.byteLength) fail('File Excel terpotong atau rusak.');
    const payload = new Uint8Array(buffer, start, entry.compressedSize);
    if (entry.method === 0) return payload;
    if (entry.method !== 8 || typeof DecompressionStream === 'undefined') {
      fail('Browser tidak bisa membuka file .xlsx ini. Simpan sebagai CSV UTF-8 lalu coba lagi.');
    }
    try {
      const stream = new Blob([payload]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
      const result = await new Response(stream).arrayBuffer();
      if (result.byteLength > MAX_XML_BYTES) fail('Bagian di dalam file Excel terlalu besar.');
      return new Uint8Array(result);
    } catch (error) {
      if (error instanceof Error && error.message.includes('terlalu besar')) throw error;
      fail('Gagal membuka workbook. Coba simpan ulang sebagai .xlsx atau CSV UTF-8.');
    }
  }

  function normalizeZipTarget(target) {
    const parts = (target.startsWith('/') ? target.slice(1) : `xl/${target}`).split('/');
    const normalized = [];
    for (const part of parts) {
      if (!part || part === '.') continue;
      if (part === '..') normalized.pop();
      else normalized.push(part);
    }
    return normalized.join('/');
  }

  function parseXml(text) {
    if (typeof DOMParser === 'undefined') fail('Browser belum mendukung pembacaan Excel. Simpan file sebagai CSV UTF-8 lalu coba lagi.');
    const document = new DOMParser().parseFromString(text, 'application/xml');
    if (document.getElementsByTagName('parsererror').length) fail('Isi workbook Excel tidak valid.');
    return document;
  }

  function elements(node, name) {
    return Array.from(node.getElementsByTagNameNS('*', name));
  }

  async function readXlsx(buffer) {
    const files = zipDirectory(buffer);
    const workbookEntry = files.get('xl/workbook.xml');
    if (!workbookEntry) fail('Workbook Excel tidak memiliki lembar kerja yang bisa dibaca.');
    const workbook = parseXml(decoder.decode(await unzipEntry(buffer, workbookEntry)));
    const firstSheet = elements(workbook, 'sheet')[0];
    if (!firstSheet) fail('Lembar kerja Excel kosong.');
    const relId = firstSheet.getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id') || firstSheet.getAttribute('r:id');
    const relEntry = files.get('xl/_rels/workbook.xml.rels');
    let sheetPath = 'xl/worksheets/sheet1.xml';
    if (relEntry && relId) {
      const relationships = parseXml(decoder.decode(await unzipEntry(buffer, relEntry)));
      const relationship = elements(relationships, 'Relationship').find((item) => item.getAttribute('Id') === relId);
      if (relationship && relationship.getAttribute('Target')) sheetPath = normalizeZipTarget(relationship.getAttribute('Target'));
    }
    const sheetEntry = files.get(sheetPath);
    if (!sheetEntry) fail('Sheet pertama tidak ditemukan di dalam workbook.');

    let sharedStrings = [];
    const sharedEntry = files.get('xl/sharedStrings.xml');
    if (sharedEntry) {
      const sharedXml = parseXml(decoder.decode(await unzipEntry(buffer, sharedEntry)));
      sharedStrings = elements(sharedXml, 'si').map((item) => elements(item, 't').map((textNode) => textNode.textContent || '').join(''));
    }

    const sheet = parseXml(decoder.decode(await unzipEntry(buffer, sheetEntry)));
    const rows = [];
    for (const rowNode of elements(sheet, 'row')) {
      const number = Number(rowNode.getAttribute('r')) || rows.length + 1;
      const cells = [];
      for (const cell of elements(rowNode, 'c')) {
        const address = /^([A-Z]+)/i.exec(cell.getAttribute('r') || '');
        if (!address) continue;
        let column = 0;
        for (const letter of address[1].toUpperCase()) column = column * 26 + letter.charCodeAt(0) - 64;
        const index = column - 1;
        const type = cell.getAttribute('t') || '';
        const valueNode = elements(cell, 'v')[0];
        let value = valueNode ? valueNode.textContent || '' : '';
        if (type === 's') value = sharedStrings[Number(value)] ?? '';
        else if (type === 'inlineStr' || !valueNode) value = elements(cell, 't').map((node) => node.textContent || '').join('');
        else if (type === 'b') value = value === '1' ? 'TRUE' : 'FALSE';
        else if (type !== 'str' && type !== 'e' && value !== '' && Number.isFinite(Number(value))) value = Number(value);
        cells[index] = value;
      }
      rows.push({ number, cells });
    }
    return rows;
  }

  async function read(file) {
    if (!file) fail('Pilih file Excel atau CSV terlebih dahulu.');
    if (file.size > MAX_FILE_BYTES) fail('Ukuran file maksimal 10 MB.');
    const name = String(file.name || '').toLowerCase();
    if (name.endsWith('.csv')) return parseCsv(await file.text());
    if (name.endsWith('.xls')) fail('Format .xls lama belum didukung. Buka file di Excel lalu simpan sebagai .xlsx atau CSV UTF-8.');
    if (!name.endsWith('.xlsx')) fail('Pilih file Excel .xlsx atau CSV .csv.');
    return readXlsx(await file.arrayBuffer());
  }

  window.HouseholdImport = { read };
})();
