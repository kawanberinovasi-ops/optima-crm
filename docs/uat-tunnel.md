# UAT lewat Cloudflare Tunnel (gratis, tanpa kartu, tanpa akun)

Server lokal dibuka ke internet lewat link `https://<acak>.trycloudflare.com`. Link hanya hidup selama laptop menyala dan
tunnel berjalan; setiap tunnel di-restart, link-nya berganti.

## Menjalankan

Cara cepat: `./start-uat.sh` (menyalakan server kalau belum, membuka tunnel, dan mengisi `APP_URL` serta
`GOOGLE_REDIRECT_URI` otomatis). Setelah itu tinggal langkah 5 (redirect di Google Cloud). Langkah manualnya:

1. Server lokal jalan: `./start-backend.sh` (PostgreSQL + `php artisan serve` di 127.0.0.1:8000).
2. Login uji dan halaman debug otomatis terkunci ke laptop (host dan IP `127.0.0.1`), jadi aman walaupun
   `OPTIMA_DEV_LOGIN=true` atau `APP_DEBUG=true`. Lewat link tunnel login hanya bisa dengan Google. Lihat
   [keamanan.md](keamanan.md).
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
- Email kantor `@optima.web.id` yang belum terhubung ke akun Google tidak bisa dipakai login Google; pemiliknya login
  dengan Gmail alternatif yang terdaftar.

## Selesai

Hentikan tunnel (Ctrl-C di tab terminalnya). Login uji di `http://127.0.0.1:8000` tetap jalan selama tunnel aktif.
