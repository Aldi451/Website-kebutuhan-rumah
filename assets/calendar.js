/* Calendar export: no credentials, network requests, or third-party libraries. */
(() => {
  'use strict';
  const compact = (date) => date.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z');
  function event(record, timezone) {
    const source = String(record.reminder_at || '').replace('T', ' ');
    const match = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::\d{2})?$/.exec(source);
    if (!match) throw new Error('Jadwal pengingat tidak valid.');
    const [, y, m, d, h, min] = match;
    // Undated-time reminders intentionally become timed events at 09:00 so
    // the calendar alarm fires at a useful hour, not at midnight.
    const hour = Number(record.has_time ?? 1) === 0 ? 9 : Number(h);
    const minute = Number(record.has_time ?? 1) === 0 ? 0 : Number(min);
    const wall = Date.UTC(+y, +m - 1, +d, hour, minute);
    const formatter = new Intl.DateTimeFormat('en-GB', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
    let instant = wall;
    for (let i = 0; i < 3; i++) {
      const parts = Object.fromEntries(formatter.formatToParts(new Date(instant)).map(p => [p.type, p.value]));
      const represented = Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour, +parts.minute, +parts.second);
      const delta = wall - represented;
      instant += delta;
      if (!delta) break;
    }
    return { start: compact(new Date(instant)), end: compact(new Date(instant + 30 * 60000)), title: String(record.title || 'Pengingat'), description: [record.category, record.note, 'Diekspor dari Catatan kebutuhan rumah. Perubahan di website tidak tersinkron otomatis.'].filter(Boolean).join('\n') };
  }
  function googleUrl(record, timezone) {
    const item = event(record, timezone);
    const params = new URLSearchParams({ action: 'TEMPLATE', text: item.title, dates: `${item.start}/${item.end}`, details: item.description, ctz: timezone });
    return `https://calendar.google.com/calendar/render?${params}`;
  }
  globalThis.ReminderCalendar = { googleUrl };
})();
