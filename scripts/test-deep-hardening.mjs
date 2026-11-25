import fs from 'node:fs';
import assert from 'node:assert/strict';
import { createHmac, randomBytes } from 'node:crypto';
import { loadPHPRuntime, PHP, PHPRequestHandler } from '@php-wasm/universal';
import { getPHPLoaderModule } from '@php-wasm/node-8-2';

const php = new PHP(await loadPHPRuntime(await getPHPLoaderModule()));
php.mkdirTree('/www/includes');
php.mkdirTree('/www/storage');

const runtimeFiles = [
  'api.php',
  'index.php',
  'cron.php',
  'whatsapp.php',
  'telegram-backup.php',
  'security-contact.php',
  'includes/app.php',
  'includes/security.php',
  'includes/wa.php',
  'includes/google-calendar.php',
];
for (const file of runtimeFiles) {
  php.writeFile(`/www/${file}`, fs.readFileSync(file));
}
php.writeFile('/www/cek-pasang.php', fs.readFileSync('update/cek-pasang.php'));

const adminPassword = randomBytes(20).toString('hex');
const encKey = randomBytes(32).toString('hex');
const backupSecret = randomBytes(32).toString('hex');
const cronSecret = randomBytes(32).toString('hex');

function writeConfig(extra = {}) {
  const cfg = {
    dsn: 'sqlite:/www/storage/hardening.sqlite',
    admin_bootstrap_password: adminPassword,
    telegram_backup_secret: backupSecret,
    cron_secret: cronSecret,
    ...extra,
  };
  const encoded = Buffer.from(JSON.stringify(cfg)).toString('base64');
  php.writeFile('/www/config.php', `<?php return json_decode(base64_decode('${encoded}'), true);`);
}

writeConfig();

// 1. Unit tests: Host validation, trusted proxy HTTPS, AEAD encryption + migration, JPEG EXIF scrubber, Anti-Lockout DoS
const unit = await php.run({
  code: `<?php
require '/www/includes/app.php';
function check(bool $cond, string $msg): void { if (!$cond) throw new Exception($msg); }

$pdo = app_db();

// Host Header & X-Forwarded-Proto validation
check(app_validated_http_host('anggaran.page.gd') === 'anggaran.page.gd', 'Valid domain');
check(app_validated_http_host('LOCALHOST:8080') === 'localhost:8080', 'Valid host with port');
foreach (['evil.com/path', 'evil.com@good.com', "evil.com\\r\\nX:1", 'bad:99999', '-invalid.com', ''] as $badHost) {
    check(app_validated_http_host($badHost) === 'localhost', 'Rejected bad host: ' . $badHost);
}
check(
    app_configured_base_url(['public_base_url' => ''], ['HTTP_HOST' => 'evil.com/attack', 'HTTP_X_FORWARDED_PROTO' => 'https', 'SCRIPT_NAME' => '/api.php']) === 'http://localhost',
    'Untrusted proxy proto and poisoned host rejected'
);
check(
    app_configured_base_url(['public_base_url' => 'https://anggaran.page.gd/'], ['HTTP_HOST' => 'attacker.example']) === 'https://anggaran.page.gd',
    'Explicit public_base_url overrides HTTP_HOST'
);
check(
    app_request_is_https(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'], ['trusted_proxy_ips' => ['10.0.0.1']]) === true,
    'Trusted proxy X-Forwarded-Proto honored'
);
check(
    app_request_is_https(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_PROTO' => 'https'], ['trusted_proxy_ips' => ['10.0.0.1']]) === false,
    'Untrusted proxy X-Forwarded-Proto ignored'
);
check(
    app_request_is_https(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_VISITOR' => '{"scheme":"https"}'], ['trusted_proxy_ips' => ['10.0.0.1']]) === true,
    'Trusted proxy CF-Visitor honored'
);

// Legacy plaintext secret migration -> AEAD encryption at rest
app_set_setting('fonnte_token', 'plain-fonnte-secret-token-12345');
app_set_setting('google_calendar_tokens', '{"refresh_token":"plain-google-refresh-xyz"}');
$cfgEnc = ['app_encryption_key' => '${encKey}'];
app_migrate_encrypted_settings($pdo, $cfgEnc);

$rawFonnte = (string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'fonnte_token'")->fetchColumn();
check(str_starts_with($rawFonnte, 'enc:v1:gcm:'), 'Plaintext secret migrated to AES-256-GCM');
check(strpos($rawFonnte, 'plain-fonnte-secret-token-12345') === false, 'Plaintext no longer in DB column');
check(app_decrypt_setting_value('fonnte_token', $rawFonnte, $cfgEnc) === 'plain-fonnte-secret-token-12345', 'Round-trip decryption works');

// AAD binds ciphertext to setting_key (prevents cross-key ciphertext transplant)
$transplanted = false;
try {
    app_decrypt_setting_value('wa_webhook_secret', $rawFonnte, $cfgEnc);
} catch (RuntimeException $e) {
    $transplanted = true;
}
check($transplanted, 'AAD blocks cross-setting ciphertext transplant');

// SQL backup dump must not expose plaintext secrets once encrypted
ob_start();
app_stream_sql_dump($pdo);
$sqlDump = (string) ob_get_clean();
check(strpos($sqlDump, 'plain-fonnte-secret-token-12345') === false, 'Raw plaintext absent from SQL dump');
check(strpos($sqlDump, bin2hex('plain-fonnte-secret-token-12345')) === false, 'Hex-encoded plaintext absent from SQL dump');
check(strpos($sqlDump, 'plain-google-refresh-xyz') === false && strpos($sqlDump, bin2hex('plain-google-refresh-xyz')) === false, 'Google refresh token absent from SQL dump');

// Pure-PHP JPEG EXIF/GPS & comment stripper (for servers without GD)
$seg = static fn(int $marker, string $body): string => "\\xFF" . chr($marker) . pack('n', strlen($body) + 2) . $body;
$app0 = $seg(0xE0, "JFIF\\x00");
$exifGps = $seg(0xE1, "Exif\\x00\\x00GPS-HOME-123");
$comment = $seg(0xFE, 'secret!');
$sosAndScan = $seg(0xDA, "\\x01\\x00") . "\\xAA\\xBB\\xFF\\xD9";
$jpegWithExif = "\\xFF\\xD8" . $app0 . $exifGps . $comment . $sosAndScan;
$stripped = app_strip_jpeg_metadata($jpegWithExif);
check(strpos($stripped, 'GPS-HOME-123') === false && strpos($stripped, 'secret!') === false, 'EXIF GPS and COM stripped');
check(strpos($stripped, 'JFIF') !== false && str_ends_with($stripped, "\\xFF\\xD9"), 'JFIF header and image scan preserved');

echo 'Deep hardening unit checks passed';
`,
});
assert.equal(unit.text, 'Deep hardening unit checks passed', unit.errors + unit.text);
console.log(unit.text);

// Configure encryption key + public_base_url for full HTTP integration tests
writeConfig({
  app_encryption_key: encKey,
  public_base_url: 'https://anggaran.page.gd',
});

// 2. Verify __Host- session cookie prefix over HTTPS
const httpsSession = await php.run({
  code: `<?php
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'csrf';
require '/www/api.php';
`,
});
const setCookies = httpsSession.headers['set-cookie'] || [];
assert.ok(
  setCookies.some((c) => c.startsWith('__Host-rumahku_session=') && /;\s*path=\/(?:;|$)/i.test(c) && /;\s*secure\b/i.test(c) && /;\s*httponly\b/i.test(c) && !/;\s*domain=/i.test(c)),
  'HTTPS session uses RFC 6265bis __Host- cookie prefix without Domain attribute: ' + JSON.stringify(setCookies),
);

// 3. Verify Anti-Lockout DoS: attacker on IP A gets 429 after 10 bad attempts on ALDI, while owner on IP B still logs in!
const lockoutCheck = await php.run({
  code: `<?php
require '/www/includes/app.php';
$pdo = app_db();
$adminId = (int) $pdo->query("SELECT id FROM users WHERE username = 'ALDI'")->fetchColumn();
$accountKey = 'id:' . $adminId;

// Simulate 10 failed attempts from attacker IP 203.0.113.99
$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
for ($i = 0; $i < 10; $i++) {
    app_limit_login($pdo, $accountKey);
    app_record_login_failure($pdo, $accountKey);
}
// 11th attempt from attacker IP must hit ip_account limit
$attackerRetry = app_login_limit_hit($pdo, 'ip_account:203.0.113.99:' . strtolower($accountKey), 10, time());
if ($attackerRetry <= 0) throw new Exception('Attacker IP was not throttled');

// Legitimate owner on 198.51.100.25 must NOT be locked out!
$_SERVER['REMOTE_ADDR'] = '198.51.100.25';
app_limit_login($pdo, $accountKey);
app_clear_login_pair($pdo, $accountKey);
echo 'Anti-lockout verified';
`,
});
assert.equal(lockoutCheck.text, 'Anti-lockout verified', lockoutCheck.errors + lockoutCheck.text);

const handler = new PHPRequestHandler({ php, documentRoot: '/www', absoluteUrl: 'http://localhost:8080', cookieStore: false });
function createClient() {
  let cookie = '';
  let csrf = '';
  return {
    async request(action, data, options = {}) {
      const response = await handler.request({
        url: `/api.php?action=${action}`,
        method: data === undefined ? 'GET' : 'POST',
        headers: { cookie, 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, ...options.headers },
        ...(data === undefined ? {} : { body: new TextEncoder().encode(JSON.stringify(data)) }),
      });
      for (const value of response.headers['set-cookie'] || []) {
        if (value.startsWith('rumahku_session=')) cookie = value.split(';')[0];
      }
      if (options.raw) return { status: response.httpStatusCode, headers: response.headers, text: response.text };
      const json = JSON.parse(response.text);
      if (json.csrf) csrf = json.csrf;
      return { status: response.httpStatusCode, json, headers: response.headers };
    },
    getCookie: () => cookie,
  };
}

const admin = createClient();
await admin.request('csrf');
assert.equal((await admin.request('login', { username: 'ALDI', password: adminPassword })).status, 200);

// 4. Settings exposes secrets_encrypted=true and Host-poison-proof webhook_url
const settings = await admin.request('settings/get', undefined, { headers: { Host: 'evil.attacker.invalid' } });
assert.equal(settings.status, 200);
assert.equal(settings.json.database.secrets_encrypted, true);
assert.ok(settings.json.whatsapp_bot.webhook_url.startsWith('https://anggaran.page.gd/whatsapp.php?key='));

// 5. Broken Access Control checks: target/save & reminder/send restricted appropriately
assert.equal((await admin.request('target/save', { month: '2026-11', amount: 5000000 })).status, 200);
assert.equal((await admin.request('reminder/create', { title: 'Admin Reminder', category: 'Rumah', reminder_date: '2026-12-01', reminder_time: '09:00', note: '' })).status, 200);
assert.equal((await admin.request('users/create', { username: 'anggota1', name: 'Anggota Satu', password: 'member-safe-password-1' })).status, 200);

const member = createClient();
await member.request('csrf');
assert.equal((await member.request('login', { username: 'anggota1', password: 'member-safe-password-1' })).status, 200);
assert.equal((await member.request('target/save', { month: '2026-11', amount: 100 })).status, 403, 'Member cannot overwrite household monthly target');
assert.equal((await member.request('reminder/send', { id: 1 })).status, 403, 'Member cannot manually broadcast admin reminder');

assert.equal((await member.request('reminder/create', { title: 'Member Reminder', category: 'Rumah', reminder_date: '2026-12-02', reminder_time: '10:00', note: '' })).status, 200);
const memberSendOwn = await member.request('reminder/send', { id: 2 });
assert.equal(memberSendOwn.status, 422, 'Member is authorized to trigger own reminder (fails only on offline WASM Fonnte)');

// 6. Attachment sandbox CSP & MIME whitelist enforcement
await php.run({
  code: `<?php require '/www/includes/app.php';
$pdo = app_db();
$pdo->exec("INSERT INTO attachments (id,entity_type,entity_id,mime,file_name,byte_size,image_data,uploaded_by,created_at) VALUES (50,'reminder',1,'text/html','bad.html',13,'<script>alert',1,'2026-11-24 00:00:00')");`,
});
const unsafeAttachment = await admin.request('attachment&id=50', undefined, { raw: true });
assert.equal(unsafeAttachment.status, 200);
assert.equal(unsafeAttachment.headers['content-type'][0], 'application/octet-stream');
assert.ok(unsafeAttachment.headers['content-disposition'][0].startsWith('attachment;'));
assert.ok(unsafeAttachment.headers['content-security-policy'][0].includes('sandbox'));

// 7. telegram-backup.php single-use signature replay protection
const ts = String(Math.floor(Date.now() / 1000));
const sig = createHmac('sha256', backupSecret).update(`nightly-sql-backup\n${ts}`).digest('hex');
const backupHeaders = { 'X-Backup-Timestamp': ts, 'X-Backup-Signature': sig };
const firstBackup = await handler.request({ url: '/telegram-backup.php', method: 'GET', headers: backupHeaders });
assert.equal(firstBackup.httpStatusCode, 200);
assert.ok(firstBackup.headers['content-type'][0].includes('application/sql'));
const replayBackup = await handler.request({ url: '/telegram-backup.php', method: 'GET', headers: backupHeaders });
assert.equal(replayBackup.httpStatusCode, 404, 'Replayed backup HMAC signature must be rejected');

// 8. Multi-message atomic webhook deduplication (Msg A -> Msg B -> retry Msg A within 120s)
const webhookSecret = new URL(settings.json.whatsapp_bot.webhook_url).searchParams.get('key');
await php.run({
  code: `<?php require '/www/includes/app.php'; app_db()->exec("INSERT INTO wa_contacts (phone,name,access,active,created_at) VALUES ('628111222333','Admin WA','admin',1,'2026-11-24 00:00:00')");`,
});
async function postWebhook(payload) {
  const res = await handler.request({
    url: `/whatsapp.php?key=${encodeURIComponent(webhookSecret)}`,
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: new TextEncoder().encode(JSON.stringify(payload)),
  });
  return JSON.parse(res.text);
}
const msgA = { sender: '628111222333', message: 'saldo', inboxid: 'msg-A', timestamp: '1700000001' };
const msgB = { sender: '628111222333', message: 'target', inboxid: 'msg-B', timestamp: '1700000002' };
assert.equal((await postWebhook(msgA)).authorized, true);
assert.equal((await postWebhook(msgB)).authorized, true);
assert.equal((await postWebhook(msgA)).ignored, 'pesan sudah pernah diproses', 'Msg A retry still deduplicated after Msg B');

// 9. cek-pasang.php requires admin auth & blocks unauthenticated GET self-delete
const anonDiag = await handler.request({ url: '/cek-pasang.php?hapus=1', method: 'GET' });
assert.equal(anonDiag.httpStatusCode, 403, 'Anonymous visitor blocked from cek-pasang.php');
assert.ok(php.isFile('/www/cek-pasang.php'), 'GET ?hapus=1 never deletes cek-pasang.php');

const adminDiag = await handler.request({ url: '/cek-pasang.php', method: 'GET', headers: { cookie: admin.getCookie() } });
assert.equal(adminDiag.httpStatusCode, 200);
assert.equal(adminDiag.headers['x-frame-options']?.[0], 'DENY');
const badCsrfDelete = await handler.request({
  url: '/cek-pasang.php',
  method: 'POST',
  headers: { cookie: admin.getCookie(), 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new TextEncoder().encode('aksi=hapus&_csrf=wrong'),
});
assert.equal(badCsrfDelete.httpStatusCode, 419, 'POST self-delete without valid CSRF rejected');
assert.ok(php.isFile('/www/cek-pasang.php'));

const csrfMatch = /name="_csrf" value="([^"]+)"/.exec(adminDiag.text);
assert.ok(csrfMatch?.[1]);
const validDelete = await handler.request({
  url: '/cek-pasang.php',
  method: 'POST',
  headers: { cookie: admin.getCookie(), 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new TextEncoder().encode(`aksi=hapus&_csrf=${encodeURIComponent(csrfMatch[1])}`),
});
assert.equal(validDelete.httpStatusCode, 200);
assert.ok(!php.isFile('/www/cek-pasang.php'), 'Authenticated POST with CSRF deletes cek-pasang.php');

console.log('All deep hardening HTTP & cryptographic security tests passed!');
