<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Catatan interaksi dan follow-up (FR-INT). Tidak bisa dihapus, hanya dibatalkan dengan alasan. */
#[Fillable(['code', 'client_partner_id', 'deal_id', 'contact_id', 'user_id', 'occurred_on', 'type', 'result', 'next_action', 'followup_on', 'followup_done_at', 'followup_done_by', 'cancelled_at', 'cancelled_by', 'cancel_reason'])]
class Interaction extends Model
{
    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'followup_on' => 'date',
            'followup_done_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function clientPartner(): BelongsTo
    {
        return $this->belongsTo(ClientPartner::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeLive(Builder $query): void
    {
        $query->whereNull('cancelled_at');
    }

    /** Follow-up yang belum selesai dan belum dibatalkan. */
    public function scopeOpenFollowups(Builder $query): void
    {
        $query->whereNotNull('followup_on')->whereNull('followup_done_at')->whereNull('cancelled_at');
    }
}
