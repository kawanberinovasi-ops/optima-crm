<?php

namespace Tests\Feature;

use App\Enums\Category;
use App\Enums\Role;
use App\Models\AccessOverride;
use App\Models\ClientPartner;
use App\Models\Department;
use App\Models\Pipeline;
use App\Models\Service;
use App\Models\TrashEntry;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OpenAccessMatrix;
use Tests\TestCase;

/** Data terhapus (pratinjau klien 27 Okt 2026): hapus bisa dipulihkan, hak dan syarat diperiksa di server. */
class TrashTest extends TestCase
{
    use OpenAccessMatrix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->openAccessMatrix();
    }

    private function user(string $dept = 'Customer Service', Role $role = Role::Staf): User
    {
        return User::factory()->inDepartment($dept)->role($role)->create();
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['login_at' => now()->timestamp]);
    }

    private function record(User $pic, array $attrs = []): ClientPartner
    {
        static $n = 0;

        return ClientPartner::create($attrs + ['code' => 'CP-T'.++$n, 'name' => 'Relasi '.$n, 'category' => Category::Lead, 'pic_id' => $pic->id, 'department_id' => $pic->department_id]);
    }

    private function entry(): TrashEntry
    {
        return TrashEntry::latest('id')->firstOrFail();
    }

    public function test_record_with_its_deals_goes_to_trash_and_comes_back(): void
    {
        $head = $this->user('Customer Service', Role::KepalaDepartment);
        $record = $this->record($head);
        $deal = $this->as($head)->postJson('/api/deals', ['title' => 'Website', 'recordId' => $record->id])->assertCreated()->json('data');

        $this->as($head)->deleteJson('/api/trash/record/'.$record->id)->assertCreated();
        $this->assertSoftDeleted($record);
        $this->as($head)->getJson('/api/client-partners/'.$record->id)->assertNotFound();
        $this->assertNull(collect($this->as($head)->getJson('/api/deals')->json('data'))->firstWhere('id', $deal['id']), 'deal ikut tersembunyi');
        $this->assertSame('record', $this->as($head)->getJson('/api/bootstrap')->json('trash.0.kind'));

        $this->as($head)->postJson('/api/trash/'.$this->entry()->id.'/restore')->assertOk();
        $this->assertNotSoftDeleted($record);
        $this->assertNotNull(collect($this->as($head)->getJson('/api/deals')->json('data'))->firstWhere('id', $deal['id']));
        $this->assertSame(0, TrashEntry::count());
    }

    public function test_record_delete_needs_level_m_and_being_pic(): void
    {
        $staff = $this->user('CampusNet');
        $own = $this->record($staff);

        $this->as($staff)->deleteJson('/api/trash/record/'.$own->id)->assertForbidden();
        $this->as($this->user('Customer Service', Role::KepalaDepartment))->deleteJson('/api/trash/record/'.$own->id)->assertForbidden();
        $this->as($this->user('Executive'))->deleteJson('/api/trash/record/'.$own->id)->assertForbidden();
        $this->as($this->user('Executive', Role::SuperAdmin))->deleteJson('/api/trash/record/'.$own->id)->assertCreated();
        $this->assertSame([], $this->as($staff)->getJson('/api/bootstrap')->json('trash'), 'yang tidak berhak tidak melihat entrinya');
    }

    public function test_line_and_stage_follow_pipeline_rights_and_blocks(): void
    {
        $line = Pipeline::with('stages')->where('name', 'Customer Service')->first();
        $other = Pipeline::where('name', 'Campaign')->first();
        $this->as($this->user('CampusNet', Role::KepalaDepartment))->deleteJson('/api/trash/line/'.$other->id)->assertForbidden();
        $this->as($this->user('CampusNet', Role::KepalaDepartment))->deleteJson('/api/trash/stage/'.$line->stages->first()->id)->assertForbidden();
        $head = $this->user('Executive', Role::SuperAdmin);
        $this->as($head)->deleteJson('/api/trash/stage/'.$line->stages->last()->id)->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Closing'));

        $meeting = $line->stages->firstWhere('name', 'Meeting');
        $this->as($head)->deleteJson('/api/trash/stage/'.$meeting->id)->assertCreated();
        $this->assertSame(['Lead', 'Qualified', 'Proposal', 'Negotiation', 'Closing'], $line->stages()->pluck('name')->all());
        $this->as($head)->postJson('/api/trash/'.$this->entry()->id.'/restore')->assertOk();
        $this->assertSame(['Lead', 'Qualified', 'Meeting', 'Proposal', 'Negotiation', 'Closing'], $line->stages()->pluck('name')->all());

        $this->record($this->user('Customer Service'));
        $this->as($head)->postJson('/api/deals', ['title' => 'Portal', 'recordId' => ClientPartner::first()->id])->assertCreated();
        $this->as($head)->deleteJson('/api/trash/line/'.$line->id)->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Masih ada deal'));
        $this->as($head)->deleteJson('/api/trash/line/'.$other->id)->assertCreated();
        $this->assertSame([], $this->as($this->user('Digital Campaign'))->getJson('/api/bootstrap')->json('lines'), 'pipeline terhapus tidak dipakai lagi');
        $this->as($head)->postJson('/api/trash/'.$this->entry()->id.'/restore')->assertOk();
        $this->assertSame(['Campaign'], array_column($this->as($this->user('Digital Campaign'))->getJson('/api/bootstrap')->json('lines'), 'name'));
    }

    public function test_admin_only_kinds_and_their_blocks(): void
    {
        $admin = $this->user('Executive', Role::SuperAdmin);
        $head = $this->user('Customer Service', Role::KepalaDepartment);
        $kol = Service::where('name', 'KOL')->first();

        $this->as($head)->deleteJson('/api/trash/service/'.$kol->id)->assertForbidden();
        $this->record($head, ['service_id' => $kol->id]);
        $this->as($admin)->deleteJson('/api/trash/service/'.$kol->id)->assertStatus(422);
        $media = Service::where('name', 'Media')->first();
        $this->as($admin)->deleteJson('/api/trash/service/'.$media->id)->assertCreated();
        $this->assertNull(Service::find($media->id));
        $this->as($admin)->postJson('/api/trash/'.$this->entry()->id.'/restore')->assertOk();
        $this->assertSame('Creative & Media', Service::find($media->id)->department->name);

        // Department dengan pengguna tidak bisa; department kosong dipulihkan beserta pipeline yang dipakainya.
        $this->as($admin)->deleteJson('/api/trash/dept/'.$head->department_id)->assertStatus(422);
        $diet = Department::where('name', 'DietPartner')->first();
        Service::where('department_id', $diet->id)->update(['department_id' => null]);
        $this->as($admin)->deleteJson('/api/trash/dept/'.$diet->id)->assertCreated();
        $this->assertFalse(Pipeline::where('name', 'DietPartner')->first()->departments()->whereKey($diet->id)->exists());
        $this->as($admin)->postJson('/api/trash/'.$this->entry()->id.'/restore')->assertOk();
        $this->assertSame(['DietPartner'], $diet->pipelines()->pluck('name')->all());
        $this->assertSame('C', $diet->accessGrants()->where('entity', 'deal')->value('level'));

        $override = AccessOverride::create(['user_id' => $head->id, 'entity' => 'deal', 'level' => 'R']);
        $this->as($admin)->deleteJson('/api/trash/override/'.$override->id)->assertCreated();
        $this->as($admin)->postJson('/api/trash/'.$this->entry()->id.'/restore')->assertOk();
        $this->assertSame('R', AccessOverride::where('user_id', $head->id)->value('level'));
    }

    public function test_user_delete_rules_and_restore_as_inactive(): void
    {
        $admin = $this->user('Executive', Role::SuperAdmin);
        $pic = $this->user();
        $this->record($pic);
        $free = $this->user('CampusNet');

        $this->as($admin)->deleteJson('/api/trash/user/'.$admin->id)->assertStatus(422);
        $this->as($admin)->deleteJson('/api/trash/user/'.$pic->id)->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'PIC'));
        $this->as($admin)->deleteJson('/api/trash/user/'.$free->id)->assertCreated();
        $this->assertFalse($free->fresh()->active);
        $this->assertTrue(collect($this->as($admin)->getJson('/api/bootstrap')->json('users'))->firstWhere('id', (string) $free->id)['deleted']);
        $this->as($free->fresh())->getJson('/api/bootstrap')->assertUnauthorized();

        $this->as($admin)->postJson('/api/trash/'.$this->entry()->id.'/restore')->assertOk();
        $this->assertNull($free->fresh()->deleted_at);
        $this->assertFalse($free->fresh()->active, 'dipulihkan dalam keadaan nonaktif');
    }
}
