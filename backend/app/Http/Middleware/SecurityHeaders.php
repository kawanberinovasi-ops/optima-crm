<?php

namespace App\Http\Middleware;

use App\Support\LocalRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lapisan keamanan untuk semua respons (SRS 8.1):
 * - debug Laravel tidak pernah tampil ke luar laptop pengembang, walaupun APP_DEBUG=true;
 * - cookie sesi otomatis "Secure" kalau diakses lewat HTTPS (kecuali SESSION_SECURE_COOKIE diisi);
 * - header browser: CSP ketat (skrip hanya dari domain sendiri + nonce), anti-framing, nosniff, HSTS lewat HTTPS.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! LocalRequest::is($request)) {
            config(['app.debug' => false]);
        }
        if (env('SESSION_SECURE_COOKIE') === null) {
            config(['session.secure' => $request->isSecure()]);
        }

        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Content-Security-Policy', self::csp((string) $request->attributes->get('csp_nonce')));
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    public static function csp(string $nonce = ''): string
    {
        $script = "'self'".($nonce !== '' ? " 'nonce-{$nonce}'" : '');

        return implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            // Atribut style inline dipakai luas di tampilan; skrip tetap dikunci.
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: blob:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
