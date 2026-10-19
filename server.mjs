import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadPHPRuntime, PHP, PHPRequestHandler } from '@php-wasm/universal';
import { getPHPLoaderModule } from '@php-wasm/node-8-2';

const root = path.dirname(fileURLToPath(import.meta.url));
const port = Number(process.env.PORT || 4173);
const documentRoot = '/www';
const databasePath = path.join(root, 'storage', 'rumahku.sqlite');
// Upload bukti berupa gambar terkompres dikirim sebagai JSON+base64, jadi badan permintaan boleh lebih besar.
const maxRequestBody = 12 * 1024 * 1024;
const filesToServe = [
  'index.php', 'api.php', 'cron.php', 'whatsapp.php', '.htaccess', 'config.php',
  'includes/app.php', 'includes/wa.php', 'assets/app.js', 'assets/importer.js', 'assets/style.css', 'assets/brand.svg', 'storage/.htaccess',
];

console.log('Menyiapkan PHP 8.2 untuk preview lokal…');
const runtimeId = await loadPHPRuntime(await getPHPLoaderModule(), {
  processId: Number(process.pid || 1),
  phpWasmAsyncMode: 'asyncify',
  // Emscripten's generated module already supplies an absolute WASM asset path.
  locateFile: (file) => file,
  quit: (_code, error) => { throw error; },
});

const php = new PHP(runtimeId);
php.mkdirTree(`${documentRoot}/assets`);
php.mkdirTree(`${documentRoot}/includes`);
php.mkdirTree(`${documentRoot}/storage`);

for (const relativePath of filesToServe) {
  const sourcePath = path.join(root, relativePath);
  if (!fs.existsSync(sourcePath) || !fs.statSync(sourcePath).isFile()) continue;
  const targetPath = path.posix.join(documentRoot, relativePath.replaceAll(path.sep, '/'));
  const directory = path.posix.dirname(targetPath);
  php.mkdirTree(directory);
  php.writeFile(targetPath, fs.readFileSync(sourcePath));
}
if (fs.existsSync(databasePath)) {
  php.writeFile(`${documentRoot}/storage/rumahku.sqlite`, fs.readFileSync(databasePath));
}

const handler = new PHPRequestHandler({
  php,
  documentRoot,
  absoluteUrl: `http://localhost:${port}`,
  cookieStore: false,
});

let requestQueue = Promise.resolve();
const server = http.createServer((request, response) => {
  let bodySize = 0;
  const chunks = [];
  request.on('data', (chunk) => {
    bodySize += chunk.length;
    if (bodySize > maxRequestBody) {
      response.writeHead(413, { 'Content-Type': 'text/plain; charset=utf-8' });
      response.end('Request body is too large.');
      request.destroy();
      return;
    }
    chunks.push(chunk);
  });
  request.on('end', () => {
    const task = requestQueue.then(async () => {
      const parsedUrl = new URL(request.url || '/', `http://${request.headers.host || 'localhost'}`);
      let decodedPath;
      try { decodedPath = decodeURIComponent(parsedUrl.pathname); } catch {
        response.writeHead(400, { 'Content-Type': 'text/plain; charset=utf-8' });
        response.end('Invalid request path.');
        return;
      }
      const blockedPath = ['/storage', '/includes', '/config.php', '/.git'].some((prefix) => decodedPath === prefix || decodedPath.startsWith(`${prefix}/`));
      if (blockedPath) {
        response.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
        response.end('Not found.');
        return;
      }
      const targetUrl = `${parsedUrl.pathname === '/' ? '/index.php' : parsedUrl.pathname}${parsedUrl.search}`;
      const headers = {};
      for (const [name, value] of Object.entries(request.headers)) {
        if (value === undefined || name === 'host' || name === 'connection' || name === 'content-length') continue;
        headers[name] = Array.isArray(value) ? value.join(', ') : value;
      }
      const requestBody = chunks.length ? Buffer.concat(chunks) : undefined;
      let result;
      try {
        result = await handler.request({
          url: targetUrl,
          method: request.method || 'GET',
          headers,
          ...(requestBody ? { body: requestBody } : {}),
        });
      } catch (error) {
        console.error('PHP request failed:', error);
        if (!response.headersSent) response.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
        response.end('Preview server encountered an error. Check the terminal output.');
        return;
      }

      const outputHeaders = {};
      for (const [name, values] of Object.entries(result.headers || {})) {
        const key = name.toLowerCase();
        if (['connection', 'transfer-encoding', 'content-length'].includes(key)) continue;
        outputHeaders[name] = key === 'set-cookie' ? values : values.length > 1 ? values.join(', ') : values[0];
      }
      if (!outputHeaders['Content-Type'] && !outputHeaders['content-type']) {
        outputHeaders['Content-Type'] = 'text/html; charset=utf-8';
      }
      response.writeHead(result.httpStatusCode || 200, outputHeaders);
      response.end(Buffer.from(result.bytes));

      // PHP-WASM's filesystem is in memory; persist the development SQLite DB between restarts.
      try {
        const database = php.readFileAsBuffer(`${documentRoot}/storage/rumahku.sqlite`);
        fs.mkdirSync(path.dirname(databasePath), { recursive: true });
        fs.writeFileSync(databasePath, Buffer.from(database));
      } catch (error) {
        // The app may be configured for MySQL and never create a local SQLite file.
        if (process.env.DEBUG_PREVIEW) console.error('SQLite persistence skipped:', error?.message || error);
      }
    });
    requestQueue = task.catch((error) => console.error('Preview request queue error:', error));
  });
});

server.on('clientError', (_error, socket) => {
  if (socket.writable) socket.end('HTTP/1.1 400 Bad Request\r\n\r\n');
});

server.listen(port, '0.0.0.0', () => {
  console.log(`Dashboard kebutuhan rumah siap di http://0.0.0.0:${port}`);
});

async function shutdown() {
  server.close();
  try { await handler[Symbol.asyncDispose](); } catch {}
  try { php[Symbol.dispose](); } catch {}
  process.exit(0);
}
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
