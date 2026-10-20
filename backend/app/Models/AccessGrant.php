<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu sel di matriks hak akses: department x entitas -> level (SRS bagian 3). */
#[Fillable(['department_id', 'entity', 'level'])]
class AccessGrant extends Model
{
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
