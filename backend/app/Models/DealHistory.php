<?php

namespace App\Models;

use App\Enums\DealStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat perpindahan stage dan status deal, dengan waktu, pelaku, dan alasan (FR-DEAL-02). */
#[Fillable(['deal_id', 'user_id', 'from_stage_id', 'to_stage_id', 'from_stage_name', 'to_stage_name', 'status', 'note', 'created_at'])]
class DealHistory extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['status' => DealStatus::class];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
