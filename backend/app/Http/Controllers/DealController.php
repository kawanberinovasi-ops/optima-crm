<?php

namespace App\Http\Controllers;

use App\Enums\AccessLevel;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Stage;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Deals;
use App\Support\FrontShape;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Pipeline deal (SRS 4.3, FR-DEAL-01..06). */
class DealController extends Controller
{
    public function __construct(private Access $access, private Audit $audit, private Deals $deals) {}

    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless($this->access->can($me, 'deal', AccessLevel::R), 403, 'Akun ini ga punya akses ke deal.');

        // Deal mengikuti divisi pemilik relasinya.
        $records = $this->access->scopeClientPartners(ClientPartner::query(), $me)->select('id');

        return response()->json(['data' => Deal::with('histories', 'clientPartner')->whereIn('client_partner_id', $records)->orderBy('id')->get()
            ->map(fn (Deal $d) => FrontShape::deal($d, $me, $this->access))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless($this->access->can($me, 'deal', AccessLevel::C), 403, 'Kamu ga punya izin menambah deal.');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'recordId' => ['required', 'integer', Rule::exists('client_partners', 'id')->whereNull('archived_at')],
            'lineId' => ['nullable', 'integer'],
            'stageId' => ['nullable', 'integer'],
            'ownerId' => ['nullable', 'integer', Rule::exists('users', 'id')->where('active', true)],
            'target' => ['nullable', 'date'],
            'service' => ['nullable', 'string', 'max:200'],
            'offer' => ['nullable', 'numeric', 'min:0'],
        ], ['title.required' => 'Judul wajib diisi.', 'recordId.required' => 'Pilih Client & Partner.']);

        $record = ClientPartner::findOrFail($data['recordId']);
        abort_unless($this->access->canViewRecord($me, $record), 403, 'Kamu ga punya akses ke data ini.');
        // Pipeline selalu pipeline divisi pemilik relasi; PIC deal bawaan = PIC relasi.
        $pipeline = $this->deals->pipelineFor($record->department_id);
        $owner = User::find($data['ownerId'] ?? $record->pic_id);
        $this->deals->assertOwner($owner, $record);
        $stage = isset($data['stageId']) ? Stage::find($data['stageId']) : null;

        $deal = $this->deals->create($me, $record, $pipeline, $stage, [
            'title' => trim($data['title']), 'service' => $data['service'] ?? null, 'owner_id' => $owner->id,
            'target_closing' => $data['target'] ?? null,
            'offer_value' => $this->access->canSeeDealValue($me) || $owner->id === $me->id ? ($data['offer'] ?? null) : null,
        ]);
        if ($owner->id !== $me->id) {
            Notify::send($owner, $me->name.' menjadikan kamu PIC deal '.$deal->title, 'deal:'.$deal->id);
        }

        return $this->respond($me, $deal, 201);
    }

    public function update(Request $request, Deal $deal): JsonResponse
    {
        $me = $request->user();
        $this->authorizeEdit($me, $deal);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'recordId' => ['sometimes', 'integer', Rule::exists('client_partners', 'id')],
            'ownerId' => ['sometimes', 'integer', Rule::exists('users', 'id')->where('active', true)],
            'target' => ['nullable', 'date'],
            'service' => ['nullable', 'string', 'max:200'],
            'offer' => ['nullable', 'numeric', 'min:0'],
        ], ['title.required' => 'Judul wajib diisi.']);

        $fields = [];
        foreach (['title' => 'title', 'service' => 'service', 'target' => 'target_closing', 'recordId' => 'client_partner_id', 'ownerId' => 'owner_id'] as $in => $col) {
            if (array_key_exists($in, $data)) {
                $fields[$col] = $data[$in];
            }
        }
        $record = isset($fields['client_partner_id']) ? ClientPartner::findOrFail($fields['client_partner_id']) : $deal->clientPartner;
        abort_unless($this->access->canViewRecord($me, $record), 403, 'Kamu ga punya akses ke data ini.');
        if (isset($fields['owner_id']) || $record->department_id !== $deal->clientPartner->department_id) {
            $owner = User::find($fields['owner_id'] ?? $deal->owner_id);
            if (! isset($fields['owner_id']) && $owner?->department_id !== $record->department_id) {
                $owner = $record->pic;
                $fields['owner_id'] = $owner?->id;
            }
            $this->deals->assertOwner($owner, $record);
        }
        // Nilai penawaran hanya bisa diubah oleh yang boleh melihatnya.
        if (array_key_exists('offer', $data) && $this->access->canSeeDealValue($me, $deal)) {
            $fields['offer_value'] = $data['offer'];
        }

        $original = $deal->getRawOriginal();
        $deal->update($fields);
        $this->deals->moveToDivision($me, $deal->fresh(), $this->deals->pipelineFor($record->department_id), 'Relasi deal diganti');
        $this->audit->changed(Deals::ENTITY, $deal, $original, $deal->title);
        if ($deal->wasChanged('owner_id') && $deal->owner_id !== $me->id) {
            Notify::send($deal->owner, 'Deal '.$deal->title.' sekarang kamu yang pegang', 'deal:'.$deal->id);
        }

        return $this->respond($me, $deal);
    }

    public function move(Request $request, Deal $deal): JsonResponse
    {
        $this->authorizeEdit($request->user(), $deal);
        $data = $request->validate(['stageId' => ['required', 'integer']]);

        return $this->respond($request->user(), $this->deals->move($request->user(), $deal, Stage::findOrFail($data['stageId'])));
    }

    public function won(Request $request, Deal $deal): JsonResponse
    {
        $this->authorizeEdit($request->user(), $deal);
        $data = $request->validate([
            'value' => ['required', 'numeric', 'gt:0'],
            'closedAt' => ['required', 'date', 'before_or_equal:today'],
        ], [
            'value.required' => 'Nilai deal wajib diisi buat Won.', 'value.gt' => 'Nilai deal wajib diisi buat Won.',
            'closedAt.required' => 'Tanggal closing wajib diisi.', 'closedAt.before_or_equal' => 'Tanggal closing ga boleh di masa depan.',
        ]);

        return $this->respond($request->user(), $this->deals->won($request->user(), $deal, (float) $data['value'], Carbon::parse($data['closedAt'])));
    }

    public function lost(Request $request, Deal $deal): JsonResponse
    {
        $this->authorizeEdit($request->user(), $deal);
        $data = $request->validate([
            'lostReason' => ['required', 'string', Rule::exists('option_lists', 'value')->where('list', 'lost_reasons')],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['lostReason.required' => 'Pilih alasan gagal.', 'lostReason.exists' => 'Pilih alasan dari daftar.']);

        return $this->respond($request->user(), $this->deals->lost($request->user(), $deal, $data['lostReason'], $data['note'] ?? null));
    }

    public function hold(Request $request, Deal $deal): JsonResponse
    {
        $this->authorizeEdit($request->user(), $deal);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'reviewDate' => ['required', 'date', 'after_or_equal:today'],
        ], [
            'reason.required' => 'Isi alasannya.', 'reviewDate.required' => 'Isi tanggal tinjau ulang.',
            'reviewDate.after_or_equal' => 'Tanggal tinjau ulang ga boleh lewat dari hari ini.',
        ]);

        return $this->respond($request->user(), $this->deals->hold($request->user(), $deal, $data['reason'], Carbon::parse($data['reviewDate'])));
    }

    public function reopen(Request $request, Deal $deal): JsonResponse
    {
        $me = $request->user();
        $this->authorizeView($me, $deal);
        abort_unless($this->access->canReopenDeal($me, $deal), 403, $deal->status->value === 'Won'
            ? 'Deal Won cuma bisa dibuka lagi oleh Super Admin atau Kepala Department terkait.'
            : 'Cuma PIC atau atasannya yang bisa buka lagi.');
        $data = $request->validate([
            'toStage' => ['required', 'integer'],
            'reason' => [$deal->status->value === 'Won' ? 'required' : 'nullable', 'string', 'max:255'],
        ], ['reason.required' => 'Alasan wajib buat buka lagi deal Won.']);

        return $this->respond($me, $this->deals->reopen($me, $deal, Stage::findOrFail($data['toStage']), $data['reason'] ?? null));
    }

    private function authorizeView(User $me, Deal $deal): void
    {
        abort_unless($this->access->can($me, 'deal', AccessLevel::R), 403, 'Akun ini ga punya akses ke deal.');
        abort_unless($this->access->canViewDeal($me, $deal), 403, 'Deal ini milik divisi lain.');
    }

    private function authorizeEdit(User $me, Deal $deal): void
    {
        $this->authorizeView($me, $deal);
        abort_unless($this->access->canEditDeal($me, $deal), 403, 'Kamu ga bisa mengubah deal ini.');
    }

    private function respond(User $me, Deal $deal, int $status = 200): JsonResponse
    {
        return response()->json(['data' => FrontShape::deal($deal->fresh('histories'), $me, $this->access)], $status);
    }
}
