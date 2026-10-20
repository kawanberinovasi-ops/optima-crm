<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use App\Support\FrontShape;
use Illuminate\Support\Collection;

/**
 * Riwayat perubahan per kolom (FR-AUD-02), dibaca dari isi sebelum dan sesudah di audit log.
 * Bentuknya sama dengan Store.db.changes di prototipe: {id, at, by, kind, refId, field, before, after, note}.
 */
class ChangeHistory
{
    public const KINDS = ['Client & Partner' => 'record', 'Deal' => 'deal'];

    private const DEAL_LABELS = [
        'title' => 'Judul', 'service' => 'Layanan', 'client_partner_id' => 'Client & Partner', 'owner_id' => 'PIC deal',
        'target_closing' => 'Target closing', 'offer_value' => 'Nilai penawaran', 'stage' => 'Stage', 'status' => 'Status',
    ];

    private const CONTACT_LABELS = [
        'contact.name' => 'Nama kontak', 'contact.title' => 'Jabatan kontak', 'contact.email' => 'Email kontak',
        'contact.phone' => 'Telepon kontak', 'contact.primary' => 'Kontak utama', 'contact' => 'Kontak',
    ];

    public function __construct(private Access $access) {}

    /** @param  Collection<int, AuditLog>  $logs */
    public function rows(Collection $logs, User $viewer): array
    {
        $users = User::pluck('name', 'id');
        $depts = Department::pluck('name', 'id');
        $seeRate = $this->access->canSeeRateCard($viewer);
        $seeValue = $this->access->canSeeDealValue($viewer);

        $show = function (string $field, $value) use ($users, $depts, $seeRate, $seeValue) {
            return match (true) {
                $value === null || $value === '' => '',
                in_array($field, ['pic_id', 'owner_id'], true) => $users[$value] ?? '',
                $field === 'department_id' => $depts[$value] ?? '',
                $field === 'archived_at' => 'Diarsipkan',
                $field === 'rate_card' => $seeRate ? 'Rp '.number_format((float) ((is_array($value) ? $value : json_decode((string) $value, true))['rate'] ?? 0), 0, ',', '.') : 'Rp ···',
                $field === 'offer_value' => $seeValue ? 'Rp '.number_format((float) $value, 0, ',', '.') : 'Rp ···',
                default => is_scalar($value) ? (string) $value : json_encode($value),
            };
        };

        $rows = [];
        foreach ($logs as $log) {
            $kind = self::KINDS[$log->entity_type] ?? null;
            if (! $kind) {
                continue;
            }
            $labels = $kind === 'record' ? FrontShape::RECORD_LABELS + self::CONTACT_LABELS : self::DEAL_LABELS;
            $base = ['at' => $log->created_at->toIso8601String(), 'by' => (string) $log->user_id, 'kind' => $kind, 'refId' => (string) $log->entity_id];
            $fields = array_intersect_key($log->after ?? [], $labels);

            if ($log->action === 'Tambah' || $fields === []) {
                $rows[] = ['id' => (string) $log->id, 'field' => $log->action === 'Tambah' ? 'Data dibuat' : $log->action, 'before' => '', 'after' => (string) $log->summary, 'note' => ''] + $base;

                continue;
            }
            foreach ($fields as $field => $after) {
                $before = ($log->before ?? [])[$field] ?? null;
                $rows[] = [
                    'id' => $log->id.'-'.$field, 'field' => $labels[$field], 'note' => (string) $log->summary,
                    'before' => $field === 'archived_at' ? ($before ? 'Diarsipkan' : 'Aktif') : $show($field, $before),
                    'after' => $field === 'archived_at' ? ($after ? 'Diarsipkan' : 'Aktif') : $show($field, $after),
                ] + $base;
            }
        }

        return $rows;
    }

    /** Riwayat satu data. */
    public function forEntity(string $entityType, int $id, User $viewer): array
    {
        return $this->rows(AuditLog::where('entity_type', $entityType)->where('entity_id', $id)->orderByDesc('id')->get(), $viewer);
    }

    /** Riwayat terbaru semua Client & Partner dan deal, untuk tab Riwayat data di Akses & Audit. */
    public function latest(User $viewer, int $limit = 300): array
    {
        return array_slice($this->rows(
            AuditLog::whereIn('entity_type', array_keys(self::KINDS))->whereNotNull('entity_id')->orderByDesc('id')->limit($limit)->get(), $viewer
        ), 0, $limit);
    }
}
