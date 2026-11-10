<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Permintaan yang benar-benar dari laptop pengembang: host dan IP klien sama-sama loopback.
 * Lewat tunnel/proxy (Cloudflare, load balancer) IP klien terbaca dari X-Forwarded-For, jadi tidak lolos.
 * Dipakai untuk mengunci fitur pengembangan (login uji, halaman debug) walaupun APP_ENV=local.
 */
class LocalRequest
{
    private const LOOPBACK = ['127.0.0.1', '::1', 'localhost'];

    public static function is(Request $request): bool
    {
        return in_array($request->getHost(), self::LOOPBACK, true)
            && in_array((string) $request->ip(), self::LOOPBACK, true)
            && ! $request->headers->has('cf-connecting-ip');
    }

    public static function devLoginAllowed(Request $request): bool
    {
        return (bool) config('optima.dev_login') && self::is($request);
    }
}
