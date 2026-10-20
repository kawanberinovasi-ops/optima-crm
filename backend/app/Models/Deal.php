<?php

namespace App\Models;

use App\Enums\DealStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** Deal di pipeline (SRS 4.3). Nilai deal hanya dikirim ke pengguna yang berizin. */
#[Fillable(['code', 'title', 'service', 'client_partner_id', 'pipeline_id', 'stage_id', 'status', 'owner_id', 'offer_value', 'deal_value', 'target_closing', 'closed_at', 'lost_reason', 'hold_reason', 'review_date', 'created_by'])]
class Deal extends Model
{
    protected function casts(): array
    {
        return [
            'status' => DealStatus::class,
            'offer_value' => 'decimal:2',
            'deal_value' => 'decimal:2',
            'target_closing' => 'date',
            'closed_at' => 'date',
            'review_date' => 'date',
        ];
    }

    public function clientPartner(): BelongsTo
    {
        return $this->belongsTo(ClientPartner::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(DealHistory::class)->orderBy('created_at');
    }

    /**
     * Filter periode (SRS 4.3): deal Open dan On Hold dihitung dari tanggal dibuat,
     * deal Won dan Lost dari tanggal closing.
     */
    public function scopeInPeriod(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->where(function (Builder $q) use ($from, $to) {
            $q->where(fn (Builder $r) => $r->whereIn('status', [DealStatus::Open, DealStatus::OnHold])
                ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]))
                ->orWhere(fn (Builder $r) => $r->whereIn('status', [DealStatus::Won, DealStatus::Lost])
                    ->whereBetween('closed_at', [$from->toDateString(), $to->toDateString()]));
        });
    }
}
