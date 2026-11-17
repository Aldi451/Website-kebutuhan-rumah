import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {randomBytes} from 'node:crypto';
import {loadPHPRuntime, PHP, PHPRequestHandler} from '@php-wasm/universal';
import {getPHPLoaderModule} from '@php-wasm/node-8-2';

// Execute the actual frontend helper without starting the UI. Only startup/render
// are stubbed; request construction, report state, markup and CSV use production code.
const content = {innerHTML:''};
let blob, download;
const context = {
  window:{APP_BOOT:{user:{id:1,role:'admin'},csrf:'',timezone:'Asia/Jakarta'},setTimeout(){}},
  document:{getElementById:()=>content,addEventListener(){},body:{appendChild(){}},createElement:()=>({click(){download=this.download;},remove(){}})},
  URLSearchParams, Blob, URL:{createObjectURL(value){blob=value;return 'blob:test';},revokeObjectURL(){}},
};
const source = fs.readFileSync('assets/app.js','utf8');
const end = source.lastIndexOf('  render();');
assert.ok(end>0);
vm.runInNewContext(source.slice(0,end)+`  globalThis.test = {api,state,reportRequestKey,refreshPage,reportMarkup,downloadReportCsv};\n  renderPageContent = () => {};\n`+source.slice(end+'  render();'.length),context);
const {api,state,reportRequestKey,refreshPage,reportMarkup,downloadReportCsv} = context.test;
let requested;
context.fetch = async url => {requested=url;return {ok:true,status:200,json:async()=>({ok:true})};};
state.reportAnchorDate='2026-02-28'; state.reportKind='expense'; state.reportCategory='Makan & minum';
await api(`report&${reportRequestKey()}`);
const params = new URL(requested,'https://test.invalid').searchParams;
assert.equal(params.get('anchor_date'),'2026-02-28');
assert.equal(params.get('kind'),'expense');
assert.equal(params.get('category'),'Makan & minum');
assert.equal(params.get('compare'),'1');
assert.equal(params.get('compare_period'),'previous');
await api('transactions&month=2026-02');
assert.equal(requested,'/api.php?action=transactions&month=2026-02');
await api('health'); assert.equal(requested,'/api.php?action=health');

// A late response/error from a previous filter must not replace the latest report.
state.page='report';
const pending=[];
context.fetch = url => new Promise(resolve=>pending.push({url,resolve}));
const old=refreshPage(true);
state.reportAnchorDate='2026-03-01'; const latest=refreshPage(true);
pending[1].resolve({ok:true,status:200,json:async()=>({ok:true,marker:'latest'})}); await latest;
pending[0].resolve({ok:true,status:200,json:async()=>({ok:true,marker:'old'})}); await old;
assert.equal(state.report.marker,'latest');
const stale=refreshPage(true); state.reportAnchorDate='2026-03-02'; content.innerHTML='new page';
pending[2].resolve({ok:false,status:422,json:async()=>({ok:false,message:'stale error'})}); await stale;
assert.equal(content.innerHTML,'new page');

// Feed requests from the real frontend helper into the real PHP report endpoint.
const php=new PHP(await loadPHPRuntime(await getPHPLoaderModule()));
php.mkdirTree('/www/includes'); php.mkdirTree('/www/storage');
for(const file of ['api.php','includes/app.php','includes/security.php','includes/wa.php','includes/google-calendar.php']) php.writeFile('/www/'+file,fs.readFileSync(file));
const password=randomBytes(16).toString('hex');
php.writeFile('/www/config.php',`<?php return ['dsn'=>'sqlite:/www/storage/test.sqlite','admin_bootstrap_password'=>'${password}'];`);
await php.run({code:`<?php require '/www/includes/app.php'; $pdo=app_db();
$q=$pdo->prepare("INSERT INTO transactions (kind,title,category,amount,transaction_date,note,user_id,created_at) VALUES (?, 'Fixture', ?, ?, ?, '', 1, '2026-01-01 00:00:00')");
foreach ([['expense','Makan & minum',100,'2026-02-28'],['expense','Makan & minum',40,'2026-02-27'],['expense','Makan & minum',60,'2026-01-28'],['expense','Makan & minum',30,'2025-02-28'],['expense','Makan & minum',999,'2026-03-01'],['income','Makan & minum',500,'2026-02-28'],['expense','Lainnya',700,'2026-02-28']] as $row) $q->execute($row);`});
const handler=new PHPRequestHandler({php,documentRoot:'/www',absoluteUrl:'http://localhost:8080',cookieStore:false});
let cookie='';
context.fetch = async (url,options={}) => {
 const r=await handler.request({url,method:options.method||'GET',headers:{...options.headers,cookie},...(options.body?{body:new TextEncoder().encode(options.body)}:{})});
 for(const c of r.headers['set-cookie']||[]) if(c.startsWith('rumahku_session=')) cookie=c.split(';')[0];
 return {ok:r.httpStatusCode<400,status:r.httpStatusCode,json:async()=>JSON.parse(r.text)};
};
state.csrf=(await api('csrf')).csrf;
state.csrf=(await api('login',{username:'ALDI',password})).csrf;
state.reportAnchorDate='2026-02-28'; state.reportKind='expense'; state.reportCategory='Makan & minum';
await refreshPage(true);
const result=state.report;
assert.equal(result.filters.anchor_date,'2026-02-28');
assert.equal(result.period_matrix.anchor_date,'2026-02-28');
assert.equal(result.summary.expense,100);
assert.equal(result.summary.income,0);
for(const [range,amount] of Object.entries({today:100,yesterday:40,mtd:140,lmtd:60,ytd:200,lytd:30})) assert.equal(result.period_matrix.summary[range].expense,amount,range);
assert.ok(reportMarkup().includes('28 Februari 2026'));
downloadReportCsv();
assert.equal(download,'laporan-keuangan-2026-02-28.csv');
assert.ok((await blob.text()).includes('"Tanggal acuan";"2026-02-28"'));
state.reportKind='income'; await refreshPage(true); assert.equal(state.report.summary.income,500);
state.reportKind='expense'; state.reportCategory=''; await refreshPage(true); assert.equal(state.report.summary.expense,800);
state.reportAnchorDate='2024-02-29'; await refreshPage(true); assert.equal(state.report.period_matrix.anchor_date,'2024-02-29');
state.reportAnchorDate='2026-02-30'; await assert.rejects(api(`report&${reportRequestKey()}`), /Tanggal acuan/);
console.log('Report tests passed: complete query, encoded category, stale responses/errors, historical totals/ranges, filters, leap day, invalid date, markup and CSV.');
