# Serah terima OPTIMA CRM ke klien

Selama pengembangan, beberapa layanan memakai akun Campus Innovate (kawanberinovasi). Dokumen ini menjelaskan cara
memindahkannya ke akun milik klien tanpa kehilangan data dan tanpa pengguna perlu mendaftar ulang.

## 0. Satu akun Google khusus proyek

Semua layanan (Render, Neon, Google Cloud OAuth, dan Laravel Cloud kalau dipakai) didaftarkan dengan satu Gmail khusus
proyek, bukan akun pribadi atau akun Campus Innovate. Saat serah terima: ganti password, nomor HP dan email pemulihan,
serta 2FA ke milik klien, lalu ganti metode pembayaran (kalau ada) ke milik klien. Semua layanan ikut pindah sekaligus.

## 1. SSO Google

### Kenapa aman dipindah

- Aplikasi mengenali pengguna dari **email**, bukan dari OAuth client. Siapa yang boleh masuk ditentukan daftar pengguna
  di CRM (Akses & Audit → Pengguna), bukan oleh akun Google Cloud.
- OAuth client hanya "pintu" login. Isinya cuma dua environment variable: `GOOGLE_CLIENT_ID` dan `GOOGLE_CLIENT_SECRET`.
- Kolom `sso_subject` menyimpan ID akun Google sebagai catatan saja dan tidak dicek saat login. ID itu juga sama untuk
  OAuth client mana pun, jadi tidak ada data yang perlu dimigrasi.
- Jadi mengganti OAuth client = mengganti dua variabel di server. Pengguna cukup login ulang seperti biasa.

### Pilihan A (disarankan): klien membuat OAuth client sendiri

Klien punya kendali penuh dan kita tidak lagi memegang secret-nya.

1. Klien (akun Google OPTIMA, sebaiknya email kantor, bukan pribadi) membuka https://console.cloud.google.com dan membuat
   project baru, misalnya `optima-crm`.
2. APIs & Services → **OAuth consent screen**:
   - User type **External** (karena sebagian pengguna memakai Gmail pribadi).
   - Nama aplikasi `OPTIMA CRM`, support email dan developer contact = email klien.
   - Scope cukup bawaan: `openid`, `email`, `profile`. Scope ini tidak sensitif, jadi tidak perlu verifikasi Google.
   - Klik **Publish app** (status *In production*). Kalau dibiarkan *Testing*, hanya email yang dimasukkan sebagai
     *Test users* yang bisa login. Setelah dipublikasikan pun yang bisa masuk tetap hanya email yang terdaftar di CRM.
3. **Credentials → Create credentials → OAuth client ID → Web application**. Authorized redirect URI:
   `https://<domain produksi>/api/auth/google/callback` (tambahkan juga domain UAT kalau ada).
4. Klien memasukkan `GOOGLE_CLIENT_ID` dan `GOOGLE_CLIENT_SECRET` langsung di server (Laravel Cloud → Environment →
   Variables), lalu redeploy. Secret jangan dikirim lewat chat atau email.
5. Uji login dengan satu akun Super Admin dan satu akun divisi.
6. Setelah berhasil, Campus Innovate **menghapus OAuth client lama** di project pengembangan supaya secret lama tidak
   bisa dipakai lagi.

Waktu henti: hanya saat redeploy (sekitar 1 menit). Sesi yang sedang aktif tetap berjalan.

### Pilihan B: project Google Cloud kita diserahkan ke klien

Dipakai kalau klien belum punya akun Google Cloud dan ingin cepat. Client ID tidak berubah, jadi server tidak perlu diubah.

1. Di project pengembangan: IAM & Admin → IAM → **Grant access** → email klien dengan role **Owner**.
2. Klien menerima undangan, lalu mengganti support email di OAuth consent screen ke email klien.
3. Klien membuat **secret baru** di OAuth client (Add secret), memasang secret baru itu di server, lalu menonaktifkan
   dan menghapus secret lama.
4. Campus Innovate menghapus aksesnya sendiri dari IAM.

Kelemahannya: tagihan dan riwayat project tetap tercatat dari akun lama sampai klien memindahkan billing.

## 2. Repo GitHub

- Repo sekarang di `kawanberinovasi-ops/optima-crm` (public, `main` dipakai GitHub Pages untuk pratinjau).
- Sebelum diserahkan: jadikan **private** (Settings → General → Danger Zone → Change visibility), karena berisi kode
  backend klien. Matikan GitHub Pages kalau pratinjau tidak dipakai lagi.
- Serah terima: Settings → General → **Transfer ownership** ke organisasi atau akun GitHub klien. Issue, PR, dan
  riwayat commit ikut pindah; Laravel Cloud perlu dihubungkan ulang ke repo di akun baru.

## 3. Hosting (Laravel Cloud)

- Paling bersih: klien membuat organisasi Laravel Cloud sendiri, lalu aplikasi di-deploy ulang dari repo klien mengikuti
  [deploy-laravel-cloud.md](deploy-laravel-cloud.md). Data dipindah dengan `php artisan optima:backup` di server lama
  dan `php artisan optima:restore` di server baru.
- Alternatif: undang klien sebagai anggota organisasi Cloud, lalu serahkan peran owner dan metode pembayaran.

## 4. Akun dan rahasia lain

| Hal | Yang dilakukan saat serah terima |
|---|---|
| SMTP (`MAIL_*`) | Ganti ke akun email OPTIMA, lalu ubah `OPTIMA_MAIL_ALLOWED_DOMAINS` ke domain asli |
| Super Admin | Pastikan minimal 2 Super Admin dari pihak klien, lalu nonaktifkan atau hapus akun Campus Innovate di Akses & Audit |
| Object Storage backup | Bucket di akun klien; uji restore sekali setelah pindah |
| `APP_KEY` | Tidak perlu diganti kecuali bocor. Menggantinya membuat sesi dan data terenkripsi lama tidak terbaca |
| Daftar akun internal | File `storage/app/private/internal-users.json` hanya ada di laptop pengembang; di produksi pengguna dikelola dari Akses & Audit |
