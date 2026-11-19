import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source = fs.readFileSync('assets/app.js', 'utf8');
const end = source.lastIndexOf('  render();');
assert.ok(end > 0);
for (const storage of [{getItem:()=> 'dark'}, {getItem(){throw new Error('Storage blocked');}}]) {
  const root = {innerHTML:''};
  const meta = {content:'#101923'};
  const context = {
    window:{APP_BOOT:{user:null,timezone:'Asia/Jakarta'},localStorage:storage},
    document:{getElementById:()=>root,querySelector:()=>meta,addEventListener(){}},
  };
  vm.runInNewContext(source.slice(0,end)+' globalThis.test = {renderLogin, renderShell, state};'+source.slice(end+'  render();'.length),context);
  context.test.renderLogin();
  assert.ok(root.innerHTML.includes('data-theme="light"'));
  assert.ok(!root.innerHTML.includes('toggle-login-theme'));
  assert.ok(root.innerHTML.includes('data-form="login"'));
  assert.equal(meta.content,'#f8fafb');
  context.test.state.user={role:'admin',name:'Fixture'};
  context.test.renderShell();
  assert.equal(meta.content,'#f8fafb');
  context.test.state.user=null;
  context.test.renderLogin();
  assert.ok(root.innerHTML.includes('data-theme="light"'));
}
const css=fs.readFileSync('assets/style.css','utf8');
assert.ok(css.includes('color-scheme: light'));
assert.ok(!css.includes('color-scheme: dark'));
assert.ok(!css.includes('.login-theme-toggle'));
assert.ok(!source.includes('household-login-theme'));
console.log('Light-only theme passed: saved dark preference ignored, blocked storage supported, login/shell/login remain light, toggle and dark CSS removed.');
