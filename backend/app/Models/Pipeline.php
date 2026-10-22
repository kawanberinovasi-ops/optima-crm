<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Satu pipeline per divisi, dengan urutan stage sendiri (SRS 4.3). department_id kosong untuk pipeline lama yang diarsipkan. */
#[Fillable(['name', 'department_id', 'position', 'archived_at'])]
class Pipeline extends Model
{
    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
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
