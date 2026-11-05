<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sees_all'])]
class Department extends Model
{
    protected function casts(): array
    {
        // sees_all: tidak dipakai lagi sejak revisi klien 5 Nov 2026 (hanya Super Admin yang melihat semua data).
        return ['sees_all' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function pipelines(): BelongsToMany
    {
        return $this->belongsToMany(Pipeline::class);
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(AccessGrant::class);
    }
}
