# Deploy OPTIMA CRM ke Laravel Cloud

Panduan ini untuk lingkungan uji (UAT) dan produksi. Repo ini monorepo: frontend prototipe ada di root
(`index.html`, `assets/`), aplikasi Laravel di `backend/`. Laravel Cloud men-deploy satu folder saja (root directory),
tapi semua folder repo bisa dibaca saat build. Karena itu frontend disalin ke dalam `backend/` di langkah build.

Rujukan resmi: [Monorepos](https://laravel.com/cloud/docs/monorepos), [Laravel deploy guide](https://laravel.com/cloud/docs/deploy-guides/laravel),
[Serverless Postgres](https://laravel.com/cloud/docs/resources/databases/postgres), [Plans and pricing](https://cloud.laravel.com/docs/pricing).
Tampilan dashboard Cloud bisa berubah; nama menu di bawah mengikuti dokumentasi per Oktober 2026.

## 1. Siapkan repo

1. Push repo ini ke GitHub, termasuk branch yang akan di-deploy. Sebaiknya private; untuk serah terima lihat [serah-terima-klien.md](serah-terima-klien.md).
2. Pastikan `backend/composer.lock` ikut ter-commit.

## 2. Buat aplikasi di Laravel Cloud

1. Masuk ke https://cloud.laravel.com, buat organisasi (paket gratis / Starter).
2. **New application** → hubungkan GitHub → pilih repo ini.
3. Cloud mendeteksi monorepo. Pilih **root directory: `backend`**.
4. Region: pilih yang paling dekat dengan Indonesia (misalnya Singapore) kalau tersedia.
5. PHP 8.4.

## 3. Attach resource

| Resource | Pengaturan | Catatan |
|---|---|---|
| Database | **Serverless Postgres** | Variabel `DB_*` diisi otomatis. Di menu Backups, set retensi point-in-time **minimal 7 hari** (SRS 8.1: data hilang paling banyak 24 jam). |
| Object Storage | Bucket privat, nama misalnya `optima-crm-backup` | Attach sebagai disk **`s3`**. Dipakai backup harian aplikasi (`OPTIMA_BACKUP_DISK=s3`). |

Fitur dan batas paket gratis (backup point-in-time, custom domain, hibernasi) perlu dicek di dashboard. Kalau backup
point-in-time tidak tersedia di paket gratis, backup harian aplikasi tetap jalan sebagai lapis utama.

## 4. Build dan deploy command

Di **Settings → Deployments**:

**Build command**

```
composer install --no-dev --optimize-autoloader --no-interaction
php artisan optima:build-frontend
php artisan optimize
```

- `optima:build-frontend` menyalin `../assets` ke `public/assets` (menggantikan symlink pengembangan) dan `../index.html` ke
  `resources/frontend/index.html`, dengan versi `?v=<hash>` di setiap aset supaya browser tidak memakai JS lama.
- Tidak perlu `npm`: frontend tidak di-bundle. Kalau Cloud menambahkan langkah npm otomatis, hapus.

**Deploy command**

```
php artisan migrate --force
```

## 5. Environment variables

Salin dari [`backend/.env.production.example`](../backend/.env.production.example) ke **Environment → Variables**.
Yang wajib diisi sendiri:

- `APP_URL`: domain yang diberikan Cloud (atau custom domain).
- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`: dari Google Cloud Console. Tambahkan redirect URI
  `https://<domain>/api/auth/google/callback` di OAuth client yang sama dengan pengembangan.
- `MAIL_*`: SMTP pengembangan Campus Innovate.
- `OPTIMA_MAIL_ALLOWED_DOMAINS`: tetap `contoh.test` atau domain uji sampai klien setuju email dikirim ke alamat OPTIMA.

Lingkungan **UAT**: pakai `APP_ENV=staging`. Seeder mengisi data contoh di semua lingkungan kecuali `production`
(SRS 8.3: data asli baru masuk saat migrasi akhir). Login akun uji tetap mati di luar `local`, jadi UAT butuh SSO Google
(atau login kode email, FR-AUT-04, kalau sudah dibuat).

## 6. Scheduler

Aktifkan **Scheduler** di pengaturan compute aplikasi. Jadwal yang berjalan (lihat `backend/routes/console.php`):

| Waktu (WIB) | Perintah | Untuk |
|---|---|---|
| 01.00 | `optima:backup` | Backup harian ke Object Storage, hapus yang lebih dari 30 hari |
| 08.00 | `optima:daily-digest` | Email ringkasan follow-up + pemberitahuan di aplikasi |

Kalau compute di-hibernate, pastikan scheduler tetap membangunkan aplikasi; kalau tidak, matikan hibernasi untuk produksi.

## 7. Deploy pertama

Setelah deploy pertama berhasil, buka **Commands** di dashboard Cloud dan jalankan:

```
php artisan db:seed --force
php artisan optima:create-admin email.superadmin@kantor.id "Nama Super Admin"
php artisan optima:backup
```

- `db:seed` di produksi hanya mengisi data referensi: department, matriks hak akses awal, daftar pilihan, dan pipeline.
- Ulangi `optima:create-admin` untuk Super Admin kedua (SRS bagian 11: minimal 2). Pengguna lain didaftarkan lewat
  halaman Akses & Audit.
- Cek: buka `https://<domain>/up` (harus 200), lalu login dengan akun Google Super Admin.

## 8. Uji pemulihan backup (syarat go-live SRS 9.4)

Lakukan sebelum go-live dan simpan hasilnya sebagai bukti:

1. Buat database Serverless Postgres **baru** (jangan pakai database produksi), misalnya `optima-restore-check`.
2. Buat environment baru di aplikasi yang sama (misalnya `restore-check`) yang memakai database baru itu dan bucket backup
   yang sama, dengan `APP_ENV=staging`.
3. Di environment itu jalankan:

   ```
   php artisan migrate --force
   php artisan optima:restore --latest --force
   ```

4. Perintah restore mencocokkan jumlah baris dan checksum SHA-256 setiap tabel dengan isi backup. Kalau ada yang tidak
   cocok, pemulihan dibatalkan dan tidak ada data yang masuk.
5. Login dan bandingkan beberapa data acak dengan produksi. Catat waktu mulai dan selesai (target pulih dalam 8 jam kerja).
6. Hapus environment dan database uji.

Untuk pemulihan point-in-time dari Cloud: **Resources → Databases → database → Backups → Restore**. Cloud membuat cluster
baru dari waktu yang dipilih; arahkan environment ke cluster itu setelah dicek.

## 9. Setiap rilis

- Push ke branch yang terhubung → Cloud menjalankan build dan deploy command di atas.
- Sebelum rilis yang mengubah struktur data, jalankan `php artisan optima:backup` manual dari menu Commands.
- Perubahan di `assets/` otomatis dapat versi baru lewat `?v=<hash>`, jadi pengguna tidak perlu hapus cache browser.

## Yang perlu diputuskan sebelum go-live

- Custom domain (misalnya `crm.optima.co.id`): kemungkinan butuh paket berbayar.
- Pindah SSO dan SMTP dari akun pengembangan Campus Innovate ke akun OPTIMA: cukup ganti environment variable.
- Batas paket gratis setelah masa percobaan (SRS 8.3: 30 pengguna, database 5 GB, dibiayai 3 bulan pertama).
