# Persiapan backend OPTIMA CRM

Acuan: SRS CRM & SSO Foundation OPTIMA v0.2 (13 Okt 2026) dan prototipe di folder ini (`assets/*.js`).
Status: rencana kerja, belum ada kode backend.

## 1. Keputusan

| Hal | Pilihan | Sumber |
|---|---|---|
| Framework | Laravel (PHP 8.3+) | keputusan tim |
| Database | PostgreSQL 16 | SRS §01 |
| Hosting | Laravel Cloud (atau Forge), **perlu dikonfirmasi** | keputusan tim |
| Login | OIDC lewat Google Workspace / Microsoft Entra ID, akun pengembangan Campus Innovate | SRS §4.1, §8.2 |
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
| `notifications` | user_id, text, link, read_at | lonceng di navbar |
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

- Paket: `laravel/socialite` + `socialiteproviders/microsoft-azure` (Google sudah bawaan).
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
2. **Hosting.** Konfirmasi Laravel Cloud atau Forge, dan region server.
3. **Provider SSO tahap awal.** Google Workspace atau Microsoft Entra ID milik Campus Innovate.

## 9. Yang perlu disiapkan sebelum scaffolding

Di Mac pengembang (butuh password admin):

- [ ] [Laravel Herd](https://herd.laravel.com): PHP 8.3+, Composer dan Laravel installer sekaligus.
- [ ] PostgreSQL 16: [Postgres.app](https://postgresapp.com) atau DBngin. Buat database `optima_crm`.
- [ ] `git init` di folder ini, supaya perubahan backend bisa direview per langkah.

Akun pengembangan Campus Innovate:

- [ ] OAuth client (Google Cloud Console atau Entra ID), redirect URI `http://localhost:8000/api/auth/google/callback`.
- [ ] Akun SMTP pengembangan untuk email notifikasi.
- [ ] Rahasia (client secret, password SMTP) disimpan di `.env`, tidak masuk repo.

## 10. Urutan kerja sesuai jadwal SRS §10

| Gate | Target backend |
|---|---|
| G0, hari 3 | Laravel + Postgres jalan, migrasi semua tabel, seeder data contoh (dipindah dari `seed()` di `assets/data.js`), service hak akses + test per role |
| G1, hari 5 | Login SSO + login cadangan, sesi 60 menit / 12 jam, Client & Partner CRUD, kontak, cek data ganda. Frontend modul ini sudah memakai API |
| G2, hari 10 | Interaksi, follow-up, pipeline, deal, dashboard, import/export, audit, email ringkasan. Semua Must lulus, tidak ada bug S1/S2 |
| G3–G5 | Perbaikan UAT, uji beban 20.000 data, migrasi data asli tahap 3, uji pemulihan backup, serah terima |
