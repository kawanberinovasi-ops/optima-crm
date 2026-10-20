<?php

namespace App\Support;

use App\Models\ClientPartner;
use App\Models\Contact;
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
            'canEdit' => $access->canEditRecord($viewer, $r),
            'canArchive' => $access->canArchiveRecord($viewer, $r),
        ];
    }
}
