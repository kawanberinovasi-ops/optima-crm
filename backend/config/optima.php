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
    'frontend_path' => env('OPTIMA_FRONTEND_PATH', base_path('..')),

];
