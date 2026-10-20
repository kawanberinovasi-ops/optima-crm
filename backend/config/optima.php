<?php

return [

    /*
    | Batas sesi (SRS 4.1): berakhir setelah 60 menit tidak aktif (SESSION_LIFETIME di config/session.php)
    | atau setelah 12 jam sejak login, mana yang lebih dulu.
    */
    'session_absolute_hours' => (int) env('SESSION_ABSOLUTE_HOURS', 12),

    /*
    | Login akun uji tanpa SSO, untuk pengembangan dan demo lokal saja. Selalu mati di luar APP_ENV=local,
    | apa pun nilai .env-nya.
    */
    'dev_login' => env('APP_ENV') === 'local' && (bool) env('OPTIMA_DEV_LOGIN', true),

    /*
    | Folder frontend (index.html dan assets/) yang disajikan dari domain yang sama dengan API.
    */
    // Hasil `php artisan optima:build-frontend` (saat deploy) dipakai kalau ada; selain itu root repo (pengembangan).
    'frontend_path' => env('OPTIMA_FRONTEND_PATH', is_file(resource_path('frontend/index.html')) ? resource_path('frontend') : base_path('..')),

    /*
    | Email ringkasan follow-up setiap pagi (SRS 4.2, 8.2). Selama tahap awal email hanya dikirim ke alamat uji:
    | isi OPTIMA_MAIL_ALLOWED_DOMAINS (dipisah koma) supaya alamat lain dilewati. Kosong = semua alamat boleh.
    | Pemberitahuan di aplikasi tetap dibuat walaupun email dilewati atau gagal.
    */
    'digest_time' => env('OPTIMA_DIGEST_TIME', '08:00'),
    'mail_daily_limit' => (int) env('OPTIMA_MAIL_DAILY_LIMIT', 200),
    'mail_allowed_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('OPTIMA_MAIL_ALLOWED_DOMAINS', 'contoh.test'))))),

    /*
    | Backup harian aplikasi (SRS 8.1), lapis kedua di samping backup point-in-time database Laravel Cloud.
    | Simpan di disk yang terpisah dari database (di Cloud: Object Storage, disk s3). Backup lebih lama dari
    | backup_keep_days dihapus otomatis.
    */
    'backup_disk' => env('OPTIMA_BACKUP_DISK', 'local'),
    'backup_time' => env('OPTIMA_BACKUP_TIME', '01:00'),
    'backup_keep_days' => (int) env('OPTIMA_BACKUP_KEEP_DAYS', 30),

];
