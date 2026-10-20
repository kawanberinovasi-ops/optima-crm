<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Siapa, melakukan apa, pada data apa, kapan, serta isi sebelum dan sesudahnya (FR-AUD). Hanya ditambah. */
#[Fillable(['user_id', 'action', 'entity_type', 'entity_id', 'summary', 'before', 'after', 'ip_address', 'user_agent'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
