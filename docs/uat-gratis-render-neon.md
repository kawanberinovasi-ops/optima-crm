# UAT gratis: Render (aplikasi) + Neon (database)

Pengganti Laravel Cloud tanpa biaya. Semua akun dibuat dengan **satu Gmail khusus proyek**, supaya saat serah terima
cukup akun itu yang dipindahkan ke klien (lihat [serah-terima-klien.md](serah-terima-klien.md)).

| Layanan | Paket | Batas yang perlu diingat |
|---|---|---|
| Render | Free web service (Docker) | Tidur setelah 15 menit sepi, buka pertama sekitar 30–60 detik; tidak ada console dan cron |
| Neon | Free | 1 GB per project; tidak ada tagihan lebihan (kalau habis, tulis data ditolak, bukan ditagih) |

Karena Render Free tidak punya console, migrasi, data awal, dan akun kepala divisi dijalankan otomatis setiap start oleh
[`docker/start.sh`](../docker/start.sh) → `php artisan optima:first-run` (aman diulang). Image ini diuji di GitHub Actions
(workflow **Docker image**). Scheduler (backup harian, email digest) tidak jalan di paket gratis; untuk UAT tidak apa-apa.

## 1. Gmail khusus proyek (dibuat sendiri)

1. Buat Gmail baru, misalnya `optimacrm.admin@gmail.com`. Pasang 2FA dan nomor HP pemulihan.
2. Simpan password di password manager tim, jangan di chat atau repo.
3. Login akun ini di Chrome (profil terpisah lebih rapi).

## 2. Neon (database)

1. https://neon.tech → **Sign up with Google** (Gmail proyek).
2. Buat project `optima-crm-uat`, Postgres 16 atau 17, region **AWS Asia Pacific (Singapore)**.
3. **Connect** → pilih koneksi **tanpa pooling** (direct), salin connection string
   (`postgresql://...neon.tech/neondb?sslmode=require`). Ini nilai `DB_URL`.

## 3. Render (aplikasi)

1. https://render.com → **Sign up with Google** (Gmail proyek). Render mungkin meminta kartu untuk verifikasi.
2. **New → Web Service → Public Git Repository**: `https://github.com/kawanberinovasi-ops/optima-crm`, branch
   `feat/revisi-klien`. Repo publik tidak perlu menghubungkan akun GitHub.
3. Language **Docker**, Dockerfile path `./Dockerfile`, region **Singapore**, instance type **Free**,
   health check path `/up`.
4. Environment variables:

```
APP_ENV=staging
APP_DEBUG=false
APP_KEY=<Generate di Render>
APP_URL=https://<nama-service>.onrender.com
APP_TIMEZONE=Asia/Jakarta
APP_LOCALE=id
DB_CONNECTION=pgsql
DB_URL=<connection string Neon>
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=sync
MAIL_MAILER=log
OPTIMA_MAIL_ALLOWED_DOMAINS=contoh.test
OPTIMA_BACKUP_DISK=local
GOOGLE_HOSTED_DOMAIN=
GOOGLE_CLIENT_ID=<langkah 4>
GOOGLE_CLIENT_SECRET=<langkah 4>
OPTIMA_INTERNAL_USERS_JSON=<base64 dari internal-users.json>
```

- `APP_KEY`: tombol **Generate** di Render boleh dipakai; awalan `base64:` ditambahkan otomatis oleh skrip start.
- `OPTIMA_INTERNAL_USERS_JSON`: `base64 -i backend/storage/app/private/internal-users.json | pbcopy`, lalu tempel.
- `QUEUE_CONNECTION=sync`: tidak ada worker antrean di paket gratis.

5. **Create Web Service**. Build pertama sekitar 5–10 menit. Log harus menampilkan migrasi, seed, lalu sinkron 7 akun.
6. Cek `https://<nama-service>.onrender.com/up` → 200.

## 4. OAuth Google (SSO)

Di https://console.cloud.google.com dengan Gmail proyek: buat project `optima-crm`, OAuth consent screen **External**
(nama `OPTIMA CRM`, lalu **Publish app**), lalu **Credentials → OAuth client ID → Web application** dengan redirect URI:

```
https://<nama-service>.onrender.com/api/auth/google/callback
http://127.0.0.1:8000/api/auth/google/callback
```

Tempel Client ID dan secret ke environment Render (dan `backend/.env` lokal kalau perlu). Render redeploy otomatis.

## Setiap ada perubahan

Push ke `feat/revisi-klien` → Render build dan deploy ulang otomatis; migrasi jalan saat start.
