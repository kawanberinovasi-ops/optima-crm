<?php

use App\Http\Middleware\EnsureCrmSession;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // API dilayani dari domain yang sama dengan frontend dan memakai cookie sesi Laravel (grup "web"),
        // jadi tidak ada token di browser dan tidak perlu CORS.
        then: function () {
            Route::middleware('web')->prefix('api')->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['crm' => EnsureCrmSession::class]);
        // Laravel Cloud menaruh aplikasi di belakang load balancer: percayai header X-Forwarded-* supaya HTTPS dan IP pengguna
        // (dicatat di audit log) terbaca benar.
        $middleware->trustProxies(at: '*');
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'Silakan masuk dulu.', 'code' => 'unauthenticated'], 401)
            : null);
    })->create();
