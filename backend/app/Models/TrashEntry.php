<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris di Data terhapus: jenis data, label, divisinya (untuk hak memulihkan), dan salinan yang dibutuhkan untuk memulihkan. */
#[Fillable(['kind', 'label', 'department_id', 'deleted_by', 'snapshot', 'created_at'])]
class TrashEntry extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        // Snapshot bisa berisi data pribadi (kontak, akun): disimpan terenkripsi.
        return ['snapshot' => 'encrypted:array', 'created_at' => 'datetime'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
