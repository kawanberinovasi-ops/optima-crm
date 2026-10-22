# Alur Lead dan Client (perubahan 22 Oktober 2026)

Perubahan alur bisnis di atas SRS v0.2. Desain dan susunan halaman tetap. Aturan di bawah diperiksa di **backend**
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
| Pengguna biasa | Lead dan Client milik divisinya saja, sesuai level role (R lihat, C tambah dan ubah punya sendiri, M kelola semua di divisinya) |
| Department dengan "lihat lintas divisi" (awal: Executive) | Semua divisi, tetap sesuai level role (Viewer Executive hanya melihat) |
| Super Admin | Semua |

Cakupan yang sama berlaku untuk detail data, pencarian, interaksi, deal, pipeline, dashboard, export, cek data ganda
(hanya alasan yang terlihat untuk data divisi lain), import, dan email ringkasan. Flag "lihat lintas divisi" bisa diubah
Super Admin di Akses & Audit → Department. Kepala department **tidak** otomatis jadi Super Admin.

Matriks hak akses disesuaikan: kolom Community, KOL/KOC, dan Media dihapus (sekarang layanan). Supaya pengguna yang
punya hak input bisa menerima Lead, Lead naik dari R ke C untuk Customer Service dan Creative & Media. Business Operation
tetap hanya melihat. Ini menyimpang dari tabel SRS bagian 3 dan perlu disetujui di revisi SRS.

## 4. Serah terima

- Dari divisi mana pun ke divisi mana pun, termasuk ke dan dari Customer Service. Pengguna memilih divisi tujuan dan
  PIC penerima yang aktif (bukan Viewer) di divisi itu, plus alasan wajib.
- Yang boleh menyerahkan: yang boleh mengubah data itu, berlevel M atau berperan Kepala Department/Koordinator.
- Tercatat di tabel `handovers` dan audit log: PIC dan divisi asal, PIC dan divisi tujuan, waktu, yang menyerahkan, alasan.
  Riwayatnya tampil di drawer detail.
- Divisi pemilik ikut pindah, jadi akses pindah ke divisi penerima. Executive tetap melihat. Pembuat awal tidak berubah.
- Interaksi dan deal tetap di relasi yang sama. Deal yang masih berjalan (Open/On Hold) ikut dipegang PIC baru supaya
  tetap terurus; deal Won/Lost tetap tercatat atas PIC lama.

## 5. Status vs layanan

- Lead/Client = status relasi. KOL, Community, Media, Digital Campaign = **layanan** (boleh kosong).
- Pemetaan awal: Media → Creative & Media, Digital Campaign → Digital Campaign, KOL dan Community → Digital Campaign
  (**sementara**, menunggu keputusan). Bisa diubah Super Admin di Akses & Audit → Daftar pilihan.
- Memilih atau mengubah layanan, atau mengubah pemetaannya, tidak memindahkan PIC atau divisi. Itu lewat serah terima.
- Rate card berlaku untuk layanan KOL.
- **Migrasi data lama** (`2026_10_22_000001_lead_client_flow`): kategori KOL/KOC, Community, dan Media jadi layanan
  dengan status Lead; yang pernah punya deal Won jadi Client. Id, kode, kontak, interaksi, deal, dan riwayat tidak berubah.
- Import: kolom Kategori lama tetap diterima (KOL/KOC, Community, Media dipetakan ke layanan), ada kolom opsional Layanan.
  Pengguna di luar Executive hanya bisa mengimport data untuk divisinya. Data yang sudah ada tidak berubah status, PIC,
  atau divisinya lewat import.

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

## Status autentikasi yang benar-benar tersedia

| Cara login | Status |
|---|---|
| SSO Google Workspace | Kode siap dan dites dengan Google tiruan. **Belum aktif**: butuh `GOOGLE_CLIENT_ID` dan `GOOGLE_CLIENT_SECRET` dari OAuth client Campus Innovate |
| Login akun uji (pilih akun) | Hanya di `APP_ENV=local`. Mati otomatis di staging dan produksi |
| Login kode email (FR-AUT-04) | Belum dibuat |

Jadi daftar akun internal sudah bisa didaftarkan, tapi mereka baru bisa login di server setelah SSO Google dikonfigurasi
atau login kode email dibuat.
