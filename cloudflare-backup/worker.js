const MAX_TELEGRAM_FILE_BYTES = 45 * 1024 * 1024;
const BACKUP_SIGNATURE_LABEL = 'nightly-sql-backup';

export default {
  async scheduled(_controller, _env, context) {
    context.waitUntil(runScheduledBackup(_env));
  },

  async fetch() {
    return new Response('Not found', { status: 404 });
  },
};

async function runScheduledBackup(env) {
  try {
    validateEnvironment(env);
    const timestamp = String(Math.floor(Date.now() / 1000));
    const signature = await signBackupRequest(env.BACKUP_HMAC_SECRET, timestamp);
    const response = await fetch(env.BACKUP_URL, {
      method: 'GET',
      headers: {
        'X-Backup-Timestamp': timestamp,
        'X-Backup-Signature': signature,
        Accept: 'application/sql',
      },
      redirect: 'error',
    });

    if (!response.ok) throw new Error(`Website backup endpoint returned HTTP ${response.status}.`);
    const contentType = (response.headers.get('content-type') || '').toLowerCase();
    if (!contentType.includes('application/sql')) {
      throw new Error('Website did not return an SQL backup (possible hosting challenge page).');
    }

    const declaredLength = Number(response.headers.get('content-length') || 0);
    if (declaredLength > MAX_TELEGRAM_FILE_BYTES) {
      throw new Error('Backup exceeds the safe Telegram file-size limit of 45 MiB.');
    }

    const backup = await response.arrayBuffer();
    if (backup.byteLength === 0) throw new Error('Website returned an empty backup.');
    if (backup.byteLength > MAX_TELEGRAM_FILE_BYTES) {
      throw new Error('Backup exceeds the safe Telegram file-size limit of 45 MiB.');
    }

    const bytes = new Uint8Array(backup);
    const headerText = new TextDecoder().decode(bytes.subarray(0, Math.min(bytes.length, 1024)));
    const tailText = new TextDecoder().decode(bytes.subarray(Math.max(0, bytes.length - 512)));
    if (!headerText.includes('Cadangan database Dashboard kebutuhan rumah') || !tailText.includes('SET FOREIGN_KEY_CHECKS = 1;')) {
      throw new Error('Backup file looks incomplete; it was not sent.');
    }

    const omittedImages = headerText.includes('TIDAK disertakan');
    const date = new Date().toISOString().slice(0, 10);
    const form = new FormData();
    form.set('chat_id', env.TELEGRAM_CHAT_ID);
    form.set('caption', `Backup database rumah · ${date}${omittedImages ? '\nPERINGATAN: bukti foto tidak disertakan karena melewati batas ekspor aplikasi.' : ''}`);
    form.set('document', new Blob([backup], { type: 'application/sql' }), `backup-rumah-${date}.sql`);

    const telegramResponse = await fetch(`https://api.telegram.org/bot${env.TELEGRAM_BOT_TOKEN}/sendDocument`, {
      method: 'POST',
      body: form,
    });
    const telegramResult = await telegramResponse.json().catch(() => null);
    if (!telegramResponse.ok || !telegramResult?.ok) {
      throw new Error(`Telegram rejected the backup (HTTP ${telegramResponse.status}).`);
    }
  } catch (error) {
    console.error('Nightly backup failed:', error instanceof Error ? error.message : 'unknown error');
    await notifyFailure(env);
  }
}

function validateEnvironment(env) {
  const required = ['BACKUP_URL', 'BACKUP_HMAC_SECRET', 'TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID'];
  for (const name of required) {
    if (typeof env[name] !== 'string' || env[name].trim() === '') {
      throw new Error(`Missing Worker setting: ${name}.`);
    }
  }
  if (!/^https:\/\//i.test(env.BACKUP_URL)) throw new Error('BACKUP_URL must use HTTPS.');
  if (env.BACKUP_HMAC_SECRET.length < 32) throw new Error('BACKUP_HMAC_SECRET must be at least 32 characters.');
  if (!/^\d+$/.test(env.TELEGRAM_CHAT_ID)) throw new Error('Use a private Telegram chat ID, not a group ID.');
}

async function signBackupRequest(secret, timestamp) {
  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );
  const signature = await crypto.subtle.sign(
    'HMAC',
    key,
    new TextEncoder().encode(`${BACKUP_SIGNATURE_LABEL}\n${timestamp}`),
  );
  return [...new Uint8Array(signature)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

async function notifyFailure(env) {
  if (!env?.TELEGRAM_BOT_TOKEN || !env?.TELEGRAM_CHAT_ID) return;
  try {
    await fetch(`https://api.telegram.org/bot${env.TELEGRAM_BOT_TOKEN}/sendMessage`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        chat_id: env.TELEGRAM_CHAT_ID,
        text: 'Backup database otomatis gagal. Cek log Worker Cloudflare; file backup tidak dikirim.',
      }),
    });
  } catch {
    // Never log bot tokens or request URLs; Cloudflare invocation logs contain the main error.
  }
}
