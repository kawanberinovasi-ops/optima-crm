<?php

namespace App\Providers;

use App\Services\Access;
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
        //
    }
}
