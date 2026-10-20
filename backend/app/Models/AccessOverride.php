<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pengecualian hak akses untuk satu pengguna (FR-USR-05). Menimpa level dari department-nya. */
#[Fillable(['user_id', 'entity', 'level', 'reason', 'granted_by'])]
class AccessOverride extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
