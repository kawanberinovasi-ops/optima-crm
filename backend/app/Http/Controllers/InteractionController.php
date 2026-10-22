<?php

namespace App\Http\Controllers;

use App\Enums\AccessLevel;
use App\Enums\DealStatus;
use App\Enums\Role;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Interaction;
use App\Models\Stage;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Deals;
use App\Support\Codes;
use App\Support\FrontShape;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Interaksi dan follow-up (SRS 4.2, FR-INT-01..04). Catatan tidak bisa dihapus, hanya dibatalkan dengan alasan.
 * Catat interaksi bisa sekaligus membuat deal baru atau memindah stage deal, dalam satu transaksi.
 */
class InteractionController extends Controller
{
    public const ENTITY = 'Interaksi';

    public function __construct(private Access $access, private Audit $audit, private Deals $deals) {}

    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless($this->access->can($me, 'interaksi', AccessLevel::R), 403, 'Akun ini ga punya akses ke interaksi.');
        $records = $this->access->scopeClientPartners(ClientPartner::query(), $me)->select('id');

        return response()->json(['data' => Interaction::whereIn('client_partner_id', $records)->orderBy('occurred_on')->orderBy('id')->get()
            ->map(fn (Interaction $i) => FrontShape::interaction($i))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless($this->access->can($me, 'interaksi', AccessLevel::C), 403, 'Kamu ga punya izin mencatat interaksi.');
        $data = $request->validate([
            'recordId' => ['required', 'integer', Rule::exists('client_partners', 'id')->whereNull('archived_at')],
            'type' => ['required', 'string', 'max:50'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'result' => ['required', 'string', 'max:5000'],
            'next' => ['nullable', 'string', 'max:1000'],
            'fuDate' => ['nullable', 'date', 'after_or_equal:date'],
            'dealId' => ['nullable', 'string'],
            'dealTitle' => ['required_if:dealId,new', 'nullable', 'string', 'max:200'],
            'lineId' => ['nullable', 'integer', Rule::exists('pipelines', 'id')],
            'toStage' => ['nullable', 'integer'],
        ], [
            'recordId.required' => 'Cari lalu pilih Client & Partner.', 'result.required' => 'Isi hasil pembicaraan.',
            'fuDate.after_or_equal' => 'Follow-up ga bisa sebelum tanggal interaksi.', 'dealTitle.required_if' => 'Kasih judul deal-nya.',
            'date.before_or_equal' => 'Tanggal interaksi ga boleh di masa depan.',
        ]);

        $record = ClientPartner::findOrFail($data['recordId']);
        abort_unless($this->access->canViewRecord($me, $record), 403, 'Kamu ga punya akses ke data ini.');

        [$interaction, $deal, $needsClosing] = DB::transaction(function () use ($me, $record, $data) {
            $deal = null;
            $needsClosing = false;
            $stageNote = '';
            $dealId = $data['dealId'] ?? '';

            if ($dealId === 'new') {
                abort_unless($this->access->can($me, 'deal', AccessLevel::C), 403, 'Kamu ga punya izin menambah deal.');
                $pipeline = $this->deals->pipelineFor($record->department_id);
                $stage = isset($data['toStage']) ? Stage::find($data['toStage']) : null;
                $owner = $me->department_id === $record->department_id && $me->role !== Role::Viewer ? $me : $record->pic;
                $this->deals->assertOwner($owner, $record, 'dealTitle');
                $deal = $this->deals->create($me, $record, $pipeline, $stage, [
                    'title' => trim($data['dealTitle']), 'owner_id' => $owner->id, 'target_closing' => today()->addDays(30),
                ], 'Deal dibuat dari catat interaksi');
                $stageNote = 'Deal baru di '.$deal->stage->name;
            } elseif ($dealId !== '') {
                $deal = Deal::where('client_partner_id', $record->id)->findOrFail((int) $dealId);
                abort_unless($this->access->can($me, 'deal', AccessLevel::R), 403, 'Akun ini ga punya akses ke deal.');
                $to = isset($data['toStage']) ? Stage::where('pipeline_id', $deal->pipeline_id)->find($data['toStage']) : null;
                if ($to && $to->id !== $deal->stage_id && $this->access->canEditDeal($me, $deal)) {
                    if ($to->is_closing) {
                        // Closing butuh nilai dan tanggal: frontend membuka form Closing setelah ini.
                        $needsClosing = true;
                        $stageNote = 'Masuk Closing';
                    } else {
                        $wasHold = $deal->status === DealStatus::OnHold;
                        $deal = $this->deals->move($me, $deal, $to, $wasHold ? 'Dibuka lagi lewat catat interaksi' : 'Lewat catat interaksi');
                        $stageNote = 'Masuk '.$to->name;
                    }
                } else {
                    $stageNote = 'Tetap di '.$deal->stage->name;
                }
            }

            [$id, $code] = Codes::next('interactions', 'INT');
            $interaction = new Interaction;
            $interaction->forceFill([
                'id' => $id, 'code' => $code, 'client_partner_id' => $record->id, 'deal_id' => $deal?->id, 'user_id' => $me->id,
                'occurred_on' => $data['date'], 'type' => $data['type'], 'result' => $data['result'],
                'next_action' => collect([$stageNote, $data['next'] ?? null])->filter()->implode(': ') ?: null,
                'followup_on' => $data['fuDate'] ?? null,
            ])->save();
            $this->audit->log('Tambah', self::ENTITY, $interaction->id, $interaction->type.' dengan '.$record->name, null, $interaction->getAttributes());
            if ($record->pic_id !== $me->id) {
                Notify::send($record->pic_id, $me->name.' mencatat '.strtolower($interaction->type).' dengan '.$record->name, 'rec:'.$record->id);
            }

            return [$interaction, $deal, $needsClosing];
        });

        return response()->json([
            'data' => FrontShape::interaction($interaction),
            'deal' => $deal ? FrontShape::deal($deal->fresh('histories'), $me, $this->access) : null,
            'needsClosing' => $needsClosing,
        ], 201);
    }

    /** Tandai follow-up selesai. */
    public function done(Request $request, Interaction $interaction): JsonResponse
    {
        $me = $request->user();
        abort_unless($this->access->canViewInteraction($me, $interaction) && $this->access->can($me, 'interaksi', AccessLevel::C), 403, 'Kamu ga bisa menandai follow-up ini.');
        abort_if($interaction->cancelled_at !== null, 409, 'Catatan ini sudah dibatalkan.');

        if ($interaction->followup_done_at === null) {
            $interaction->update(['followup_done_at' => now(), 'followup_done_by' => $me->id]);
            $this->audit->log('Ubah', self::ENTITY, $interaction->id, 'Follow-up '.$interaction->clientPartner->name.' ditandai selesai',
                ['followup_done_at' => null], ['followup_done_at' => $interaction->followup_done_at->toIso8601String()]);
        }

        return response()->json(['data' => FrontShape::interaction($interaction)]);
    }

    /** Membatalkan catatan yang salah (FR-INT-04): tidak dihapus, tetap tampil dengan tanda batal. */
    public function cancel(Request $request, Interaction $interaction): JsonResponse
    {
        $me = $request->user();
        abort_unless($this->access->canViewInteraction($me, $interaction), 403, 'Kamu ga punya akses ke catatan ini.');
        abort_unless($this->access->canCancelInteraction($me, $interaction), 403, 'Cuma pencatatnya atau pengelola interaksi yang bisa membatalkan.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Isi alasan pembatalan.']);

        $interaction->update(['cancelled_at' => now(), 'cancelled_by' => $me->id, 'cancel_reason' => $data['reason']]);
        $this->audit->log('Batal', self::ENTITY, $interaction->id,
            $interaction->type.' '.$interaction->occurred_on->toDateString().' dengan '.$interaction->clientPartner->name.': '.$data['reason'],
            ['cancelled_at' => null], ['cancelled_at' => $interaction->cancelled_at->toIso8601String(), 'cancel_reason' => $data['reason']]);

        return response()->json(['data' => FrontShape::interaction($interaction)]);
    }
}
