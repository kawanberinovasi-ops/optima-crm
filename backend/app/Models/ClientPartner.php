<?php

namespace App\Models;

use App\Enums\Category;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Relasi Client & Partner. category = status relasi (Lead/Client), service_id = layanan (KOL, Community, Media, ...).
 * created_by = pembuat data (tidak pernah berubah), pic_id = PIC aktif, department_id = divisi pemilik. Tidak pernah dihapus permanen:
 * "Hapus" mengisi deleted_at (Data terhapus) dan bisa dipulihkan.
 */
#[Fillable(['code', 'name', 'category', 'service_id', 'partner_type', 'segment', 'status', 'pic_id', 'department_id', 'source', 'city', 'note', 'rate_card', 'client_info', 'converted_at', 'archived_at', 'archived_by', 'archive_reason', 'merged_into_id', 'created_by'])]
class ClientPartner extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'category' => Category::class,
            'rate_card' => 'array',
            'client_info' => 'array',
            'converted_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function handovers(): HasMany
    {
        return $this->hasMany(Handover::class)->orderBy('id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
