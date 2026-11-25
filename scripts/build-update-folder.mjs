import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const updateRoot = path.join(root, 'update');
const websiteOutDir = path.join(updateRoot, 'website');

// Paket update kumulatif lengkap: seluruh berkas runtime website dengan struktur persis htdocs/.
// Aman diekstrak langsung di htdocs karena TIDAK menyertakan config.php maupun data database/lampiran.
export const RUNTIME_UPDATE_FILES = [
  // Endpoint utama di root (htdocs/)
  'index.php',
  'api.php',
  'whatsapp.php',
  'cron.php',
  'telegram-backup.php',
  'security-contact.php',
  'config.example.php',

  // Proteksi Apache (.htaccess)
  '.htaccess',
  'includes/.htaccess',
  'storage/.htaccess',
  'database/.htaccess',

  // Modul backend PHP (htdocs/includes/)
  'includes/app.php',
  'includes/security.php',
  'includes/wa.php',
  'includes/google-calendar.php',

  // Aset frontend (htdocs/assets/)
  'assets/app.js',
  'assets/style.css',
  'assets/importer.js',
  'assets/clipboard-import.js',
  'assets/calendar.js',
  'assets/brand.svg',
];

// Bersihkan folder staging update/website dan artefak usang agar isi selalu sinkron 100%.
await fs.rm(websiteOutDir, { recursive: true, force: true });
await fs.rm(path.join(updateRoot, 'cloudflare-worker'), { recursive: true, force: true });

for (const relativePath of RUNTIME_UPDATE_FILES) {
  const sourcePath = path.join(root, relativePath);
  const destinationPath = path.join(websiteOutDir, relativePath);
  await fs.mkdir(path.dirname(destinationPath), { recursive: true });
  await fs.copyFile(sourcePath, destinationPath);
}

console.log(`Update package refreshed: ${RUNTIME_UPDATE_FILES.length} runtime files copied to update/website/`);
