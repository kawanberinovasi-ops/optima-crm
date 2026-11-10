# Keamanan dan perlindungan data OPTIMA CRM

Ringkasan lapisan keamanan yang aktif. Pengetatan terakhir: 10 Nov 2026 (tes: `tests/Feature/SecurityHardeningTest.php`).

## Login dan sesi

- Login produksi hanya lewat **SSO Google**; yang bisa masuk hanya email terdaftar dan aktif di CRM (email alternatif
  dikenali sebagai orang yang sama). Tidak ada password yang disimpan.
- **Login uji (pilih akun)** hanya jalan kalau `APP_ENV=local` **dan** permintaan datang dari laptop sendiri
  (host dan IP klien `127.0.0.1`/`localhost`). Lewat tunnel, proxy, atau domain publik selalu ditolak (404).
- Sesi berakhir setelah 60 menit tidak aktif dan maksimal 12 jam sejak login; akun yang dinonaktifkan langsung keluar.
- Isi sesi di database **dienkripsi** (`SESSION_ENCRYPT=true`). Cookie sesi `HttpOnly`, `SameSite=Lax`, dan otomatis
  `Secure` kalau diakses lewat HTTPS.
- Batas permintaan: login 20/menit per IP, API 240/menit per akun, import dan export audit 10/menit per akun.

## Hak akses data

- Semua aturan akses dicek di **backend** (bukan hanya disembunyikan di tampilan): Super Admin melihat semua data,
  yang lain hanya data yang dia pegang sebagai PIC, sesuai matriks Hak akses per divisi.
- **Minimalisasi data:** email pengguna lain hanya dikirim ke Super Admin; pengguna lain cukup menerima nama, divisi,
  dan role. Rate card dan nilai deal hanya dikirim ke yang berhak.
- Setiap perubahan tercatat di audit log (siapa, kapan, sebelum/sesudah, IP); hapus selalu lewat Data terhapus dan bisa
  dipulihkan.

## Browser

- Dalam mode backend, data CRM **tidak pernah disimpan di `localStorage`**: hanya di memori tab, dibuang saat logout,
  dan sisa cache versi lama dihapus otomatis. Yang tersimpan di browser hanya pilihan tema.
- **Content-Security-Policy** ketat: skrip hanya dari domain sendiri (+ satu skrip inline dengan nonce acak per
  permintaan), tanpa `eval`, tidak bisa disematkan di situs lain (`frame-ancestors 'none'`), koneksi hanya ke server
  sendiri. Ditambah `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, `COOP`, dan HSTS lewat HTTPS.
- Respons API `Cache-Control: no-store`.

## Enkripsi data tersimpan

- **Email dan telepon kontak** dienkripsi di database (AES-256 dengan APP_KEY). Cek duplikat tetap jalan lewat indeks
  HMAC (`email_hash`, `phone_hash`) yang tidak bisa dibalik ke email/telepon aslinya.
- **Data terhapus** (snapshot di `trash_entries`) dienkripsi.
- **Backup harian** dienkripsi per potongan (libsodium XChaCha20-Poly1305, file `.ndjson.gz.enc`): isinya tidak
  terbaca, dan file yang diubah, terpotong, atau dibuka dengan kunci lain ditolak tanpa memasukkan data apa pun.
  Backup kini memuat semua tabel data (termasuk pipeline per divisi, layanan, serah terima, Data terhapus).

> **APP_KEY sekarang kunci data.** Tanpa APP_KEY yang sama, email/telepon kontak, Data terhapus, dan backup tidak bisa
> dibaca lagi. Simpan APP_KEY di password manager milik klien, jangan pernah diganti/di-generate ulang di server yang
> sudah berisi data, dan ikutkan saat pindah server. Untuk memisahkan kunci backup dari server, isi
> `OPTIMA_BACKUP_KEY` (`openssl rand -base64 32`) dan simpan salinannya terpisah dari lokasi backup.

## Server dan file

- Halaman debug Laravel **tidak pernah tampil** ke luar laptop pengembang walaupun `APP_DEBUG=true`.
- Export CSV (audit log, backend dan frontend) aman dari **formula injection**: sel diawali `= + - @` diberi kutip.
- Dependensi dicek dengan `composer audit` (bersih per 10 Nov 2026; Symfony diperbarui ke 8.1.11).
- Daftar akun asli (`internal-users.json`), `.env`, dan secret tidak pernah masuk repo. Client secret Google dan
  password database hanya diisi oleh pemilik akun.

## Yang masih bisa ditingkatkan

- Audit log menyimpan nilai sebelum/sesudah apa adanya (termasuk perubahan email/telepon kontak); bisa ikut dienkripsi
  atau disamarkan kalau diperlukan.
- Verifikasi consent screen Google dan halaman kebijakan privasi sebelum dipublikasikan untuk semua akun.
- Hosting produksi milik klien dengan HTTPS dan domain sendiri (tunnel hanya untuk UAT).
