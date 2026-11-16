import fs from 'node:fs';
import assert from 'node:assert/strict';
import {loadPHPRuntime, PHP, PHPRequestHandler} from '@php-wasm/universal';
import {getPHPLoaderModule} from '@php-wasm/node-8-2';
const php = new PHP(await loadPHPRuntime(await getPHPLoaderModule()));
php.mkdirTree('/www/includes'); php.mkdirTree('/www/storage');
for (const file of ['api.php','index.php','security-contact.php','cron.php','whatsapp.php','telegram-backup.php','includes/app.php','includes/security.php','includes/wa.php','includes/google-calendar.php']) {
  php.writeFile('/www/'+file, fs.readFileSync(file));
  const lint = await php.run({code:`<?php token_get_all(file_get_contents('/www/${file}'), TOKEN_PARSE); echo 'valid';`});
  assert.equal(lint.text,'valid',lint.errors);
}
const configuration = (extra={}) => php.writeFile('/www/config.php', `<?php return json_decode(base64_decode('${Buffer.from(JSON.stringify({dsn:'sqlite:/www/storage/test.sqlite',...extra})).toString('base64')}'),true);`);
configuration();
const unit = await php.run({code:`<?php
require '/www/includes/security.php';
function check($value, $label) {if (!$value) throw new Exception($label);}
check(app_request_is_https(['HTTPS'=>'on']), 'HTTPS on');
check(app_request_is_https(['HTTPS'=>'1']), 'HTTPS 1');
check(!app_request_is_https(['HTTPS'=>'off','HTTP_X_FORWARDED_PROTO'=>'https']), 'Spoofed forwarded header ignored');
check(!app_request_is_https([]), 'HTTP remains HTTP');
$prod = app_security_header_values(false, true, 86400);
check($prod['Strict-Transport-Security'] === 'max-age=86400', 'HSTS staged max-age');
check($prod['X-Frame-Options'] === 'SAMEORIGIN', 'Production framing');
check($prod['Cross-Origin-Opener-Policy'] === 'same-origin', 'COOP');
check($prod['Cross-Origin-Resource-Policy'] === 'same-origin', 'CORP');
check($prod['Cross-Origin-Embedder-Policy'] === 'require-corp', 'COEP');
check(strpos($prod['Content-Security-Policy'], "frame-ancestors 'self'") !== false, 'CSP framing');
check(app_security_header_values(false,true,0)['Strict-Transport-Security'] === 'max-age=0', 'HSTS rollback');
check(!isset(app_security_header_values(false,false,86400)['Strict-Transport-Security']), 'No HSTS over HTTP');
$preview = app_security_header_values(true,false,86400);
check(!isset($preview['X-Frame-Options'], $preview['Cross-Origin-Embedder-Policy']), 'Preview can be embedded');
$now = strtotime('2026-11-16T00:00:00Z');
$c = ['security_contact'=>'mailto:security@example.test','security_contact_expires'=>'2027-05-15T00:00:00Z'];
check(strpos(app_security_contact_text($c,$now), "Contact: mailto:security@example.test\\n") === 0, 'Mail contact');
check(app_security_contact_text([],$now) === null, 'Missing contact not fabricated');
check(app_security_contact_text(array_replace($c,['security_contact'=>'https://example.test/report']),$now) !== null, 'HTTPS report form');
foreach (['http://example.test/report','mailto:invalid','mailto:a@example.test\\nContact: https://evil.test','https://user:secret@example.test/report'] as $bad) {
 check(app_security_contact_text(array_replace($c,['security_contact'=>$bad]),$now) === null, 'Invalid contact rejected');
}
foreach (['2025-01-01T00:00:00Z','2028-01-01T00:00:00Z','2027-02-30T00:00:00Z','bad'] as $bad) {
 check(app_security_contact_text(array_replace($c,['security_contact_expires'=>$bad]),$now) === null, 'Invalid/expired expiry rejected');
}
echo 'Assessment header/contact unit tests passed';
`});
assert.equal(unit.text,'Assessment header/contact unit tests passed',unit.errors+unit.text);
console.log(unit.text);
const handler = new PHPRequestHandler({php,documentRoot:'/www',absoluteUrl:'http://localhost:8080',cookieStore:false});
for (const [url, status] of [['/api.php?action=me',401],['/cron.php',403],['/whatsapp.php',403],['/telegram-backup.php',404],['/security-contact.php',503]]) {
  const r = await handler.request({url});
  assert.equal(r.httpStatusCode,status,`${url}: ${r.text}`);
  assert.equal(r.headers['x-content-type-options']?.[0],'nosniff',url);
  assert.equal(r.headers['referrer-policy']?.[0],'no-referrer',url);
  assert.ok(r.headers['content-security-policy']?.[0],url);
  assert.ok(r.headers['cache-control']?.[0].includes('no-store'),url);
  assert.ok(!r.headers['x-powered-by'],url);
}
for (const method of ['PUT','DELETE','OPTIONS']) {
 const r = await handler.request({url:'/api.php?action=health',method});
 assert.equal(r.httpStatusCode,405); assert.equal(r.headers.allow?.[0],'GET, POST');
}
configuration({security_contact:'mailto:security@example.test',security_contact_expires:new Date(Date.now()+30*86400000).toISOString().replace(/\.\d{3}Z$/,'Z')});
const contact = await handler.request({url:'/security-contact.php'});
assert.equal(contact.httpStatusCode,200);
assert.ok(contact.headers['content-type'][0].startsWith('text/plain'));
assert.ok(contact.text.includes('Contact: mailto:security@example.test'));
assert.ok(!contact.headers['set-cookie'], 'Public contact does not create a session');
const head = await handler.request({url:'/security-contact.php',method:'HEAD'});
assert.equal(head.httpStatusCode,200); assert.equal(head.text,'');
assert.equal((await handler.request({url:'/security-contact.php',method:'POST'})).httpStatusCode,405);
// Header generation is exercised with HTTPS server metadata; this is not a real TLS handshake.
const secure = await php.run({code:`<?php $_SERVER['HTTPS']='on'; require '/www/includes/app.php'; echo 'ok';`});
assert.equal(secure.headers['strict-transport-security']?.[0],'max-age=86400');
console.log('Assessment HTTP tests passed: unauthenticated/error endpoints, no-store, method restrictions, contact readiness, HEAD, HSTS metadata.');
