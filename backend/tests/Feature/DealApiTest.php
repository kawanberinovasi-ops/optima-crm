<?php

namespace Tests\Feature;

use App\Enums\Category;
use App\Enums\DealStatus;
use App\Enums\Role;
use App\Models\AccessOverride;
use App\Models\AuditLog;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pipeline deal (SRS 4.3, FR-DEAL-01..06) dan pengaturan stage. */
class DealApiTest extends TestCase
{
    use RefreshDatabase;

    private Pipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        // Pengguna bawaan tes ada di Customer Service, yang memakai pipeline Sales.
        $this->pipeline = Pipeline::with('stages')->where('name', 'Sales')->firstOrFail();
    }

    private function user(string $dept = 'Customer Service', Role $role = Role::Staf): User
    {
        return User::factory()->inDepartment($dept)->role($role)->create();
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['login_at' => now()->timestamp]);
    }

    private function stage(string $name): int
    {
        return $this->pipeline->stages->firstWhere('name', $name)->id;
    }

    private function newDeal(User $owner, array $extra = []): array
    {
        static $n = 0;
        $record = ClientPartner::create(['code' => 'CP-D'.++$n, 'name' => 'Klien '.$n, 'category' => Category::Client, 'pic_id' => $owner->id, 'department_id' => $owner->department_id]);

        return $this->as($owner)->postJson('/api/deals', $extra + [
            'title' => 'Website '.$n, 'recordId' => $record->id, 'lineId' => $this->pipeline->id, 'offer' => 50000000, 'target' => now()->addMonth()->toDateString(),
        ])->assertCreated()->json('data');
    }

    public function test_create_deal_starts_in_first_stage_with_history_and_audit(): void
    {
        $deal = $this->newDeal($this->user());

        $this->assertSame((string) $this->stage('Lead'), $deal['stageId']);
        $this->assertSame('Open', $deal['status']);
        $this->assertSame(50000000, $deal['offer']);
        $this->assertStringStartsWith('DL-', $deal['code']);
        $this->assertSame('Deal dibuat', $deal['history'][0]['note']);
        $this->assertTrue(AuditLog::where(['entity_type' => 'Deal', 'entity_id' => $deal['id'], 'action' => 'Tambah'])->exists());
    }

    public function test_deals_of_other_people_and_unused_pipelines_are_hidden(): void
    {
        $this->newDeal($this->user());
        $dc = $this->user('Digital Campaign');
        $colleague = $this->user();

        $this->as($colleague)->getJson('/api/deals')->assertOk()->assertJsonPath('data', [], 'rekan sedivisi tidak melihat deal yang bukan PIC-nya');
        $boot = $this->as($dc)->getJson('/api/bootstrap')->assertOk();
        $this->assertSame([], $boot->json('deals'));
        $this->assertSame(['Campaign'], array_column($boot->json('lines'), 'name'), 'Digital Campaign memakai pipeline Campaign');
        $this->assertSame(['Sales'], array_column($this->as($colleague)->getJson('/api/bootstrap')->json('lines'), 'name'));
        $this->assertCount(2, $this->as($this->user('Executive', Role::SuperAdmin))->getJson('/api/bootstrap')->json('lines'), 'Super Admin memakai semua pipeline');

        $noDeal = $this->user('Business Operation');
        AccessOverride::create(['user_id' => $noDeal->id, 'entity' => 'deal', 'level' => 'N']);
        $this->as($noDeal)->getJson('/api/deals')->assertForbidden();
        $this->as($noDeal)->postJson('/api/deals', ['title' => 'X', 'recordId' => 1])->assertForbidden();
    }

    public function test_super_admin_adds_or_revokes_pipelines_per_user(): void
    {
        $admin = $this->user('Executive', Role::SuperAdmin);
        $campaign = Pipeline::where('name', 'Campaign')->first();
        $staff = $this->user();
        $dcStaff = $this->user('Digital Campaign');

        $this->as($this->user('Customer Service', Role::KepalaDepartment))->putJson('/api/pipelines/'.$campaign->id.'/users', ['userId' => $staff->id, 'allowed' => true])->assertForbidden();
        $this->as($admin)->putJson('/api/pipelines/'.$campaign->id.'/users', ['userId' => $staff->id, 'allowed' => true])->assertOk();
        $this->as($admin)->putJson('/api/pipelines/'.$campaign->id.'/users', ['userId' => $dcStaff->id, 'allowed' => false])->assertOk();
        $this->assertSame(['Sales', 'Campaign'], array_column($this->as($staff)->getJson('/api/bootstrap')->json('lines'), 'name'));
        $this->assertSame([], $this->as($dcStaff)->getJson('/api/bootstrap')->json('lines'), 'pipeline dicabut: menu Pipeline hilang');

        // Divisi yang memakai pipeline diatur Super Admin.
        $this->as($admin)->putJson('/api/pipelines/'.$campaign->id.'/departments', ['deptIds' => [$staff->department_id]])->assertOk();
        $this->as($admin)->putJson('/api/pipelines/'.$campaign->id.'/users', ['userId' => $staff->id, 'allowed' => null])->assertOk();
        $this->assertSame(['Sales', 'Campaign'], array_column($this->as($staff)->getJson('/api/bootstrap')->json('lines'), 'name'), 'lewat divisinya');
    }

    public function test_new_deal_owner_is_record_pic_and_pipeline_is_one_the_pic_uses(): void
    {
        $pic = $this->user('Digital Campaign');
        $record = ClientPartner::create(['code' => 'CP-DC', 'name' => 'Brand Kopi', 'category' => Category::Lead, 'pic_id' => $pic->id, 'department_id' => $pic->department_id]);

        $deal = $this->as($pic)->postJson('/api/deals', ['title' => 'Kampanye', 'recordId' => $record->id, 'lineId' => $this->pipeline->id])->assertCreated()->json('data');
        $this->assertSame('Campaign', Pipeline::find($deal['lineId'])->name, 'Sales tidak dipakai PIC ini, jadi masuk Campaign');
        $this->assertSame('Brief', Pipeline::find($deal['lineId'])->stages->first()->name);
        $this->assertSame((string) $pic->id, $deal['ownerId'], 'PIC deal = PIC relasi');

        $admin = $this->user('Executive', Role::SuperAdmin);
        $byAdmin = $this->as($admin)->postJson('/api/deals', ['title' => 'Lain', 'recordId' => $record->id, 'ownerId' => $admin->id])->assertCreated()->json('data');
        $this->assertSame((string) $pic->id, $byAdmin['ownerId'], 'ownerId dari klien diabaikan');
    }

    public function test_deals_are_sent_only_to_their_pic_and_super_admin(): void
    {
        $deal = $this->newDeal($this->user('Customer Service', Role::KepalaDepartment));

        $this->assertNull(collect($this->as($this->user('Customer Service', Role::Koordinator))->getJson('/api/deals')->json('data'))->firstWhere('id', $deal['id']), 'rekan sedivisi tidak menerima deal');
        $row = collect($this->as($this->user('Executive', Role::SuperAdmin))->getJson('/api/deals')->json('data'))->firstWhere('id', $deal['id']);
        $this->assertSame(50000000, $row['offer'], 'Super Admin melihat semua deal dan nilainya');
    }

    public function test_move_stage_and_history(): void
    {
        $owner = $this->user();
        $deal = $this->newDeal($owner);

        $moved = $this->as($owner)->postJson('/api/deals/'.$deal['id'].'/move', ['stageId' => $this->stage('Meeting')])->assertOk()->json('data');

        $this->assertSame((string) $this->stage('Meeting'), $moved['stageId']);
        $this->assertSame(['Lead', 'Meeting'], [end($moved['history'])['from'], end($moved['history'])['to']]);
    }

    public function test_moving_into_closing_directly_is_refused(): void
    {
        $owner = $this->user();
        $deal = $this->newDeal($owner);

        $this->as($owner)->postJson('/api/deals/'.$deal['id'].'/move', ['stageId' => $this->stage('Closing')])
            ->assertUnprocessable()->assertJsonValidationErrors('stageId');
    }

    public function test_only_the_pic_or_super_admin_can_change_a_deal(): void
    {
        $deal = $this->newDeal($this->user());

        $this->as($this->user('Customer Service', Role::KepalaDepartment))->postJson('/api/deals/'.$deal['id'].'/move', ['stageId' => $this->stage('Meeting')])->assertForbidden();
        $this->as($this->user('Executive', Role::SuperAdmin))->postJson('/api/deals/'.$deal['id'].'/move', ['stageId' => $this->stage('Meeting')])->assertOk();
    }

    public function test_won_requires_value_and_date_then_moves_to_closing(): void
    {
        $owner = $this->user();
        $deal = $this->newDeal($owner);

        $this->as($owner)->postJson('/api/deals/'.$deal['id'].'/won', [])->assertUnprocessable()->assertJsonValidationErrors(['value', 'closedAt']);
        $won = $this->as($owner)->postJson('/api/deals/'.$deal['id'].'/won', ['value' => 45000000, 'closedAt' => today()->toDateString()])->assertOk()->json('data');

        $this->assertSame('Won', $won['status']);
        $this->assertSame((string) $this->stage('Closing'), $won['stageId']);
        $this->assertSame(45000000, $won['value']);
        $this->assertSame(today()->toDateString(), $won['closedAt']);
    }

    public function test_lost_requires_reason_from_list_and_keeps_last_stage(): void
    {
        $owner = $this->user();
        $deal = $this->newDeal($owner);
        $this->as($owner)->postJson('/api/deals/'.$deal['id'].'/move', ['stageId' => $this->stage('Proposal')]);

        $this->as($owner)->postJson('/api/deals/'.$deal['id'].'/lost', ['lostReason' => 'Asal-asalan'])->assertUnprocessable();
        $lost = $this->as($owner)->postJson('/api/deals/'.$deal['id'].'/lost', ['lostReason' => 'Pilih vendor lain', 'note' => 'Harga beda 10%'])->assertOk()->json('data');

        $this->assertSame('Lost', $lost['status']);
        $this->assertSame((string) $this->stage('Proposal'), $lost['stageId'], 'stage terakhir tetap tersimpan');
        $this->assertSame('Pilih vendor lain', $lost['lostReason']);
        $this->assertSame('Harga beda 10%', $lost['reason']);
    }

    public function test_on_hold_requires_reason_and_future_review_date(): void
    {
        $owner = $this->user();
        $deal = $this->newDeal($owner);

        $this->as($owner)->postJson('/api/deals/'.$deal['id'].'/hold', ['reason' => 'Anggaran', 'reviewDate' => today()->subDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('reviewDate');
        $held = $this->as($owner)->postJson('/api/deals/'.$deal['id'].'/hold', ['reason' => 'Menunggu anggaran', 'reviewDate' => today()->addWeek()->toDateString()])
            ->assertOk()->json('data');

        $this->assertSame('On Hold', $held['status']);
        $this->assertSame('Menunggu anggaran', $held['reason']);
    }

    public function test_reopen_rules_follow_srs(): void
    {
        $owner = $this->user();
        $deal = $this->newDeal($owner);
        $this->as($owner)->postJson('/api/deals/'.$deal['id'].'/won', ['value' => 10000000, 'closedAt' => today()->toDateString()]);

        $this->as($owner)->postJson('/api/deals/'.$deal['id'].'/reopen', ['toStage' => $this->stage('Negotiation'), 'reason' => 'Salah klik'])->assertForbidden();
        $this->as($this->user('Customer Service', Role::KepalaDepartment))->postJson('/api/deals/'.$deal['id'].'/reopen', ['toStage' => $this->stage('Negotiation'), 'reason' => 'x'])
            ->assertForbidden();

        $head = $this->user('Executive', Role::SuperAdmin);
        $this->as($head)->postJson('/api/deals/'.$deal['id'].'/reopen', ['toStage' => $this->stage('Negotiation')])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $open = $this->as($head)->postJson('/api/deals/'.$deal['id'].'/reopen', ['toStage' => $this->stage('Negotiation'), 'reason' => 'Klien minta revisi'])
            ->assertOk()->json('data');

        $this->assertSame('Open', $open['status']);
        $this->assertSame('', $open['value']);
        $this->assertSame('', $open['closedAt']);
        $this->assertStringContainsString('Dibuka kembali dari Won', end($open['history'])['note']);
    }

    public function test_pipeline_configuration_only_by_super_admin(): void
    {
        $head = $this->user('Executive', Role::SuperAdmin);

        $this->as($this->user())->postJson('/api/pipelines', ['name' => 'Event'])->assertForbidden();
        $this->as($this->user('Customer Service', Role::KepalaDepartment))->postJson('/api/pipelines', ['name' => 'Event'])->assertForbidden();
        $this->as($this->user('Customer Service', Role::KepalaDepartment))->postJson('/api/pipelines/'.$this->pipeline->id.'/stages', ['name' => 'X'])->assertForbidden();

        $line = $this->as($head)->postJson('/api/pipelines', ['name' => 'Event Management', 'stages' => "Brief received\nConcept\nClosing"])
            ->assertCreated()->json('data');
        $this->assertSame(['Brief received', 'Concept', 'Closing'], array_column($line['stages'], 'name'));

        $line = $this->as($head)->postJson('/api/pipelines/'.$line['id'].'/stages', ['name' => 'Budgeting', 'after' => $line['stages'][1]['id']])->assertOk()->json('data');
        $this->assertSame(['Brief received', 'Concept', 'Budgeting', 'Closing'], array_column($line['stages'], 'name'));

        $this->as($head)->postJson('/api/pipelines/'.$line['id'].'/stages', ['name' => 'concept'])->assertUnprocessable();
        $line = $this->as($head)->patchJson('/api/stages/'.$line['stages'][2]['id'], ['name' => 'Anggaran'])->assertOk()->json('data');
        $this->assertSame('Anggaran', $line['stages'][2]['name']);

        $this->as($head)->deleteJson('/api/stages/'.$line['stages'][3]['id'])->assertUnprocessable();
        $line = $this->as($head)->deleteJson('/api/stages/'.$line['stages'][2]['id'])->assertOk()->json('data');
        $this->assertSame(['Brief received', 'Concept', 'Closing'], array_column($line['stages'], 'name'));
    }

    public function test_stage_with_deals_cannot_be_deleted(): void
    {
        $this->newDeal($this->user('Customer Service', Role::KepalaDepartment));
        $admin = $this->user('Executive', Role::SuperAdmin);

        $this->as($admin)->deleteJson('/api/stages/'.$this->stage('Lead'))->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'Masih ada 1 deal'));
        $this->assertSame(DealStatus::Open, Deal::first()->status);
    }
}
