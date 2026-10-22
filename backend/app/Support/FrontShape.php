<?php

namespace App\Support;

use App\Enums\DealStatus;
use App\Models\ClientPartner;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealHistory;
use App\Models\Handover;
use App\Models\Interaction;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\User;
use App\Services\Access;

/**
 * Bentuk data yang dipakai frontend prototipe (assets/*.js), supaya Store.db bisa diisi langsung dari API.
 * Semua id dikirim sebagai string karena frontend membandingkannya dengan nilai atribut data-v.
 */
class FrontShape
{
    /** Nama label field Client & Partner, dipakai juga untuk riwayat perubahan. */
    public const RECORD_LABELS = [
        'name' => 'Nama', 'category' => 'Kategori', 'segment' => 'Segmen', 'status' => 'Status',
        'department_id' => 'Department pemilik', 'source' => 'Sumber', 'city' => 'Kota', 'note' => 'Catatan',
        'rate_card' => 'Rate card', 'pic_id' => 'PIC', 'archived_at' => 'Status data',
    ];

    /** @return array<string, mixed> */
    public static function user(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'dept' => $user->department?->name,
            'role' => $user->role->value,
            'active' => $user->active,
        ];
    }

    /** Pipeline dengan stage berurutan; stage Closing selalu yang terakhir. */
    public static function line(Pipeline $p): array
    {
        return [
            'id' => (string) $p->id,
            'name' => $p->name,
            'stages' => $p->stages->sortBy('position')->values()->map(fn (Stage $s) => ['id' => (string) $s->id, 'name' => $s->name])->all(),
        ];
    }

    /** Deal. Nilai penawaran dan nilai deal dikosongkan untuk pengguna tanpa izin (SRS bagian 3). */
    public static function deal(Deal $d, User $viewer, Access $access): array
    {
        $see = $access->canSeeDealValue($viewer, $d);
        $money = fn ($v) => $see && $v !== null ? (int) round((float) $v) : '';

        return [
            'id' => (string) $d->id,
            'code' => $d->code,
            'title' => $d->title,
            'service' => (string) $d->service,
            'recordId' => (string) $d->client_partner_id,
            'lineId' => (string) $d->pipeline_id,
            'stageId' => (string) $d->stage_id,
            'status' => $d->status->value,
            'offer' => $money($d->offer_value),
            'value' => $money($d->deal_value),
            'valueHidden' => ! $see,
            'ownerId' => (string) $d->owner_id,
            'target' => $d->target_closing?->toDateString() ?? '',
            'closedAt' => $d->closed_at?->toDateString() ?? '',
            'lostReason' => (string) $d->lost_reason,
            'reason' => (string) ($d->status === DealStatus::OnHold ? $d->hold_reason : $d->lost_note),
            'reviewDate' => $d->review_date?->toDateString() ?? '',
            'createdAt' => $d->created_at?->toDateString(),
            'history' => $d->histories->map(fn (DealHistory $h) => [
                'at' => $h->created_at?->toIso8601String(),
                'by' => (string) $h->user_id,
                'from' => (string) $h->from_stage_name,
                'to' => (string) $h->to_stage_name,
                'status' => $h->status->value,
                'note' => (string) $h->note,
            ])->values()->all(),
        ];
    }

    /** Interaksi dan follow-up. */
    public static function interaction(Interaction $i): array
    {
        return [
            'id' => (string) $i->id,
            'code' => $i->code,
            'recordId' => (string) $i->client_partner_id,
            'dealId' => $i->deal_id ? (string) $i->deal_id : '',
            'type' => $i->type,
            'date' => $i->occurred_on?->toDateString(),
            'result' => $i->result,
            'next' => (string) $i->next_action,
            'fuDate' => $i->followup_on?->toDateString() ?? '',
            'done' => $i->followup_done_at !== null,
            'doneAt' => $i->followup_done_at?->toIso8601String() ?? '',
            'cancelled' => $i->cancelled_at !== null,
            'cancelledAt' => $i->cancelled_at?->toIso8601String() ?? '',
            'cancelledBy' => $i->cancelled_by ? (string) $i->cancelled_by : '',
            'cancelReason' => (string) $i->cancel_reason,
            'by' => (string) $i->user_id,
        ];
    }

    /** @return array<string, mixed> */
    public static function contact(Contact $c): array
    {
        return [
            'id' => (string) $c->id,
            'name' => $c->name,
            'title' => (string) $c->title,
            'email' => (string) $c->email,
            'phone' => (string) $c->phone,
            'social' => (string) $c->social,
            'primary' => $c->is_primary,
        ];
    }

    /** @return array<string, mixed> */
    public static function record(ClientPartner $r, User $viewer, Access $access): array
    {
        $contacts = $r->contacts->sortByDesc('is_primary')->values()->map(fn (Contact $c) => self::contact($c))->all();
        $primary = $contacts[0] ?? null;
        $seeRate = $access->canSeeRateCard($viewer);

        return [
            'id' => (string) $r->id,
            'code' => $r->code,
            'name' => $r->name,
            'cat' => $r->category->value,
            'service' => (string) $r->service?->name,
            'createdBy' => $r->created_by ? (string) $r->created_by : '',
            'segment' => (string) $r->segment,
            'status' => (string) $r->status,
            'picId' => (string) $r->pic_id,
            'dept' => $r->department?->name,
            'source' => (string) $r->source,
            'city' => (string) $r->city,
            'note' => (string) $r->note,
            'rate' => $seeRate ? ($r->rate_card['rate'] ?? '') : '',
            'rateHidden' => ! $seeRate && ! empty($r->rate_card),
            'createdAt' => $r->created_at?->toDateString(),
            'updatedAt' => $r->updated_at?->toDateString(),
            'convertedAt' => $r->converted_at?->toDateString() ?? '',
            'archived' => $r->archived_at !== null,
            'archivedAt' => $r->archived_at?->toDateString() ?? '',
            'archiveReason' => (string) $r->archive_reason,
            'archivedBy' => $r->archived_by ? (string) $r->archived_by : '',
            'mergedInto' => $r->merged_into_id ? (string) $r->merged_into_id : '',
            'contacts' => $contacts,
            'contact' => $primary['name'] ?? '',
            'title' => $primary['title'] ?? '',
            'cEmail' => $primary['email'] ?? '',
            'phone' => $primary['phone'] ?? '',
            'handovers' => $r->handovers->map(fn (Handover $h) => [
                'at' => $h->created_at?->toIso8601String(), 'from' => (string) $h->from_user_id, 'fromDept' => (string) $h->fromDepartment?->name,
                'to' => (string) $h->to_user_id, 'toDept' => (string) $h->toDepartment?->name, 'by' => (string) $h->by_user_id,
                'reason' => $h->reason, 'dealsMoved' => $h->deals_moved,
            ])->values()->all(),
            'canEdit' => $access->canEditRecord($viewer, $r),
            'canHandover' => $access->canHandover($viewer, $r),
            'canArchive' => $access->canArchiveRecord($viewer, $r),
        ];
    }
}
