<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stage di satu pipeline. Tiap pipeline punya tepat satu stage Closing di urutan terakhir. */
#[Fillable(['pipeline_id', 'name', 'position', 'is_closing'])]
class Stage extends Model
{
    protected function casts(): array
    {
        return ['is_closing' => 'boolean'];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }
}
