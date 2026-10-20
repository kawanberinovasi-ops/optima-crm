<?php

namespace App\Services;

use App\Enums\DealStatus;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\User;
use App\Support\Codes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aturan deal (SRS 4.3), dipakai halaman Pipeline dan form Catat interaksi:
 * Closing berarti Won (nilai dan tanggal closing wajib), Lost wajib alasan, On Hold wajib alasan dan tanggal tinjau,
 * stage terakhir tetap tersimpan, dan setiap perpindahan dicatat di riwayat dan audit log.
 */
class Deals
{
    public const ENTITY = 'Deal';

    public function __construct(private Audit $audit) {}

    public function create(User $by, ClientPartner $record, Pipeline $pipeline, ?Stage $stage, array $attrs, string $note = 'Deal dibuat'): Deal
    {
        $stage ??= $pipeline->stages()->where('is_closing', false)->orderBy('position')->first();
        $this->assertOpenStage($pipeline, $stage, 'stageId');

        return DB::transaction(function () use ($by, $record, $pipeline, $stage, $attrs, $note) {
            [$id, $code] = Codes::next('deals', 'DL');
            $deal = new Deal;
            $deal->forceFill($attrs + [
                'id' => $id, 'code' => $code, 'client_partner_id' => $record->id, 'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id, 'status' => DealStatus::Open, 'owner_id' => $by->id, 'created_by' => $by->id,
            ])->save();
            $this->history($deal, $by, null, $stage, DealStatus::Open, $note);
            $this->audit->log('Tambah', self::ENTITY, $deal->id, $deal->title.' di '.$pipeline->name.', mulai di '.$stage->name, null, $deal->getAttributes(), $by);

            return $deal;
        });
    }

    /** Pindah ke stage lain yang bukan Closing. Deal On Hold yang dipindah otomatis aktif lagi. */
    public function move(User $by, Deal $deal, Stage $to, string $note = ''): Deal
    {
        if (! in_array($deal->status, [DealStatus::Open, DealStatus::OnHold], true)) {
            throw ValidationException::withMessages(['stageId' => 'Deal '.$deal->status->value.' harus dibuka kembali dulu.']);
        }
        $this->assertOpenStage($deal->pipeline, $to, 'stageId');
        if ($to->id === $deal->stage_id && $deal->status === DealStatus::Open) {
            return $deal;
        }

        return DB::transaction(function () use ($by, $deal, $to, $note) {
            $from = $deal->stage;
            $wasHold = $deal->status === DealStatus::OnHold;
            $deal->update(['stage_id' => $to->id] + ($wasHold ? ['status' => DealStatus::Open, 'hold_reason' => null, 'review_date' => null] : []));
            $this->history($deal, $by, $from, $to, DealStatus::Open, $note ?: ($wasHold ? 'Dibuka lagi' : ''));
            $this->audit->log('Ubah', self::ENTITY, $deal->id, $deal->title.' pindah dari '.$from->name.' ke '.$to->name.($note ? ' ('.$note.')' : ''),
                ['stage' => $from->name, 'status' => $wasHold ? 'On Hold' : 'Open'], ['stage' => $to->name, 'status' => 'Open'], $by);

            return $deal->fresh();
        });
    }

    public function won(User $by, Deal $deal, float $value, Carbon $closedAt): Deal
    {
        $this->assertStatus($deal, [DealStatus::Open, DealStatus::OnHold], 'value');

        return DB::transaction(function () use ($by, $deal, $value, $closedAt) {
            $from = $deal->stage;
            $closing = $deal->pipeline->closingStage;
            $deal->update(['stage_id' => $closing->id, 'status' => DealStatus::Won, 'deal_value' => $value, 'closed_at' => $closedAt,
                'hold_reason' => null, 'review_date' => null, 'lost_reason' => null, 'lost_note' => null]);
            $this->history($deal, $by, $from, $closing, DealStatus::Won, 'Closing');
            $this->audit->log('Ubah', self::ENTITY, $deal->id, $deal->title.' dari '.$from->name.' ke Closing, Won',
                ['stage' => $from->name, 'status' => 'Open'], ['stage' => $closing->name, 'status' => 'Won', 'closed_at' => $closedAt->toDateString()], $by);

            return $deal->fresh();
        });
    }

    public function lost(User $by, Deal $deal, string $reason, ?string $note): Deal
    {
        $this->assertStatus($deal, [DealStatus::Open, DealStatus::OnHold], 'lostReason');

        return DB::transaction(function () use ($by, $deal, $reason, $note) {
            $deal->update(['status' => DealStatus::Lost, 'lost_reason' => $reason, 'lost_note' => $note, 'closed_at' => today(),
                'hold_reason' => null, 'review_date' => null]);
            $this->history($deal, $by, $deal->stage, $deal->stage, DealStatus::Lost, $reason.($note ? ', '.$note : ''));
            $this->audit->log('Ubah', self::ENTITY, $deal->id, $deal->title.' Lost: '.$reason, ['status' => 'Open'], ['status' => 'Lost', 'lost_reason' => $reason], $by);

            return $deal->fresh();
        });
    }

    public function hold(User $by, Deal $deal, string $reason, Carbon $reviewDate): Deal
    {
        $this->assertStatus($deal, [DealStatus::Open], 'reason');

        return DB::transaction(function () use ($by, $deal, $reason, $reviewDate) {
            $deal->update(['status' => DealStatus::OnHold, 'hold_reason' => $reason, 'review_date' => $reviewDate]);
            $this->history($deal, $by, $deal->stage, $deal->stage, DealStatus::OnHold, $reason.', tinjau '.$reviewDate->translatedFormat('j M'));
            $this->audit->log('Ubah', self::ENTITY, $deal->id, $deal->title.' On Hold sampai '.$reviewDate->toDateString(),
                ['status' => 'Open'], ['status' => 'On Hold', 'review_date' => $reviewDate->toDateString()], $by);

            return $deal->fresh();
        });
    }

    /** Buka kembali deal Won, Lost, atau On Hold ke stage yang dipilih. Nilai dan tanggal closing deal Won dikosongkan. */
    public function reopen(User $by, Deal $deal, Stage $to, ?string $reason): Deal
    {
        $this->assertStatus($deal, [DealStatus::Won, DealStatus::Lost, DealStatus::OnHold], 'toStage');
        $this->assertOpenStage($deal->pipeline, $to, 'toStage');

        return DB::transaction(function () use ($by, $deal, $to, $reason) {
            $prev = $deal->status;
            $from = $deal->stage;
            $deal->update(['status' => DealStatus::Open, 'stage_id' => $to->id, 'hold_reason' => null, 'review_date' => null]
                + ($prev === DealStatus::Won ? ['deal_value' => null, 'closed_at' => null] : [])
                + ($prev === DealStatus::Lost ? ['closed_at' => null, 'lost_reason' => null, 'lost_note' => null] : []));
            $note = 'Dibuka kembali dari '.$prev->value.($reason ? ': '.$reason : '');
            $this->history($deal, $by, $from, $to, DealStatus::Open, $note);
            $this->audit->log('Ubah', self::ENTITY, $deal->id, $deal->title.' dibuka kembali dari '.$prev->value.($reason ? ': '.$reason : ''),
                ['status' => $prev->value, 'stage' => $from->name], ['status' => 'Open', 'stage' => $to->name], $by);

            return $deal->fresh();
        });
    }

    private function history(Deal $deal, User $by, ?Stage $from, Stage $to, DealStatus $status, string $note): void
    {
        $deal->histories()->create([
            'user_id' => $by->id, 'from_stage_id' => $from?->id, 'from_stage_name' => $from?->name,
            'to_stage_id' => $to->id, 'to_stage_name' => $to->name, 'status' => $status, 'note' => $note ?: null, 'created_at' => now(),
        ]);
    }

    private function assertOpenStage(Pipeline $pipeline, ?Stage $stage, string $field): void
    {
        if (! $stage || $stage->pipeline_id !== $pipeline->id) {
            throw ValidationException::withMessages([$field => 'Stage tidak ada di pipeline ini.']);
        }
        if ($stage->is_closing) {
            throw ValidationException::withMessages([$field => 'Masuk Closing berarti Won. Pakai tombol Closing supaya nilai dan tanggalnya terisi.']);
        }
    }

    /** @param  list<DealStatus>  $allowed */
    private function assertStatus(Deal $deal, array $allowed, string $field): void
    {
        if (! in_array($deal->status, $allowed, true)) {
            throw ValidationException::withMessages([$field => 'Deal ini berstatus '.$deal->status->value.'.']);
        }
    }
}
