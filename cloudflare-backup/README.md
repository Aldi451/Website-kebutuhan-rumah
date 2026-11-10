# Backup harian database rumah ke Telegram

Worker ini melakukan backup dari endpoint PHP di website dan mengirim file `.sql` sebagai dokumen Telegram. Tidak ada koneksi MySQL langsung dari Cloudflare ke InfinityFree; Worker hanya mengambil file dari endpoint HTTPS yang dilindungi HMAC.

## 1. Siapkan endpoint website

1. Unggah `telegram-backup.php` ke document root (sejajar dengan `index.php`). Unggah juga `includes/app.php` terbaru yang memiliki default `telegram_backup_secret`.
2. Buat secret acak 32 byte atau lebih di komputer sendiri, misalnya dengan `python -c "import secrets; print(secrets.token_hex(32))"`.
3. Tambahkan secret tersebut ke `config.php` hosting:

   ```php
   'telegram_backup_secret' => 'SECRET_ACAK_YANG_SAMA_DENGAN_WORKER',
   ```

Jangan commit `config.php` atau membagikan secret ini. Endpoint akan menolak semua permintaan tanpa signature HMAC yang benar dan timestamp yang masih berlaku (maksimal 5 menit).

## 2. Siapkan bot Telegram

1. Pastikan bot sudah dibuat dan tekan `/start` di **chat privat**.
2. Dapatkan chat ID privat lewat metode yang kamu percaya. Jangan tempel token bot di chat publik atau ke source code.

## 3. Deploy Cloudflare Worker

1. Instal Wrangler (`npm install -g wrangler`) dan login (`wrangler login`), atau salin `worker.js` lewat editor Worker di dashboard Cloudflare.
2. Dari folder ini, masukkan bindings sebagai secrets (perintah akan meminta nilainya; jangan masukkan nilai ke Git):

   ```sh
   npx wrangler secret put BACKUP_URL
   npx wrangler secret put BACKUP_HMAC_SECRET
   npx wrangler secret put TELEGRAM_BOT_TOKEN
   npx wrangler secret put TELEGRAM_CHAT_ID
   npx wrangler deploy
   ```

   `BACKUP_URL` harus URL HTTPS langsung ke `telegram-backup.php`. Gunakan secret yang sama pada `BACKUP_HMAC_SECRET` dan `telegram_backup_secret` di hosting. Gunakan chat ID privat positif; chat grup sengaja ditolak.
3. `wrangler.toml` menjadwalkan `0 17 * * *` UTC, yaitu sekitar 00.00 WIB. Verifikasi Cron Trigger tampil di Settings → Triggers dan jalankan test Scheduled Event dari dashboard.
4. Untuk uji lokal, salin `.dev.vars.example` menjadi `.dev.vars`, isi nilainya secara lokal, lalu jalankan `npx wrangler dev --test-scheduled`. Trigger dengan `curl "http://localhost:8787/cdn-cgi/local/scheduled?cron=0+17+*+*+*"`. File `.dev.vars` sudah di-ignore Git. Setelah deploy, cek Logs saat Cron pertama berjalan. Kalau tidak ada dokumen, periksa endpoint URL/canonical domain, secret, log Worker, serta pesan gagal dari bot. Worker hanya menerima respons SQL yang utuh dan berukuran maksimal 45 MiB; HTML challenge atau backup besar tidak akan dikirim.

## Keamanan dan keterbatasan

- Dump berisi data rumah, hash kata sandi, dan nilai pengaturan aplikasi. Telegram bot chat **bukan** end-to-end encrypted. Pakai chat privat, aktifkan keamanan akun Telegram, dan hapus file yang tidak lagi dibutuhkan.
- Aplikasi tidak memasukkan gambar bukti ke SQL dump jika jumlah byte semua gambar lebih dari 6 MB; Worker menyertakan peringatan pada caption.
- InfinityFree Free tidak menyediakan cron. Jadwal berlangsung di Cloudflare; Worker mengambil backup melalui HTTPS. Kalau lapisan keamanan InfinityFree menolak request Worker, periksa Cloudflare Logs. Jangan mematikan keamanan hosting untuk membuatnya berfungsi.
- Untuk restore, unduh `.sql` dari chat privat Telegram dan impor lewat phpMyAdmin. Simpan juga salinan lokal cadangan.
