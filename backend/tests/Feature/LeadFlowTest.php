<?php

namespace Tests\Feature;

use App\Enums\Category;
use App\Enums\DealStatus;
use App\Enums\Role;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Department;
use App\Models\Handover;
use App\Models\Interaction;
use App\Models\Pipeline;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\OpenAccessMatrix;
use Tests\TestCase;

/**
 * Alur bisnis Lead/Client (22 Okt 2026): penerimaan Lead dari divisi mana pun dengan PIC otomatis, Lead jadi Client
 * hanya lewat Won, akses per divisi, serah terima ke arah mana pun, status vs layanan, dan akun internal.
 * Nomor di nama test mengikuti daftar uji minimal di permintaan.
 */
class LeadFlowTest extends TestCase
{
    use OpenAccessMatrix, RefreshDatabase;

    private Pipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->openAccessMatrix();
        $this->pipeline = Pipeline::with('stages')->first();
    }

    private function user(string $dept, Role $role = Role::Staf): User
    {
        return User::factory()->inDepartment($dept)->role($role)->create();
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['login_at' => now()->timestamp]);
    }

    private function newLead(User $me, array $extra = []): array
    {
        static $n = 0;

        return $this->as($me)->postJson('/api/client-partners', $extra + ['name' => 'Calon '.++$n])->assertCreated()->json('data');
    }

    private function newDeal(User $me, string $recordId): array
    {
        return $this->as($me)->postJson('/api/deals', ['title' => 'Deal '.uniqid(), 'recordId' => $recordId, 'lineId' => $this->pipeline->id, 'offer' => 10000000])
            ->assertCreated()->json('data');
    }

    private function handover(User $by, string $recordId, User $to, string $reason = 'Lebih cocok ditangani divisi ini')
    {
        return $this->as($by)->postJson('/api/client-partners/'.$recordId.'/handover', ['deptId' => $to->department_id, 'picId' => $to->id, 'reason' => $reason]);
    }

    public function test_1_users_from_many_divisions_receive_leads_directly(): void
    {
        foreach (['Customer Service', 'Digital Campaign', 'Creative & Media', 'CampusNet', 'DietPartner', 'Business Operation', 'Executive'] as $dept) {
            $lead = $this->newLead($this->user($dept), ['service' => 'KOL']);
            $this->assertSame('Lead', $lead['cat'], $dept);
        }
        // Viewer tetap tidak bisa.
        $this->as($this->user('Business Operation', Role::Viewer))->postJson('/api/client-partners', ['name' => 'X'])->assertForbidden();
    }

    public function test_2_pic_division_and_creator_follow_the_person_who_fills_in(): void
    {
        $dc = $this->user('Digital Campaign');
        $lead = $this->newLead($dc, ['service' => 'KOL']);

        $this->assertSame((string) $dc->id, $lead['picId']);
        $this->assertSame('Digital Campaign', $lead['dept']);
        $this->assertSame((string) $dc->id, $lead['createdBy']);
        $this->assertSame('KOL', $lead['service']);
        $this->assertNotNull(ClientPartner::find($lead['id'])->created_at);
    }

    public function test_3_lead_becomes_client_after_won(): void
    {
        $me = $this->user('Customer Service');
        $lead = $this->newLead($me);
        $deal = $this->newDeal($me, $lead['id']);

        $this->as($me)->postJson('/api/deals/'.$deal['id'].'/won', ['value' => 25000000, 'closedAt' => today()->toDateString()])->assertOk();

        $record = ClientPartner::find($lead['id']);
        $this->assertSame(Category::Client, $record->category);
        $this->assertNotNull($record->converted_at);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $lead['id'], 'summary' => $record->name.' jadi Client karena deal '.$deal['title'].' Won']);
    }

    public function test_4_lost_and_on_hold_do_not_convert_and_client_stays_client(): void
    {
        $me = $this->user('Customer Service');
        $lead = $this->newLead($me);
        $lost = $this->newDeal($me, $lead['id']);
        $hold = $this->newDeal($me, $lead['id']);

        $this->as($me)->postJson('/api/deals/'.$lost['id'].'/lost', ['lostReason' => 'Pilih vendor lain'])->assertOk();
        $this->as($me)->postJson('/api/deals/'.$hold['id'].'/hold', ['reason' => 'Anggaran', 'reviewDate' => today()->addWeek()->toDateString()])->assertOk();
        $this->assertSame(Category::Lead, ClientPartner::find($lead['id'])->category);

        // Deal berikutnya Won: jadi Client. Deal sesudahnya Lost: tetap Client, dan relasinya tetap satu.
        $won = $this->newDeal($me, $lead['id']);
        $this->as($me)->postJson('/api/deals/'.$won['id'].'/won', ['value' => 5000000, 'closedAt' => today()->toDateString()])->assertOk();
        $next = $this->newDeal($me, $lead['id']);
        $this->as($me)->postJson('/api/deals/'.$next['id'].'/lost', ['lostReason' => 'Pilih vendor lain'])->assertOk();

        $this->assertSame(Category::Client, ClientPartner::find($lead['id'])->category);
        $this->assertSame(4, Deal::where('client_partner_id', $lead['id'])->count());
        $this->assertSame(1, ClientPartner::count(), 'tidak ada relasi ganda per deal');
    }

    public function test_5_handover_works_between_divisions_in_any_direction(): void
    {
        $dc = $this->user('Digital Campaign', Role::KepalaDepartment);
        $cs = $this->user('Customer Service', Role::KepalaDepartment);
        $cm = $this->user('Creative & Media', Role::KepalaDepartment);
        $cn = $this->user('CampusNet', Role::KepalaDepartment);
        $lead = $this->newLead($dc, ['service' => 'Media']);

        // DC -> CS -> Creative & Media -> CampusNet -> DC
        foreach ([[$dc, $cs], [$cs, $cm], [$cm, $cn], [$cn, $dc]] as [$from, $to]) {
            $res = $this->handover($from, $lead['id'], $to)->assertOk();
            $this->assertSame($to->department->name, $res->json('data.dept'));
            $this->assertSame((string) $to->id, $res->json('data.picId'));
        }

        $log = Handover::where('client_partner_id', $lead['id'])->orderBy('id')->get();
        $this->assertCount(4, $log);
        $this->assertSame([$dc->department_id, $cs->department_id, $cs->id, $cs->department_id, $dc->id],
            [$log[0]->from_department_id, $log[0]->to_department_id, $log[0]->to_user_id, $log[1]->from_department_id, $log[0]->by_user_id]);
        $this->assertSame('Lebih cocok ditangani divisi ini', $log[0]->reason);
        $this->assertSame((string) $dc->id, ClientPartner::find($lead['id'])->created_by.'', 'pembuat awal tidak berubah');
    }

    public function test_5b_handover_validation(): void
    {
        $dc = $this->user('Digital Campaign', Role::KepalaDepartment);
        $lead = $this->newLead($dc);
        $cs = $this->user('Customer Service');

        $this->as($dc)->postJson('/api/client-partners/'.$lead['id'].'/handover', ['deptId' => $cs->department_id, 'picId' => $cs->id])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->as($dc)->postJson('/api/client-partners/'.$lead['id'].'/handover', ['deptId' => Department::where('name', 'CampusNet')->value('id'), 'picId' => $cs->id, 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('picId');
        $viewer = $this->user('Customer Service', Role::Viewer);
        $this->handover($dc, $lead['id'], $viewer)->assertUnprocessable();
        $inactive = User::factory()->inDepartment('Customer Service')->inactive()->create();
        $this->handover($dc, $lead['id'], $inactive)->assertUnprocessable();
        // Siapa pun yang boleh mengubah data boleh memindahkan: staf C atas data yang PIC-nya dia sendiri,
        // tapi tidak atas data rekan sedivisi.
        $staff = $this->user('Digital Campaign');
        $own = $this->newLead($staff);
        $this->handover($this->user('Digital Campaign'), $own['id'], $cs)->assertForbidden();
        $this->handover($staff, $own['id'], $cs)->assertOk();
    }

    public function test_6_access_follows_the_new_division_after_handover(): void
    {
        $dc = $this->user('Digital Campaign', Role::KepalaDepartment);
        $dcStaff = $this->user('Digital Campaign');
        $com = $this->user('Customer Service');
        $lead = $this->newLead($dc);
        $this->as($dc)->postJson('/api/interactions', ['recordId' => $lead['id'], 'type' => 'Telepon', 'date' => today()->toDateString(), 'result' => 'Kenalan', 'fuDate' => today()->toDateString()])->assertCreated();

        $this->as($com)->getJson('/api/client-partners/'.$lead['id'])->assertForbidden();
        $this->handover($dc, $lead['id'], $com)->assertOk();

        // Penerima: data, interaksi lama, dan deal terlihat. Asal: tidak lagi.
        $this->as($com)->getJson('/api/client-partners/'.$lead['id'])->assertOk();
        $this->assertSame(1, collect($this->as($com)->getJson('/api/interactions')->json('data'))->where('recordId', $lead['id'])->count());
        $deal = $this->newDeal($com, $lead['id']);
        $this->assertNotNull(collect($this->as($com)->getJson('/api/deals')->json('data'))->firstWhere('id', $deal['id']));
        $this->as($dcStaff)->getJson('/api/client-partners/'.$lead['id'])->assertForbidden();
        $this->assertSame(0, collect($this->as($dcStaff)->getJson('/api/interactions')->json('data'))->where('recordId', $lead['id'])->count());
        $this->assertSame((string) $dc->id, $this->as($com)->getJson('/api/client-partners/'.$lead['id'])->json('data.createdBy'));
    }

    public function test_6b_running_deals_move_to_new_pic_closed_deals_keep_history(): void
    {
        $com = $this->user('Customer Service', Role::KepalaDepartment);
        $exec = $this->user('Executive', Role::KepalaDepartment);
        $lead = $this->newLead($com);
        $open = $this->newDeal($com, $lead['id']);
        $won = $this->newDeal($com, $lead['id']);
        $this->as($com)->postJson('/api/deals/'.$won['id'].'/won', ['value' => 1000000, 'closedAt' => today()->toDateString()]);

        $this->handover($com, $lead['id'], $exec)->assertOk();

        $this->assertSame($exec->id, Deal::find($open['id'])->owner_id);
        $this->assertSame($com->id, Deal::find($won['id'])->owner_id, 'deal Won tetap tercatat atas PIC lama');
        $this->assertSame(1, Handover::first()->deals_moved);
        $this->assertStringContainsString('Pindah ke', Deal::find($open['id'])->histories()->latest('id')->value('note'));

        // Executive punya pipeline sendiri: deal pindah ke sana, stage dicocokkan lewat nama, Won tetap di Closing.
        $exLine = Pipeline::where('name', 'Executive')->value('id');
        $this->assertSame([$exLine, $exLine], [Deal::find($open['id'])->pipeline_id, Deal::find($won['id'])->pipeline_id]);
        $this->assertSame('Lead', Deal::find($open['id'])->stage->name);
        $this->assertTrue(Deal::find($won['id'])->stage->is_closing);

        // Ke Digital Campaign (pipeline Campaign): deal pindah pipeline, Won tetap di Closing.
        $dc = $this->user('Digital Campaign');
        $this->handover($this->user('Executive', Role::SuperAdmin), $lead['id'], $dc)->assertOk();
        $campaign = Pipeline::where('name', 'Campaign')->value('id');
        $this->assertSame([$campaign, $campaign], [Deal::find($open['id'])->pipeline_id, Deal::find($won['id'])->pipeline_id]);
        $this->assertSame('Brief', Deal::find($open['id'])->stage->name, 'stage dicocokkan lewat urutan');
        $this->assertTrue(Deal::find($won['id'])->stage->is_closing);
    }

    public function test_7_only_super_admin_sees_everything_others_only_their_own(): void
    {
        foreach (['Digital Campaign', 'Customer Service', 'Creative & Media', 'CampusNet'] as $dept) {
            $this->newLead($this->user($dept), ['name' => 'Lead '.$dept]);
        }

        $names = fn (User $u) => collect($this->as($u)->getJson('/api/client-partners?all=1')->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertCount(4, $names($this->user('Executive', Role::SuperAdmin)));
        $this->assertSame([], $names($this->user('Executive')), 'Executive tidak lagi melihat lintas divisi');
        $this->assertSame([], $names($this->user('Customer Service', Role::KepalaDepartment)), 'Kepala Department tidak melihat data timnya');

        $head = $this->user('Creative & Media', Role::KepalaDepartment);
        $this->assertFalse($head->isSuperAdmin());
        $this->newLead($head, ['name' => 'Lead milik kepala']);
        $this->assertSame(['Lead milik kepala'], $names($head));
    }

    public function test_8_legacy_categories_migrate_to_status_and_service_keeping_ids_and_history(): void
    {
        $migration = require database_path('migrations/2026_10_22_000001_lead_client_flow.php');
        $migration->down();

        $pic = $this->user('Customer Service');
        $line = Pipeline::with('stages')->first();
        $insert = fn (string $code, string $cat) => DB::table('client_partners')->insertGetId([
            'code' => $code, 'name' => 'Lama '.$code, 'category' => $cat, 'pic_id' => $pic->id, 'department_id' => $pic->department_id,
            'created_by' => $pic->id, 'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
        ]);
        $kol = $insert('OLD-1', 'KOL/KOC');
        $community = $insert('OLD-2', 'Community');
        $lead = $insert('OLD-3', 'Lead');
        DB::table('contacts')->insert(['client_partner_id' => $community, 'name' => 'Kontak lama', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('deals')->insert(['code' => 'OLD-D1', 'title' => 'Fun run', 'client_partner_id' => $community, 'pipeline_id' => $line->id,
            'stage_id' => $line->stages->last()->id, 'status' => 'Won', 'owner_id' => $pic->id, 'deal_value' => 1, 'closed_at' => '2026-03-01',
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('interactions')->insert(['code' => 'OLD-I1', 'client_partner_id' => $kol, 'user_id' => $pic->id, 'occurred_on' => '2026-01-01',
            'type' => 'Telepon', 'result' => 'Riwayat lama', 'created_at' => now(), 'updated_at' => now()]);

        // Hak "partner" baru ada sejak migrasi matriks klien: lepas dulu supaya migrasi lama bisa diulang, lalu pasang lagi.
        DB::table('access_grants')->where('entity', 'partner')->delete();
        $migration->up();
        (require database_path('migrations/2026_11_05_000004_client_access_matrix.php'))->up();
        $this->seed(ReferenceDataSeeder::class);

        $get = fn ($id) => ClientPartner::with('service')->find($id);
        $this->assertSame([Category::Lead, 'KOL'], [$get($kol)->category, $get($kol)->service->name]);
        $this->assertSame([Category::Client, 'Community'], [$get($community)->category, $get($community)->service->name], 'pernah Won jadi Client');
        $this->assertSame('2026-03-01', $get($community)->converted_at->toDateString());
        $this->assertSame([Category::Lead, null], [$get($lead)->category, $get($lead)->service]);
        $this->assertSame('OLD-1', $get($kol)->code, 'id dan kode tetap');
        $this->assertSame(1, Interaction::where('client_partner_id', $kol)->count());
        $this->assertSame(1, $get($community)->contacts()->count());
        $this->assertSame(1, Deal::where('client_partner_id', $community)->count());
        $this->assertSame('Digital Campaign', Service::where('name', 'KOL')->first()->department->name);
    }

    public function test_9_demo_data_and_public_assets_contain_no_real_identities(): void
    {
        $sample = json_decode(file_get_contents(database_path('seeders/data/sample.json')), true);
        foreach ($sample['users'] as $u) {
            $this->assertStringEndsWith('@contoh.test', $u['email']);
        }
        $public = file_get_contents(base_path('../assets/data.js')).file_get_contents(database_path('seeders/data/sample.json'));
        $this->assertStringNotContainsString('optima.web.id', $public);
        $this->assertStringNotContainsString('@gmail.com', $public);

        $root = base_path('..');
        if (is_dir($root.'/.git')) {
            exec('git -C '.escapeshellarg($root).' check-ignore -q backend/storage/app/private/internal-users.json', $out, $code);
            $this->assertSame(0, $code, 'daftar akun internal harus di luar git');
        }
    }

    public function test_internal_accounts_link_alternate_emails_to_one_identity_as_department_heads(): void
    {
        config(['optima.dev_login' => true]);
        $file = tempnam(sys_get_temp_dir(), 'internal');
        file_put_contents($file, json_encode([
            ['department' => 'Executive', 'name' => 'Kepala Uji', 'email' => 'kepala@kantor.test', 'alternate_emails' => ['Kepala.Pribadi@pribadi.test']],
            ['department' => 'Customer Service', 'name' => 'CS Uji', 'email' => 'cs@kantor.test', 'alternate_emails' => []],
        ]));

        $this->artisan('optima:sync-internal-users', ['file' => $file])->assertSuccessful();
        $this->artisan('optima:sync-internal-users', ['file' => $file])->assertSuccessful(); // aman diulang

        $this->assertSame(2, User::count());
        $head = User::where('email', 'kepala@kantor.test')->first();
        $this->assertSame(Role::KepalaDepartment, $head->role);
        $this->assertFalse($head->isSuperAdmin());
        $this->assertSame(['kepala.pribadi@pribadi.test'], $head->alternateEmails()->pluck('email')->all());

        // Login lewat email alternatif masuk ke identitas yang sama.
        $this->postJson('/api/auth/dev-login', ['email' => 'kepala.pribadi@pribadi.test'])->assertOk();
        $this->assertAuthenticatedAs($head);
        unlink($file);
    }

    public function test_service_mapping_is_admin_editable_and_never_moves_ownership(): void
    {
        $dc = $this->user('Digital Campaign');
        $lead = $this->newLead($dc, ['service' => 'Community']);
        $admin = $this->user('Executive', Role::SuperAdmin);
        $community = Service::where('name', 'Community')->first();

        $this->as($this->user('Customer Service', Role::KepalaDepartment))->patchJson('/api/services/'.$community->id, ['deptId' => null])->assertForbidden();
        $this->as($admin)->patchJson('/api/services/'.$community->id, ['deptId' => Department::where('name', 'CampusNet')->value('id')])
            ->assertOk()->assertJsonFragment(['name' => 'Community', 'dept' => 'CampusNet']);

        $record = ClientPartner::find($lead['id']);
        $this->assertSame($dc->department_id, $record->department_id, 'pemetaan layanan tidak memindahkan divisi');
        $this->assertSame($dc->id, $record->pic_id);
        // Memilih layanan saat edit juga tidak memindahkan.
        $this->as($dc)->patchJson('/api/client-partners/'.$lead['id'], ['service' => 'Media'])->assertOk()->assertJsonPath('data.dept', 'Digital Campaign');
    }

    public function test_import_by_non_executive_is_limited_to_own_division(): void
    {
        $dc = $this->user('Digital Campaign', Role::KepalaDepartment);
        $dc->update(['email' => 'dc@contoh.test']);
        $this->user('Customer Service')->update(['email' => 'cs@contoh.test']);
        $rows = fn (string $dept, string $pic = 'dc@contoh.test') => ['records' => [['_row' => 6, 'kode sumber' => 'X-'.$dept, 'nama' => 'Data '.$dept, 'kategori' => 'KOL/KOC',
            'email pic' => $pic, 'department pemilik' => $dept]]];

        $own = $this->as($dc)->postJson('/api/imports/check', ['filename' => 'a.xlsx', 'sheets' => $rows('Digital Campaign')])->json('data');
        $this->assertCount(1, $own['records']);
        $other = $this->as($dc)->postJson('/api/imports/check', ['filename' => 'b.xlsx', 'sheets' => $rows('Customer Service', 'cs@contoh.test')])->json('data');
        $this->assertStringContainsString('PIC harus kamu sendiri', $other['errors'][0]);

        $this->as($dc)->postJson('/api/imports/'.$own['id'].'/commit')->assertOk();
        $record = ClientPartner::with('service')->where('code', 'X-Digital Campaign')->first();
        $this->assertSame([Category::Lead, 'KOL'], [$record->category, $record->service->name], 'kategori lama KOL/KOC jadi layanan');
    }

    public function test_won_deal_from_import_makes_client(): void
    {
        $com = $this->user('Customer Service', Role::KepalaDepartment);
        $com->update(['email' => 'com@contoh.test']);
        $sheets = [
            'records' => [['_row' => 6, 'kode sumber' => 'W-1', 'nama' => 'PT Won', 'kategori' => 'Lead', 'email pic' => 'com@contoh.test', 'department pemilik' => 'Customer Service']],
            'deals' => [['_row' => 6, 'kode sumber' => 'WD-1', 'kode client & partner' => 'W-1', 'lini bisnis' => $this->pipeline->name, 'status' => 'Won',
                'email pic' => 'com@contoh.test', 'nilai deal (rp)' => '1000000', 'tanggal closing' => today()->toDateString()]],
        ];
        $id = $this->as($com)->postJson('/api/imports/check', ['filename' => 'w.xlsx', 'sheets' => $sheets])->json('data.id');
        $this->as($com)->postJson('/api/imports/'.$id.'/commit')->assertOk();

        $this->assertSame(Category::Client, ClientPartner::where('code', 'W-1')->first()->category);
        $this->assertSame(DealStatus::Won, Deal::where('code', 'WD-1')->first()->status);
    }
}
