<?php

namespace App\Http\Controllers;

use App\Enums\Category;
use App\Models\AuditLog;
use App\Models\ClientPartner;
use App\Models\Contact;
use App\Models\Department;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Duplicates;
use App\Support\FrontShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Client & Partner Database (SRS 4.2, FR-REL-01..08). Hak akses diperiksa di sini untuk setiap cara mengakses data,
 * dan setiap perubahan dicatat di audit log di bawah data induknya (termasuk perubahan kontak).
 */
class ClientPartnerController extends Controller
{
    public const ENTITY = 'Client & Partner';

    public function __construct(private Access $access, private Audit $audit) {}

    /** Daftar dengan filter dan halaman. all=1 mengembalikan semua data yang boleh dilihat (dipakai frontend saat ini). */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'cat' => ['nullable', Rule::enum(Category::class)],
            'dept' => ['nullable', 'string'],
            'archived' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['name', 'new'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'all' => ['nullable', 'boolean'],
        ]);

        $query = $this->access->scopeClientPartners(ClientPartner::query(), $me)
            ->with(['contacts', 'department'])
            ->whereNull('merged_into_id')
            ->when(isset($f['archived']), fn ($q) => $f['archived'] ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at'))
            ->when($f['cat'] ?? null, fn ($q, $cat) => $q->where('category', $cat))
            ->when($f['dept'] ?? null, fn ($q, $dept) => $q->whereHas('department', fn ($d) => $d->where('name', $dept)))
            ->when($f['q'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like)->orWhere('city', 'ilike', $like)
                    ->orWhereHas('contacts', fn ($c) => $c->where('name', 'ilike', $like))
                    ->orWhereHas('pic', fn ($p) => $p->where('name', 'ilike', $like)));
            })
            ->when(($f['sort'] ?? 'name') === 'new', fn ($q) => $q->latest(), fn ($q) => $q->orderBy('name'));

        $shape = fn (ClientPartner $r) => FrontShape::record($r, $me, $this->access);

        if ($request->boolean('all')) {
            return response()->json(['data' => $query->get()->map($shape)->values()]);
        }

        $page = $query->paginate($f['per_page'] ?? 20);

        return response()->json([
            'data' => collect($page->items())->map($shape)->values(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'pages' => $page->lastPage(),
        ]);
    }

    public function show(Request $request, ClientPartner $clientPartner): JsonResponse
    {
        $this->authorizeView($request->user(), $clientPartner);

        return $this->respond($request->user(), $clientPartner);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate($this->rules(creating: true));
        $category = Category::from($data['cat']);
        abort_unless($this->access->canCreateRecord($me, $category), 403, 'Kamu ga punya izin menambah data '.$category->value.'.');

        $pic = isset($data['picId']) ? User::find($data['picId']) : $me;
        if (! $pic?->active) {
            return $this->invalid('picId', 'PIC harus pengguna yang aktif.');
        }

        $record = DB::transaction(function () use ($data, $category, $me, $pic) {
            $id = (int) DB::selectOne("select nextval('client_partners_id_seq') as id")->id;
            // Kode CP-#### mengikuti id, jadi id diambil dulu dari sequence.
            $record = new ClientPartner;
            $record->forceFill([
                'id' => $id,
                'code' => 'CP-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
                'name' => trim($data['name']),
                'category' => $category,
                'segment' => $data['segment'] ?? null,
                'status' => $data['status'] ?? null,
                'pic_id' => $pic->id,
                'department_id' => isset($data['dept']) ? Department::where('name', $data['dept'])->value('id') : $me->department_id,
                'source' => $data['source'] ?? null,
                'city' => $data['city'] ?? null,
                'note' => $data['note'] ?? null,
                'rate_card' => $this->rateCard($me, $category, $data),
                'created_by' => $me->id,
            ])->save();
            if (($data['contact'] ?? '') !== '' || ($data['cEmail'] ?? '') !== '' || ($data['phone'] ?? '') !== '') {
                $record->contacts()->create([
                    'name' => (string) ($data['contact'] ?? ''), 'title' => $data['title'] ?? null,
                    'email' => $data['cEmail'] ?? null, 'phone' => $data['phone'] ?? null, 'is_primary' => true,
                ]);
            }
            $this->audit->log('Tambah', self::ENTITY, $record->id, $record->name.' ('.$record->code.')', null, $record->getAttributes());
            if ($pic->id !== $me->id) {
                $this->notify($pic, $me->name.' menjadikan kamu PIC '.$record->name, 'rec:'.$record->id);
            }

            return $record;
        });

        return $this->respond($me, $record, 201);
    }

    public function update(Request $request, ClientPartner $clientPartner): JsonResponse
    {
        $me = $request->user();
        $this->authorizeView($me, $clientPartner);
        abort_unless($this->access->canEditRecord($me, $clientPartner), 403, 'Kamu ga bisa mengubah data ini.');
        $data = $request->validate($this->rules(creating: false));

        if (isset($data['cat']) && $data['cat'] !== $clientPartner->category->value
            && ! $this->access->canCreateRecord($me, Category::from($data['cat']))) {
            abort(403, 'Kamu ga punya izin memindahkan data ke kategori '.$data['cat'].'.');
        }

        DB::transaction(function () use ($me, $clientPartner, $data) {
            $original = $clientPartner->getRawOriginal();
            $fields = array_filter([
                'name' => isset($data['name']) ? trim($data['name']) : null,
                'category' => $data['cat'] ?? null,
            ], fn ($v) => $v !== null);
            foreach (['segment', 'status', 'source', 'city', 'note'] as $k) {
                if (array_key_exists($k, $data)) {
                    $fields[$k] = $data[$k];
                }
            }
            if (array_key_exists('dept', $data) && $data['dept']) {
                $fields['department_id'] = Department::where('name', $data['dept'])->value('id');
            }
            if (array_key_exists('rate', $data) && $this->access->canManageRateCard($me)) {
                $fields['rate_card'] = $this->rateCard($me, Category::from($fields['category'] ?? $clientPartner->category->value), $data);
            }
            $clientPartner->update($fields);
            $this->audit->changed(self::ENTITY, $clientPartner, $original, $clientPartner->name);

            $this->updatePrimaryContact($clientPartner, $data);
        });

        return $this->respond($me, $clientPartner->fresh());
    }

    /** Arsip, bukan hapus (FR-REL-08). Hanya level M. */
    public function archive(Request $request, ClientPartner $clientPartner): JsonResponse
    {
        $me = $request->user();
        $this->authorizeView($me, $clientPartner);
        abort_unless($this->access->canArchiveRecord($me, $clientPartner), 403, 'Arsip cuma bisa oleh pengelola kategori ini.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Alasan wajib diisi.']);
        abort_if($clientPartner->archived_at !== null, 409, 'Data ini sudah diarsipkan.');

        $clientPartner->update(['archived_at' => now(), 'archived_by' => $me->id, 'archive_reason' => $data['reason']]);
        $this->audit->log('Arsip', self::ENTITY, $clientPartner->id, $clientPartner->name.' diarsipkan: '.$data['reason'],
            ['archived_at' => null], ['archived_at' => $clientPartner->archived_at->toIso8601String(), 'archive_reason' => $data['reason']]);

        return $this->respond($me, $clientPartner);
    }

    public function restore(Request $request, ClientPartner $clientPartner): JsonResponse
    {
        $me = $request->user();
        $this->authorizeView($me, $clientPartner);
        abort_unless($this->access->canArchiveRecord($me, $clientPartner), 403, 'Pulihkan cuma bisa oleh pengelola kategori ini.');
        abort_if($clientPartner->archived_at === null, 409, 'Data ini tidak sedang diarsipkan.');

        $before = ['archived_at' => $clientPartner->archived_at->toIso8601String(), 'archive_reason' => $clientPartner->archive_reason];
        $clientPartner->update(['archived_at' => null, 'archived_by' => null, 'archive_reason' => null]);
        $this->audit->log('Ubah', self::ENTITY, $clientPartner->id, $clientPartner->name.' dipulihkan dari arsip', $before, ['archived_at' => null]);

        return $this->respond($me, $clientPartner);
    }

    /** Lead yang sudah memenuhi syarat menjadi Client (FR-REL-07). */
    public function convert(Request $request, ClientPartner $clientPartner): JsonResponse
    {
        $me = $request->user();
        $this->authorizeView($me, $clientPartner);
        abort_unless($clientPartner->category === Category::Lead, 409, 'Cuma Lead yang bisa dijadikan Client.');
        abort_unless($this->access->canEditRecord($me, $clientPartner) && $this->access->canCreateRecord($me, Category::Client), 403, 'Kamu ga bisa menjadikan data ini Client.');

        $original = $clientPartner->getRawOriginal();
        $clientPartner->update(['category' => Category::Client, 'converted_at' => now()]);
        $this->audit->changed(self::ENTITY, $clientPartner, $original, $clientPartner->name.' dikonversi dari Lead jadi Client');

        return $this->respond($me, $clientPartner);
    }

    /** Pindah PIC (FR-REL-06): wajib alasan, PIC baru dapat pemberitahuan. */
    public function movePic(Request $request, ClientPartner $clientPartner): JsonResponse
    {
        $me = $request->user();
        $this->authorizeView($me, $clientPartner);
        abort_unless($this->access->canMovePic($me, $clientPartner), 403, 'Kamu ga bisa memindahkan PIC data ini.');
        $data = $request->validate([
            'picId' => ['required', Rule::exists('users', 'id')->where('active', true), Rule::notIn([$clientPartner->pic_id])],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'picId.required' => 'Pilih PIC baru.', 'picId.exists' => 'PIC harus pengguna yang aktif.',
            'picId.not_in' => 'Pilih orang lain dari PIC sekarang.', 'reason.required' => 'Alasan wajib diisi.',
        ]);

        $original = $clientPartner->getRawOriginal();
        $clientPartner->update(['pic_id' => (int) $data['picId']]);
        $this->audit->changed(self::ENTITY, $clientPartner, $original, 'Pindah PIC: '.$data['reason']);
        $this->notify(User::find($data['picId']), $me->name.' menjadikan kamu PIC '.$clientPartner->name, 'rec:'.$clientPartner->id);

        return $this->respond($me, $clientPartner);
    }

    /** Cek data ganda sebelum menyimpan (FR-REL-03). */
    public function duplicates(Request $request, Duplicates $duplicates): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:50'],
            'excludeId' => ['nullable', 'integer'],
        ]);

        return response()->json($duplicates->check($request->user(), $data['name'] ?? null, $data['email'] ?? null, $data['phone'] ?? null, $data['excludeId'] ?? null));
    }

    /** Riwayat perubahan satu data (FR-AUD-02), dalam bentuk "changes" yang dipakai frontend. */
    public function history(Request $request, ClientPartner $clientPartner): JsonResponse
    {
        $this->authorizeView($request->user(), $clientPartner);
        $seeRate = $this->access->canSeeRateCard($request->user());
        $users = User::pluck('name', 'id');
        $depts = Department::pluck('name', 'id');
        $show = function (string $field, $value) use ($users, $depts, $seeRate) {
            return match (true) {
                $value === null || $value === '' => '',
                $field === 'pic_id' => $users[$value] ?? '',
                $field === 'department_id' => $depts[$value] ?? '',
                $field === 'archived_at' => 'Diarsipkan',
                $field === 'rate_card' => $seeRate ? 'Rp '.number_format((float) ((is_array($value) ? $value : json_decode((string) $value, true))['rate'] ?? 0), 0, ',', '.') : 'Rp ···',
                default => is_scalar($value) ? (string) $value : json_encode($value),
            };
        };

        $rows = [];
        foreach (AuditLog::where('entity_type', self::ENTITY)->where('entity_id', $clientPartner->id)->orderByDesc('id')->get() as $log) {
            $base = ['at' => $log->created_at->toIso8601String(), 'by' => (string) $log->user_id, 'kind' => 'record', 'refId' => (string) $clientPartner->id, 'note' => (string) $log->summary];
            $fields = array_intersect_key($log->after ?? [], FrontShape::RECORD_LABELS + self::CONTACT_LABELS);
            if ($log->action === 'Tambah' || $fields === []) {
                // Tanpa field yang berubah (misalnya data dibuat): tampilkan ringkasannya sebagai isi baru.
                $rows[] = ['id' => (string) $log->id, 'field' => $log->action === 'Tambah' ? 'Data dibuat' : $log->action, 'before' => '', 'after' => (string) $log->summary] + $base;
                $rows[array_key_last($rows)]['note'] = '';

                continue;
            }
            foreach ($fields as $field => $after) {
                $before = ($log->before ?? [])[$field] ?? null;
                $label = FrontShape::RECORD_LABELS[$field] ?? self::CONTACT_LABELS[$field];
                $rows[] = $base + [
                    'id' => $log->id.'-'.$field, 'field' => $label,
                    'before' => $field === 'archived_at' ? ($before ? 'Diarsipkan' : 'Aktif') : $show($field, $before),
                    'after' => $field === 'archived_at' ? ($after ? 'Diarsipkan' : 'Aktif') : $show($field, $after),
                ];
            }
        }

        return response()->json(['data' => $rows]);
    }

    public const CONTACT_LABELS = [
        'contact.name' => 'Nama kontak', 'contact.title' => 'Jabatan kontak', 'contact.email' => 'Email kontak',
        'contact.phone' => 'Telepon kontak', 'contact.primary' => 'Kontak utama', 'contact' => 'Kontak',
    ];

    /** Field kontak utama dari form utama (contact, title, cEmail, phone). */
    private function updatePrimaryContact(ClientPartner $record, array $data): void
    {
        $map = ['contact' => 'name', 'title' => 'title', 'cEmail' => 'email', 'phone' => 'phone'];
        $incoming = array_intersect_key($data, $map);
        if ($incoming === []) {
            return;
        }
        $contact = $record->contacts()->orderByDesc('is_primary')->orderBy('id')->first()
            ?? new Contact(['client_partner_id' => $record->id, 'name' => '', 'is_primary' => true]);
        $before = [];
        $after = [];
        foreach ($incoming as $from => $value) {
            $col = $map[$from];
            $value = (string) $value;
            if ((string) $contact->{$col} !== $value) {
                $before['contact.'.$col] = (string) $contact->{$col};
                $after['contact.'.$col] = $value;
                $contact->{$col} = $col === 'name' ? $value : ($value === '' ? null : $value);
            }
        }
        if ($after === []) {
            return;
        }
        $contact->save();
        $this->audit->log('Ubah', self::ENTITY, $record->id, $record->name.': kontak utama diperbarui', $before, $after);
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:200'],
            'cat' => [$req, Rule::enum(Category::class)],
            'segment' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
            'picId' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'dept' => ['nullable', 'string', Rule::exists('departments', 'name')],
            'source' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:5000'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'contact' => ['nullable', 'string', 'max:200'],
            'title' => ['nullable', 'string', 'max:200'],
            'cEmail' => ['nullable', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }

    /** Rate card hanya untuk KOL/KOC dan hanya disimpan oleh pengguna yang boleh mengelolanya. */
    private function rateCard(User $me, Category $category, array $data): ?array
    {
        if ($category !== Category::KolKoc || ! $this->access->canManageRateCard($me) || ($data['rate'] ?? '') === '') {
            return null;
        }

        return ['rate' => (int) $data['rate']];
    }

    private function authorizeView(User $user, ClientPartner $record): void
    {
        abort_unless($this->access->canViewRecord($user, $record) && $record->merged_into_id === null, 403, 'Kamu ga punya akses ke data ini.');
    }

    private function notify(User $user, string $text, string $link): void
    {
        DB::table('user_notifications')->insert(['user_id' => $user->id, 'text' => $text, 'link' => $link, 'created_at' => now()]);
    }

    private function invalid(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }

    private function respond(User $me, ClientPartner $record, int $status = 200): JsonResponse
    {
        return response()->json(['data' => FrontShape::record($record->load(['contacts', 'department']), $me, $this->access)], $status);
    }
}
