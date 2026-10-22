<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Email alternatif milik satu pengguna. Login lewat email ini masuk ke identitas pengguna yang sama. */
#[Fillable(['user_id', 'email'])]
class UserEmail extends Model
{
    public const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
