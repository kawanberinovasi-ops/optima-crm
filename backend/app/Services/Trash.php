<?php

namespace App\Services;

use App\Enums\DealStatus;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\AccessOverride;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Department;
use App\Models\Pipeline;
use App\Models\Service;
use App\Models\Stage;
use App\Models\TrashEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Data terhapus (mengikuti pratinjau klien 27 Okt 2026). Hapus selalu masuk ke sini dan bisa dipulihkan sesuai hak akses;
 * tidak ada penghapusan permanen otomatis. Hak dan syaratnya diperiksa lagi saat konfirmasi, bukan hanya saat tombol tampil.
 *
 * - Client & Partner: level M atas data itu. Interaksi dan deal-nya ikut tersembunyi.
 * - Lini bisnis dan stage: yang boleh mengatur pipeline itu.
 * - Layanan, department, pengguna, pengecualian: Super Admin.
 */
class Trash
{
    public const KINDS = ['record', 'line', 'stage', 'service', 'dept', 'user', 'override'];

    public const LABELS = ['record' => 'Client & Partner', 'line' => 'Lini bisnis', 'stage' => 'Stage', 'service' => 'Layanan',
        'dept' => 'Department', 'user' => 'Pengguna', 'override' => 'Pengecualian'];

    public function __construct(private Access $access, private Audit $audit) {}

    public function delete(User $by, string $kind, int $id): TrashEntry
    {
        [$item, $allowed] = $this->find($by, $kind, $id);
        abort_unless($allowed, 403, 'Akun ini tidak punya hak untuk tindakan tersebut.');
        if ($block = $this->deleteBlock($by, $kind, $item)) {
            throw new HttpException(422, $block);
        }

        return DB::transaction(function () use ($by, $kind, $item) {
            [$label, $deptId, $snapshot] = match ($kind) {
                'record' => $this->deleteRecord($item),
                'line' => $this->deleteLine($item),
                'stage' => $this->deleteStage($item),
                'service' => $this->deleteRow($item, $item->name, $item->department_id),
                'dept' => $this->deleteDept($item),
                'user' => $this->deleteUser($item),
                'override' => $this->deleteRow($item, ($item->user?->name ?? '-').' · '.$item->entity, $item->user?->department_id),
            };
            $entry = TrashEntry::create(['kind' => $kind, 'label' => $label, 'department_id' => $deptId, 'deleted_by' => $by->id,
                'snapshot' => $snapshot, 'created_at' => now()]);
            $this->audit->log('Hapus', self::LABELS[$kind], $item->id, $label.' dipindahkan ke Data terhapus', null, null, $by);

            return $entry;
        });
    }

    public function restore(User $by, TrashEntry $entry): void
    {
        abort_unless($this->canRestore($by, $entry), 403, 'Akun ini tidak punya hak memulihkan data.');
        if ($block = $this->restoreBlock($entry)) {
            throw new HttpException(422, $block);
        }
        DB::transaction(function () use ($by, $entry) {
            $s = $entry->snapshot;
            match ($entry->kind) {
                'record' => ClientPartner::withTrashed()->find($s['id'])->restore(),
                'user' => DB::table('users')->where('id', $s['id'])->update(['deleted_at' => null, 'updated_at' => now()]),
                'line' => DB::table('pipelines')->where('id', $s['id'])->update(['archived_at' => null, 'updated_at' => now()]),
                'stage' => $this->restoreStage($s),
                'service' => DB::table('services')->insert($s['row']),
                'override' => DB::table('access_overrides')->insert($s['row']),
                'dept' => $this->restoreDept($s),
            };
            $this->audit->log('Pulihkan', self::LABELS[$entry->kind], $s['id'] ?? null, $entry->label.' dipulihkan dari Data terhapus'
                .($entry->kind === 'user' ? ' (dalam keadaan nonaktif)' : ''), null, null, $by);
            $entry->delete();
        });
        $this->access->forget();
    }

    /** Admin memulihkan semuanya; selain itu hanya data yang boleh dihapusnya sendiri. */
    public function canRestore(User $by, TrashEntry $entry): bool
    {
        if (! $by->active) {
            return false;
        }
        if ($this->access->canAdminister($by)) {
            return true;
        }
        $s = $entry->snapshot;

        return match ($entry->kind) {
            'record' => ($r = ClientPartner::withTrashed()->find($s['id'])) !== null && $this->access->canArchiveRecord($by, $r),
            'line' => ($p = Pipeline::find($s['id'])) !== null && $this->access->canConfigurePipeline($by, $p),
            'stage' => ($p = Pipeline::find($s['row']['pipeline_id'])) !== null && $this->access->canConfigurePipeline($by, $p),
            default => false,
        };
    }

    /** @return list<array<string, mixed>> */
    public function listFor(User $by): array
    {
        return TrashEntry::with('department')->orderByDesc('id')->get()->filter(fn (TrashEntry $e) => $this->canRestore($by, $e))
            ->map(fn (TrashEntry $e) => ['id' => (string) $e->id, 'kind' => $e->kind, 'label' => $e->label, 'dept' => $e->department?->name ?? '',
                'by' => (string) $e->deleted_by, 'at' => $e->created_at->toIso8601String()])->values()->all();
    }

    /** @return array{0: mixed, 1: bool} */
    private function find(User $by, string $kind, int $id): array
    {
        $item = match ($kind) {
            'record' => ClientPartner::find($id),
            'line' => Pipeline::whereNull('archived_at')->find($id),
            'stage' => Stage::find($id),
            'service' => Service::find($id),
            'dept' => Department::find($id),
            'user' => User::whereNull('deleted_at')->find($id),
            'override' => AccessOverride::find($id),
            default => null,
        };
        abort_if($item === null, 404, 'Data tidak ditemukan.');
        $allowed = match ($kind) {
            'record' => $this->access->canArchiveRecord($by, $item),
            'line' => $this->access->canConfigurePipeline($by, $item),
            'stage' => $this->access->canConfigurePipeline($by, $item->pipeline),
            default => $this->access->canAdminister($by),
        };

        return [$item, $allowed];
    }

    private function deleteBlock(User $by, string $kind, $item): ?string
    {
        return match ($kind) {
            'line' => $item->deals()->exists() ? 'Masih ada deal di lini bisnis ini. Pindahkan deal ke lini bisnis lain dahulu.' : null,
            'stage' => $item->is_closing ? 'Stage Closing tetap diperlukan untuk proses Won dan tidak dapat dihapus.'
                : ($item->pipeline->stages()->count() <= 2 ? 'Pipeline harus memiliki setidaknya satu stage awal dan Closing.'
                : (Deal::where('stage_id', $item->id)->exists() ? 'Masih ada deal di stage ini. Pindahkan deal ke stage lain dahulu.' : null)),
            'service' => ClientPartner::withTrashed()->where('service_id', $item->id)->exists() || Deal::where('service', $item->name)->exists()
                ? 'Layanan masih dipakai oleh relasi atau deal. Ganti layanan pada data terkait dahulu.' : null,
            'dept' => User::where('department_id', $item->id)->whereNull('deleted_at')->exists()
                || ClientPartner::withTrashed()->where('department_id', $item->id)->exists()
                || Service::where('department_id', $item->id)->exists()
                || Deal::whereIn('pipeline_id', $item->pipelines()->select('id'))->exists()
                ? 'Department masih dipakai oleh pengguna, relasi, layanan, atau deal. Pindahkan penugasannya dahulu. Pipeline kosong akan ikut masuk Data terhapus.' : null,
            'user' => $item->id === $by->id ? 'Akun yang sedang dipakai tidak dapat dihapus.'
                : ($item->active && $item->role === Role::SuperAdmin && User::where(['active' => true, 'role' => Role::SuperAdmin])->whereNull('deleted_at')->count() <= 1
                    ? 'Setidaknya satu Super Admin aktif harus tetap tersedia.'
                    : (ClientPartner::where('pic_id', $item->id)->exists() || Deal::where('owner_id', $item->id)->whereIn('status', [DealStatus::Open, DealStatus::OnHold])->exists()
                        ? 'Pengguna masih menjadi PIC relasi atau deal aktif. Serahkan penugasannya ke pengguna lain dahulu.' : null)),
            default => null,
        };
    }

    private function restoreBlock(TrashEntry $entry): ?string
    {
        $s = $entry->snapshot;

        return match ($entry->kind) {
            'record' => ($r = ClientPartner::withTrashed()->find($s['id'])) === null ? 'Data tidak ditemukan.'
                : (! Department::whereKey($r->department_id)->exists() ? 'Pulihkan department data ini dahulu.'
                : (User::whereKey($r->pic_id)->whereNull('deleted_at')->doesntExist() ? 'Pulihkan pengguna PIC relasi ini dahulu.'
                : ($r->service_id && ! Service::whereKey($r->service_id)->exists() ? 'Pulihkan layanan relasi ini dahulu.' : null))),
            'user' => ! Department::whereKey($s['department_id'] ?? 0)->exists() ? 'Pulihkan department data ini dahulu.' : null,
            'line' => ($p = Pipeline::find($s['id'])) === null ? 'Data tidak ditemukan.'
                : (Pipeline::whereNull('archived_at')->where('department_id', $p->department_id)->whereRaw('lower(name) = ?', [strtolower($p->name)])->exists()
                    ? 'Nama yang sama sudah dipakai. Ubah data yang sudah tersedia dahulu.'
                    : (! Department::whereKey($p->department_id)->exists() ? 'Pulihkan department data ini dahulu.' : null)),
            'stage' => ($p = Pipeline::whereNull('archived_at')->find($s['row']['pipeline_id'])) === null ? 'Pulihkan lini bisnis stage ini dahulu.'
                : ($p->stages()->where('id', $s['row']['id'])->orWhere(fn ($q) => $q->where('pipeline_id', $p->id)->whereRaw('lower(name) = ?', [strtolower($s['row']['name'])]))->exists()
                    ? 'Stage dengan ID atau nama yang sama sudah tersedia.' : null),
            'service' => Service::whereKey($s['row']['id'])->orWhereRaw('lower(name) = ?', [strtolower($s['row']['name'])])->exists()
                ? 'Nama yang sama sudah dipakai. Ubah data yang sudah tersedia dahulu.'
                : ($s['row']['department_id'] && ! Department::whereKey($s['row']['department_id'])->exists() ? 'Pulihkan department data ini dahulu.' : null),
            'override' => User::whereKey($s['row']['user_id'])->whereNull('deleted_at')->doesntExist()
                || AccessOverride::where(['user_id' => $s['row']['user_id'], 'entity' => $s['row']['entity']])->exists()
                ? 'Pengguna belum tersedia atau pengecualian untuk bagian yang sama sudah ada.' : null,
            'dept' => Department::whereKey($s['row']['id'])->orWhereRaw('lower(name) = ?', [strtolower($s['row']['name'])])->exists()
                ? 'Nama yang sama sudah dipakai. Ubah data yang sudah tersedia dahulu.'
                : (collect($s['pipelines'])->contains(fn ($p) => Pipeline::whereKey($p['row']['id'])->orWhere('name', $p['row']['name'])->exists())
                    ? 'Ada lini bisnis dengan ID atau nama yang sama.' : null),
            default => null,
        };
    }

    /** @return array{0: string, 1: ?int, 2: array<string, mixed>} */
    private function deleteRecord(ClientPartner $r): array
    {
        $r->delete();

        return [$r->name, $r->department_id, ['id' => $r->id, 'interactions' => $r->interactions()->count(), 'deals' => $r->deals()->count()]];
    }

    private function deleteLine(Pipeline $p): array
    {
        $p->update(['archived_at' => now()]);

        return [$p->name, $p->department_id, ['id' => $p->id]];
    }

    private function deleteStage(Stage $stage): array
    {
        $pipeline = $stage->pipeline;
        $row = (array) DB::table('stages')->where('id', $stage->id)->first();
        DB::table('deal_histories')->where('from_stage_id', $stage->id)->update(['from_stage_id' => null]);
        DB::table('deal_histories')->where('to_stage_id', $stage->id)->update(['to_stage_id' => null]);
        $stage->delete();
        $pipeline->stages()->where('position', '>', $row['position'])->decrement('position');

        return [$row['name'].' · '.$pipeline->name, $pipeline->department_id, ['id' => $row['id'], 'row' => $row]];
    }

    private function deleteRow($model, string $label, ?int $deptId): array
    {
        $row = (array) DB::table($model->getTable())->where('id', $model->id)->first();
        $model->delete();

        return [$label, $deptId, ['id' => $row['id'], 'row' => $row]];
    }

    private function deleteDept(Department $dept): array
    {
        $row = (array) DB::table('departments')->where('id', $dept->id)->first();
        $grants = DB::table('access_grants')->where('department_id', $dept->id)->get()->map(fn ($g) => (array) $g)->all();
        $pipelines = $dept->pipelines()->get()->map(fn (Pipeline $p) => [
            'row' => (array) DB::table('pipelines')->where('id', $p->id)->first(),
            'stages' => DB::table('stages')->where('pipeline_id', $p->id)->get()->map(fn ($s) => (array) $s)->all(),
        ])->all();
        DB::table('pipelines')->where('department_id', $dept->id)->delete();
        AccessGrant::where('department_id', $dept->id)->delete();
        $dept->delete();

        return [$row['name'], null, ['id' => $row['id'], 'row' => $row, 'grants' => $grants, 'pipelines' => $pipelines]];
    }

    private function deleteUser(User $user): array
    {
        $user->forceFill(['active' => false, 'deactivated_at' => $user->deactivated_at ?? now(), 'deleted_at' => now()])->save();
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        return [$user->name, $user->department_id, ['id' => $user->id, 'department_id' => $user->department_id]];
    }

    private function restoreStage(array $s): void
    {
        $row = $s['row'];
        $max = Stage::where('pipeline_id', $row['pipeline_id'])->count() - 1; // sebelum Closing
        $row['position'] = min($row['position'], max($max, 0));
        Stage::where('pipeline_id', $row['pipeline_id'])->where('position', '>=', $row['position'])->increment('position');
        DB::table('stages')->insert($row);
    }

    private function restoreDept(array $s): void
    {
        DB::table('departments')->insert($s['row']);
        foreach ($s['grants'] as $g) {
            DB::table('access_grants')->insert($g);
        }
        foreach ($s['pipelines'] as $p) {
            DB::table('pipelines')->insert($p['row']);
            foreach ($p['stages'] as $stage) {
                DB::table('stages')->insert($stage);
            }
        }
    }
}
