# Paket update — backup harian ke Telegram

Folder ini mengumpulkan file runtime yang perlu dipasang untuk fitur backup otomatis. Isi `website/` untuk InfinityFree; isi `cloudflare-worker/` untuk Worker. **Jangan upload seluruh folder `update/` ke `htdocs` sebagai aplikasi terpisah.** File `update/.htaccess` menolak akses web langsung jika folder ini tidak sengaja ikut terunggah.

## A. Pasang pada website InfinityFree

Salin hanya file berikut ke document root situs (`htdocs`):

| File paket | Tujuan di hosting |
| --- | --- |
| `website/telegram-backup.php` | `htdocs/telegram-backup.php` |
| `website/includes/app.php` | `htdocs/includes/app.php` |

Jangan timpa `config.php` milikmu. Tambahkan baris dari `website/config-snippet.txt` ke dalam `return [ ... ]` pada `config.php` yang sudah ada. Buat secret acak sendiri (minimal 32 karakter) dan pakai nilai yang sama nanti untuk secret `BACKUP_HMAC_SECRET` di Cloudflare. Contoh generator lokal: `python -c "import secrets; print(secrets.token_hex(32))"`. Jangan kirim/commit secret tersebut.

## B. Pasang Worker di Cloudflare

Gunakan isi `cloudflare-worker/` sebagai project Worker. Set `BACKUP_URL` ke alamat HTTPS langsung `https://DOMAINMU/telegram-backup.php`, lalu set secrets `BACKUP_HMAC_SECRET`, `TELEGRAM_BOT_TOKEN`, dan `TELEGRAM_CHAT_ID`. Set Cron Trigger sesuai `cloudflare-worker/wrangler.toml`: `0 17 * * *` UTC, kira-kira pukul 00.00 WIB. Token Telegram hanya disimpan di Cloudflare Secrets. Bot harus sudah di-`/start` lewat chat pribadi.

Baca `cloudflare-worker/.dev.vars.example` hanya sebagai contoh untuk uji lokal; jangan upload atau commit file `.dev.vars` yang berisi nilai asli.

## C. File yang tidak perlu diunggah ke hosting

- `cloudflare-worker/worker.js`, `wrangler.toml`, dan `.dev.vars.example` dipakai di Cloudflare, bukan di `htdocs`.
- `website/config-snippet.txt` hanya panduan edit manual `config.php`.
- Tidak ada perubahan `assets/` untuk fitur backup ini.

Backup berisi informasi sensitif, termasuk hash password dan pengaturan aplikasi. Kirim hanya ke chat pribadi Telegram. Ukuran file dibatasi 45 MiB; bukti foto dapat dilewati oleh dump aplikasi jika total bukti melebihi 6 MB.

## Refresh paket

File sumber tetap berada di `telegram-backup.php`, `includes/app.php`, dan `cloudflare-backup/`. Setelah sumber berubah, jalankan dari root repository:

```sh
npm run package:update
```
