<?php

namespace App\Providers;

use App\Services\Access;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Satu instance per request, supaya cache level hak akses tidak terbawa ke request lain.
        $this->app->scoped(Access::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Di produksi semua URL (redirect SSO, tautan email) memakai HTTPS.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Batas permintaan: mencegah tebak-tebakan login, scraping data, dan beban berlebih dari satu akun atau IP.
        RateLimiter::for('crm-api', fn (Request $r) => Limit::perMinute(240)->by('u'.($r->user()?->id ?? $r->ip())));
        RateLimiter::for('crm-login', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('crm-heavy', fn (Request $r) => Limit::perMinute(10)->by('u'.($r->user()?->id ?? $r->ip())));
    }
}
