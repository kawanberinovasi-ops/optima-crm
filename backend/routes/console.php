<?php

use Illuminate\Support\Facades\Schedule;

// Ringkasan follow-up setiap pagi (SRS 4.2). Di server perlu cron "php artisan schedule:run" setiap menit.
Schedule::command('optima:daily-digest')
    ->dailyAt(config('optima.digest_time'))
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();

// Backup harian aplikasi (SRS 8.1), lapis kedua di samping backup point-in-time database Laravel Cloud.
Schedule::command('optima:backup')
    ->dailyAt(config('optima.backup_time'))
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();
