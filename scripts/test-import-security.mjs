import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { deflateRawSync } from 'node:zlib';
const source = fs.readFileSync('assets/importer.js', 'utf8');
const context = {window:{}, TextDecoder, Blob, DecompressionStream, Error};
vm.runInNewContext(source.replace('window.HouseholdImport = { read };', 'window.HouseholdImport = { read, unzipEntry, parseXml };'), context);
const {unzipEntry, parseXml, read} = context.window.HouseholdImport;
function archive(payload, declaredSize, method = 8) {
  const header = Buffer.alloc(30); header.writeUInt32LE(0x04034b50);
  const data = Buffer.concat([header, payload]);
  return [data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength), {localOffset:0, compressedSize:payload.length, uncompressedSize:declaredSize, method}];
}
const plain = Buffer.from('<workbook/>');
assert.equal(Buffer.from(await unzipEntry(...archive(deflateRawSync(plain),plain.length))).toString(), plain.toString());
assert.equal(Buffer.from(await unzipEntry(...archive(plain,plain.length,0))).toString(), plain.toString());
await assert.rejects(unzipEntry(...archive(plain,1,0)), /tidak valid/);
await assert.rejects(unzipEntry(...archive(deflateRawSync(plain),1)), /Gagal membuka/);
// Metadata deliberately lies; streaming cap must stop this expansion regardless.
const bomb = deflateRawSync(Buffer.alloc(21 * 1024 * 1024, 65));
await assert.rejects(unzipEntry(...archive(bomb, 10)), /terlalu besar/);
assert.throws(()=>parseXml('<!DOCTYPE foo [<!ENTITY x "abc">]><foo>&x;</foo>'), /DTD/);
const csv = await read({name:'test.csv',size:30,text:async()=> 'Judul;Tanggal\nTagihan;2026-11-16'});
assert.equal(csv[1].cells[0], 'Tagihan');
console.log('Import security tests passed: valid ZIP/CSV, stored-size mismatch, forged deflate size, streaming expansion cap, forbidden DTD.');
