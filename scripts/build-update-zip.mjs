import fs from 'node:fs/promises';
import path from 'node:path';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';

/**
 * Membuat update/website-update.zip dari isi update/website/.
 * ZIP-nya sudah membawa struktur folder yang benar (index.php + api.php + assets/…),
 * jadi bisa langsung diekstrak di htdocs tanpa risiko salah tempat.
 * Ditulis tanpa dependensi: memakai format ZIP standar + zlib bawaan Node.
 */

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourceDir = path.join(root, 'update', 'website');
const zipPath = path.join(root, 'update', 'website-update.zip');
const entriesToZip = ['index.php', 'api.php', 'assets/app.js', 'assets/clipboard-import.js', 'assets/style.css'];

const CRC_TABLE = (() => {
  const table = new Uint32Array(256);
  for (let index = 0; index < 256; index += 1) {
    let value = index;
    for (let bit = 0; bit < 8; bit += 1) {
      value = value & 1 ? 0xedb88320 ^ (value >>> 1) : value >>> 1;
    }
    table[index] = value >>> 0;
  }
  return table;
})();

function crc32(buffer) {
  let crc = 0xffffffff;
  for (const byte of buffer) crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);
  return (crc ^ 0xffffffff) >>> 0;
}

// Waktu MS-DOS: tanggal/jam lokal berkas, tahun minimal 1980.
function dosTimestamp(date) {
  const year = Math.max(1980, date.getFullYear());
  const time = (date.getHours() << 11) | (date.getMinutes() << 5) | Math.floor(date.getSeconds() / 2);
  const day = ((year - 1980) << 9) | ((date.getMonth() + 1) << 5) | date.getDate();
  return { time, day };
}

const localParts = [];
const directoryParts = [];
let offset = 0;

for (const relativePath of entriesToZip) {
  const absolutePath = path.join(sourceDir, relativePath);
  const [data, stats] = await Promise.all([fs.readFile(absolutePath), fs.stat(absolutePath)]);
  const name = Buffer.from(relativePath.replaceAll(path.sep, '/'), 'utf8');
  const deflated = zlib.deflateRawSync(data, { level: 9 });
  const useDeflate = deflated.length < data.length;
  const payload = useDeflate ? deflated : data;
  const checksum = crc32(data);
  const { time, day } = dosTimestamp(stats.mtime);

  const localHeader = Buffer.alloc(30);
  localHeader.writeUInt32LE(0x04034b50, 0); // signature
  localHeader.writeUInt16LE(20, 4); // versi minimum
  localHeader.writeUInt16LE(0x0800, 6); // nama berkas UTF-8
  localHeader.writeUInt16LE(useDeflate ? 8 : 0, 8); // metode kompresi
  localHeader.writeUInt16LE(time, 10);
  localHeader.writeUInt16LE(day, 12);
  localHeader.writeUInt32LE(checksum, 14);
  localHeader.writeUInt32LE(payload.length, 18);
  localHeader.writeUInt32LE(data.length, 22);
  localHeader.writeUInt16LE(name.length, 26);
  localHeader.writeUInt16LE(0, 28);

  const centralHeader = Buffer.alloc(46);
  centralHeader.writeUInt32LE(0x02014b50, 0); // signature central directory
  centralHeader.writeUInt16LE(20, 4); // versi pembuat
  centralHeader.writeUInt16LE(20, 6); // versi minimum
  centralHeader.writeUInt16LE(0x0800, 8);
  centralHeader.writeUInt16LE(useDeflate ? 8 : 0, 10);
  centralHeader.writeUInt16LE(time, 12);
  centralHeader.writeUInt16LE(day, 14);
  centralHeader.writeUInt32LE(checksum, 16);
  centralHeader.writeUInt32LE(payload.length, 20);
  centralHeader.writeUInt32LE(data.length, 24);
  centralHeader.writeUInt16LE(name.length, 28);
  centralHeader.writeUInt16LE(0, 30); // extra
  centralHeader.writeUInt16LE(0, 32); // komentar
  centralHeader.writeUInt16LE(0, 34); // nomor disk
  centralHeader.writeUInt16LE(0, 36); // atribut internal
  centralHeader.writeUInt32LE(0, 38); // atribut eksternal
  centralHeader.writeUInt32LE(offset, 42);

  localParts.push(localHeader, name, payload);
  directoryParts.push(centralHeader, name);
  offset += localHeader.length + name.length + payload.length;
}

const directory = Buffer.concat(directoryParts);
const endRecord = Buffer.alloc(22);
endRecord.writeUInt32LE(0x06054b50, 0);
endRecord.writeUInt16LE(0, 4);
endRecord.writeUInt16LE(0, 6);
endRecord.writeUInt16LE(entriesToZip.length, 8);
endRecord.writeUInt16LE(entriesToZip.length, 10);
endRecord.writeUInt32LE(directory.length, 12);
endRecord.writeUInt32LE(offset, 16);
endRecord.writeUInt16LE(0, 20);

const zip = Buffer.concat([...localParts, directory, endRecord]);
await fs.writeFile(zipPath, zip);

console.log(`ZIP siap: ${path.relative(root, zipPath)} (${(zip.length / 1024).toFixed(1)} KB, ${entriesToZip.length} berkas)`);
for (const relativePath of entriesToZip) console.log(`  + ${relativePath}`);
console.log('Ekstrak ZIP ini di htdocs; struktur folder sudah benar.');
