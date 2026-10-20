<?php

use Illuminate\Support\Facades\Schedule;

// Ringkasan follow-up setiap pagi (SRS 4.2). Di server perlu cron "php artisan schedule:run" setiap menit.
Schedule::command('optima:daily-digest')
    ->dailyAt(config('optima.digest_time'))
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();
