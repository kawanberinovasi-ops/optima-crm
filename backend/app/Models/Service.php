<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Layanan (KOL, Community, Media, Digital Campaign) dan divisi yang menanganinya. Pemetaan bisa diubah admin.
 * Memilih layanan tidak memindahkan PIC atau divisi pemilik; itu lewat serah terima.
 */
#[Fillable(['name', 'department_id', 'position', 'active'])]
class Service extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
