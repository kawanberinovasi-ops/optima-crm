<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Pipeline per jenis pekerjaan (Sales, Campaign, ...) dengan urutan stage sendiri (SRS 4.3). Divisi memakai pipeline lewat
 * department_pipeline; Super Admin bisa menambah atau mencabut pipeline per pengguna lewat pipeline_user.
 */
#[Fillable(['name', 'position', 'archived_at'])]
class Pipeline extends Model
{
    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class);
    }

    /** Pengecualian per pengguna: allowed=true tambahan, allowed=false dicabut. */
    public function userOverrides(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('allowed');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('position');
    }

    public function closingStage(): HasOne
    {
        return $this->hasOne(Stage::class)->where('is_closing', true);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }
}
