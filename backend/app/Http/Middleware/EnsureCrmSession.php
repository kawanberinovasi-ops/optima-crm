<?php

namespace App\Http\Middleware;

use App\Services\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aturan sesi SRS 4.1 dan bagian 3:
 * - harus login (sesi tidak aktif 60 menit sudah dibuang oleh SESSION_LIFETIME),
 * - berakhir 12 jam sejak login walaupun masih aktif,
 * - pengguna yang dinonaktifkan langsung keluar.
 */
class EnsureCrmSession
{
    public function __construct(private Audit $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $this->deny('Silakan masuk dulu.', 'unauthenticated');
        }

        if (! $user->active) {
            $this->end($request);

            return $this->deny('Akun kamu sudah dinonaktifkan. Hubungi Super Admin.', 'account_inactive');
        }

        $loginAt = (int) $request->session()->get('login_at', 0);
        if ($loginAt === 0 || now()->timestamp - $loginAt > config('optima.session_absolute_hours') * 3600) {
            $this->audit->log('Logout', 'Sesi', null, $user->name.': sesi berakhir, sudah '.config('optima.session_absolute_hours').' jam sejak login', by: $user);
            $this->end($request);

            return $this->deny('Sesi berakhir karena sudah '.config('optima.session_absolute_hours').' jam sejak login.', 'session_expired');
        }

        return $next($request);
    }

    private function end(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function deny(string $message, string $code): Response
    {
        return response()->json(['message' => $message, 'code' => $code], 401);
    }
}
