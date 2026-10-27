# OPTIMA CRM

Prototipe OPTIMA CRM dengan seluruh modul yang tersedia diaktifkan. Folder dan penyimpanan browser tetap terpisah dari salinan di Downloads.

## Menjalankan secara lokal

Dengan Python 3:

```bash
python3 serve.py 5175
```

Buka **http://127.0.0.1:5175/** di Safari atau browser lain. Biarkan server berjalan selama aplikasi digunakan.

Alternatif: `bash start.sh` pada macOS/Linux atau `start.bat` pada Windows. Dengan Node.js, tersedia `npm start`.

## Modul aktif

- **S-01:** halaman login menggunakan akun pengembangan. SSO masih simulasi lokal.
- **S-02:** pengguna, department, role, aktivasi akun, hak akses, pengecualian per pengguna, dan edit profil sendiri.
- **S-03:** status Lead/Client, dengan layanan KOL, Community, Media, Digital Campaign, CampusNet, dan DietPartner.
- **S-04:** pencarian, filter, segmen, PIC, validasi, pemeriksaan duplikasi, penggabungan, arsip, dan pemulihan data.
- **S-05:** interaksi, hasil komunikasi, langkah berikutnya, dan follow-up.
- **S-06:** Pipeline, deal, stage, PIC, layanan, penawaran, target closing, Won, Lost, dan On Hold.
- **S-07:** My Work, Dashboard pipeline, dan Dashboard CRM.
- **S-08:** import/export Excel, unduh template, preview import, serta pembatalan batch import.
- **S-09:** riwayat perubahan data dan audit log.
- Daftar pilihan admin, edit profil, serta pilihan warna tema melalui menu profil.

Setelah login, aplikasi membuka Client & Partner. Semua menu dapat digunakan sesuai role dan divisi pengguna. Tidak ada pembatasan modul khusus presentasi. Pengaktifan modul bukan bukti penyelesaian UAT, training, atau integrasi produksi.

## Penyimpanan dan batas prototipe

Data perubahan dan foto profil tersimpan secara lokal di browser menggunakan kunci `optima_crm_client_preview_v1`. Tema menggunakan kunci `optima_client_preview_theme`. Data tidak otomatis tersinkron antarbrowser atau perangkat, dan tidak ikut tersimpan ke GitHub.

Repositori berisi kode aplikasi dan aset contoh, bukan ekspor data dari browser. Tidak ada integrasi SSO produksi atau penyimpanan server bersama yang diklaim oleh prototipe ini. GitHub menyimpan kode; pengunggahan kode tidak otomatis menerbitkan aplikasi ke hosting.

Lihat [BACA_DULU.txt](BACA_DULU.txt) untuk cakupan dan petunjuk tambahan.

## Alur penerimaan dan pembagian data

- Setiap pengguna yang memiliki hak tambah dapat menerima Lead langsung; CS bukan pintu masuk wajib.
- PIC awal dan divisi pemilik otomatis mengikuti akun pengisi. Identitas pengisi tetap tersimpan ketika penanganan berpindah.
- Lead hanya berubah otomatis menjadi Client setelah deal Won. Lost dan On Hold tidak mengubah status relasi menjadi Client. Pembukaan kembali deal tidak menghapus status pelanggan yang sudah pernah Won.
- KOL dan Community awalnya dipetakan ke Digital Campaign; Media ke Creative & Media. Pemetaan layanan dapat diubah oleh admin pada tab Layanan & Divisi. Pemetaan memberi saran penerima dan tidak memaksa semua Lead melewati divisi tersebut.
- Serah terima PIC/divisi dapat dilakukan dari dan ke divisi mana pun, dengan penerima aktif dan alasan. Kontak, interaksi, serta deal mengikuti pemilik baru; pengisi awal tetap tercatat.
- Lead, Client, pencarian, detail deal, dan Pipeline ditampilkan sesuai divisi. Executive melihat lintas divisi.

## Akun asli untuk internal

Demo publik tetap memakai identitas contoh. Daftar nama/email tim asli disimpan terpisah dari repositori. Pada localhost, Super Admin dapat memuat JSON daftar tim melalui Pengguna → Muat daftar tim internal. Pemilihan akun dan penyimpanannya tetap simulasi lokal; tindakan ini belum membuat akun SSO/server. Tombol muat daftar internal tidak ditampilkan di situs publik.

Pembatasan tampilan prototipe ini belum merupakan keamanan otorisasi di server. Untuk penggunaan operasional, autentikasi, akses per divisi, dan penyimpanan bersama perlu ditegakkan oleh backend.

## Pengujian aturan bisnis

```bash
npm test
```

Pengujian mencakup PIC otomatis, penerimaan langsung lintas divisi, promosi setelah Won, penolakan perubahan kategori manual, pemisahan akses divisi, serah terima dua arah, migrasi data contoh, serta penjagaan akun internal.
