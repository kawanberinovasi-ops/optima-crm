<?php

namespace App\Console\Commands;

use App\Mail\DailyDigest;
use App\Models\Interaction;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Support\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Ringkasan follow-up setiap pagi pukul 08.00 WIB (SRS 4.2, FR-INT-05).
 * Untuk tiap pengguna aktif: follow-up yang jatuh tempo hari ini atau sudah telat, dari catatannya sendiri atau data yang dia pegang.
 * Pemberitahuan di aplikasi selalu dibuat; email dikirim ke alamat yang diizinkan, paling banyak sesuai batas harian (SRS 8.2).
 */
class SendDailyDigest extends Command
{
    protected $signature = 'optima:daily-digest {--dry-run : Tampilkan ringkasan tanpa membuat pemberitahuan atau mengirim email}';

    protected $description = 'Kirim ringkasan follow-up jatuh tempo dan telat ke setiap pengguna';

    public function handle(Access $access, Audit $audit): int
    {
        $today = today();
        $sentKey = 'digest-mails-'.$today->format('Ymd');
        $limit = config('optima.mail_daily_limit');
        $allowed = config('optima.mail_allowed_domains');
        $stats = ['users' => 0, 'mailed' => 0, 'skipped' => 0, 'failed' => 0];

        foreach (User::where('active', true)->orderBy('id')->get() as $user) {
            $items = Interaction::openFollowups()
                ->whereDate('followup_on', '<=', $today)
                ->whereHas('clientPartner', fn ($q) => $q->whereNull('archived_at'))
                ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereHas('clientPartner', fn ($c) => $c->where('pic_id', $user->id)))
                ->with('clientPartner')->orderBy('followup_on')->get()
                ->filter(fn (Interaction $i) => $access->canViewInteraction($user, $i))
                ->map(fn (Interaction $i) => [
                    'name' => $i->clientPartner->name, 'next' => (string) $i->next_action,
                    'due' => $i->followup_on->translatedFormat('j M'), 'late' => $i->followup_on->lt($today),
                ])->values();
            if ($items->isEmpty()) {
                continue;
            }
            $late = $items->where('late', true)->count();
            $stats['users']++;
            $this->line($user->email.': '.$items->count().' follow-up, '.$late.' telat');
            if ($this->option('dry-run')) {
                continue;
            }

            Notify::send($user, 'Ringkasan hari ini: '.$items->count().' follow-up jatuh tempo'.($late ? ', '.$late.' telat' : ''), 'mywork');

            $domain = strtolower(substr(strrchr($user->email, '@') ?: '', 1));
            if (($allowed && ! in_array($domain, $allowed, true)) || (int) Cache::get($sentKey, 0) >= $limit) {
                $stats['skipped']++;

                continue;
            }
            try {
                Mail::to($user->email)->send(new DailyDigest($user, $items, $late));
                Cache::put($sentKey, (int) Cache::get($sentKey, 0) + 1, now()->endOfDay());
                $stats['mailed']++;
            } catch (Throwable $e) {
                // Email gagal tidak menghentikan ringkasan untuk pengguna lain; pemberitahuan di aplikasi tetap ada.
                report($e);
                $stats['failed']++;
            }
        }

        if (! $this->option('dry-run')) {
            $audit->log('Kirim', 'Email ringkasan', null, 'Ringkasan follow-up: '.$stats['users'].' pengguna, '.$stats['mailed'].' email terkirim, '
                .$stats['skipped'].' dilewati, '.$stats['failed'].' gagal');
        }
        $this->info(json_encode($stats));

        return self::SUCCESS;
    }
}
