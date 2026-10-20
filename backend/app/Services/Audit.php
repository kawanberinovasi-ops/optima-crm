<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Satu pencatat audit untuk semua perubahan penting (SRS 6.2, kelas "Audit"; FR-AUD-01, 02).
 * Mencatat siapa, melakukan apa, pada data apa, kapan, serta isi sebelum dan sesudahnya.
 */
class Audit
{
    /** Field yang tidak pernah disalin ke audit log. */
    private const SKIP = ['updated_at', 'remember_token', 'password', 'sso_subject'];

    public function __construct(private Request $request) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(string $action, string $entityType, ?int $entityId = null, ?string $summary = null, ?array $before = null, ?array $after = null, ?User $by = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => ($by ?? $this->request->user())?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'summary' => $summary,
            'before' => $before ? $this->clean($before) : null,
            'after' => $after ? $this->clean($after) : null,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);
    }

    /** Catat perubahan model: hanya field yang benar-benar berubah, sebelum dan sesudah. Tidak mencatat bila tidak ada perubahan. */
    public function changed(string $entityType, Model $model, array $original, ?string $summary = null): ?AuditLog
    {
        $after = $this->clean($model->getChanges());
        if ($after === []) {
            return null;
        }
        $before = array_intersect_key($original, $after);

        return $this->log('Ubah', $entityType, $model->getKey(), $summary, $before, $after);
    }

    /** @param  array<string, mixed>  $data */
    private function clean(array $data): array
    {
        return array_diff_key($data, array_flip(self::SKIP));
    }
}
