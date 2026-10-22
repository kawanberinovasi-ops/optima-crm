# Persiapan backend OPTIMA CRM

Acuan: SRS CRM & SSO Foundation OPTIMA v0.2 (13 Okt 2026) dan prototipe di folder ini (`assets/*.js`).
Status: G0, G1, dan G2 sudah ada di `backend/` (106 test). Semua data dan perubahan di frontend lewat API: login SSO
Google, sesi, Client & Partner, kontak, data ganda, gabung data, pipeline dan stage, deal, interaksi dan follow-up,
import dan batalkan import, Akses & Audit, pemberitahuan, tampilan tersimpan, dan email ringkasan 08.00.
Persiapan deploy ke Laravel Cloud ada di [deploy-laravel-cloud.md](deploy-laravel-cloud.md): paket frontend, konfigurasi
produksi, scheduler, dan backup harian dengan uji pemulihan.
Yang belum: login cadangan kode email (FR-AUT-04, Should) dan tes login Google sungguhan.
Perubahan alur bisnis 22 Okt 2026 (status Lead/Client vs layanan, akses per divisi, serah terima, akun internal):
lihat [alur-lead-client.md](alur-lead-client.md).

## 1. Keputusan

| Hal | Pilihan | Sumber |
|---|---|---|
| Framework | Laravel 13 (PHP 8.4) | keputusan tim |
| Database | PostgreSQL 16 | SRS §01 |
| Hosting | Laravel Cloud, paket gratis | keputusan tim, 20 Okt 2026 |
| Login | OIDC lewat Google Workspace, akun pengembangan Campus Innovate | SRS §4.1, §8.2; keputusan tim, 20 Okt 2026 |
| Login cadangan | Kode sekali pakai lewat email | FR-AUT-04 |
| Frontend | Prototipe sekarang dipakai terus. `Store` (localStorage) diganti panggilan API, per modul | keputusan tim |

Frontend dan API dilayani dari domain yang sama. Laravel menyajikan isi folder frontend dari `public/`, jadi login cukup
pakai cookie sesi Laravel (tanpa token di browser, tanpa CORS).

## 2. Struktur repo

```
optima-crm/
  backend/            Laravel (app, database/migrations, routes/api.php, tests)
  assets/, index.html frontend prototipe, nanti disalin/di-link ke backend/public
  docs/               dokumen ini, catatan keputusan
```

## 3. Skema database

Diturunkan dari ERD (SRS §6.1) dan field yang sudah dipakai prototipe. Tidak ada hapus permanen: arsip pakai
`archived_at`, interaksi dibatalkan pakai `cancelled_at` (SRS §4.2, §8.1).

| Tabel | Kolom utama | Catatan |
|---|---|---|
| `departments` | name | nama bisa diganti Super Admin |
| `users` | name, email (unik), department_id, role, active, last_login_at | role: Super Admin, Kepala Department, Koordinator, Staf, Viewer |
| `access_grants` | department_id, entity, level | entity: lead, client, community, kol, media, interaksi, deal, nilai, rate. Level N/R/C/M, Ya/Ya*/Lihat*/Tidak |
| `access_overrides` | user_id, entity, value, reason | FR-USR-05 |
| `option_lists` | key, value, position | segmen, sumber, jenis interaksi, alasan Lost (FR-USR-06) |
| `client_partners` | code, name, category, segment, status, pic_id, department_id, source, city, note, rate_card (json), client_info (json), archived_at, merged_into_id | 5 kategori, satu tabel |
| `contacts` | client_partner_id, name, title, email, phone, social, is_primary | FR-REL-02 |
| `interactions` | code, client_partner_id, deal_id?, user_id, date, type, result, next_action, followup_date, followup_done_at, cancelled_at, cancel_reason | FR-INT-01..04 |
| `pipelines` | name | satu per lini bisnis |
| `stages` | pipeline_id, name, position, is_closing | tiap pipeline satu stage Closing |
| `deals` | code, client_partner_id, pipeline_id, stage_id, service, status, owner_id, offer_value, deal_value, target_closing, closed_at, lost_reason, hold_reason, review_date | status: Open, Won, Lost, On Hold |
| `deal_histories` | deal_id, user_id, from_stage, to_stage, status, note, at | FR-DEAL-02 |
| `audit_logs` | user_id, action, entity_type, entity_id, before (json), after (json), ip, at | FR-AUD-01, 02. Riwayat perubahan per data dibaca dari sini |
| `imports`, `import_rows` | file, status, counts / row_no, data, errors, created_id | dry-run, simpan, batalkan (FR-IMP) |
| `saved_views` | user_id, page, name, filters (json) | FR-REL-05 |
| `user_notifications` | user_id, text, link, read_at | lonceng di navbar |
| `login_codes` | email, code_hash, expires_at | login cadangan |
| `sessions` | bawaan Laravel (driver `database`) | supaya sesi bisa diputus saat user dinonaktifkan |

Index yang wajib untuk target 20.000 data (SRS §8.1): `client_partners(category, archived_at)`, `(pic_id)`,
`(department_id)`, pencarian nama pakai `pg_trgm`; `interactions(followup_date) where followup_done_at is null and cancelled_at is null`;
`deals(pipeline_id, status, stage_id)`.

## 4. Hak akses (satu pintu)

SRS §3 dan §6.2: semua operasi lewat satu pemeriksa izin dan satu pencatat audit.

- `App\Services\Access` berisi `can(user, entity, level)`, `visibleCategories(user)`, `canSeeDealValue(user, deal)`,
  `canSeeRateCard(user)`, `canEditRecord`, `canEditDeal`, `canReopen`, `canMovePic`, `canConfigPipeline`.
  Aturannya dipindah apa adanya dari `assets/data.js` (fungsi `perm` sampai `canViewAdmin`), karena sudah dicoba di prototipe.
- Laravel Policy per model memanggil service ini. Query daftar memakai scope yang sama, jadi pencarian, dashboard,
  export dan email ringkasan otomatis tersaring.
- `saringField`: API Resource tidak mengirim `offer_value`, `deal_value` dan `rate_card` ke user tanpa izin.
  Export dan angka dashboard memakai resource/scope yang sama.
- Super Admin selalu M, Viewer selalu R. Hanya Super Admin yang mengelola user, department dan hak akses.
- Test otomatis per role wajib ada sebelum G2 (SRS §9.3), termasuk percobaan membuka data lewat URL langsung.

## 5. Login dan sesi

- Paket: `laravel/socialite` (provider Google sudah bawaan). Microsoft Entra ID bisa ditambah lewat `socialiteproviders/microsoft-azure` kalau nanti pindah akun.
- Alur: SSO → ambil email → cocokkan ke `users`. Belum terdaftar atau nonaktif: tolak dengan pesan jelas dan catat
  di audit log (SRS §4.1).
- Sesi: `SESSION_LIFETIME=60` (60 menit tidak aktif) plus middleware batas 12 jam sejak login.
- Menonaktifkan user menghapus semua barisnya di tabel `sessions`, jadi langsung keluar (SRS §3).
- Akun uji tanpa SSO (seperti tombol "Pilih akun uji" di prototipe) hanya aktif saat `APP_ENV=local`.

## 6. API

Semua di bawah `/api`, JSON, cookie sesi.

| Modul | Endpoint |
|---|---|
| Auth | `GET /auth/{provider}/redirect`, `GET /auth/{provider}/callback`, `POST /auth/code`, `POST /auth/code/verify`, `POST /auth/logout`, `GET /me` |
| My Work | `GET /my-work` (follow-up telat, hari ini, 7 hari, deal open per stage, deal On Hold yang perlu ditinjau) |
| Client & Partner | `GET/POST /client-partners`, `GET/PATCH /client-partners/{id}`, `POST …/{id}/archive`, `…/restore`, `…/convert-to-client`, `…/move-pic`, `POST /client-partners/merge`, `GET /client-partners/duplicates?name=` |
| Kontak | `POST /client-partners/{id}/contacts`, `PATCH/DELETE /contacts/{id}` |
| Interaksi | `GET/POST /interactions`, `POST /interactions/{id}/done`, `POST /interactions/{id}/cancel` |
| Pipeline | `GET/POST /pipelines`, `PATCH /pipelines/{id}`, `POST/PATCH /stages`, `POST /stages/reorder` |
| Deal | `GET/POST /deals`, `GET/PATCH /deals/{id}`, `POST /deals/{id}/move`, `…/won`, `…/lost`, `…/hold`, `…/reopen` |
| Dashboard | `GET /dashboard/crm`, `GET /dashboard/pipeline?per=m|q|y&val=` |
| Import/Export | `GET /import/template`, `POST /imports` (cek saja), `POST /imports/{id}/commit`, `POST /imports/{id}/undo`, `GET /export/client-partners` |
| Admin | `/users`, `/departments`, `/access-grants`, `/access-overrides`, `/option-lists`, `GET /audit-logs`, `GET /audit-logs/export` |
| Lainnya | `GET/POST/DELETE /saved-views`, `GET /notifications`, `POST /notifications/read`, `GET /search?q=` (Cmd+K) |

## 7. Pekerjaan latar

- Scheduler harian 08.00 WIB (`Asia/Jakarta`): email ringkasan follow-up jatuh tempo dan telat (SRS §4.2).
- Email penugasan saat PIC dipindah atau deal diberikan. Kirim lewat SMTP pengembangan Campus Innovate ke alamat uji,
  maksimal sekitar 200 email per hari (SRS §8.2). Kalau gagal, notifikasi tetap ada di aplikasi.
- Queue driver `database` cukup untuk 30 pengguna.
- Import pakai `maatwebsite/excel` (PhpSpreadsheet): format template yang sama dengan `assets/Template_Data_Klien_CRM_OPTIMA.xlsx`,
  maksimal 2.000 baris per file, cek dulu tanpa simpan, import ulang tidak membuat data ganda, bisa dibatalkan (SRS §4.4, §8.3).
- Backup harian `pg_dump`, dan pemulihan diuji sebelum go-live (SRS §8.1, §9.4).

## 8. Yang perlu diputuskan

1. ~~Filter periode pipeline untuk deal yang masih berjalan.~~ **Diputuskan 20 Okt 2026: tanggal dibuat**, sesuai
   SRS §4.3. Deal Open dan On Hold difilter dari `deals.created_at`, deal Won dan Lost dari `closed_at`. Prototipe
   sudah disesuaikan. Akibatnya untuk migrasi: template impor sekarang belum punya kolom tanggal dibuat, jadi deal
   hasil impor tercatat dibuat pada hari impor. Untuk migrasi data asli (tahap 3), sheet 9 Data Deal perlu kolom
   "Tanggal dibuat", dan backend memakainya bila diisi.
2. ~~Hosting.~~ **Diputuskan: Laravel Cloud paket gratis.** Sebelum G0 perlu dicek batas paket gratisnya terhadap SRS §8.1
   (backup harian, data hilang paling banyak 24 jam, pulih dalam 8 jam kerja) dan §8.3 (30 pengguna, database 5 GB).
   Kalau backup harian tidak tersedia di paket gratis, perlu `pg_dump` terjadwal ke penyimpanan terpisah.
3. ~~Provider SSO tahap awal.~~ **Diputuskan: Google Workspace** milik Campus Innovate.
4. **Kolom "Tanggal dibuat" di template impor** (sheet 9 Data Deal): belum diputuskan, lihat poin 1.

## 9. Lingkungan pengembangan

Sudah terpasang di Mac pengembang (20 Okt 2026), tanpa sudo:

- PHP 8.4, Composer, dan Laravel installer lewat Herd Lite di `~/.config/herd-lite/bin` (PATH di `~/.zshrc`).
- PostgreSQL 16.13 di `~/.local/pgsql-16`, data di `~/.local/pgsql-16-data`, hanya mendengarkan localhost:5432.
  Database `optima_crm` (pengembangan) dan `optima_crm_test` (test otomatis).
- Repo git dengan branch `main` (prototipe) dan `feat/backend-foundation` (backend).

PostgreSQL tidak jalan otomatis setelah Mac restart. Nyalakan dengan:

```
~/.local/pgsql-16/bin/pg_ctl -D ~/.local/pgsql-16-data -l ~/.local/pgsql-16.log start
```

Perintah sehari-hari dari folder `backend/`:

```
php artisan migrate:fresh --seed   # database bersih + data referensi + data contoh
php artisan test                   # test hak akses, filter periode, dan data contoh
```

Data contoh diekspor dari `seed()` di prototipe ke `database/seeders/data/sample.json`. Kalau data contoh di
prototipe diubah, jalankan ulang `node database/seeders/data/export-prototype-seed.mjs`.

Masih perlu disiapkan di akun pengembangan Campus Innovate:

- [ ] OAuth client di Google Cloud Console (tipe Web), redirect URI `http://localhost:8000/api/auth/google/callback`.
- [ ] Akun SMTP pengembangan untuk email notifikasi.
- [ ] Rahasia (client secret, password SMTP) disimpan di `.env`, tidak masuk repo.

### Mencoba aplikasi dengan backend (G1)

Dari folder `backend/`, setelah PostgreSQL menyala:

```
php artisan serve --port=8000
```

Buka http://localhost:8000. Halaman ini adalah frontend yang sama, tapi data login, pengguna, hak akses, dan
Client & Partner datang dari server. Prototipe lama tanpa backend tetap bisa dibuka lewat `serve.py` (port 5173),
dengan data contoh terpisah.

- Tanpa `GOOGLE_CLIENT_ID` di `.env`, tombol SSO membuka pemilih akun uji (hanya di `APP_ENV=local`).
- Untuk login Google sungguhan, isi `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, dan bila perlu `GOOGLE_HOSTED_DOMAIN`
  (membatasi ke satu domain Workspace) di `.env`. Redirect URI di Google Cloud Console:
  `http://localhost:8000/api/auth/google/callback`.
- Folder `assets/` disajikan lewat symlink `backend/public/assets -> ../../assets`. Saat deploy, folder ini
  disalin ke dalam `backend/` oleh `php artisan optima:build-frontend`. Lihat [deploy-laravel-cloud.md](deploy-laravel-cloud.md).

Email ringkasan 08.00 WIB (`php artisan optima:daily-digest`, coba dulu dengan `--dry-run`):

- Di server perlu cron `* * * * * php artisan schedule:run` (Laravel Cloud punya pengaturan scheduler sendiri).
- `OPTIMA_MAIL_ALLOWED_DOMAINS` (default `contoh.test`) membatasi alamat tujuan selama tahap awal, sesuai SRS 8.2.
  Alamat di luar daftar tetap dapat pemberitahuan di aplikasi, tapi tidak dikirimi email.
- `OPTIMA_MAIL_DAILY_LIMIT` (default 200) membatasi jumlah email per hari.
- Selama `MAIL_MAILER=log`, email hanya ditulis ke `storage/logs/laravel.log`. Isi pengaturan SMTP pengembangan di `.env`
  untuk mengirim sungguhan.

Yang masih tersimpan di browser (per orang, tidak dikirim ke server): profil (foto, bio) dan pilihan tema.

Yang perlu dicek sebelum UAT:

- Dashboard dan halaman Client & Partner memuat semua data yang boleh dilihat sekaligus, lalu menghitung di browser.
  Untuk target 20.000 data (SRS §8.1) ini perlu diuji beban pada hari 9–10; kalau lambat, daftar dan angka dashboard
  dipindah ke perhitungan server (endpoint `GET /api/client-partners` sudah mendukung `page`).
- Import menerima kolom opsional "Tanggal dibuat" di sheet 9. Template klien belum punya kolom ini.

### Catatan dari pembangunan G0

- **Rate card Executive bernilai "Ya"** di SRS. Prototipe hanya mengenali M dan R*, jadi Kepala Department atau
  Staf Executive di prototipe tidak bisa melihat rate card. Backend memperlakukan "Ya" sebagai boleh melihat.
- **Buka kembali deal** di backend mengikuti bunyi SRS dan lebih ketat dari prototipe: deal Won hanya oleh Super Admin
  atau Kepala Department dari department PIC deal; deal Lost dan On Hold oleh PIC atau Kepala Department/Koordinator
  di department yang sama.
- Kolom `status` di Client & Partner (disebut di SRS §6) belum dipakai prototipe. Isinya perlu disepakati.

## 10. Urutan kerja sesuai jadwal SRS §10

| Gate | Target backend |
|---|---|
| G0, hari 3 | ✅ Laravel + Postgres jalan, migrasi semua tabel, seeder data contoh (dipindah dari `seed()` di `assets/data.js`), service hak akses + test per role |
| G1, hari 5 | ✅ Login SSO Google, sesi 60 menit / 12 jam, Client & Partner (tambah, ubah, arsip, pulihkan, Lead jadi Client, pindah PIC, riwayat), kontak, cek data ganda. Frontend modul ini sudah memakai API. Login cadangan kode email (Should) belum |
| G2, hari 10 | ✅ Interaksi, follow-up, pipeline, deal, import (cek, simpan, batalkan), gabung data, Akses & Audit, audit CSV, pemberitahuan, tampilan tersimpan, email ringkasan. Dashboard dan export dihitung di browser dari data server yang sudah disaring hak aksesnya |
| G3–G5 | Perbaikan UAT, uji beban 20.000 data, migrasi data asli tahap 3, uji pemulihan backup, serah terima |
