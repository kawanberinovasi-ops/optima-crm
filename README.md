# OPTIMA CRM — Client Preview

Prototipe presentasi OPTIMA CRM dengan cakupan S-01 sampai S-06. Folder dan penyimpanan browser terpisah dari aplikasi lengkap.

## Menjalankan secara lokal

Dengan Python 3:

```bash
python3 serve.py 5175
```

Buka **http://127.0.0.1:5175/** di Safari atau browser lain. Biarkan server berjalan selama aplikasi digunakan.

Alternatif: `bash start.sh` pada macOS/Linux atau `start.bat` pada Windows. Dengan Node.js, tersedia `npm start`.

## Cakupan presentasi

- **S-01:** halaman login menggunakan akun pengembangan. SSO masih simulasi lokal.
- **S-02:** pengguna, department, role, aktivasi akun, hak akses, pengecualian per pengguna, dan edit profil sendiri.
- **S-03:** database Lead, Client, Community, KOL/KOC, dan Media.
- **S-04:** pencarian, filter, segmen, PIC, validasi, pemeriksaan duplikasi, penggabungan, arsip, dan pemulihan data.
- **S-05:** interaksi, hasil komunikasi, langkah berikutnya, dan follow-up.
- **S-06:** Pipeline, deal, stage, PIC, layanan, penawaran, target closing, Won, Lost, dan On Hold.
- Pilihan warna tema melalui menu profil.

Setelah login, aplikasi membuka Client & Partner. My Work dan Dashboard tetap terlihat normal, tetapi tidak menjalankan aksi saat diklik. Import/export dan audit log tidak diaktifkan pada presentasi ini. Sumber modul lengkap dipertahankan.

## Penyimpanan dan batas prototipe

Data perubahan dan foto profil tersimpan secara lokal di browser menggunakan kunci `optima_crm_client_preview_v1`. Tema menggunakan kunci `optima_client_preview_theme`. Data tidak otomatis tersinkron antarbrowser atau perangkat, dan tidak ikut tersimpan ke GitHub.

Repositori berisi kode aplikasi dan aset contoh, bukan ekspor data dari browser. Tidak ada integrasi SSO produksi atau penyimpanan server bersama yang diklaim oleh prototipe ini. GitHub menyimpan kode; pengunggahan kode tidak otomatis menerbitkan aplikasi ke hosting.

Lihat [BACA_DULU.txt](BACA_DULU.txt) untuk cakupan dan petunjuk tambahan.
