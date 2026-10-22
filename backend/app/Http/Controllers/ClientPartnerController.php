<?php

namespace App\Http\Controllers;

use App\Enums\Category;
use App\Enums\DealStatus;
use App\Enums\Role;
use App\Models\ClientPartner;
use App\Models\Contact;
use App\Models\Handover;
use App\Models\Service;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\ChangeHistory;
use App\Services\Duplicates;
use App\Support\Codes;
use App\Support\FrontShape;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            ->with(['contacts', 'department', 'service', 'handovers.fromDepartment', 'handovers.toDepartment'])
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

    /**
     * Data baru selalu Lead, dengan PIC dan divisi pemilik otomatis dari pengguna yang mengisi (siapa pun yang punya hak
     * input, dari divisi mana pun). Kategori, PIC, dan divisi dari form diabaikan. Pembuat dan waktu dibuat disimpan permanen.
     */
    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless($this->access->canCreateRecord($me, Category::Lead), 403, 'Kamu ga punya izin menambah Lead.');
        $data = $request->validate($this->rules(creating: true));
        $service = $this->service($data);

        $record = DB::transaction(function () use ($data, $me, $service) {
            [$id, $code] = Codes::next('client_partners', 'CP');
            $record = new ClientPartner;
            $record->forceFill([
                'id' => $id,
                'code' => $code,
                'name' => trim($data['name']),
                'category' => Category::Lead,
                'service_id' => $service?->id,
                'segment' => $data['segment'] ?? null,
                'pic_id' => $me->id,
                'department_id' => $me->department_id,
                'source' => $data['source'] ?? null,
                'city' => $data['city'] ?? null,
                'note' => $data['note'] ?? null,
                'rate_card' => $this->rateCard($me, $service, $data),
                'created_by' => $me->id,
            ])->save();
            if (($data['contact'] ?? '') !== '' || ($data['cEmail'] ?? '') !== '' || ($data['phone'] ?? '') !== '') {
                $record->contacts()->create([
                    'name' => (string) ($data['contact'] ?? ''), 'title' => $data['title'] ?? null,
                    'email' => $data['cEmail'] ?? null, 'phone' => $data['phone'] ?? null, 'is_primary' => true,
                ]);
            }
            $this->audit->log('Tambah', self::ENTITY, $record->id, $record->name.' ('.$record->code.') sebagai Lead, PIC '.$me->name, null, $record->getAttributes());

            return $record;
        });

        return $this->respond($me, $record, 201);
    }

    public function update(Request $request, ClientPartner $clientPartner): JsonResponse
    {
        $me = $request->user();
        $this->authorizeView($me, $clientPartner);
        abort_unless($this->access->canEditRecord($me, $clientPartner), 403, 'Kamu ga bisa mengubah data ini.');
        // Status relasi berubah hanya lewat deal Won, divisi dan PIC hanya lewat serah terima.
        $data = $request->validate($this->rules(creating: false));

        DB::transaction(function () use ($me, $clientPartner, $data) {
            $original = $clientPartner->getRawOriginal();
            $fields = isset($data['name']) ? ['name' => trim($data['name'])] : [];
            foreach (['segment', 'source', 'city', 'note'] as $k) {
                if (array_key_exists($k, $data)) {
                    $fields[$k] = $data[$k];
                }
            }
            $service = $clientPartner->service;
            if (array_key_exists('service', $data)) {
                $service = $this->service($data);
                $fields['service_id'] = $service?->id;
            }
            if (array_key_exists('rate', $data) && $this->access->canManageRateCard($me)) {
                $fields['rate_card'] = $this->rateCard($me, $service, $data);
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

    /**
     * Serah terima Lead/Client ke divisi dan PIC lain, ke arah mana pun (termasuk ke dan dari Customer Service).
     * Divisi pemilik ikut pindah, jadi akses mengikuti divisi baru. Pembuat data tidak berubah. Interaksi dan deal tetap
     * di relasi yang sama; deal yang masih berjalan (Open/On Hold) ikut dipegang PIC baru supaya tetap terurus.
     */
    public function handover(Request $request, ClientPartner $clientPartner): JsonResponse
    {
        $me = $request->user();
        $this->authorizeView($me, $clientPartner);
        abort_unless($this->access->canHandover($me, $clientPartner), 403, 'Kamu ga bisa menyerahkan data ini.');
        abort_if($clientPartner->archived_at !== null, 409, 'Data yang diarsipkan ga bisa diserahkan.');
        $data = $request->validate([
            'deptId' => ['required', 'integer', Rule::exists('departments', 'id')],
            'picId' => ['required', 'integer', Rule::exists('users', 'id')->where('active', true)],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'deptId.required' => 'Pilih divisi tujuan.', 'picId.required' => 'Pilih PIC penerima.',
            'picId.exists' => 'PIC penerima harus pengguna yang aktif.', 'reason.required' => 'Alasan wajib diisi.',
        ]);
        $to = User::with('department')->find($data['picId']);
        if ($to->department_id !== (int) $data['deptId']) {
            return $this->invalid('picId', 'PIC penerima harus dari divisi tujuan.');
        }
        if ($to->role === Role::Viewer) {
            return $this->invalid('picId', 'Viewer ga bisa jadi PIC.');
        }
        if ($to->id === $clientPartner->pic_id && $to->department_id === $clientPartner->department_id) {
            return $this->invalid('picId', 'Pilih PIC atau divisi yang berbeda dari sekarang.');
        }

        $from = $clientPartner->pic;
        $fromDept = $clientPartner->department;
        DB::transaction(function () use ($me, $clientPartner, $to, $from, $fromDept, $data) {
            $original = $clientPartner->getRawOriginal();
            $clientPartner->update(['pic_id' => $to->id, 'department_id' => $to->department_id]);

            $running = $clientPartner->deals()->whereIn('status', [DealStatus::Open, DealStatus::OnHold])->where('owner_id', '<>', $to->id)->get();
            foreach ($running as $deal) {
                $deal->update(['owner_id' => $to->id]);
                $deal->histories()->create([
                    'user_id' => $me->id, 'from_stage_id' => $deal->stage_id, 'from_stage_name' => $deal->stage->name,
                    'to_stage_id' => $deal->stage_id, 'to_stage_name' => $deal->stage->name, 'status' => $deal->status,
                    'note' => 'Serah terima ke '.$to->name.' ('.$to->department->name.')', 'created_at' => now(),
                ]);
            }

            Handover::create([
                'client_partner_id' => $clientPartner->id, 'from_user_id' => $from?->id, 'from_department_id' => $fromDept?->id,
                'to_user_id' => $to->id, 'to_department_id' => $to->department_id, 'by_user_id' => $me->id,
                'reason' => $data['reason'], 'deals_moved' => $running->count(), 'created_at' => now(),
            ]);
            $this->audit->changed(self::ENTITY, $clientPartner, $original, 'Serah terima '.($fromDept?->name ?? '-').' ke '.$to->department->name.': '.$data['reason']);
            Notify::send($to, $me->name.' menyerahkan '.$clientPartner->name.' ke kamu', 'rec:'.$clientPartner->id);
            if ($from && $from->id !== $to->id && $from->id !== $me->id) {
                Notify::send($from, $clientPartner->name.' sekarang dipegang '.$to->name.' ('.$to->department->name.')');
            }
        });

        return $this->respond($me, $clientPartner->fresh());
    }

    /**
     * Gabung data ganda (FR-REL-09): interaksi, deal, dan kontak dari data duplikat dipindah ke data yang disimpan,
     * kolom kosong diisi dari data duplikat, lalu data duplikat diarsipkan (tidak dihapus).
     */
    public function merge(Request $request, ClientPartner $clientPartner): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate(['srcId' => ['required', 'integer', Rule::exists('client_partners', 'id'), Rule::notIn([$clientPartner->id])]],
            ['srcId.required' => 'Pilih data duplikatnya.', 'srcId.not_in' => 'Pilih data lain.']);
        $source = ClientPartner::findOrFail($data['srcId']);
        foreach ([$clientPartner, $source] as $r) {
            $this->authorizeView($me, $r);
            abort_unless($this->access->canArchiveRecord($me, $r), 403, 'Gabung data cuma bisa oleh pengelola kategori '.$r->category->value.'.');
        }
        abort_if($clientPartner->archived_at || $source->archived_at, 409, 'Data yang diarsipkan ga bisa digabung.');

        $moved = DB::transaction(function () use ($clientPartner, $source, $me) {
            $ints = $source->interactions()->update(['client_partner_id' => $clientPartner->id]);
            $deals = $source->deals()->update(['client_partner_id' => $clientPartner->id]);
            $emails = $clientPartner->contacts()->pluck('email')->filter()->map(fn ($e) => strtolower($e))->all();
            $contacts = 0;
            foreach ($source->contacts as $c) {
                if (! $c->email || ! in_array(strtolower($c->email), $emails, true)) {
                    $c->update(['client_partner_id' => $clientPartner->id, 'is_primary' => false]);
                    $contacts++;
                }
            }
            $original = $clientPartner->getRawOriginal();
            $fill = [];
            foreach (['segment', 'city', 'source', 'note', 'rate_card'] as $k) {
                if (empty($clientPartner->{$k}) && ! empty($source->{$k})) {
                    $fill[$k] = $source->{$k};
                }
            }
            $clientPartner->update($fill);
            $this->audit->changed(self::ENTITY, $clientPartner, $original, 'Diisi dari '.$source->name);
            $source->update(['archived_at' => now(), 'archived_by' => $me->id, 'archive_reason' => 'Digabung ke '.$clientPartner->name, 'merged_into_id' => $clientPartner->id]);
            $summary = $source->name.' ('.$source->code.') digabung ke '.$clientPartner->name.' ('.$clientPartner->code.'): '.$ints.' interaksi, '.$deals.' deal, '.$contacts.' kontak dipindah';
            $this->audit->log('Gabung', self::ENTITY, $clientPartner->id, $summary, null, ['merged' => $source->id]);
            $this->audit->log('Gabung', self::ENTITY, $source->id, $summary, ['archived_at' => null], ['archived_at' => now()->toIso8601String(), 'merged_into_id' => $clientPartner->id]);

            return ['interactions' => $ints, 'deals' => $deals, 'contacts' => $contacts];
        });

        return response()->json(['data' => FrontShape::record($clientPartner->fresh(['contacts', 'department', 'service', 'handovers.fromDepartment', 'handovers.toDepartment']), $me, $this->access), 'moved' => $moved, 'mergedId' => (string) $source->id]);
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
    public function history(Request $request, ClientPartner $clientPartner, ChangeHistory $history): JsonResponse
    {
        $this->authorizeView($request->user(), $clientPartner);

        return response()->json(['data' => $history->forEntity(self::ENTITY, $clientPartner->id, $request->user())]);
    }

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
            'service' => ['nullable', 'string', 'max:100'],
            'segment' => ['nullable', 'string', 'max:100'],
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

    /** Rate card hanya untuk layanan KOL dan hanya disimpan oleh pengguna yang boleh mengelolanya. */
    private function rateCard(User $me, ?Service $service, array $data): ?array
    {
        if ($service?->name !== 'KOL' || ! $this->access->canManageRateCard($me) || ($data['rate'] ?? '') === '') {
            return null;
        }

        return ['rate' => (int) $data['rate']];
    }

    /** Layanan dari form (nama), boleh kosong: layanan bisa belum ditentukan saat Lead masuk. */
    private function service(array $data): ?Service
    {
        $name = trim((string) ($data['service'] ?? ''));
        if ($name === '') {
            return null;
        }

        return Service::where('name', $name)->where('active', true)->first()
            ?? throw ValidationException::withMessages(['service' => 'Layanan "'.$name.'" belum ada di daftar.']);
    }

    private function authorizeView(User $user, ClientPartner $record): void
    {
        abort_unless($this->access->canViewRecord($user, $record) && $record->merged_into_id === null, 403, 'Kamu ga punya akses ke data ini.');
    }

    private function invalid(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }

    private function respond(User $me, ClientPartner $record, int $status = 200): JsonResponse
    {
        return response()->json(['data' => FrontShape::record($record->load(['contacts', 'department', 'service', 'handovers.fromDepartment', 'handovers.toDepartment']), $me, $this->access)], $status);
    }
}
