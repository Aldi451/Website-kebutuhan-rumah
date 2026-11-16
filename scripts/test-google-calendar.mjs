import fs from 'node:fs';
import assert from 'node:assert/strict';
import { loadPHPRuntime, PHP } from '@php-wasm/universal';
import { getPHPLoaderModule } from '@php-wasm/node-8-2';
const runtime = await loadPHPRuntime(await getPHPLoaderModule());
const php = new PHP(runtime);
php.mkdirTree('/test');
for (const file of ['api.php', 'index.php', 'includes/google-calendar.php']) {
  const name = '/test/' + file.replaceAll('/', '-');
  php.writeFile(name, fs.readFileSync(file));
  const lint = await php.run({ code: `<?php token_get_all(file_get_contents('${name}'), TOKEN_PARSE); echo 'valid';` });
  assert.equal(lint.text, 'valid', `${file}: ${lint.errors}`);
}
const result = await php.run({ code: `<?php
$settings = [];
function app_setting($key, $fallback = null) { global $settings; return $settings[$key] ?? $fallback; }
function app_set_setting($key, $value) { global $settings; $settings[$key] = $value; }
function app_db() { static $pdo; if (!$pdo) { $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); $pdo->exec("CREATE TABLE reminders (id INTEGER, title TEXT, category TEXT, note TEXT, reminder_at TEXT, has_time INTEGER, status TEXT)"); $pdo->exec("INSERT INTO reminders VALUES (1,'Bayar tagihan','Rumah','Catatan','2026-12-31 23:45:00',1,'pending')"); } return $pdo; }
function check($ok, $message) { if (!$ok) throw new Exception($message); }
require '/test/includes-google-calendar.php';
date_default_timezone_set('Asia/Jakarta');
$r = ['title' => 'Tagihan', 'category' => 'Rumah', 'note' => 'Catatan', 'reminder_at' => '2026-12-31 23:45:00', 'has_time' => 1];
$e = app_gc_event($r, 30);
check($e['start']['dateTime'] === '2026-12-31T23:45:00+07:00', 'Timezone');
check($e['end']['dateTime'] === '2027-01-01T00:15:00+07:00', 'Rollover');
check($e['reminders']['overrides'][0]['minutes'] === 30, 'Alarm');
check($e['reminders']['useDefault'] === false, 'Explicit alarm');
$r['has_time'] = 0;
check(app_gc_event($r, 0)['start']['dateTime'] === '2026-12-31T09:00:00+07:00', 'No-time fallback');
check(app_gc_create(1)['skipped'] === true, 'Disabled integration');
app_gc_store('config', ['enabled' => true, 'client_id' => 'id', 'client_secret' => 'do-not-expose', 'minutes' => 10]);
$status = app_gc_status();
check($status['has_secret'] === true && !isset($status['client_secret']), 'Secret redaction');
check(app_gc_create(1)['ok'] === false, 'Missing connection must not report success');
check(strpos(app_setting('google_calendar_error'), 'Hubungkan') !== false, 'Persist failure');
check((int) app_db()->query('SELECT COUNT(*) FROM reminders')->fetchColumn() === 1, 'Reminder survives Google failure');
app_gc_store('tokens', ['refresh_token' => 'private-token']);
check(app_gc_status()['connected'] === true && !isset(app_gc_status()['refresh_token']), 'Token redaction');
class JsonReply extends Exception { public $data; public $status; function __construct($data, $status) { parent::__construct('reply'); $this->data = $data; $this->status = $status; } }
function app_json($data, $status = 200) { throw new JsonReply($data, $status); }
function app_require_admin($user) { if ($user['role'] !== 'admin') app_json(['ok' => false], 403); }
function route($action, $input, $role = 'admin') { try { app_gc_routes($action, 'POST', $input, ['role' => $role]); } catch (JsonReply $e) { return $e; } throw new Exception('Expected JSON reply'); }
$_SESSION = [];
check(route('google/save', [], 'member')->status === 403, 'Admin only config');
check(route('google/save', [])->status === 422, 'Invalid configuration');
$config = ['client_id' => 'test-client', 'client_secret' => 'secret', 'redirect_uri' => 'https://rumah.example/api.php?action=google/callback', 'enabled' => true, 'minutes' => 10];
check(route('google/save', $config)->status === 200, 'Save config');
check(!app_gc_status()['connected'], 'Changing credentials clears connection');
$config['client_secret'] = '';
check(route('google/save', $config)->status === 200 && app_gc_config()['client_secret'] === 'secret', 'Blank preserves secret');
$reply = route('google/connect', []);
parse_str(parse_url($reply->data['url'], PHP_URL_QUERY), $auth);
check($auth['access_type'] === 'offline' && $auth['code_challenge_method'] === 'S256', 'Offline PKCE');
check(strlen($_SESSION['google_oauth']['state']) === 64 && $auth['state'] === $_SESSION['google_oauth']['state'], 'Session state');
check(strpos($reply->data['url'], 'secret') === false, 'No secret in authorization URL');
app_gc_store('tokens', ['refresh_token' => 'private']);
check(route('google/disconnect', [])->status === 200, 'Disconnect');
check(!app_gc_status()['connected'] && !app_gc_status()['enabled'] && !isset($_SESSION['google_oauth']), 'Disconnect clears tokens and pending OAuth');
echo 'Google Calendar PHP tests passed';
` });
assert.equal(result.text, 'Google Calendar PHP tests passed', result.errors + result.text);
console.log(result.text);
