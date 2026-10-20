<?php

namespace App\Models;

use App\Enums\Category;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Data lead, client, community, KOL/KOC, dan media (SRS 4.2). Tidak pernah dihapus permanen. */
#[Fillable(['code', 'name', 'category', 'segment', 'status', 'pic_id', 'department_id', 'source', 'city', 'note', 'rate_card', 'client_info', 'converted_at', 'archived_at', 'archived_by', 'archive_reason', 'merged_into_id', 'created_by'])]
class ClientPartner extends Model
{
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
