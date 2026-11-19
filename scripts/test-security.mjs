import fs from 'node:fs';
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { loadPHPRuntime, PHP, PHPRequestHandler } from '@php-wasm/universal';
import { getPHPLoaderModule } from '@php-wasm/node-8-2';
const php = new PHP(await loadPHPRuntime(await getPHPLoaderModule()));
php.mkdirTree('/www/includes'); php.mkdirTree('/www/storage');
for (const file of ['api.php', 'index.php', 'includes/app.php', 'includes/wa.php', 'includes/security.php', 'includes/google-calendar.php']) {
  php.writeFile(`/www/${file}`, fs.readFileSync(file));
  const lint = await php.run({code: `<?php token_get_all(file_get_contents('/www/${file}'), TOKEN_PARSE); echo 'valid';`});
  assert.equal(lint.text, 'valid', lint.errors);
}
const password = randomBytes(20).toString('hex');
const config = (value) => php.writeFile('/www/config.php', `<?php return ['dsn'=>'sqlite:/www/storage/test.sqlite','admin_bootstrap_password'=>'${value}'];`);
config('');
let result = await php.run({code: `<?php require '/www/includes/app.php'; echo app_db()->query("SELECT COUNT(*) FROM users")->fetchColumn();`});
assert.equal(result.text, '0', 'No universal default admin is created');
config(password);
result = await php.run({code: `<?php
require '/www/includes/app.php';
function check($condition, $message) { if (!$condition) throw new Exception($message); }
$pdo = app_db();
$user = $pdo->query("SELECT * FROM users WHERE role='admin'")->fetch();
check(password_verify(app_config()['admin_bootstrap_password'], $user['password_hash']), 'Bootstrap password');
check(!app_valid_new_password('admin123'), 'Reject old default');
check(!app_valid_new_password(str_repeat('a', 73)), 'No bcrypt truncation');
check(app_valid_new_password(str_repeat('a', 12)), '12 byte boundary');
check(app_valid_new_password(str_repeat('a', 72)), '72 byte boundary');
check(!app_auth_session_valid($user, time()), 'Pre-upgrade sessions invalid');
app_bind_auth_session($user['password_hash']);
check(app_auth_session_valid($user, time()), 'Current session valid');
check(!app_auth_session_valid($user, time()+1801), 'Idle timeout');
$_SESSION['auth_last_seen'] = time()+43201;
check(!app_auth_session_valid($user, time()+43201), 'Absolute timeout');
app_bind_auth_session($user['password_hash']);
$changed = $user; $changed['password_hash'] = password_hash('a-new-strong-password', PASSWORD_DEFAULT);
check(!app_auth_session_valid($changed, time()), 'Password change revokes session');
for ($i=0; $i<10; $i++) check(app_login_limit_hit($pdo, 'test-account', 10, 1000) === 0, 'Allowed attempt');
check(app_login_limit_hit($pdo, 'test-account', 10, 1000) === 900, 'Threshold enforced');
check(app_login_limit_hit($pdo, 'test-account', 10, 1900) === 0, 'Window expires');
check(app_login_limit_hit($pdo, 'other-account', 10, 1000) === 0, 'Independent bucket');
$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash('admin123', PASSWORD_DEFAULT), $user['id']]);
app_bootstrap_admin($pdo);
check(password_verify(app_config()['admin_bootstrap_password'], $pdo->query('SELECT password_hash FROM users')->fetchColumn()), 'Legacy default recovery');
$custom = password_hash('custom-strong-password', PASSWORD_DEFAULT);
$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$custom, $user['id']]);
app_bootstrap_admin($pdo);
check($pdo->query('SELECT password_hash FROM users')->fetchColumn() === $custom, 'Never overwrite customized password');
$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$user['password_hash'], $user['id']]);
echo 'Security unit tests passed';
`});
assert.equal(result.text, 'Security unit tests passed', result.errors + result.text);
console.log(result.text);
const handler = new PHPRequestHandler({php, documentRoot:'/www', absoluteUrl:'http://localhost:8080', cookieStore:false});
function browser() {
  let cookie = ''; let csrf = '';
  return {
    async request(action, data, options = {}) {
      const response = await handler.request({url: `/api.php?action=${action}`, method:data === undefined ? 'GET':'POST', headers: {cookie, 'Content-Type':'application/json', 'X-CSRF-Token':csrf, ...options.headers}, ...(data === undefined ? {} : {body:new TextEncoder().encode(JSON.stringify(data))})});
      for (const value of response.headers['set-cookie'] || []) if (value.startsWith('rumahku_session=')) cookie = value.split(';')[0];
      if (options.raw) return {status:response.httpStatusCode, headers:response.headers, text:response.text};
      let json; try {json = JSON.parse(response.text);} catch {throw new Error(response.errors + response.text);}
      if (json.csrf) csrf = json.csrf;
      return {status:response.httpStatusCode, json, headers:response.headers};
    }
  };
}
const page = await handler.request({url:'/index.php'});
const nonce = /<script nonce="([^"]+)"/.exec(page.text)?.[1];
assert.ok(nonce && page.headers['content-security-policy'][0].includes(`'nonce-${nonce}'`), 'Bootstrap nonce matches CSP');
const nextPage = await handler.request({url:'/index.php'});
assert.notEqual(/<script nonce="([^"]+)"/.exec(nextPage.text)?.[1], nonce, 'Nonce is unique per response');
const a = browser(), b = browser(), guest = browser();
assert.equal((await guest.request('settings/get')).status,401);
assert.equal((await guest.request('google/save', {}, {headers:{'X-CSRF-Token':''}})).status,419);
for (const client of [a,b]) {
  await client.request('csrf');
  const login = await client.request('login', {username:'ALDI', password});
  assert.equal(login.status,200,JSON.stringify(login.json));
}
await php.run({code:`<?php require '/www/includes/app.php'; app_db()->exec("INSERT INTO attachments (entity_type,entity_id,mime,file_name,byte_size,image_data,uploaded_by,created_at) VALUES ('reminder',1,'image/png','fixture.png',4,'test',1,'2026-11-16 00:00:00')");`});
const attachment = await a.request('attachment&id=1', undefined, {raw:true});
assert.equal(attachment.status,200);
assert.ok(attachment.headers['cache-control'][0].includes('no-store'), 'Private receipt is not cached after logout');
const me = await a.request('me');
assert.equal(me.status,200);
assert.ok(!JSON.stringify(me.json).includes('password_hash'));
assert.ok(me.headers['content-security-policy'][0].includes("object-src 'none'"));
assert.equal(me.headers['referrer-policy'][0], 'no-referrer');
assert.equal((await a.request('password/change',{current_password:password,new_password:'new-password-for-testing-only'})).status,200);
assert.equal((await a.request('me')).status,200, 'Current session remains usable');
assert.equal((await b.request('me')).status,401, 'Other session revoked immediately');
assert.equal((await a.request('users/create',{username:'testmember',name:'Member',password:'member-test-password'})).status,200);
const member=browser(); await member.request('csrf');
assert.equal((await member.request('login',{username:'testmember',password:'member-test-password'})).status,200);
assert.equal((await member.request('google/save',{})).status,403);
assert.equal((await member.request('backup/download')).status,403);
// Shared reading is intentional; mutating another member/admin's data is not.
await php.run({code:`<?php require '/www/includes/app.php';$pdo=app_db();$pdo->exec("INSERT INTO transactions (kind,title,category,amount,transaction_date,user_id,created_at) VALUES ('expense','Owner fixture','Rumah',100,'2026-11-16',1,'2026-11-16 00:00:00')");$pdo->exec("INSERT INTO reminders (title,category,reminder_at,has_time,status,created_by,created_at) VALUES ('Owner reminder','Rumah','2026-12-01 09:00:00',1,'pending',1,'2026-11-16 00:00:00')");`});
for (const [action,data] of [
 ['transaction/update',{id:1,title:'Unauthorized change'}],
 ['transaction/delete',{id:1}],
 ['reminder/update',{id:1,title:'Unauthorized change'}],
 ['reminder/delete',{id:1}],
 ['attachment/save',{entity_type:'transaction',entity_id:1}],
 ['attachment/delete',{id:1}],
 ['google/send',{id:1}],
 ['users/reset-password',{id:1,password:'unauthorized-new-password'}],
]) assert.equal((await member.request(action,data)).status,403,action+' ownership/role');
assert.equal((await member.request('reminders')).status,200,'Household reading remains shared by design');
assert.equal((await member.request('transaction/create',{kind:'expense',title:'Member own',category:'Rumah',amount:50,date:'2026-11-16',user_id:1})).status,200);
const actualOwner=await php.run({code:`<?php require '/www/includes/app.php';echo app_db()->query("SELECT user_id FROM transactions WHERE title='Member own'")->fetchColumn();`});
assert.notEqual(actualOwner.text,'1','Client user_id cannot impersonate another owner');
const ownerTitle=await php.run({code:`<?php require '/www/includes/app.php';echo app_db()->query('SELECT title FROM transactions WHERE id=1')->fetchColumn();`});
assert.equal(ownerTitle.text,'Owner fixture','Denied operations leave the owner data intact');

// Cancellation is owner/admin-only, persisted, filterable and cannot be sent.
assert.equal((await member.request('reminder/toggle',{id:1,status:'cancel'})).status,403);
assert.equal((await a.request('reminder/toggle',{id:1,status:'unknown'})).status,422);
assert.equal((await a.request('reminder/toggle',{id:99999,status:'cancel'})).status,404);
assert.equal((await a.request('reminder/toggle',{id:1,status:'cancel'},{headers:{'X-CSRF-Token':''}})).status,419);
assert.equal((await a.request('reminder/toggle',{id:1,status:'done'})).status,200);
assert.equal((await a.request('reminder/toggle',{id:1,status:'cancel'})).status,200);
let cancelled=(await a.request('reminders&status=cancel')).json.reminders;
assert.equal(cancelled.length,1);assert.equal(cancelled[0].status,'cancel');
assert.equal(cancelled[0].completed_at,null);
assert.equal((await a.request('reminders&status=pending')).json.reminders.length,0);
assert.equal((await a.request('reminder/send',{id:1})).status,422);
assert.equal((await member.request('reminder/toggle',{id:1})).status,403);
assert.equal((await a.request('reminder/toggle',{id:1})).status,200);
assert.equal((await a.request('reminders&status=pending')).json.reminders.length,1);
assert.equal((await a.request('reminders&status=cancel')).json.reminders.length,0);
console.log('Cancel API tests passed: authorization, CSRF, validation, persistence, filter, delivery rejection and reactivation.');

// Editing persists fields without silently changing pending/done/cancel status.
for (const status of ['pending','done','cancel']) {
  assert.equal((await a.request('reminder/toggle',{id:1,status})).status,200);
  const payload={id:1,title:'Edited '+status,category:'Tagihan',reminder_date:'2026-12-19',reminder_time:status==='done'?'':'10:30',note:'Updated fixture'};
  assert.equal((await a.request('reminder/update',payload)).status,200);
  const saved=(await a.request('reminders')).json.reminders.find(r=>r.id===1);
  assert.equal(saved.title,payload.title);assert.equal(saved.category,'Tagihan');assert.equal(saved.note,'Updated fixture');
  assert.equal(saved.reminder_at.slice(0,10),'2026-12-19');assert.equal(saved.has_time,status==='done'?0:1);
  assert.equal(saved.status,status);
  assert.equal((await member.request('reminder/update',payload)).status,403);
}
console.log('Reminder edit persistence tests passed for all statuses; other-member edits denied.');

const users = (await a.request('users/list')).json;
const memberId = users.users.find(u=>u.username==='testmember').id;
assert.equal((await a.request('users/reset-password',{id:memberId,password:'reset-member-password'})).status,200);
assert.equal((await member.request('me')).status,401,'Admin reset revokes member session');
const attacker = browser(); await attacker.request('csrf');
for(let i=0;i<10;i++) assert.equal((await attacker.request('login',{username:'nonexistent',password:'wrong-password'})).status,401);
assert.equal((await attacker.request('login',{username:'nonexistent',password:'wrong-password'})).status,429);
// Legacy public password must not work when the recovery config is absent.
config('');
await php.run({code:`<?php require '/www/includes/app.php'; app_db()->prepare("UPDATE users SET password_hash = ? WHERE role = 'admin'")->execute([password_hash('admin123', PASSWORD_DEFAULT)]);`});
const legacy=browser(); await legacy.request('csrf');
assert.equal((await legacy.request('login',{username:'ALDI',password:'admin123'})).status,403);
console.log('Security HTTP tests passed: auth, CSRF, headers, session revocation, admin restrictions, persistent throttling, legacy default rejection.');
