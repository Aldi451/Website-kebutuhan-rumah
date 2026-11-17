import fs from 'node:fs';
import assert from 'node:assert/strict';
import {deflateSync} from 'node:zlib';
import {loadPHPRuntime,PHP} from '@php-wasm/universal';
import {getPHPLoaderModule} from '@php-wasm/node-8-2';
const php=new PHP(await loadPHPRuntime(await getPHPLoaderModule()));
php.mkdirTree('/www/includes');php.mkdirTree('/www/storage');
for(const file of ['includes/app.php','includes/security.php','includes/wa.php'])php.writeFile('/www/'+file,fs.readFileSync(file));
php.writeFile('/www/config.php',"<?php return ['dsn'=>'sqlite:/www/storage/test.sqlite'];");
function chunk(type,data){
 const content=Buffer.concat([Buffer.from(type),data]);let crc=0xffffffff;
 for(const byte of content){crc^=byte;for(let i=0;i<8;i++)crc=crc&1?0xedb88320^(crc>>>1):crc>>>1;}
 const len=Buffer.alloc(4),sum=Buffer.alloc(4);len.writeUInt32BE(data.length);sum.writeUInt32BE((crc^0xffffffff)>>>0);
 return Buffer.concat([len,content,sum]);
}
function png(width,height){const ihdr=Buffer.alloc(13);ihdr.writeUInt32BE(width);ihdr.writeUInt32BE(height,4);ihdr[8]=8;return Buffer.concat([Buffer.from([137,80,78,71,13,10,26,10]),chunk('IHDR',ihdr),chunk('IDAT',deflateSync(Buffer.alloc((width+1)*height))),chunk('IEND',Buffer.alloc(0))]);}
const oversized=png(4000,2500);assert.ok(oversized.length<1600000);
php.writeFile('/www/big.png',oversized);php.writeFile('/www/tiny.png',png(1,1));
const samples=["C:\\nota\\rumah", "O'Reilly", "quote'\\end", 'baris\nbaru\r\nakhir', 'nul\0byte', 'Rumah 🏠 café', "'); SELECT 'fixture'; --", ''];
php.writeFile('/www/samples.json',JSON.stringify(samples));
const r=await php.run({code:`<?php require '/www/includes/app.php';
function check($value,$message){if(!$value)throw new Exception($message);}
$pdo=app_db();
check(app_receipt_memory_limit_bytes('128M')===134217728,'M suffix');
check(app_receipt_memory_limit_bytes('64k')===65536,'K suffix');
check(app_receipt_memory_limit_bytes('-1')===-1,'Unlimited');
check(app_receipt_memory_limit_bytes('unknown')===0,'Unknown limit fails closed');
app_validate_receipt_resources(1600,1600,100000,128*1024*1024,4*1024*1024);
$denied=false;try{app_validate_receipt_resources(4000,2000,100000,64*1024*1024,10*1024*1024);}catch(RuntimeException $e){$denied=true;}check($denied,'Reject insufficient decode memory');
$info=getimagesizefromstring(file_get_contents('/www/big.png'));check($info[0]===4000&&$info[1]===2500,'Compressed fixture dimensions');
$denied=false;try{app_prepare_receipt(file_get_contents('/www/big.png'));}catch(RuntimeException $e){$denied=strpos($e->getMessage(),'megapiksel')!==false;}check($denied,'Reject small compressed image with excessive pixels before GD decode');
$tiny=app_prepare_receipt(file_get_contents('/www/tiny.png'),'safe.png');check($tiny['width']===1&&strlen($tiny['bytes'])>0,'Valid small image');
$denied=false;try{app_prepare_receipt('<svg xmlns="http://www.w3.org/2000/svg"><script>fixture</script></svg>');}catch(RuntimeException $e){$denied=true;}check($denied,'Reject SVG');
$denied=false;try{app_prepare_receipt(str_repeat('x',app_receipt_max_bytes()+1));}catch(RuntimeException $e){$denied=true;}check($denied,'Encoded byte cap');
$values=json_decode(file_get_contents('/www/samples.json'),true);
$q=$pdo->prepare("INSERT INTO transactions (kind,title,category,amount,transaction_date,note,user_id,created_at) VALUES ('expense','Fixture','Test',1,'2026-11-16',?,1,'2026-11-16 00:00:00')");
foreach($values as $value)$q->execute([$value]);
// Confirm backup still contains settings; hex encoding is not token encryption.
app_set_setting('google_calendar_tokens','fixture-secret-not-encrypted');
ob_start();app_stream_sql_dump($pdo);$dump=ob_get_clean();
$literals=[];
foreach($values as $value){$literal=app_mysql_dump_text($value);check(strpos($dump,$literal)!==false,'Dump uses safe text serializer');$literals[]=$literal;}
check(strpos($dump,app_mysql_dump_text('fixture-secret-not-encrypted'))!==false,'Secrets remain recoverable in backup');
echo json_encode(['literals'=>$literals,'dump'=>$dump]);
`});
const result=JSON.parse(r.text);
for(let i=0;i<samples.length;i++){
 const match=/^CONVERT\(X'([0-9a-f]*)' USING utf8mb4\)$/.exec(result.literals[i]);assert.ok(match);
 assert.equal(Buffer.from(match[1],'hex').toString('utf8'),samples[i]);
}
assert.ok(!result.dump.includes("'); SELECT 'fixture'; --"),'User SQL-looking text never appears as raw SQL');
console.log('Backup/upload tests passed: reversible hex text literals, Unicode/backslashes/quotes/NUL, dump coverage, pixel/memory bounds, valid PNG, SVG and byte-size rejection. MySQL restore and real GD-host memory behavior require staging verification.');
