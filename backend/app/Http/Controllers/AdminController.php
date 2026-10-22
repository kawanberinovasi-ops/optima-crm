<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\AccessOverride;
use App\Models\AuditLog;
use App\Models\ClientPartner;
use App\Models\Department;
use App\Models\OptionList;
use App\Models\Service;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\ChangeHistory;
use App\Support\FrontShape;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Akses & Audit (SRS bagian 3, FR-USR-01..06, FR-AUD-01..02).
 * Hanya Super Admin yang mengelola pengguna, department, role, dan hak akses. Kepala Department boleh melihat.
 */
class AdminController extends Controller
{
    private const DATA_ENTITIES = ['lead', 'client', 'interaksi', 'deal'];

    private const OPTION_KEYS = ['segments' => 'segments', 'sources' => 'sources', 'intTypes' => 'interaction_types', 'lostReasons' => 'lost_reasons'];

    private const OPTION_LABEL = ['segments' => 'Segmen', 'sources' => 'Sumber data', 'interaction_types' => 'Jenis interaksi', 'lost_reasons' => 'Alasan deal Lost'];

    public function __construct(private Access $access, private Audit $audit) {}

    /* ---------- pengguna ---------- */

    public function storeUser(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:200', Rule::unique('users', 'email')],
            'dept' => ['required', Rule::exists('departments', 'name')],
            'role' => ['required', Rule::enum(Role::class)],
        ], ['name.required' => 'Nama wajib diisi.', 'email.email' => 'Format email belum benar.', 'email.unique' => 'Email ini udah terdaftar.']);

        $user = User::create([
            'name' => trim($data['name']), 'email' => strtolower($data['email']), 'role' => $data['role'], 'active' => true,
            'department_id' => Department::where('name', $data['dept'])->value('id'),
        ]);
        $this->audit->log('Tambah', 'Pengguna', $user->id, $user->name.' ('.$user->role->value.', '.$data['dept'].')', null, ['email' => $user->email, 'role' => $user->role->value, 'dept' => $data['dept']]);

        return response()->json(['data' => FrontShape::user($user->load('department'))], 201);
    }

    /** Ubah department, role, atau status aktif. Menonaktifkan langsung memutus sesi pengguna itu. */
    public function updateUser(Request $request, User $user): JsonResponse
    {
        $me = $this->authorizeAdmin($request);
        $data = $request->validate([
            'dept' => ['sometimes', Rule::exists('departments', 'name')],
            'role' => ['sometimes', Rule::enum(Role::class)],
            'active' => ['sometimes', 'boolean'],
        ]);
        abort_if($user->id === $me->id && (isset($data['role']) || array_key_exists('active', $data)), 422, 'Kamu ga bisa mengubah role atau menonaktifkan akun sendiri.');

        $before = ['dept' => $user->department->name, 'role' => $user->role->value, 'active' => $user->active];
        $fields = [];
        if (isset($data['dept'])) {
            $fields['department_id'] = Department::where('name', $data['dept'])->value('id');
        }
        if (isset($data['role'])) {
            $fields['role'] = $data['role'];
        }
        if (array_key_exists('active', $data)) {
            $fields['active'] = (bool) $data['active'];
            $fields['deactivated_at'] = $data['active'] ? null : now();
        }
        $user->update($fields);
        $user->load('department');
        $after = ['dept' => $user->department->name, 'role' => $user->role->value, 'active' => $user->active];
        $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));
        if ($changed) {
            $summary = $user->name.': '.implode(', ', array_map(fn ($k) => match ($k) {
                'active' => $user->active ? 'diaktifkan' : 'dinonaktifkan',
                'dept' => 'pindah department '.$before['dept'].' ke '.$after['dept'],
                'role' => 'role '.$before['role'].' jadi '.$after['role'],
            }, $changed));
            $this->audit->log('Ubah', 'Pengguna', $user->id, $summary, array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)));
        }
        $this->access->forget($user);

        return response()->json(['data' => FrontShape::user($user)]);
    }

    /* ---------- department ---------- */

    public function storeDepartment(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('departments', 'name')]], ['name.required' => 'Nama wajib diisi.', 'name.unique' => 'Nama udah dipakai.']);

        $dept = DB::transaction(function () use ($data) {
            $dept = Department::create(['name' => trim($data['name'])]);
            // Sama seperti prototipe: department baru bisa melihat semua data, belum boleh melihat nilai deal dan rate card.
            foreach (self::DATA_ENTITIES as $e) {
                AccessGrant::create(['department_id' => $dept->id, 'entity' => $e, 'level' => 'R']);
            }
            AccessGrant::create(['department_id' => $dept->id, 'entity' => 'nilai', 'level' => 'Tidak']);
            AccessGrant::create(['department_id' => $dept->id, 'entity' => 'rate', 'level' => 'Tidak']);

            return $dept;
        });
        $this->audit->log('Tambah', 'Department', $dept->id, $dept->name);

        return $this->deptResponse($dept, 201);
    }

    public function updateDepartment(Request $request, Department $department): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('departments', 'name')->ignore($department->id)],
            'seesAll' => ['sometimes', 'boolean'],
        ], ['name.required' => 'Nama wajib diisi.', 'name.unique' => 'Nama udah dipakai.']);

        $before = ['name' => $department->name, 'sees_all' => $department->sees_all];
        $department->update(array_filter(['name' => isset($data['name']) ? trim($data['name']) : null, 'sees_all' => $data['seesAll'] ?? null], fn ($v) => $v !== null));
        $after = ['name' => $department->name, 'sees_all' => $department->sees_all];
        $summary = $before['name'] !== $after['name'] ? $before['name'].' jadi '.$after['name'] : $department->name;
        if ($before['sees_all'] !== $after['sees_all']) {
            $summary .= $after['sees_all'] ? ': bisa melihat data lintas divisi' : ': hanya melihat data divisinya';
        }
        $this->audit->log('Ubah', 'Department', $department->id, $summary, $before, $after);
        $this->access->forget();

        return $this->deptResponse($department);
    }

    public function destroyDepartment(Request $request, Department $department): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_if($department->users()->exists() || ClientPartner::where('department_id', $department->id)->exists(), 422,
            'Department ini masih punya pengguna atau data. Pindahkan dulu.');

        $name = $department->name;
        $department->delete();
        $this->audit->log('Hapus', 'Department', null, $name);

        return response()->json(['ok' => true]);
    }

    /* ---------- hak akses ---------- */

    public function updateGrant(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'dept' => ['required', Rule::exists('departments', 'name')],
            'entity' => ['required', Rule::in([...self::DATA_ENTITIES, 'nilai', 'rate'])],
            'level' => ['required', 'string'],
        ]);
        $this->assertLevel($data['entity'], $data['level']);

        $dept = Department::where('name', $data['dept'])->first();
        $grant = AccessGrant::firstOrNew(['department_id' => $dept->id, 'entity' => $data['entity']]);
        $old = $grant->level;
        $grant->level = $data['level'];
        $grant->save();
        $this->audit->log('Ubah', 'Hak akses', $grant->id, $dept->name.' · '.$data['entity'].': '.($old ?? '-').' jadi '.$data['level'],
            ['level' => $old], ['level' => $data['level']]);
        $this->access->forget();

        return response()->json(['ok' => true]);
    }

    public function storeOverride(Request $request): JsonResponse
    {
        $me = $this->authorizeAdmin($request);
        $data = $request->validate([
            'userId' => ['required', 'integer', Rule::exists('users', 'id')],
            'key' => ['required', Rule::in([...self::DATA_ENTITIES, 'nilai', 'rate'])],
            'value' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:255'],
        ], ['userId.required' => 'Pilih pengguna.', 'reason.required' => 'Isi alasannya.']);
        $this->assertLevel($data['key'], $data['value']);
        $user = User::find($data['userId']);
        abort_if($user->isSuperAdmin(), 422, 'Super Admin selalu punya akses penuh.');

        $old = AccessOverride::where(['user_id' => $user->id, 'entity' => $data['key']])->value('level');
        $override = AccessOverride::updateOrCreate(['user_id' => $user->id, 'entity' => $data['key']],
            ['level' => $data['value'], 'reason' => $data['reason'], 'granted_by' => $me->id]);
        $this->audit->log('Ubah', 'Hak akses', $override->id, 'Pengecualian '.$data['key'].' = '.$data['value'].' buat '.$user->name.' ('.$data['reason'].')',
            ['level' => $old], ['level' => $data['value']]);
        Notify::send($user, 'Hak akses kamu buat '.$data['key'].' diubah jadi '.$data['value']);
        $this->access->forget($user);

        return response()->json(['data' => $this->overrideShape($override)], 201);
    }

    public function destroyOverride(Request $request, AccessOverride $override): JsonResponse
    {
        $this->authorizeAdmin($request);
        $override->delete();
        $this->audit->log('Ubah', 'Hak akses', $override->id, 'Pengecualian '.$override->entity.' buat '.$override->user->name.' dicabut',
            ['level' => $override->level], ['level' => null]);

        return response()->json(['ok' => true]);
    }

    /* ---------- layanan dan pemetaan ke divisi ---------- */

    /** Layanan baru, atau ubah divisi penanganan / status aktif layanan yang ada. Tidak memindahkan PIC data mana pun. */
    public function storeService(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('services', 'name')],
            'deptId' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ], ['name.required' => 'Nama layanan wajib diisi.', 'name.unique' => 'Layanan ini udah ada.']);
        $service = Service::create(['name' => trim($data['name']), 'department_id' => $data['deptId'] ?? null, 'position' => (int) Service::max('position') + 1]);
        $this->audit->log('Tambah', 'Layanan', $service->id, $service->name.' ditangani '.($service->department?->name ?? 'belum ditentukan'));

        return response()->json(['data' => self::servicesShape()], 201);
    }

    public function updateService(Request $request, Service $service): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'deptId' => ['sometimes', 'nullable', 'integer', Rule::exists('departments', 'id')],
            'active' => ['sometimes', 'boolean'],
        ]);
        $before = ['department' => $service->department?->name, 'active' => $service->active];
        $fields = [];
        if (array_key_exists('deptId', $data)) {
            $fields['department_id'] = $data['deptId'];
        }
        if (array_key_exists('active', $data)) {
            $fields['active'] = (bool) $data['active'];
        }
        $service->update($fields);
        $service->load('department');
        $after = ['department' => $service->department?->name, 'active' => $service->active];
        $this->audit->log('Ubah', 'Layanan', $service->id, $service->name.': ditangani '.($after['department'] ?? 'belum ditentukan').($after['active'] ? '' : ', nonaktif'), $before, $after);

        return response()->json(['data' => self::servicesShape()]);
    }

    public static function servicesShape(): array
    {
        return Service::with('department')->orderBy('position')->get()->map(fn (Service $s) => [
            'id' => (string) $s->id, 'name' => $s->name, 'dept' => (string) $s->department?->name, 'deptId' => $s->department_id ? (string) $s->department_id : '', 'active' => $s->active,
        ])->values()->all();
    }

    /* ---------- daftar pilihan ---------- */

    public function storeOption(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['list' => ['required', Rule::in(array_keys(self::OPTION_KEYS))], 'value' => ['required', 'string', 'max:100']]);
        $list = self::OPTION_KEYS[$data['list']];
        $value = trim($data['value']);

        $existing = OptionList::where('list', $list)->whereRaw('lower(value) = ?', [strtolower($value)])->first();
        abort_if($existing?->active, 422, $value.' udah ada di daftar.');
        $existing
            ? $existing->update(['active' => true])
            : OptionList::create(['list' => $list, 'value' => $value, 'position' => (int) OptionList::where('list', $list)->max('position') + 1]);
        $this->audit->log('Ubah', 'Daftar pilihan', null, self::OPTION_LABEL[$list].': '.$value.' ditambah');

        return response()->json(['data' => $this->options()]);
    }

    /** Pilihan tidak dihapus, hanya dinonaktifkan, supaya data lama yang memakainya tetap terbaca. */
    public function destroyOption(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['list' => ['required', Rule::in(array_keys(self::OPTION_KEYS))], 'value' => ['required', 'string']]);
        $list = self::OPTION_KEYS[$data['list']];

        OptionList::where(['list' => $list, 'value' => $data['value']])->update(['active' => false]);
        $this->audit->log('Ubah', 'Daftar pilihan', null, self::OPTION_LABEL[$list].': '.$data['value'].' dihapus');

        return response()->json(['data' => $this->options()]);
    }

    /* ---------- audit log ---------- */

    public function auditLogs(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json(['data' => self::auditShape(AuditLog::orderByDesc('id')->limit(500)->get())]);
    }

    /** Unduh semua audit log sebagai CSV (FR-AUD-01). */
    public function exportAudit(Request $request): StreamedResponse
    {
        $this->authorizeView($request);
        $this->audit->log('Export', 'Audit log', null, 'Audit log diunduh sebagai CSV');
        $users = User::pluck('name', 'id');

        return response()->streamDownload(function () use ($users) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Waktu', 'Pengguna', 'Aksi', 'Bagian', 'Id data', 'Detail', 'Sebelum', 'Sesudah', 'IP']);
            AuditLog::orderBy('id')->chunk(1000, function ($logs) use ($out, $users) {
                foreach ($logs as $log) {
                    fputcsv($out, [
                        $log->created_at->toDateTimeString(), $users[$log->user_id] ?? 'Sistem', $log->action, $log->entity_type, $log->entity_id,
                        $log->summary, $log->before ? json_encode($log->before, JSON_UNESCAPED_UNICODE) : '',
                        $log->after ? json_encode($log->after, JSON_UNESCAPED_UNICODE) : '', $log->ip_address,
                    ]);
                }
            });
            fclose($out);
        }, 'audit-log-optima-crm-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function changes(Request $request, ChangeHistory $history): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json(['data' => $history->latest($request->user())]);
    }

    /** Bentuk audit log yang dipakai frontend: {id, at, by, action, entity, detail}. */
    public static function auditShape($logs): array
    {
        return collect($logs)->map(fn (AuditLog $a) => [
            'id' => (string) $a->id, 'at' => $a->created_at->toIso8601String(), 'by' => (string) $a->user_id,
            'action' => $a->action, 'entity' => $a->entity_type, 'detail' => (string) $a->summary,
        ])->values()->all();
    }

    public static function optionsShape(): array
    {
        $out = array_fill_keys(array_keys(self::OPTION_KEYS), []);
        $back = array_flip(self::OPTION_KEYS);
        foreach (OptionList::where('active', true)->orderBy('position')->get() as $o) {
            $out[$back[$o->list]][] = $o->value;
        }

        return $out;
    }

    private function options(): array
    {
        return self::optionsShape();
    }

    private function overrideShape(AccessOverride $o): array
    {
        return ['id' => (string) $o->id, 'userId' => (string) $o->user_id, 'key' => $o->entity, 'value' => $o->level, 'reason' => (string) $o->reason];
    }

    private function deptResponse(Department $dept, int $status = 200): JsonResponse
    {
        $access = $dept->accessGrants()->pluck('level', 'entity');

        return response()->json(['data' => ['id' => (string) $dept->id, 'name' => $dept->name], 'access' => $access], $status);
    }

    private function assertLevel(string $entity, string $level): void
    {
        $allowed = match ($entity) {
            'nilai' => ['Ya', 'Ya*', 'Lihat*', 'Tidak'],
            'rate' => ['Ya', 'M', 'R*', 'Tidak'],
            default => ['N', 'R', 'C', 'M'],
        };
        abort_unless(in_array($level, $allowed, true), 422, 'Level '.$level.' tidak berlaku untuk '.$entity.'.');
    }

    private function authorizeAdmin(Request $request): User
    {
        abort_unless($this->access->canAdminister($request->user()), 403, 'Cuma Super Admin yang bisa mengubah pengaturan ini.');

        return $request->user();
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($this->access->canViewAdmin($request->user()), 403, 'Halaman ini khusus admin.');
    }
}
