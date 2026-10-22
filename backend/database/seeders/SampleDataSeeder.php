<?php

namespace Database\Seeders;

use App\Enums\DealStatus;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Department;
use App\Models\Interaction;
use App\Models\Pipeline;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Akun uji per role dan data contoh buatan tim (SRS 8.3), sama dengan isi prototipe.
 * Tanggal di sample.json berupa selisih hari dari hari ini, jadi data selalu segar setiap kali di-seed.
 */
class SampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $data = SampleData::load();
        $today = Carbon::today();
        $day = fn (?int $offset) => $offset === null ? null : $today->copy()->addDays($offset);
        $dept = Department::pluck('id', 'name');

        $users = [];
        foreach ($data['users'] as $u) {
            $users[$u['email']] = User::updateOrCreate(['email' => $u['email']], [
                'name' => $u['name'],
                'department_id' => $dept[$u['department']],
                'role' => $u['role'],
                'active' => $u['active'],
            ])->id;
        }

        $records = [];
        foreach ($data['client_partners'] as $r) {
            $cp = ClientPartner::updateOrCreate(['code' => $r['code']], [
                'name' => $r['name'],
                'category' => $r['category'],
                'service_id' => $r['service'] ? Service::where('name', $r['service'])->value('id') : null,
                'converted_at' => $day($r['converted_days'] ?? null),
                'segment' => $r['segment'],
                'pic_id' => $users[$r['pic']],
                'department_id' => $dept[$r['department']],
                'source' => $r['source'],
                'city' => $r['city'],
                'note' => $r['note'],
                'rate_card' => $r['rate_card'],
                'created_by' => $users[$r['created_by'] ?? $r['pic']],
            ]);
            $cp->timestamps = false;
            $cp->forceFill(['created_at' => $day($r['created_days']), 'updated_at' => $day($r['updated_days'])])->save();
            if (! $cp->contacts()->exists()) {
                $cp->contacts()->createMany($r['contacts']);
            }
            $records[$r['code']] = $cp->id;
        }

        $pipelines = Pipeline::with('stages')->get()->keyBy('name');
        foreach ($data['deals'] as $d) {
            $pipeline = $pipelines[$d['pipeline']];
            $stage = $pipeline->stages->firstWhere('name', $d['stage']);
            if (isset($d['history_month'])) {
                // Deal riwayat closing untuk grafik 12 bulan: tanggal di bulan tertentu, bukan selisih hari.
                $closed = $today->copy()->startOfMonth()->subMonthsNoOverflow($d['history_month']['months_ago'])->day($d['history_month']['day']);
                [$created, $target, $closedAt, $review] = [$closed, $closed, $closed, null];
            } else {
                [$created, $target, $closedAt, $review] = [$day($d['created_days']), $day($d['target_days']), $day($d['closed_days']), $day($d['review_days'])];
            }
            $deal = Deal::updateOrCreate(['code' => $d['code']], [
                'title' => $d['title'],
                'service' => $d['service'],
                'client_partner_id' => $records[$d['client_partner']],
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'status' => $d['status'],
                'owner_id' => $users[$d['owner']],
                'offer_value' => $d['offer_value'],
                'deal_value' => $d['deal_value'],
                'target_closing' => $target,
                'closed_at' => $closedAt,
                'lost_reason' => $d['lost_reason'],
                'hold_reason' => $d['hold_reason'],
                'review_date' => $review,
                'created_by' => $users[$d['owner']],
            ]);
            $deal->timestamps = false;
            $deal->forceFill(['created_at' => $created->copy()->setTime(9, 0), 'updated_at' => ($closedAt ?? $created)->copy()->setTime(15, 0)])->save();

            if (! $deal->histories()->exists()) {
                $this->seedHistory($deal, $pipeline, $stage, $created, $closedAt);
            }
        }

        foreach ($data['interactions'] as $x) {
            Interaction::updateOrCreate(['code' => $x['code']], [
                'client_partner_id' => $records[$x['client_partner']],
                'user_id' => $users[$x['user']],
                'occurred_on' => $day($x['occurred_days']),
                'type' => $x['type'],
                'result' => $x['result'],
                'next_action' => $x['next_action'],
                'followup_on' => $day($x['followup_days']),
            ]);
        }
    }

    /** Riwayat awal yang sama dengan prototipe: dibuat, lalu Won, Lost, atau On Hold bila ada. */
    private function seedHistory(Deal $deal, Pipeline $pipeline, $stage, Carbon $created, ?Carbon $closedAt): void
    {
        $base = ['user_id' => $deal->owner_id];
        $start = $deal->status === DealStatus::Won ? $pipeline->stages[3] : $stage;
        $deal->histories()->create($base + [
            'to_stage_id' => $start->id, 'to_stage_name' => $start->name, 'status' => DealStatus::Open,
            'note' => 'Deal dibuat', 'created_at' => $created->copy()->setTime(9, 0),
        ]);

        $end = match ($deal->status) {
            DealStatus::Won => ['from' => $pipeline->stages[4], 'to' => $stage, 'note' => 'Closing', 'at' => $closedAt->copy()->setTime(15, 0)],
            DealStatus::Lost => ['from' => $stage, 'to' => $stage, 'note' => $deal->lost_reason, 'at' => $closedAt->copy()->setTime(15, 0)],
            DealStatus::OnHold => ['from' => $stage, 'to' => $stage, 'note' => $deal->hold_reason, 'at' => Carbon::today()->subDays(3)->setTime(10, 0)],
            default => null,
        };
        if ($end) {
            $deal->histories()->create($base + [
                'from_stage_id' => $end['from']->id, 'from_stage_name' => $end['from']->name,
                'to_stage_id' => $end['to']->id, 'to_stage_name' => $end['to']->name,
                'status' => $deal->status, 'note' => $end['note'], 'created_at' => $end['at'],
            ]);
        }
    }
}
