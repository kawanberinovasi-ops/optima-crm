<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Login dan aktivasi akun (SRS 4.1, FR-AUT-01..03).
 * Akun harus sudah didaftarkan admin. Akun yang belum terdaftar atau nonaktif ditolak dengan pesan jelas,
 * dan percobaannya tercatat di audit log.
 */
class AuthController extends Controller
{
    public function __construct(private Audit $audit) {}

    /** Arahkan ke halaman login Google Workspace. */
    public function googleRedirect(): RedirectResponse
    {
        if (! config('services.google.client_id')) {
            return redirect('/#/login?err=sso_not_configured');
        }

        $params = ['prompt' => 'select_account'];
        if ($domain = config('services.google.hosted_domain')) {
            $params['hd'] = $domain;
        }

        return Socialite::driver('google')->with($params)->redirect();
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            report($e);
            $this->audit->log('Login ditolak', 'Sesi', null, 'SSO Google gagal: '.class_basename($e));

            return redirect('/#/login?err=sso_failed');
        }

        $email = strtolower((string) $google->getEmail());
        $raw = $google->getRaw();
        if (($raw['email_verified'] ?? true) === false) {
            $this->audit->log('Login ditolak', 'Sesi', null, 'Email Google belum terverifikasi: '.$email);

            return redirect('/#/login?err=not_verified');
        }
        $domain = config('services.google.hosted_domain');
        if ($domain && ($raw['hd'] ?? null) !== $domain) {
            $this->audit->log('Login ditolak', 'Sesi', null, 'Bukan akun Workspace '.$domain.': '.$email);

            return redirect('/#/login?err=wrong_domain');
        }

        $error = $this->startSession($request, $email, 'SSO Google', $google->getId());

        return redirect($error ? '/#/login?err='.$error : '/#/mywork');
    }

    /** Login akun uji tanpa SSO. Hanya ada di APP_ENV=local (config optima.dev_login). */
    public function devLogin(Request $request): JsonResponse
    {
        abort_unless(config('optima.dev_login'), 404);
        $data = $request->validate(['email' => ['required', 'email']]);

        $error = $this->startSession($request, strtolower($data['email']), 'akun uji lokal');
        if ($error) {
            return response()->json(['message' => self::MESSAGES[$error], 'code' => $error], 403);
        }

        return response()->json(['ok' => true]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($user = $request->user()) {
            $this->audit->log('Logout', 'Sesi', null, $user->name.' keluar');
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    /** Menjaga sesi tetap hidup selama pengguna aktif di halaman (sesi berakhir setelah 60 menit tanpa permintaan). */
    public function ping(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    public const MESSAGES = [
        'not_registered' => 'Email ini belum didaftarkan. Hubungi Super Admin di tim kamu.',
        'inactive' => 'Akun kamu sudah dinonaktifkan. Hubungi Super Admin.',
    ];

    /** @return string|null kode galat, atau null kalau berhasil masuk */
    private function startSession(Request $request, string $email, string $via, ?string $ssoSubject = null): ?string
    {
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->audit->log('Login ditolak', 'Sesi', null, 'Email belum terdaftar: '.$email.' (lewat '.$via.')');

            return 'not_registered';
        }
        if (! $user->active) {
            $this->audit->log('Login ditolak', 'Sesi', $user->id, 'Akun nonaktif: '.$email.' (lewat '.$via.')', by: $user);

            return 'inactive';
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('login_at', now()->timestamp);

        $user->forceFill(['last_login_at' => now()] + ($ssoSubject && ! $user->sso_subject ? ['sso_subject' => $ssoSubject] : []))->save();
        $this->audit->log('Login', 'Sesi', $user->id, $user->name.' masuk lewat '.$via, by: $user);

        return null;
    }
}
