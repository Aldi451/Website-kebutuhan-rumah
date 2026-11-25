import fs from 'node:fs';
import assert from 'node:assert/strict';
import {randomBytes} from 'node:crypto';
import {loadPHPRuntime,PHP,PHPRequestHandler} from '@php-wasm/universal';
import {getPHPLoaderModule} from '@php-wasm/node-8-2';

const php = new PHP(await loadPHPRuntime(await getPHPLoaderModule()));
php.mkdirTree('/www/includes');
php.mkdirTree('/www/storage');
for (const file of ['api.php', 'includes/app.php', 'includes/security.php', 'includes/wa.php', 'includes/google-calendar.php']) {
  php.writeFile('/www/' + file, fs.readFileSync(file));
}
const password = randomBytes(16).toString('hex');
php.writeFile('/www/config.php', `<?php return ['dsn'=>'sqlite:/www/storage/test.sqlite','admin_bootstrap_password'=>'${password}'];`);

const seeded = await php.run({
  code: `<?php
require '/www/includes/app.php';
$pdo = app_db();
$start = new DateTimeImmutable('first day of this month');
$prevMonth = $start->modify('-1 month');
$q = $pdo->prepare("INSERT INTO transactions (kind,title,category,amount,transaction_date,user_id,created_at) VALUES (?,?, 'Test', ?, ?, 1, '2020-01-01 00:00:00')");
// Old year (-1 year): -10
$q->execute(['expense', 'old-year', 10, $start->modify('-1 year')->format('Y-m-01')]);
// Previous month: +250 - 40 = +210 -> cumulative ending at end of prevMonth = -10 + 210 = 200
$q->execute(['income', 'prev-income', 250, $prevMonth->format('Y-m-15')]);
$q->execute(['expense', 'previous', 40, $start->modify('-1 day')->format('Y-m-d')]);
// Current month: -10 - 10 = -20 -> cumulative ending at end of currentMonth = 200 - 20 = 180
$q->execute(['expense', 'first', 10, $start->format('Y-m-01')]);
$q->execute(['expense', 'last', 10, $start->format('Y-m-t')]);
// Future month (+1 month): -999 (must NOT affect currentMonth or prevMonth ending balance!)
$q->execute(['expense', 'next', 999, $start->modify('+1 month')->format('Y-m-01')]);
echo json_encode(['current' => $start->format('Y-m'), 'prev' => $prevMonth->format('Y-m')]);
`,
});
const months = JSON.parse(seeded.text);

const handler = new PHPRequestHandler({ php, documentRoot: '/www', absoluteUrl: 'http://localhost:8080', cookieStore: false });
let cookie = '';
let csrf = '';
async function rawRequest(action, data) {
  const r = await handler.request({
    url: '/api.php?action=' + action,
    method: data ? 'POST' : 'GET',
    headers: { cookie, 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
    ...(data ? { body: new TextEncoder().encode(JSON.stringify(data)) } : {}),
  });
  for (const c of r.headers['set-cookie'] || []) {
    if (c.startsWith('rumahku_session=')) cookie = c.split(';')[0];
  }
  return r;
}
async function request(action, data) {
  const r = await rawRequest(action, data);
  assert.equal(r.httpStatusCode, 200, r.text);
  const result = JSON.parse(r.text);
  if (result.csrf) csrf = result.csrf;
  return result;
}

await request('csrf');
await request('login', { username: 'ALDI', password });

// 1. Previous month ending balance: opening (-10 from old-year) + net (+250 - 40 = +210) = 200
const prevResult = await request('dashboard&month=' + encodeURIComponent(months.prev));
assert.equal(prevResult.month, months.prev);
assert.equal(prevResult.summary.opening_balance, -10);
assert.equal(prevResult.summary.income, 250);
assert.equal(prevResult.summary.expense, 40);
assert.equal(prevResult.summary.period_net, 210);
assert.equal(prevResult.summary.balance, 200, 'Ending balance of prevMonth = -10 + 210 = 200 (ignores current & future months)');
assert.equal(prevResult.chart.at(-1)?.month, months.prev);

// 2. Current month carries forward exact ending balance of prevMonth (200) as opening_balance!
let result = await request('dashboard');
assert.equal(result.month, months.current);
assert.deepEqual(result.transactions.map((t) => t.title), ['last', 'first']);
assert.equal(result.summary.opening_balance, prevResult.summary.balance, 'Opening balance of current month equals ending balance of previous month (200)');
assert.equal(result.summary.previous_month, months.prev);
assert.equal(result.summary.income, 0);
assert.equal(result.summary.expense, 20);
assert.equal(result.summary.period_net, -20);
assert.equal(result.summary.balance, 180, 'Ending balance of current month = 200 + (0 - 20) = 180, ignoring future month -999');

// 3. Explicit Jan 2026 -> Feb 2026 chain check (exact user scenario)
await php.run({
  code: `<?php
require '/www/includes/app.php';
$pdo = app_db();
$pdo->exec('DELETE FROM transactions');
$q = $pdo->prepare("INSERT INTO transactions (kind,title,category,amount,transaction_date,user_id,created_at) VALUES (?,?, 'Audit', ?, ?, 1, '2026-01-01 00:00:00')");
$q->execute(['income', 'Gaji Jan', 5000000, '2026-01-05']);
$q->execute(['expense', 'Belanja Jan', 3200000, '2026-01-20']); // Ending Jan 2026 = 1.800.000
$q->execute(['income', 'Gaji Feb', 5000000, '2026-02-05']);
$q->execute(['expense', 'Belanja Feb', 4100000, '2026-02-18']); // Net Feb = +900.000 -> Ending Feb 2026 = 2.700.000
$q->execute(['expense', 'Belanja Mar', 1500000, '2026-03-10']); // Ending Mar 2026 = 1.200.000
`,
});
const jan2026 = await request('dashboard&month=2026-01');
assert.equal(jan2026.summary.opening_balance, 0);
assert.equal(jan2026.summary.balance, 1800000);

const feb2026 = await request('dashboard&month=2026-02');
assert.equal(feb2026.summary.opening_balance, 1800000, 'Feb 2026 starts from Jan 2026 ending balance');
assert.equal(feb2026.summary.period_net, 900000);
assert.equal(feb2026.summary.balance, 2700000, 'Feb 2026 ending balance = Ending Jan (1.800.000) + Feb net (900.000)');

const mar2026 = await request('dashboard&month=2026-03');
assert.equal(mar2026.summary.opening_balance, 2700000, 'Mar 2026 starts from Feb 2026 ending balance');
assert.equal(mar2026.summary.balance, 1200000);

// 4. Invalid month format rejected with HTTP 422
const badMonth = await rawRequest('dashboard&month=2026-13');
assert.equal(badMonth.httpStatusCode, 422);

// 5. Seven-row limit and ordering for selected period
await php.run({
  code: `<?php
require '/www/includes/app.php';
$pdo = app_db();
$q = $pdo->prepare("INSERT INTO transactions (kind,title,category,amount,transaction_date,user_id,created_at) VALUES ('income',?,'Test',5,?,1,'2026-02-01 00:00:00')");
for ($i = 0; $i < 10; $i++) $q->execute(['extra-' . $i, '2026-02-28']);
`,
});
const febWithExtras = await request('dashboard&month=2026-02');
assert.equal(febWithExtras.transactions.length, 7);
assert.deepEqual(febWithExtras.transactions.map((t) => t.title), [9, 8, 7, 6, 5, 4, 3].map((i) => 'extra-' + i));

const source = fs.readFileSync('assets/app.js', 'utf8');
assert.ok(source.includes('data-dashboard-month'));
assert.ok(source.includes('opening_balance'));
assert.ok(source.includes('Transaksi bulan ini'));
assert.ok(source.includes('Belum ada transaksi bulan ini'));

console.log('Dashboard cumulative period ending-balance tests passed!');
