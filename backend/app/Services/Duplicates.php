<?php

namespace App\Services;

use App\Models\ClientPartner;
use App\Models\Contact;
use App\Models\User;
use App\Support\ContactIndex;
use Illuminate\Support\Collection;

/**
 * Pemeriksaan data ganda (SRS 4.2, FR-REL-03). Aturannya sama dengan prototipe (findDupes di assets/features.js):
 * nama mirip, email kontak sama, atau 8 digit terakhir telepon sama.
 * Pemeriksaan mencakup semua data, tetapi detail hanya dikirim untuk data yang boleh dilihat pengguna.
 */
class Duplicates
{
    public function __construct(private Access $access) {}

    /**
     * @return array{matches: list<array{id: string, why: list<string>}>, hidden: int, hiddenWhy: list<list<string>>}
     */
    public function check(User $user, ?string $name, ?string $email = null, ?string $phone = null, ?int $excludeId = null): array
    {
        $n = self::normName($name);
        $e = strtolower(trim((string) $email));
        $p = self::digits8($phone);

        $candidates = $this->candidates($name, $e, $p, $excludeId);

        $matches = [];
        $hiddenWhy = [];
        foreach ($candidates as $r) {
            $why = [];
            $rn = self::normName($r->name);
            if ($n !== '' && $rn !== '' && ($rn === $n || self::dice($n, $rn) >= 0.8
                || (strlen($n) > 5 && strlen($rn) > 5 && (str_contains($rn, $n) || str_contains($n, $rn))))) {
                $why[] = 'nama mirip';
            }
            if ($e !== '' && $r->contacts->contains(fn (Contact $c) => strtolower((string) $c->email) === $e)) {
                $why[] = 'email sama';
            }
            if (strlen($p) >= 7 && $r->contacts->contains(fn (Contact $c) => self::digits8($c->phone) === $p)) {
                $why[] = 'telepon sama';
            }
            if ($why === []) {
                continue;
            }
            if ($this->access->canViewRecord($user, $r)) {
                $matches[] = ['id' => (string) $r->id, 'why' => $why];
            } else {
                $hiddenWhy[] = $why;
            }
        }

        return ['matches' => $matches, 'hidden' => count($hiddenWhy), 'hiddenWhy' => $hiddenWhy];
    }

    /** Kandidat dari database: trigram nama, email, atau telepon. Aturan pastinya diperiksa di PHP. */
    private function candidates(?string $name, string $email, string $phone, ?int $excludeId): Collection
    {
        $ids = ClientPartner::query()
            ->whereNull('merged_into_id')
            ->when($excludeId, fn ($q) => $q->where('id', '<>', $excludeId))
            ->where(function ($q) use ($name, $email, $phone) {
                $q->whereRaw('false');
                if (trim((string) $name) !== '') {
                    $q->orWhereRaw('similarity(lower(name), lower(?)) > 0.25', [$name])
                        ->orWhere('name', 'ilike', '%'.addcslashes(trim($name), '%_\\').'%');
                }
                // Email dan telepon kontak terenkripsi: dicari lewat indeks HMAC (lihat ContactIndex).
                if ($email !== '') {
                    $q->orWhereHas('contacts', fn ($c) => $c->where('email_hash', ContactIndex::email($email)));
                }
                if (strlen($phone) >= 7) {
                    $q->orWhereHas('contacts', fn ($c) => $c->where('phone_hash', ContactIndex::phone($phone)));
                }
            })
            ->limit(200)
            ->pluck('id');

        return ClientPartner::with('contacts')->whereIn('id', $ids)->get();
    }

    public static function normName(?string $s): string
    {
        $s = strtolower((string) $s);
        $s = preg_replace('/\b(pt|cv|tbk|ud|yayasan)\b\.?/', '', $s);

        return preg_replace('/[^a-z0-9]/', '', $s);
    }

    public static function digits8(?string $s): string
    {
        return ContactIndex::digits8($s);
    }

    /** Koefisien Dice atas bigram, sama dengan fungsi dice di prototipe. */
    public static function dice(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $bigrams = fn (string $s) => array_map(fn ($i) => substr($s, $i, 2), range(0, max(0, strlen($s) - 2)));
        $x = strlen($a) > 1 ? $bigrams($a) : [];
        $y = strlen($b) > 1 ? $bigrams($b) : [];
        $total = count($x) + count($y);
        $match = 0;
        foreach ($x as $g) {
            $k = array_search($g, $y, true);
            if ($k !== false) {
                $match++;
                unset($y[$k]);
            }
        }

        return (2 * $match) / ($total ?: 1);
    }
}
