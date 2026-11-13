import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const updateRoot = path.join(root, 'update');

// Paket update "import cepat dari clipboard": file runtime yang berubah.
const files = [
  ['index.php', 'website/index.php'],
  ['assets/app.js', 'website/assets/app.js'],
  ['assets/clipboard-import.js', 'website/assets/clipboard-import.js'],
  ['assets/style.css', 'website/assets/style.css'],
];

// Paket lama (backup Telegram) sudah diganti. File sumbernya tetap ada di root
// repository, jadi paketnya bisa dibuat lagi kapan saja bila diperlukan.
const obsolete = [
  'website/telegram-backup.php',
  'website/includes',
  'website/config-snippet.txt',
  'cloudflare-worker',
];

for (const relativePath of obsolete) {
  await fs.rm(path.join(updateRoot, relativePath), { recursive: true, force: true });
}

for (const [source, destination] of files) {
  const sourcePath = path.join(root, source);
  const destinationPath = path.join(updateRoot, destination);
  await fs.mkdir(path.dirname(destinationPath), { recursive: true });
  await fs.copyFile(sourcePath, destinationPath);
}

console.log(`Update package refreshed: ${files.length} runtime files copied to ${path.relative(root, updateRoot)}/`);
