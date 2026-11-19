import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const content = {innerHTML:''};
const context = {window:{APP_BOOT:{user:{id:1,role:'admin'},timezone:'Asia/Jakarta'}},document:{getElementById:()=>content,addEventListener(){}}};
const source=fs.readFileSync('assets/app.js','utf8');
const end=source.lastIndexOf('  render();');
vm.runInNewContext(source.slice(0,end)+'  globalThis.test = {state, reminderDateRange, filterReminderRows, remindersMarkup, bindPageLocalEvents, localDateValue};\n  renderPageContent = () => {};\n'+source.slice(end+'  render();'.length),context);
const {state,reminderDateRange,filterReminderRows,remindersMarkup,bindPageLocalEvents,localDateValue}=context.test;
assert.equal(state.reminderPeriod,localDateValue());
for (const [date, start] of [['2026-11-19','2026-11-01'],['2024-02-29','2024-02-01'],['2026-01-01','2026-01-01'],['2026-12-31','2026-12-01']]) {
 state.reminderPeriod=date;assert.deepEqual(Array.from(reminderDateRange()),[start,date]);
}
const rows=[
{id:1,title:'First',category:'Tagihan',reminder_at:'2026-11-01 00:00:00',status:'pending'},
{id:2,title:'Last',category:'Tagihan',reminder_at:'2026-11-19 23:59:59',status:'done'},
{id:3,title:'Later',category:'Tagihan',reminder_at:'2026-11-20 00:00:00',status:'pending'},
{id:4,title:'Other',category:'<script>fixture</script>',reminder_at:'2026-11-16 08:00:00',status:'pending'},
{id:5,title:'Previous month',category:'Tagihan',reminder_at:'2026-10-31 23:59:59',status:'pending'},
{id:6,title:'Previous year',category:'Tagihan',reminder_at:'2025-11-19 09:00:00',status:'pending'}];
state.reminders={reminders:rows};state.reminderPeriod='2026-11-19';state.reminderCategory='Tagihan';
assert.deepEqual(Array.from(filterReminderRows(rows),r=>r.id),[1,2]);
state.reminderFilter='done';let html=remindersMarkup();
assert.ok(html.includes('Menampilkan 1 dari 2'));assert.ok(html.includes('&lt;script&gt;fixture&lt;/script&gt;'));assert.ok(!html.includes('<script>fixture'));
assert.ok(html.includes('01/11/2026 – 19/11/2026'));assert.ok(!html.includes('Rentang tanggal'));assert.ok(!html.includes('Semua waktu'));
state.reminderPeriod='2026-12-01';assert.ok(remindersMarkup().includes('Tidak ada pengingat yang cocok'));
for(const invalid of ['', 'invalid','2026-02-30']) {state.reminderPeriod=invalid;assert.deepEqual(Array.from(reminderDateRange('2026-11-19')),['2026-11-01','2026-11-19']);}
const events={};const control={dataset:{reminderControl:'period'},value:'2026-11-19',addEventListener:(event,fn)=>events.change=fn};
const reset={addEventListener:(event,fn)=>events.reset=fn};
bindPageLocalEvents({querySelectorAll:(selector)=>selector==='[data-reminder-control]'?[control]:[],querySelector:(selector)=>selector==='[data-reminder-reset]'?reset:null});
events.change();assert.equal(state.reminderPeriod,'2026-11-19');
control.value='';events.change();assert.equal(state.reminderPeriod,localDateValue());
events.reset();assert.equal(state.reminderCategory,'');assert.equal(state.reminderPeriod,localDateValue());assert.equal(state.reminderFilter,'all');
state.reminders={reminders:[]};assert.ok(remindersMarkup().includes('Belum ada pengingat'));
console.log('Reminder filters passed: selected month-to-date, inclusive full final day, category/status, exclusion of other months/years, leap day, invalid/empty fallback, safe markup, reset to today.');

state.reminderPeriod='2026-11-19';state.reminderFilter='cancel';state.reminders={reminders:[{...rows[0],status:'cancel',created_by:1}]};
const cancelledMarkup=remindersMarkup();
assert.ok(cancelledMarkup.includes('data-reminder-filter="cancel"'));
assert.ok(cancelledMarkup.includes('Cancel · 1'));
assert.ok(cancelledMarkup.includes('status-cancel'));
assert.ok(cancelledMarkup.includes('Menampilkan 1 dari 1'));
assert.ok(cancelledMarkup.includes('Aktifkan lagi'));
for(const action of ['google-send','send-reminder','calendar-reminder','cancel-reminder']) assert.ok(!cancelledMarkup.includes('data-action="'+action+'"'));
assert.ok(!cancelledMarkup.includes('status-overdue'));
state.reminderFilter='all';state.reminders.reminders[0].status='pending';
assert.ok(remindersMarkup().includes('data-action="cancel-reminder"'));
console.log('Cancel UI tests passed: filter, count, badge, actions and no overdue marker.');

// Edit remains available for every status to admins/owners, but not other members.
for (const status of ['pending','done','cancel']) {
  state.reminderFilter='all';state.reminderPeriod='2026-11-19';
  state.reminders={reminders:[{...rows[0],status,created_by:7}]};
  for (const user of [{id:1,role:'admin'},{id:7,role:'member'},{id:8,role:'member'}]) {
    state.user=user;
    const html=remindersMarkup();
    const allowed=user.role==='admin'||user.id===7;
    assert.equal(html.includes('data-action="edit-reminder"'),allowed);
    if(allowed){
      assert.ok(html.includes('<span>Edit</span>'));
      assert.ok(html.includes('<span class="reminder-actions"><button class="btn btn-secondary btn-small reminder-edit-button"'));
    }
  }
}
console.log('Edit reminder visibility passed: labelled first action, all statuses, admin/owner only.');
