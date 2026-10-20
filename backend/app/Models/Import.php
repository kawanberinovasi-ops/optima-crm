<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu proses import file (FR-IMP). Status: checked (sudah diperiksa, belum disimpan), committed, undone. */
#[Fillable(['user_id', 'filename', 'status', 'total_rows', 'ok_rows', 'error_rows', 'skipped_example', 'committed_at', 'undone_at', 'undone_by'])]
class Import extends Model
{
    protected function casts(): array
    {
        return ['committed_at' => 'datetime', 'undone_at' => 'datetime', 'skipped_example' => 'boolean'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class)->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
