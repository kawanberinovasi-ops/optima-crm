<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\Department;
use App\Models\OptionList;
use App\Models\Service;
use App\Models\User;
use App\Services\Access;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\OpenAccessMatrix;
use Tests\TestCase;

/** Akses & Audit (SRS bagian 3, FR-USR, FR-AUD), pemberitahuan, dan tampilan tersimpan. */
class AdminApiTest extends TestCase
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

    private function admin(): User
    {
        return $this->user('Executive', Role::SuperAdmin);
    }

    public function test_only_super_admin_changes_admin_settings(): void
    {
        $head = $this->user('Customer Service', Role::KepalaDepartment);

        $this->as($head)->postJson('/api/users', ['name' => 'X', 'email' => 'x@contoh.test', 'dept' => 'Customer Service', 'role' => 'Staf'])->assertForbidden();
        $this->as($head)->putJson('/api/access-grants', ['dept' => 'Customer Service', 'entity' => 'deal', 'level' => 'R'])->assertForbidden();
        $this->as($head)->getJson('/api/audit-logs')->assertForbidden(); // audit log hanya Super Admin
        $this->as($this->user())->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_division_heads_manage_their_own_services_and_option_lists(): void
    {
        $head = $this->user('CampusNet', Role::KepalaDepartment);
        $staff = $this->user('CampusNet');

        // Layanan baru selalu masuk divisi sendiri, walaupun deptId lain dikirim.
        $this->as($head)->postJson('/api/services', ['name' => 'Aplikasi Sekolah', 'deptId' => Department::where('name', 'Executive')->value('id')])->assertCreated();
        $svc = Service::where('name', 'Aplikasi Sekolah')->first();
        $this->assertSame('CampusNet', $svc->department->name);
        $this->as($head)->patchJson('/api/services/'.$svc->id, ['name' => 'Aplikasi Sekolah Pintar'])->assertOk();
        $this->as($head)->patchJson('/api/services/'.$svc->id, ['deptId' => null])->assertForbidden();
        $this->as($head)->patchJson('/api/services/'.Service::where('name', 'Media')->value('id'), ['name' => 'X'])->assertForbidden();
        $this->as($staff)->postJson('/api/services', ['name' => 'Y'])->assertForbidden();
        $this->as($head)->deleteJson('/api/trash/service/'.$svc->id)->assertCreated();

        $this->as($head)->postJson('/api/option-lists', ['list' => 'partnerFields', 'value' => 'Pendidikan'])->assertOk();
        $this->as($staff)->postJson('/api/option-lists', ['list' => 'partnerFields', 'value' => 'Lain'])->assertForbidden();
    }

    public function test_register_user_then_login_is_allowed(): void
    {
        config(['optima.dev_login' => true]);
        $this->as($this->admin())->postJson('/api/users', ['name' => 'Budi Baru', 'email' => 'Budi@Contoh.test', 'dept' => 'CampusNet', 'role' => 'Staf'])
            ->assertCreated()->assertJsonPath('data.email', 'budi@contoh.test');
        $this->as($this->admin())->postJson('/api/users', ['name' => 'Lagi', 'email' => 'budi@contoh.test', 'dept' => 'CampusNet', 'role' => 'Staf'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        auth()->logout();
        $this->postJson('/api/auth/dev-login', ['email' => 'budi@contoh.test'])->assertOk();
    }

    public function test_deactivating_user_ends_their_sessions_and_cannot_target_self(): void
    {
        $admin = $this->admin();
        $staff = $this->user();
        DB::table('sessions')->insert(['id' => 'sesi-staf', 'user_id' => $staff->id, 'payload' => '', 'last_activity' => now()->timestamp]);

        $this->as($admin)->patchJson('/api/users/'.$staff->id, ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->assertDatabaseMissing('sessions', ['id' => 'sesi-staf']);
        $this->as($admin)->patchJson('/api/users/'.$admin->id, ['active' => false])->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'Pengguna', 'entity_id' => $staff->id]);
    }

    public function test_change_department_and_role(): void
    {
        $staff = $this->user();

        $this->as($this->admin())->patchJson('/api/users/'.$staff->id, ['dept' => 'CampusNet', 'role' => 'Koordinator'])
            ->assertOk()->assertJsonPath('data.dept', 'CampusNet')->assertJsonPath('data.role', 'Koordinator');
    }

    public function test_access_grant_change_takes_effect_and_is_audited(): void
    {
        $staff = $this->user('Digital Campaign');
        $this->assertTrue(app(Access::class)->can($staff, 'deal', AccessLevel::C));

        $this->as($this->admin())->putJson('/api/access-grants', ['dept' => 'Digital Campaign', 'entity' => 'deal', 'level' => 'R'])->assertOk();
        $this->as($this->admin())->putJson('/api/access-grants', ['dept' => 'Digital Campaign', 'entity' => 'nilai', 'level' => 'M'])->assertStatus(422);

        app(Access::class)->forget();
        $this->assertFalse(app(Access::class)->can($staff, 'deal', AccessLevel::C));
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'Hak akses', 'summary' => 'Digital Campaign · deal: C jadi R']);
    }

    public function test_override_replace_and_revoke(): void
    {
        $staff = $this->user('Customer Service');
        $admin = $this->admin();

        $first = $this->as($admin)->postJson('/api/access-overrides', ['userId' => $staff->id, 'key' => 'deal', 'value' => 'C', 'reason' => 'Bantu BD'])->assertCreated()->json('data');
        $this->as($admin)->postJson('/api/access-overrides', ['userId' => $staff->id, 'key' => 'deal', 'value' => 'M', 'reason' => 'Naik'])->assertCreated();
        $this->assertSame(1, $staff->accessOverrides()->count());
        $this->as($admin)->postJson('/api/access-overrides', ['userId' => $admin->id, 'key' => 'deal', 'value' => 'R', 'reason' => 'x'])->assertStatus(422);

        $this->as($admin)->deleteJson('/api/access-overrides/'.$staff->accessOverrides()->first()->id)->assertOk();
        $this->assertSame(0, $staff->accessOverrides()->count());
        $this->assertDatabaseHas('user_notifications', ['user_id' => $staff->id]);
        $this->assertNotEmpty($first['id']);
    }

    public function test_departments_create_rename_and_delete_only_when_empty(): void
    {
        $admin = $this->admin();

        $res = $this->as($admin)->postJson('/api/departments', ['name' => 'Training'])->assertCreated();
        $this->assertSame('C', $res->json('access.lead'));
        $this->assertSame('Ya*', $res->json('access.nilai'));
        $id = $res->json('data.id');
        $this->assertSame(['Training'], Department::find($id)->pipelines()->pluck('name')->all(), 'department baru langsung punya pipeline sendiri');

        $this->as($admin)->patchJson('/api/departments/'.$id, ['name' => 'Training & Development'])->assertOk();
        $this->user('Customer Service'); // department yang masih punya pengguna
        $this->as($admin)->deleteJson('/api/departments/'.Department::where('name', 'Customer Service')->value('id'))->assertStatus(422);
        $this->as($admin)->deleteJson('/api/departments/'.$id)->assertOk();
        $this->assertSame(0, AccessGrant::where('department_id', $id)->count());
    }

    public function test_option_lists_add_and_deactivate(): void
    {
        $admin = $this->admin();

        $this->as($admin)->postJson('/api/option-lists', ['list' => 'segments', 'value' => 'Pemerintahan'])->assertOk()->assertJsonFragment(['Pemerintahan']);
        $this->as($admin)->postJson('/api/option-lists', ['list' => 'segments', 'value' => 'pemerintahan'])->assertStatus(422);
        $res = $this->as($admin)->deleteJson('/api/option-lists', ['list' => 'segments', 'value' => 'Pemerintahan'])->assertOk();

        $this->assertNotContains('Pemerintahan', $res->json('data.segments'));
        $this->assertDatabaseHas('option_lists', ['value' => 'Pemerintahan', 'active' => false]);
        $this->assertTrue(OptionList::where('value', 'Korporat')->value('active'));
    }

    public function test_audit_export_is_csv_and_logged(): void
    {
        $admin = $this->admin();
        $this->as($admin)->postJson('/api/departments', ['name' => 'Audit Uji']);

        $res = $this->as($admin)->get('/api/audit-logs/export')->assertOk();
        $csv = $res->streamedContent();

        $this->assertStringContainsString('Waktu,Pengguna,Aksi', $csv);
        $this->assertStringContainsString('Audit Uji', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Export', 'entity_type' => 'Audit log']);
    }

    public function test_notifications_and_saved_views_belong_to_the_user(): void
    {
        $me = $this->user();
        $other = $this->user();
        DB::table('user_notifications')->insert([['user_id' => $me->id, 'text' => 'A', 'created_at' => now()], ['user_id' => $other->id, 'text' => 'B', 'created_at' => now()]]);

        $boot = $this->as($me)->getJson('/api/bootstrap')->assertOk();
        $this->assertSame(['A'], array_column($boot->json('notifs'), 'text'));
        $this->assertSame([], $boot->json('audit'), 'staf tidak menerima audit log');

        $this->as($me)->postJson('/api/notifications/read')->assertOk()->assertJsonPath('data.0.read', true);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $other->id, 'read_at' => null]);

        $views = $this->as($me)->postJson('/api/saved-views', ['name' => 'Lead Bandung', 'filter' => ['cat' => 'Lead', 'q' => 'bandung', 'arch' => false]])->assertCreated()->json('data');
        $this->as($other)->deleteJson('/api/saved-views/'.$views[0]['id'])->assertOk();
        $this->assertDatabaseCount('saved_views', 1);
        $this->as($me)->deleteJson('/api/saved-views/'.$views[0]['id'])->assertOk()->assertJsonCount(0, 'data');
    }
}
