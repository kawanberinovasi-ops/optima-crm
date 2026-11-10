# Menyiapkan UAT di Laravel Cloud (langkah cepat)

> Laravel Cloud berbayar (Starter $5/bulan + pemakaian; Postgres-nya terpisah). Versi tanpa biaya:
> [uat-gratis-render-neon.md](uat-gratis-render-neon.md).

Tujuannya: versi online dari branch `feat/revisi-klien` dengan akun kepala divisi asli. Login lewat SSO Google, karena
login akun uji mati otomatis di luar `local`. Panduan lengkap produksi ada di [deploy-laravel-cloud.md](deploy-laravel-cloud.md).

Simulasi langkah ini sudah dijalankan di laptop (10 Nov 2026) dengan `APP_ENV=staging`. Hasilnya: build frontend, migrasi,
seed, dan pembuatan admin berhasil, `/up` 200, login akun uji tertutup, dan tombol Google mengarah ke pesan
"SSO belum diatur" sampai variabel `GOOGLE_*` diisi.

## Yang dikerjakan sendiri (butuh akun, tidak bisa diwakilkan)

### 1. Buat aplikasi di Laravel Cloud (sekitar 10 menit)

1. Buka https://cloud.laravel.com, daftar atau masuk dengan GitHub **kawanberinovasi-ops**.
2. **New application** → hubungkan GitHub → repo `kawanberinovasi-ops/optima-crm`.
3. Branch **`feat/revisi-klien`**, root directory **`backend`**, region Singapore, PHP 8.4. Nama environment: `uat`.
4. **Resources → Database → Serverless Postgres** (paket gratis cukup). Variabel `DB_*` terisi otomatis.
5. **Settings → Deployments**:
   - Build command:
     ```
     composer install --no-dev --optimize-autoloader --no-interaction
     php artisan optima:build-frontend
     php artisan optimize
     ```
   - Deploy command: `php artisan migrate --force`
   - Kalau Cloud menambahkan langkah `npm`, hapus. Frontend tidak di-bundle.
6. Catat domain yang diberikan Cloud, misalnya `https://optima-crm-uat-xxxx.laravel.cloud`.

### 2. Environment variables

Di **Environment → Variables**, isi (sisanya biarkan bawaan Cloud):

```
APP_NAME="OPTIMA CRM"
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://<domain dari Cloud>
APP_TIMEZONE=Asia/Jakarta
APP_LOCALE=id
LOG_CHANNEL=stderr
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=log
OPTIMA_MAIL_ALLOWED_DOMAINS=contoh.test
OPTIMA_BACKUP_DISK=local
GOOGLE_HOSTED_DOMAIN=
GOOGLE_CLIENT_ID=<langkah 3>
GOOGLE_CLIENT_SECRET=<langkah 3>
OPTIMA_INTERNAL_USERS_JSON=<langkah 4>
```

- `MAIL_MAILER=log` dan `OPTIMA_MAIL_ALLOWED_DOMAINS=contoh.test`: email tidak benar-benar terkirim selama UAT.
- `OPTIMA_BACKUP_DISK=local`: UAT tidak butuh Object Storage. Di produksi pakai `s3`.

### 3. OAuth client Google

Di Google Cloud Console (project pengembangan yang sama dengan lokal), buka **Credentials** → OAuth client yang dipakai
lokal → **Authorized redirect URIs** → tambahkan:

```
https://<domain dari Cloud>/api/auth/google/callback
```

Salin Client ID dan Client secret langsung ke variabel Cloud. Jangan kirim lewat chat. Kalau consent screen masih
*Testing*, tambahkan email para kepala divisi sebagai *Test users*, atau klik *Publish app*.

### 4. Daftar akun kepala divisi

File `backend/storage/app/private/internal-users.json` tidak ikut repo. Untuk dibawa ke Cloud, ubah ke base64 lalu tempel
sebagai nilai `OPTIMA_INTERNAL_USERS_JSON`:

```bash
base64 -i backend/storage/app/private/internal-users.json | pbcopy
```

Isinya hanya terlihat oleh orang yang punya akses ke dashboard Cloud. Setelah langkah 5 selesai, variabel ini boleh dihapus.

### 5. Deploy dan isi data

1. Klik **Deploy**. Tunggu sampai hijau.
2. Buka **Commands**, jalankan berurutan:
   ```
   php artisan db:seed --force
   php artisan optima:sync-internal-users --from-env --replace-demo
   ```
   - `db:seed` di staging mengisi data referensi dan data contoh.
   - `--replace-demo` menghapus akun uji `@contoh.test` dan mengalihkan datanya ke kepala divisi, sama seperti versi lokal.
3. Buka `https://<domain>/up`. Hasilnya harus 200.

## Daftar uji UAT

| Uji | Akun | Yang diharapkan |
|---|---|---|
| Login Google | Ali (Executive, Super Admin) | Masuk ke My Work; Akses & Audit menampilkan semua tab |
| Login email tidak terdaftar | Gmail lain | Ditolak dengan pesan akun belum terdaftar |
| Pipeline per divisi | Super Admin → Akses & Audit → Pipeline | Tambah Campaign ke Customer Service; akun CS melihat dua pipeline setelah muat ulang |
| Hak akses | Super Admin → Hak akses | Ubah satu nilai, akun divisi itu langsung terdampak setelah muat ulang |
| Data per PIC | Kepala CS | Hanya melihat client, partner, dan deal yang PIC-nya dia |
| Client dan Partner | Kepala divisi mana pun | Bisa menambah client dan partner; form partner berbeda dari client |
| Layanan | Kepala divisi | Menambah, mengganti nama, dan menghapus layanan divisinya sendiri |
| Data terhapus | Super Admin | Hapus satu data lalu pulihkan dari Data terhapus |

## Setiap ada perubahan

Push ke `feat/revisi-klien`. Cloud otomatis build dan deploy; migrasi jalan di deploy command. Perubahan `assets/`
mendapat versi baru, jadi pengguna tidak perlu hapus cache.
