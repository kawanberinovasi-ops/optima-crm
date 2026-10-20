<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Satu baris file import: data yang sudah dinormalkan, galat (kalau ditolak), dan data yang dibuat atau diperbarui. */
#[Fillable(['import_id', 'sheet', 'row_no', 'data', 'errors', 'entity_type', 'entity_id', 'action', 'before', 'source_key'])]
class ImportRow extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['data' => 'array', 'errors' => 'array', 'before' => 'array'];
    }
}
