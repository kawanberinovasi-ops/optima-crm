# Alur Lead dan Client (perubahan 22 Oktober 2026)

Perubahan alur bisnis di atas SRS v0.2, disamakan dengan pratinjau klien
(`kawanberinovasi-ops.github.io/optima-crm`) **kecuali cakupan halaman**: aplikasi tetap lengkap (My Work, Dashboard,
import, dan export tetap aktif, tidak dibatasi ke S-01–S-06 seperti pratinjau). Desain dan susunan halaman tetap. Aturan di bawah diperiksa di **backend**
(Laravel) untuk setiap cara mengakses data. Prototipe tanpa backend (`serve.py`) meniru aturan yang sama di browser,
tapi itu **simulasi tampilan, bukan keamanan**: datanya ada di browser dan bisa diubah siapa pun yang membuka DevTools.

## 1. Penerimaan Lead

- Siapa pun yang punya hak input Lead (level C atau M) bisa memasukkan Lead dari divisi mana pun. Customer Service
  tetap bisa, tapi bukan satu-satunya jalur.
- Data baru **selalu Lead**. PIC = pengguna yang mengisi, divisi pemilik = divisi pengguna itu. Nilai kategori, PIC,
  atau divisi yang dikirim dari form diabaikan server.
- **Pembuat** (`created_by`) dan waktu dibuat disimpan permanen dan tidak pernah berubah, terpisah dari **PIC aktif**.

## 2. Lead jadi Client

- Lead jadi Client **otomatis** saat deal dinyatakan Closing/Won (Pipeline, form Catat interaksi, atau import deal Won).
- Lost dan On Hold tidak mengubah status. Client tetap Client walaupun deal berikutnya Lost atau dibuka kembali.
- Tidak ada konversi manual: endpoint dan tombol "Jadikan Client" dihapus, dan form edit tidak bisa mengubah status.
- Satu relasi punya banyak deal; deal baru tidak membuat relasi baru.

## 3. Akses per divisi

| Siapa | Melihat |
|---|---|
| Pengguna biasa | Lead dan Client milik divisinya saja, sesuai level role (R lihat, C tambah dan ubah data yang PIC-nya dia sendiri, M kelola semua di divisinya). Kepala Department dan Koordinator berlevel C boleh mengubah semua data divisinya |
| Department dengan "lihat lintas divisi" (awal: Executive) | Semua divisi dengan level M; Viewer Executive hanya melihat |
| Super Admin | Semua |

Cakupan yang sama berlaku untuk detail data, pencarian, interaksi, deal, pipeline, dashboard, export, cek data ganda
(hanya alasan yang terlihat untuk data divisi lain), import, dan email ringkasan. Flag "lihat lintas divisi" bisa diubah
Super Admin di Akses & Audit → Department. Kepala department **tidak** otomatis jadi Super Admin.

Matriks hak akses awal: **keputusan klien** di tabel "3. Hak akses awal" (5 Nov 2026), sumbernya `App\Support\DivisionDefaults`.

| Department | Lead | Client | Partner | Interaksi | Deal | Nilai deal | Rate card |
|---|---|---|---|---|---|---|---|
| Executive | M | M | M | M | M | Ya | Ya |
| Customer Service | C | R | R | M | R | Tidak | R* |
| Digital Campaign | R | R | M | C | N | Tidak | M |
| Creative & Media | R | R | M | C | N | Tidak | M |
| CampusNet | C | R | M | C | N | Tidak | M |
| DietPartner | C | R | M | C | N | Tidak | M |
| Business Operation | R | M | R | R | M | Ya | M |

- Commercial sudah dilebur ke Customer Service, jadi baris Commercial di tabel klien tidak dipakai; CS memakai barisnya sendiri.
- Kolom Community, KOL/KOC, dan Media sekarang layanan. Hak **Partner** = level tertinggi dari ketiganya.
- Ya* / R* = hanya Kepala Department dan Koordinator. Divisi dengan Deal **N** tidak melihat menu Pipeline; Deal **R** hanya
  melihat deal, tidak bisa membuat. Tombol "Lead baru" butuh Lead minimal C, "Partner baru" butuh Partner minimal C.
- Migrasi `2026_11_05_000004` mengisi matriks ini ke database yang sudah ada. Super Admin tetap bisa mengubahnya di
  Akses & Audit → Hak akses, atau memberi pengecualian per orang.

## 3a. Pipeline per divisi

- Setiap divisi punya satu pipeline (nama = nama divisi) dengan stage Lead, Qualified, Meeting, Proposal, Negotiation,
  Closing. Department baru otomatis mendapat pipeline; ganti nama department ikut mengganti nama pipeline-nya.
- Pengguna melihat pipeline divisinya; Executive dan Super Admin melihat semua. Stage diatur Super Admin atau Kepala
  Department, hanya untuk pipeline divisinya (Executive untuk semua). Pipeline baru dari menu jadi milik divisi pembuatnya.
- Deal baru otomatis masuk pipeline divisi pemilik relasi (pilihan lini bisnis dari klien diabaikan server). PIC deal
  bawaan = PIC relasi, dan harus pengguna aktif, bukan Viewer, dari divisi itu. Berlaku juga untuk Catat interaksi dan import.
- **Migrasi** (`2026_10_22_000002_division_pipelines`): pipeline lama tanpa divisi diarsipkan (tidak dihapus), deal-nya
  pindah ke pipeline divisi relasinya dengan stage dicocokkan lewat nama (kalau tidak ada, urutan; Won ke Closing).

## 4. Pindah PIC / divisi (serah terima)

- Dari divisi mana pun ke divisi mana pun, termasuk ke dan dari Customer Service. Pengguna memilih divisi tujuan dan
  PIC penerima yang aktif (bukan Viewer) di divisi itu, plus alasan wajib.
- Yang boleh memindahkan: siapa pun yang boleh mengubah data itu (aturan pratinjau klien). Divisi tujuan bawaan di form
  adalah divisi yang menangani layanannya.
- Tercatat di tabel `handovers` dan audit log: PIC dan divisi asal, PIC dan divisi tujuan, waktu, yang menyerahkan, alasan.
  Riwayatnya tampil di drawer detail.
- Divisi pemilik ikut pindah, jadi akses pindah ke divisi penerima. Executive tetap melihat. Pembuat awal tidak berubah.
- Riwayat serah terima tercatat di tabel `handovers`, audit log, dan Riwayat data (tidak lagi ditampilkan sebagai daftar
  terpisah di detail, sama seperti pratinjau).
- Interaksi dan deal tetap di relasi yang sama. Semua deal pindah ke **pipeline divisi penerima** (stage dicocokkan lewat
  nama, Won tetap di Closing) dan dicatat di riwayat deal. Deal yang masih berjalan (Open/On Hold) ikut dipegang PIC baru;
  deal Won/Lost tetap tercatat atas PIC lama. Gabung data ganda juga memindahkan deal ke pipeline divisi data yang disimpan.

## 5. Status vs layanan

- Lead/Client = status relasi. KOL, Community, Media, Digital Campaign = **layanan** (boleh kosong).
- Pemetaan awal: Media → Creative & Media, Digital Campaign → Digital Campaign, CampusNet → CampusNet,
  DietPartner → DietPartner, KOL dan Community → Digital Campaign (**sementara**, menunggu keputusan). Bisa diubah
  Super Admin di Akses & Audit → Layanan & Divisi.
- Daftar Client & Partner punya filter Layanan (termasuk "Belum ditentukan"), dan tampilan tersimpan ikut menyimpan filter itu.
- Memilih atau mengubah layanan, atau mengubah pemetaannya, tidak memindahkan PIC atau divisi. Itu lewat serah terima.
- Rate card berlaku untuk layanan KOL.
- **Migrasi data lama** (`2026_10_22_000001_lead_client_flow`): kategori KOL/KOC, Community, dan Media jadi layanan
  dengan status Lead; yang pernah punya deal Won jadi Client. Id, kode, kontak, interaksi, deal, dan riwayat tidak berubah.
- Import: kolom Kategori lama tetap diterima (KOL/KOC, Community, Media dipetakan ke layanan), ada kolom opsional Layanan.
  Pengguna di luar Executive hanya bisa mengimport data untuk divisinya. Data yang sudah ada tidak berubah status, PIC,
  atau divisinya lewat import.

## 5a. Disamakan dengan pratinjau klien versi 27 Okt 2026

- **Halaman awal** setelah login dan logo di header: Client & Partner (`#/cp`), sama seperti pratinjau.
- **Data terhapus**: tombol Hapus di Client & Partner (baris tabel dan detail), Hapus lini bisnis, hapus stage, Hapus
  pengguna, hapus department, hapus pengecualian, dan hapus layanan. Semua masuk ke Data terhapus (menu profil dan tombol
  di Client & Partner) dan bisa dipulihkan. Tidak ada penghapusan permanen otomatis.
  - Yang boleh menghapus: Client & Partner = level M atas data itu; lini bisnis dan stage = yang boleh mengatur pipeline
    itu; layanan, department, pengguna, pengecualian = Super Admin. Memulihkan: Super Admin semua, selain itu sesuai hak
    menghapusnya. Hak dan syarat diperiksa ulang di server saat konfirmasi (`App\Services\Trash`).
  - Syarat yang menahan penghapusan sama dengan pratinjau: lini bisnis yang masih punya deal, stage Closing atau stage
    yang masih punya deal, layanan yang masih dipakai, department yang masih punya pengguna/data/layanan/deal, akun yang
    sedang dipakai, Super Admin aktif terakhir, dan pengguna yang masih jadi PIC relasi atau deal aktif.
  - Client & Partner yang dihapus disembunyikan (`deleted_at`) beserta interaksi dan deal-nya. Pengguna yang dihapus
    langsung nonaktif dan dipulihkan dalam keadaan nonaktif; namanya tetap terbaca di riwayat lama. Arsip tetap ada
    terpisah dari Hapus.
- Tab Akses & Audit → **Layanan & Divisi** dipindah setelah Department, dengan tombol Layanan baru dan Hapus. Pengaturan
  "lihat lintas divisi" tidak lagi tampil di kartu department (Executive tetap melihat semua divisi).
- Layanan deal otomatis mengikuti layanan relasinya. Dashboard, My Work, pencarian, dan pemberitahuan hanya menampilkan
  data, deal, pipeline, dan tim yang boleh dilihat.

## 5b. Revisi klien 5 Nov 2026

Perubahan di atas pratinjau klien, sesuai catatan revisi klien:

1. **Setelah login masuk ke My Work** (juga logo di header).
2. **Tidak ada tombol ganti akun** ("Executive ▾") di header; akun ditentukan saat login. Divisi dan role terlihat di menu profil.
3. **Akses & Audit jadi menu header** (Super Admin dan Kepala Department), tidak lagi di menu profil.
4. **Setiap divisi punya pipeline sendiri, diatur Super Admin.**
   - Pipeline bernama sesuai divisi (Lead → Qualified → Meeting → Proposal → Negotiation → Closing); Digital Campaign memakai
     pipeline **Campaign** (Brief → Konsep → Produksi → Tayang → Report → Closing). Department baru langsung punya pipeline.
   - Pengguna memakai pipeline divisinya. Di Akses & Audit → Pipeline, Super Admin mengatur divisi pemakai setiap pipeline
     dan pengecualian per pengguna (tambahkan atau cabut). Pengguna tanpa pipeline tidak melihat menu Pipeline.
   - Membuat pipeline, mengubah atau menghapus stage, dan menghapus pipeline hanya Super Admin (Kepala Department tidak lagi).
   - Deal dibuat di pipeline yang dipakai PIC relasinya. Pindah PIC memindahkan deal ke pipeline PIC baru kalau PIC baru
     tidak memakai pipeline lamanya (stage dicocokkan lewat nama, kalau tidak ada lewat urutan; Won tetap di Closing).
   - **Migrasi** `2026_11_05_000002` sempat menggabungkan pipeline jadi Sales dan Campaign (stage pipeline Digital Campaign
     diganti nama sesuai urutan); `2026_11_05_000003` memecah Sales lagi jadi pipeline per divisi, dan deal ikut divisi PIC relasinya.
5. **Client dan Partner dipisah jadi dua menu header**: Client (Lead dan Client) dan Partner. Status relasi: Lead, Client, atau **Partner**. Partner (vendor, media partner, KOL,
   komunitas) langsung tercatat tanpa tahap Lead; Lead jadi Client tetap hanya lewat deal Won. Saat edit, Lead dan Partner
   boleh saling diganti kalau salah isi; Client tidak. Data lama tidak diubah otomatis.
6. **Hak lihat data: Super Admin melihat semua; pengguna lain hanya data yang PIC-nya dia** (beserta interaksi dan deal-nya).
   Ini menggantikan akses per divisi dan akses lintas divisi Executive. Kepala Department dan Executive tidak lagi otomatis
   melihat data timnya. Pindah PIC tetap jalan: penerima melihat datanya, PIC lama tidak lagi. Import oleh selain Super Admin
   hanya untuk data dengan PIC dirinya sendiri. PIC deal selalu mengikuti PIC relasinya.

## 6. Akun internal

- Daftar kepala department ada di `backend/storage/app/private/internal-users.json`. Folder itu **diabaikan git**, jadi
  nama dan email asli tidak masuk repository, aset frontend, atau data contoh. Demo tetap memakai akun `@contoh.test`.
- Muat ke database lingkungan internal dengan:

  ```
  php artisan optima:sync-internal-users storage/app/private/internal-users.json --dry-run
  php artisan optima:sync-internal-users storage/app/private/internal-users.json
  ```

- Satu orang = satu pengguna. Email kedua disimpan sebagai email alternatif (`user_emails`); login lewat email mana pun
  masuk ke identitas yang sama. Semua didaftarkan sebagai Kepala Department di divisinya, tidak ada yang otomatis
  Super Admin. Super Admin dibuat terpisah dengan `php artisan optima:create-admin`.
- Di server, salin file itu lewat jalur aman (bukan lewat git), jalankan perintahnya, lalu hapus filenya.
- Kepala Executive didaftarkan sebagai **Super Admin** (keputusan 5 Nov 2026); kepala lain sebagai Kepala Department.
- `--replace-demo` menghapus akun uji `@contoh.test`; semua data yang mereka pegang dialihkan ke kepala department
  divisi yang sama (atau Super Admin kalau divisinya belum punya kepala). `./start-backend.sh --reset` menjalankan ini otomatis.

## 5c. Modul Client dan modul Partner dipisah (5 Nov 2026)

- Menu header **Client** langsung membuka halaman Client (tanpa dropdown); import dan export ada di halamannya.
- **Form berbeda.** Client: Lead baru (Status, Layanan, Segmen, PIC, Divisi, kontak). Partner: Partner baru (Jenis partner,
  Bidang, PIC, Divisi, kontak; rate card muncul kalau jenisnya KOL/KOC). Jenis partner disimpan di `client_partners.partner_type`.
- **Import dan export terpisah.** Dari menu Client: sheet "7 Data Client" (+ interaksi dan deal); baris Partner ditolak.
  Dari menu Partner: template "Template kosong partner" (sheet "7 Data Partner" dan interaksi), semua baris jadi Partner,
  sheet deal tidak dibaca. Kode yang sudah dipakai modul lain ditolak.
- **Daftar pilihan per modul** (Akses & Audit → Daftar pilihan, tambah dan hapus oleh Super Admin): Modul Client (Segmen client,
  Alasan deal Lost), Modul Partner (Jenis partner, Bidang partner), dipakai keduanya (Sumber data, Jenis interaksi).
  Migrasi `2026_11_05_000005` memindahkan nilai segmen yang bersifat bidang partner ke Bidang partner.
- Akses & Audit → Pipeline: setiap pipeline punya tombol **Hapus** (masuk Data terhapus; ditolak kalau masih ada deal).

## Menyambungkan SSO Google (Gmail)

Akun kepala department memakai Gmail pribadi dan email optima.web.id, jadi login tidak dibatasi satu domain Workspace.
1. Google Cloud Console → APIs & Services → OAuth consent screen: tipe **External**. Selama status *Testing*, tambahkan
   email para pengguna sebagai *Test users* (atau *Publish app* supaya semua akun Google bisa mencoba; yang bisa masuk
   tetap hanya email yang terdaftar di CRM).
2. Credentials → Create OAuth client ID → *Web application*. Authorized redirect URI:
   `http://127.0.0.1:8000/api/auth/google/callback` (lokal) dan `https://<domain produksi>/api/auth/google/callback`.
3. Isi `GOOGLE_CLIENT_ID` dan `GOOGLE_CLIENT_SECRET` di `backend/.env` (jangan dibagikan di chat atau repo),
   biarkan `GOOGLE_HOSTED_DOMAIN` kosong, lalu `php artisan config:clear`.
4. Email utama maupun email alternatif di daftar akun internal sama-sama bisa dipakai login.

Saat proyek dialihkan ke klien: OAuth client sebaiknya dibuat ulang di akun Google milik klien, lalu ganti isi `.env` server.

## Status autentikasi yang benar-benar tersedia

| Cara login | Status |
|---|---|
| SSO Google Workspace | Kode siap dan dites dengan Google tiruan. **Belum aktif**: butuh `GOOGLE_CLIENT_ID` dan `GOOGLE_CLIENT_SECRET` dari OAuth client Campus Innovate |
| Login akun uji (pilih akun) | Hanya di `APP_ENV=local`. Mati otomatis di staging dan produksi |
| Login kode email (FR-AUT-04) | Belum dibuat |

Jadi daftar akun internal sudah bisa didaftarkan, tapi mereka baru bisa login di server setelah SSO Google dikonfigurasi
atau login kode email dibuat.
