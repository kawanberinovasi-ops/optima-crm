# UAT lewat Cloudflare Tunnel (gratis, tanpa kartu, tanpa akun)

Server lokal dibuka ke internet lewat link `https://<acak>.trycloudflare.com`. Link hanya hidup selama laptop menyala dan
tunnel berjalan; setiap tunnel di-restart, link-nya berganti.

## Menjalankan

1. Server lokal jalan: `./start-backend.sh` (PostgreSQL + `php artisan serve` di 127.0.0.1:8000).
2. Pastikan di `backend/.env` (wajib, karena situs terbuka ke publik):
   ```
   APP_DEBUG=false
   OPTIMA_DEV_LOGIN=false
   ```
3. Jalankan tunnel: `~/.local/bin/cloudflared tunnel --no-autoupdate --url http://127.0.0.1:8000`
   (unduhan resmi: github.com/cloudflare/cloudflared/releases). Catat link `https://....trycloudflare.com`.
4. Di `backend/.env` ganti `APP_URL` dan `GOOGLE_REDIRECT_URI=<link>/api/auth/google/callback`, lalu
   `php artisan config:clear`.
5. Google Cloud (project `optima-crm-514309`) → Google Auth Platform → Clients → **OPTIMA CRM web** → tambahkan
   `<link>/api/auth/google/callback` di Authorized redirect URIs → Save (berlaku dalam beberapa menit).

## Login

- Hanya lewat **Masuk dengan SSO OPTIMA**. Yang bisa masuk: email yang terdaftar di CRM **dan** ada di daftar
  Test users consent screen (mode Testing).
- `innovatecampus@gmail.com` didaftarkan sebagai Super Admin pengembang (hanya di database lokal). Nonaktifkan saat
  serah terima.
- Email kantor `@optima.web.id` milik Ali dan Chitta bukan akun Google; mereka login dengan Gmail alternatifnya.

## Selesai

Hentikan tunnel (Ctrl-C di tab terminalnya). Untuk kembali ke login uji lokal, kembalikan `.env` dari
`backend/storage/app/private/.env.before-tunnel` (tanpa GOOGLE_CLIENT_SECRET) atau set `OPTIMA_DEV_LOGIN=true`
dan `APP_URL=http://127.0.0.1:8000`.
