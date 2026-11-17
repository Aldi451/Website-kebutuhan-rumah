import fs from 'node:fs';
import assert from 'node:assert/strict';
import {randomBytes} from 'node:crypto';
import {loadPHPRuntime,PHP,PHPRequestHandler} from '@php-wasm/universal';
import {getPHPLoaderModule} from '@php-wasm/node-8-2';
const php=new PHP(await loadPHPRuntime(await getPHPLoaderModule()));
php.mkdirTree('/www/includes'); php.mkdirTree('/www/storage');
for(const f of ['api.php','includes/app.php','includes/security.php','includes/wa.php','includes/google-calendar.php']) php.writeFile('/www/'+f,fs.readFileSync(f));
const password=randomBytes(18).toString('hex');
php.writeFile('/www/config.php',`<?php return ['dsn'=>'sqlite:/www/storage/test.sqlite','admin_bootstrap_password'=>'${password}'];`);
const unit=await php.run({code:`<?php
require '/www/includes/app.php';
function check($ok,$message){if(!$ok)throw new Exception($message);}
$pdo=app_db();
check(app_notification_slot($pdo,'cooldown',1,60,1000)===0,'First allowed');
check(app_notification_slot($pdo,'cooldown',1,60,1010)===50,'Cooldown');
check(app_notification_slot($pdo,'cooldown',1,60,1059)===1,'Denial must not extend cooldown');
check(app_notification_slot($pdo,'cooldown',1,60,1060)===0,'Exact expiry');
$_SESSION=['user_id'=>1];
for($i=0;$i<30;$i++)check(app_notification_budget('whatsapp')['ok'],'Actor allowance');
check(!app_notification_budget('whatsapp')['ok'],'Actor limit');
$_SESSION=['user_id'=>2]; check(app_notification_budget('whatsapp')['ok'],'Other actor');
$_SESSION=['user_id'=>1]; check(app_notification_budget('google')['ok'],'Channels independent');
$pdo->exec('DELETE FROM security_notification_limits');
for($i=0;$i<100;$i++) {$_SESSION=['user_id'=>intdiv($i,25)+1];check(app_notification_budget('whatsapp')['ok'],'Household allowance');}
$_SESSION=['user_id'=>5];check(!app_notification_budget('whatsapp')['ok'],'Household limit');
$pdo->exec('DELETE FROM security_notification_limits');
$_SESSION=[];for($i=0;$i<30;$i++)check(app_notification_budget('whatsapp')['ok'],'System allowance');
check(!app_notification_budget('whatsapp')['ok'],'System bounded');
$pdo->exec('DELETE FROM security_notification_limits');
$_SESSION=['user_id'=>1];check(app_notification_budget('whatsapp','reminder:1')['ok'],'Resource first');
$_SESSION=['user_id'=>2];check(!app_notification_budget('whatsapp','reminder:1')['ok'],'Resource shared across users');
check(app_notification_budget('whatsapp','reminder:2')['ok'],'Different reminder');
$pdo->exec('DELETE FROM security_notification_limits');
app_set_setting('fonnte_token','fake-test-token');app_set_setting('fonnte_target','628123456789');
// WASM never calls the provider, but failed attempts must still consume quota.
for($i=0;$i<30;$i++)check(empty(app_send_whatsapp('fixture')['rate_limited']),'Outbound attempt allowance');
check(!empty(app_send_whatsapp('fixture')['rate_limited']),'Central send path enforced');
$multi=app_wa_send_to_targets('fixture',['628123456789','628123456780']);
check(!empty($multi['rate_limited'])&&$multi['sent']===0,'Broadcast propagates denial');
$pdo->exec('DELETE FROM security_notification_limits');
app_set_setting('wa_notify_types','finance');
echo 'Quota unit tests passed';`});
assert.equal(unit.text,'Quota unit tests passed',unit.errors+unit.text); console.log(unit.text);
const handler=new PHPRequestHandler({php,documentRoot:'/www',absoluteUrl:'http://localhost:8080',cookieStore:false});
let cookie='',csrf='';
async function request(action,data){
 const r=await handler.request({url:'/api.php?action='+action,method:data===undefined?'GET':'POST',headers:{cookie,'Content-Type':'application/json','X-CSRF-Token':csrf},...(data===undefined?{}:{body:new TextEncoder().encode(JSON.stringify(data))})});
 for(const c of r.headers['set-cookie']||[])if(c.startsWith('rumahku_session='))cookie=c.split(';')[0];
 const json=JSON.parse(r.text);if(json.csrf)csrf=json.csrf;return {status:r.httpStatusCode,json,headers:r.headers};
}
await request('csrf');assert.equal((await request('login',{username:'ALDI',password})).status,200);
const created=await request('reminder/create',{title:'Notification test',category:'Rumah',reminder_date:'2026-12-20',reminder_time:'09:00',note:''});
assert.equal(created.status,200);assert.equal(created.json.whatsapp_sent,false);
assert.ok(created.json.whatsapp_message.includes('dimatikan'));
let count=await php.run({code:`<?php require '/www/includes/app.php';echo app_db()->query('SELECT COUNT(*) FROM security_notification_limits')->fetchColumn();`});
assert.equal(count.text,'0','Disabled automatic notifications must not even reserve a provider slot');
assert.equal((await request('reminder/send',{id:1})).status,422,'Preview cannot reach provider');
let denied=await request('reminder/send',{id:1});
assert.equal(denied.status,429);assert.ok(Number(denied.headers['retry-after'][0])>0);
await request('logout',{});await request('login',{username:'ALDI',password});
assert.equal((await request('reminder/send',{id:1})).status,429,'New session cannot bypass cooldown');
// Force an exhausted budget; a new reminder still saves successfully.
await php.run({code:`<?php require '/www/includes/app.php'; app_set_setting('wa_notify_types','reminders'); $_SESSION=['user_id'=>1];for($i=0;$i<30;$i++)app_notification_budget('whatsapp');`});
const limitedCreate=await request('reminder/create',{title:'Saved under quota limit',category:'Rumah',reminder_date:'2026-12-21',reminder_time:''});
assert.equal(limitedCreate.status,200);assert.equal(limitedCreate.json.whatsapp_sent,false);assert.ok(limitedCreate.json.whatsapp_message.includes('Batas pengiriman'));
const reminders=await request('reminders');assert.equal(reminders.json.reminders.length,2);
// Exhaust Google budget before calling the route: no live OAuth/network involved.
await php.run({code:`<?php require '/www/includes/app.php';require '/www/includes/google-calendar.php';app_gc_store('config',['enabled'=>true,'client_id'=>'fixture','client_secret'=>'fixture']);app_gc_store('tokens',['refresh_token'=>'fixture']);$_SESSION=['user_id'=>1];for($i=0;$i<30;$i++)app_notification_budget('google');`});
const google=await request('google/send',{id:2});assert.equal(google.status,429);assert.ok(Number(google.headers['retry-after'][0])>0);
// Database failure must fail closed rather than allowing a provider request.
const failure=await php.run({code:`<?php require '/www/includes/app.php';app_db()->exec('DROP TABLE security_notification_limits');app_db()->exec('CREATE TABLE security_notification_limits (broken INTEGER)');echo json_encode(app_send_whatsapp('fixture'));`});
assert.equal(JSON.parse(failure.text).ok,false);assert.equal(JSON.parse(failure.text).rate_limited,true);
console.log('Quota HTTP tests passed: disabled notifications, 429/Retry-After, persistence across login, saved reminder on denial, fail-closed quota storage.');
