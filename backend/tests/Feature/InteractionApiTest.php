<?php

namespace Tests\Feature;

use App\Enums\Category;
use App\Enums\Role;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Interaction;
use App\Models\Pipeline;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Interaksi dan follow-up (SRS 4.2, FR-INT-01..04). */
class InteractionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function user(string $dept = 'Customer Service', Role $role = Role::Staf): User
    {
        return User::factory()->inDepartment($dept)->role($role)->create();
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['login_at' => now()->timestamp]);
    }

    private function record(User $pic, Category $category = Category::Client): ClientPartner
    {
        static $n = 0;

        return ClientPartner::create(['code' => 'CP-I'.++$n, 'name' => 'Relasi '.$n, 'category' => $category, 'pic_id' => $pic->id, 'department_id' => $pic->department_id]);
    }

    private function log(User $me, ClientPartner $record, array $extra = []): array
    {
        return $this->as($me)->postJson('/api/interactions', $extra + [
            'recordId' => $record->id, 'type' => 'Telepon', 'date' => today()->toDateString(), 'result' => 'Bahas kebutuhan', 'next' => 'Kirim proposal', 'fuDate' => today()->addDays(2)->toDateString(),
        ])->assertCreated()->json();
    }

    public function test_log_interaction_with_followup_and_notify_pic(): void
    {
        // Hanya PIC dan Super Admin yang melihat data; Super Admin yang mencatat memberi tahu PIC-nya.
        $pic = $this->user();
        $me = $this->user('Executive', Role::SuperAdmin);
        $record = $this->record($pic);
        $this->as($this->user())->postJson('/api/interactions', ['recordId' => $record->id, 'type' => 'Telepon', 'date' => today()->toDateString(), 'result' => 'x'])->assertForbidden();

        $res = $this->log($me, $record);

        $this->assertSame('Kirim proposal', $res['data']['next']);
        $this->assertSame(today()->addDays(2)->toDateString(), $res['data']['fuDate']);
        $this->assertStringStartsWith('INT-', $res['data']['code']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $pic->id]);
    }

    public function test_followup_cannot_be_before_interaction_date(): void
    {
        $me = $this->user();
        $this->as($me)->postJson('/api/interactions', [
            'recordId' => $this->record($me)->id, 'type' => 'Telepon', 'date' => today()->toDateString(), 'result' => 'x', 'fuDate' => today()->subDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('fuDate');
    }

    public function test_log_can_create_a_new_deal(): void
    {
        $me = $this->user();
        $res = $this->log($me, $this->record($me), ['dealId' => 'new', 'dealTitle' => 'Gathering 2027', 'lineId' => Pipeline::first()->id]);

        $this->assertSame('Gathering 2027', $res['deal']['title']);
        $this->assertSame($res['deal']['id'], $res['data']['dealId']);
        $this->assertStringStartsWith('Deal baru di Lead', $res['data']['next']);
    }

    public function test_log_can_move_deal_stage_or_ask_for_closing(): void
    {
        $me = $this->user();
        $record = $this->record($me);
        $first = $this->log($me, $record, ['dealId' => 'new', 'dealTitle' => 'Website', 'lineId' => Pipeline::where('name', 'Campaign')->value('id')]);
        $stages = Pipeline::find($first['deal']['lineId'])->stages;
        $this->assertSame('Sales', Pipeline::find($first['deal']['lineId'])->name, 'deal baru masuk pipeline yang dipakai PIC relasi');

        $moved = $this->log($me, $record, ['dealId' => $first['deal']['id'], 'toStage' => $stages->firstWhere('name', 'Meeting')->id]);
        $this->assertSame((string) $stages->firstWhere('name', 'Meeting')->id, $moved['deal']['stageId']);

        $closing = $this->log($me, $record, ['dealId' => $first['deal']['id'], 'toStage' => $stages->firstWhere('is_closing', true)->id]);
        $this->assertTrue($closing['needsClosing']);
        $this->assertSame('Open', Deal::find($first['deal']['id'])->status->value, 'Closing tetap lewat form Won');
    }

    public function test_mark_followup_done(): void
    {
        $me = $this->user();
        $res = $this->log($me, $this->record($me));

        $done = $this->as($me)->postJson('/api/interactions/'.$res['data']['id'].'/done')->assertOk()->json('data');
        $this->assertTrue($done['done']);
        $this->assertSame(0, Interaction::openFollowups()->count());
    }

    public function test_interaction_is_cancelled_with_reason_never_deleted(): void
    {
        // CampusNet berlevel C untuk interaksi: staf lain tidak bisa membatalkan catatan orang lain.
        $me = $this->user('CampusNet');
        $res = $this->log($me, $this->record($me));
        $id = $res['data']['id'];

        $this->as($this->user('CampusNet'))->postJson('/api/interactions/'.$id.'/cancel', ['reason' => 'x'])->assertForbidden();
        $this->as($me)->postJson('/api/interactions/'.$id.'/cancel', [])->assertUnprocessable();
        $cancelled = $this->as($me)->postJson('/api/interactions/'.$id.'/cancel', ['reason' => 'Salah pilih client'])->assertOk()->json('data');

        $this->assertTrue($cancelled['cancelled']);
        $this->assertSame('Salah pilih client', $cancelled['cancelReason']);
        $this->assertDatabaseHas('interactions', ['id' => $id]);
        $this->assertSame(0, Interaction::openFollowups()->count(), 'follow-up keluar dari antrian');
    }

    public function test_interactions_of_hidden_records_are_not_sent(): void
    {
        $owner = $this->user('Customer Service');
        $this->log($owner, $this->record($owner, Category::Lead));
        $viewer = $this->user('Customer Service', Role::KepalaDepartment); // bukan PIC-nya

        $this->as($viewer)->getJson('/api/interactions')->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->user('Business Operation', Role::Viewer))->postJson('/api/interactions', ['recordId' => 1])->assertForbidden();
    }
}
