<?php

namespace App\Services;

use App\Enums\AccessLevel;
use App\Enums\Category;
use App\Enums\DealStatus;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Interaction;
use App\Models\Pipeline;
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
        // Department lintas divisi (Executive) mengelola semua data; Viewer-nya tetap hanya melihat.
        if ($user->department?->sees_all) {
            return $user->role === Role::Viewer ? AccessLevel::R : AccessLevel::M;
        }

        $level = AccessLevel::from($this->raw($user, $entity) ?? 'N');

        // Viewer selalu hanya bisa melihat, termasuk lewat pengecualian per orang.
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

    /** Super Admin dan department dengan sees_all (Executive) melihat Lead dan Client semua divisi. */
    public function seesAllDivisions(User $user): bool
    {
        return $user->active && ($user->isSuperAdmin() || (bool) $user->department?->sees_all);
    }

    /**
     * Batasi query Client & Partner ke status yang boleh dilihat dan ke divisi pemilik pengguna.
     * Dipakai daftar, pencarian, interaksi, deal, dashboard, export, dan email ringkasan.
     */
    public function scopeClientPartners(Builder $query, User $user): Builder
    {
        $categories = array_map(fn (Category $c) => $c->value, $this->visibleCategories($user));
        if ($categories === []) {
            return $query->whereRaw('false');
        }
        $query->whereIn('category', $categories);

        return $this->seesAllDivisions($user) ? $query : $query->where('department_id', $user->department_id);
    }

    /** Boleh membuka data: level R untuk statusnya, dan data milik divisinya (kecuali yang melihat lintas divisi). */
    public function canViewRecord(User $user, ClientPartner $record): bool
    {
        return $this->can($user, $record->category->entity(), AccessLevel::R)
            && ($this->seesAllDivisions($user) || $record->department_id === $user->department_id);
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

    /** M bisa mengubah semua data yang boleh dilihatnya; C data yang PIC-nya dia sendiri, atau semua data divisinya untuk Kepala Department/Koordinator. */
    public function canEditRecord(User $user, ClientPartner $record): bool
    {
        if (! $this->canViewRecord($user, $record)) {
            return false;
        }
        $level = $this->level($user, $record->category->entity());

        return $level === AccessLevel::M || ($level === AccessLevel::C && ($record->pic_id === $user->id || $user->role->isLead()));
    }

    /** Arsip, pulihkan, dan gabung data ganda hanya untuk level M, pada data yang boleh dilihat. */
    public function canArchiveRecord(User $user, ClientPartner $record): bool
    {
        return $this->canViewRecord($user, $record) && $this->level($user, $record->category->entity()) === AccessLevel::M;
    }

    /** Pindah PIC atau divisi, ke arah mana pun (termasuk ke dan dari Customer Service): siapa pun yang boleh mengubah data itu. */
    public function canHandover(User $user, ClientPartner $record): bool
    {
        return $this->canEditRecord($user, $record);
    }

    /** Deal terlihat kalau boleh melihat deal dan relasinya (deal ikut divisi pemilik relasi). */
    public function canViewDeal(User $user, Deal $deal): bool
    {
        return $this->can($user, 'deal', AccessLevel::R)
            && $deal->clientPartner !== null
            && $this->canViewRecord($user, $deal->clientPartner);
    }

    public function canEditDeal(User $user, Deal $deal): bool
    {
        if (! $this->canViewDeal($user, $deal)) {
            return false;
        }
        $level = $this->level($user, 'deal');

        return $level === AccessLevel::M || ($level === AccessLevel::C && ($deal->owner_id === $user->id || $user->role->isLead()));
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
        if ($deal && ! $this->canViewDeal($user, $deal)) {
            return false;
        }
        if ($this->seesAllDivisions($user) || ($deal && $deal->owner_id === $user->id)) {
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
        if ($this->seesAllDivisions($user)) {
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
        return $user->active && ($user->isSuperAdmin() || ($this->seesAllDivisions($user) && $user->role !== Role::Viewer) || $this->raw($user, 'rate') === 'M');
    }

    /**
     * Membuka kembali deal, dan tetap harus boleh mengubah deal itu.
     * Won: Super Admin atau Kepala Department. Lost dan On Hold: PIC deal, Kepala Department/Koordinator, atau Super Admin.
     */
    public function canReopenDeal(User $user, Deal $deal): bool
    {
        if ($deal->status === DealStatus::Open || ! $this->canEditDeal($user, $deal)) {
            return false;
        }
        if ($deal->status === DealStatus::Won) {
            return $user->isSuperAdmin() || $user->role === Role::KepalaDepartment;
        }

        return $user->isSuperAdmin() || $deal->owner_id === $user->id || $user->role->isLead();
    }

    /** Interaksi terlihat kalau boleh melihat interaksi dan data Client & Partner-nya. */
    public function canViewInteraction(User $user, Interaction $interaction): bool
    {
        return $this->can($user, 'interaksi', AccessLevel::R)
            && $interaction->clientPartner !== null
            && $this->canViewRecord($user, $interaction->clientPartner);
    }

    /** Membatalkan catatan interaksi: pencatatnya sendiri, atau pengelola interaksi (level M). */
    public function canCancelInteraction(User $user, Interaction $interaction): bool
    {
        return $interaction->cancelled_at === null
            && ($interaction->user_id === $user->id || $this->level($user, 'interaksi') === AccessLevel::M)
            && $this->can($user, 'interaksi', AccessLevel::C);
    }

    /** Pipeline yang terlihat: pipeline divisinya sendiri, atau semua untuk yang melihat lintas divisi. */
    public function scopePipelines(Builder $query, User $user): Builder
    {
        return $this->seesAllDivisions($user) ? $query : $query->where('department_id', $user->department_id);
    }

    public function canViewPipeline(User $user, Pipeline $pipeline): bool
    {
        return $user->active && ($this->seesAllDivisions($user) || $pipeline->department_id === $user->department_id);
    }

    /** Mengatur pipeline dan stage tanpa developer (SRS 4.3): Super Admin atau Kepala Department, untuk pipeline divisinya. */
    public function canConfigurePipeline(User $user, ?Pipeline $pipeline = null): bool
    {
        return $user->active && in_array($user->role, [Role::SuperAdmin, Role::KepalaDepartment], true)
            && ($pipeline === null || $this->canViewPipeline($user, $pipeline));
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
