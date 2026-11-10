import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const updateRoot = path.join(root, 'update');
const files = [
  ['telegram-backup.php', 'website/telegram-backup.php'],
  ['includes/app.php', 'website/includes/app.php'],
  ['cloudflare-backup/worker.js', 'cloudflare-worker/worker.js'],
  ['cloudflare-backup/wrangler.toml', 'cloudflare-worker/wrangler.toml'],
  ['cloudflare-backup/.dev.vars.example', 'cloudflare-worker/.dev.vars.example'],
];

for (const [source, destination] of files) {
  const sourcePath = path.join(root, source);
  const destinationPath = path.join(updateRoot, destination);
  await fs.mkdir(path.dirname(destinationPath), { recursive: true });
  await fs.copyFile(sourcePath, destinationPath);
}

console.log(`Update package refreshed: ${files.length} runtime files copied to ${path.relative(root, updateRoot)}/`);
