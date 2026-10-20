<?php

namespace App\Services;

use App\Enums\AccessLevel;
use App\Enums\Category;
use App\Enums\DealStatus;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Satu pintu pemeriksaan hak akses (SRS bagian 3 dan 6.2, kelas "Izin").
 * Semua controller, query daftar, dashboard, export, dan email memakai service ini,
 * jadi aturannya sama di semua cara mengakses data.
 *
 * Aturan dipindah dari prototipe (assets/data.js, fungsi perm sampai canViewAdmin),
 * dengan dua aturan buka-kembali deal yang mengikuti bunyi SRS (lihat canReopenDeal).
 */
class Access
{
    /** @var array<int, array<string, string>> level per entitas, di-cache per pengguna selama satu request */
    private array $levels = [];

    /** Level akses untuk entitas data: lead, client, community, kol, media, interaksi, deal. */
    public function level(User $user, string $entity): AccessLevel
    {
        if (! $user->active) {
            return AccessLevel::N;
        }
        if ($user->isSuperAdmin()) {
            return AccessLevel::M;
        }

        $level = AccessLevel::from($this->raw($user, $entity) ?? 'N');

        // Viewer selalu hanya bisa melihat.
        if ($user->role === Role::Viewer && $level !== AccessLevel::N) {
            return AccessLevel::R;
        }

        return $level;
    }

    public function can(User $user, string $entity, AccessLevel $needed): bool
    {
        return $this->level($user, $entity)->atLeast($needed);
    }

    /** @return list<Category> kategori Client & Partner yang boleh dilihat */
    public function visibleCategories(User $user): array
    {
        return array_values(array_filter(Category::cases(), fn (Category $c) => $this->can($user, $c->entity(), AccessLevel::R)));
    }

    /** Batasi query Client & Partner ke kategori yang boleh dilihat. Dipakai daftar, pencarian, dashboard, dan export. */
    public function scopeClientPartners(Builder $query, User $user): Builder
    {
        $categories = array_map(fn (Category $c) => $c->value, $this->visibleCategories($user));

        return $categories === [] ? $query->whereRaw('false') : $query->whereIn('category', $categories);
    }

    public function canViewRecord(User $user, ClientPartner $record): bool
    {
        return $this->can($user, $record->category->entity(), AccessLevel::R);
    }

    public function canCreateRecord(User $user, Category $category): bool
    {
        return $this->can($user, $category->entity(), AccessLevel::C);
    }

    public function canCreateAnyRecord(User $user): bool
    {
        foreach (Category::cases() as $category) {
            if ($this->canCreateRecord($user, $category)) {
                return true;
            }
        }

        return false;
    }

    /** M bisa mengubah semua data di kategorinya, C hanya data yang PIC-nya dia sendiri. */
    public function canEditRecord(User $user, ClientPartner $record): bool
    {
        $level = $this->level($user, $record->category->entity());

        return $level === AccessLevel::M || ($level === AccessLevel::C && $record->pic_id === $user->id);
    }

    /** Arsip, pulihkan, dan gabung data ganda hanya untuk level M. */
    public function canArchiveRecord(User $user, ClientPartner $record): bool
    {
        return $this->level($user, $record->category->entity()) === AccessLevel::M;
    }

    public function canMovePic(User $user, ClientPartner $record): bool
    {
        return $this->canEditRecord($user, $record)
            && ($this->level($user, $record->category->entity()) === AccessLevel::M || $user->role->isLead());
    }

    public function canEditDeal(User $user, Deal $deal): bool
    {
        $level = $this->level($user, 'deal');

        return $level === AccessLevel::M || ($level === AccessLevel::C && $deal->owner_id === $user->id);
    }

    /**
     * Nilai deal (SRS bagian 3): Super Admin dan PIC deal selalu boleh. Selain itu mengikuti kolom "Nilai deal":
     * Ya boleh, Ya* dan Lihat* hanya Kepala Department dan Koordinator, Tidak tidak boleh.
     */
    public function canSeeDealValue(User $user, ?Deal $deal = null): bool
    {
        if (! $user->active) {
            return false;
        }
        if ($user->isSuperAdmin() || ($deal && $deal->owner_id === $user->id)) {
            return true;
        }

        return match ($this->raw($user, 'nilai') ?? 'Tidak') {
            'Ya' => true,
            'Ya*', 'Lihat*' => $user->role->isLead(),
            default => false,
        };
    }

    /** Rate card KOL/KOC: Ya dan M boleh, R* hanya Kepala Department dan Koordinator. */
    public function canSeeRateCard(User $user): bool
    {
        if (! $user->active) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }

        return match ($this->raw($user, 'rate') ?? 'Tidak') {
            'Ya', 'M' => true,
            'R*' => $user->role->isLead(),
            default => false,
        };
    }

    public function canManageRateCard(User $user): bool
    {
        return $user->active && ($user->isSuperAdmin() || $this->raw($user, 'rate') === 'M');
    }

    /**
     * Membuka kembali deal (SRS 4.3 dan bagian 3).
     * Won: Super Admin, atau Kepala Department dari department PIC deal ("Kepala Department terkait").
     * Lost dan On Hold: PIC deal atau atasannya (Kepala Department atau Koordinator di department yang sama),
     * dan tetap harus boleh mengubah deal.
     */
    public function canReopenDeal(User $user, Deal $deal): bool
    {
        if (! $user->active || $deal->status === DealStatus::Open) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }

        $sameDept = $deal->owner()->value('department_id') === $user->department_id;

        if ($deal->status === DealStatus::Won) {
            return $user->role === Role::KepalaDepartment && $sameDept;
        }

        return $this->canEditDeal($user, $deal)
            && ($deal->owner_id === $user->id || ($user->role->isLead() && $sameDept));
    }

    /** Mengatur pipeline dan stage tanpa developer (SRS 4.3). */
    public function canConfigurePipeline(User $user): bool
    {
        return $user->active && in_array($user->role, [Role::SuperAdmin, Role::KepalaDepartment], true);
    }

    /** Hanya Super Admin yang mengelola pengguna, department, role, dan hak akses. */
    public function canAdminister(User $user): bool
    {
        return $user->active && $user->isSuperAdmin();
    }

    /** Melihat halaman Akses & Audit (tanpa mengubah). */
    public function canViewAdmin(User $user): bool
    {
        return $user->active && in_array($user->role, [Role::SuperAdmin, Role::KepalaDepartment], true);
    }

    /**
     * "saringField" di class diagram: buang field yang tidak boleh dikirim ke pengguna ini.
     * Nilai deal dan rate card tidak pernah dikirim ke pengguna tanpa izin, jadi juga tidak muncul di export.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function filterDeal(User $user, Deal $deal, array $data): array
    {
        if (! $this->canSeeDealValue($user, $deal)) {
            unset($data['offer_value'], $data['deal_value']);
            $data['value_hidden'] = true;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function filterClientPartner(User $user, array $data): array
    {
        if (! $this->canSeeRateCard($user)) {
            unset($data['rate_card']);
        }

        return $data;
    }

    /** Nilai mentah: pengecualian per orang dulu, lalu matriks department. */
    private function raw(User $user, string $entity): ?string
    {
        return $this->levelsFor($user)[$entity] ?? null;
    }

    /** @return array<string, string> */
    private function levelsFor(User $user): array
    {
        return $this->levels[$user->id] ??= array_merge(
            AccessGrant::where('department_id', $user->department_id)->pluck('level', 'entity')->all(),
            $user->accessOverrides()->pluck('level', 'entity')->all(),
        );
    }

    /** Dipanggil setelah hak akses atau department pengguna berubah di tengah request. */
    public function forget(?User $user = null): void
    {
        if ($user) {
            unset($this->levels[$user->id]);
        } else {
            $this->levels = [];
        }
    }
}
