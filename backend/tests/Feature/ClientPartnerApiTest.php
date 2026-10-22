<?php

namespace Tests\Feature;

use App\Enums\Category;
use App\Enums\Role;
use App\Models\AccessOverride;
use App\Models\AuditLog;
use App\Models\ClientPartner;
use App\Models\Contact;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** API Client & Partner (SRS 4.2, FR-REL-01..08) dengan akses per divisi dan hak akses di server untuk setiap cara mengakses data. */
class ClientPartnerApiTest extends TestCase
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

    private function record(User $pic, Category $category = Category::Lead, array $attrs = []): ClientPartner
    {
        static $n = 0;
        $r = ClientPartner::create($attrs + [
            'code' => 'CP-T'.++$n, 'name' => $attrs['name'] ?? 'Data uji '.$n, 'category' => $category,
            'pic_id' => $pic->id, 'department_id' => $pic->department_id, 'created_by' => $pic->id,
        ]);
        $r->contacts()->create(['name' => 'Kontak '.$n, 'email' => 'kontak'.$n.'@contoh.test', 'phone' => '0812-0000-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'is_primary' => true]);

        return $r;
    }

    public function test_list_only_contains_records_of_own_division(): void
    {
        $this->record($this->user('Customer Service'), Category::Lead, ['name' => 'Lead CS']);
        $this->record($this->user('Digital Campaign'), Category::Lead, ['name' => 'Lead DC']);

        $names = collect($this->as($this->user('Digital Campaign'))->getJson('/api/client-partners?all=1')->assertOk()->json('data'))->pluck('name');

        $this->assertContains('Lead DC', $names);
        $this->assertNotContains('Lead CS', $names);
    }

    public function test_opening_a_record_of_another_division_by_url_is_refused(): void
    {
        $other = $this->record($this->user('Customer Service'));
        $viewer = $this->user('Digital Campaign');

        $this->as($viewer)->getJson('/api/client-partners/'.$other->id)->assertForbidden();
        $this->as($viewer)->getJson('/api/client-partners/'.$other->id.'/history')->assertForbidden();
        $this->as($viewer)->patchJson('/api/client-partners/'.$other->id, ['city' => 'X'])->assertForbidden();
    }

    public function test_create_is_always_lead_with_creator_as_pic_and_own_division(): void
    {
        $me = $this->user('Digital Campaign');
        $someoneElse = $this->user('Customer Service');

        $res = $this->as($me)->postJson('/api/client-partners', [
            'name' => 'PT Cahaya Baru', 'cat' => 'Client', 'picId' => $someoneElse->id, 'dept' => 'Customer Service', // diabaikan server
            'service' => 'KOL', 'contact' => 'Budi', 'cEmail' => 'budi@contoh.test',
        ])->assertCreated();

        $res->assertJsonPath('data.cat', 'Lead')->assertJsonPath('data.picId', (string) $me->id)
            ->assertJsonPath('data.dept', 'Digital Campaign')->assertJsonPath('data.createdBy', (string) $me->id)
            ->assertJsonPath('data.service', 'KOL')->assertJsonPath('data.contact', 'Budi');
        $id = (int) $res->json('data.id');
        $this->assertSame('CP-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT), $res->json('data.code'));
        $this->assertTrue(AuditLog::where(['action' => 'Tambah', 'entity_type' => 'Client & Partner', 'entity_id' => $id])->exists());
    }

    public function test_service_is_optional_and_must_exist(): void
    {
        $me = $this->user();
        $this->as($me)->postJson('/api/client-partners', ['name' => 'Tanpa layanan'])->assertCreated()->assertJsonPath('data.service', '');
        $this->as($me)->postJson('/api/client-partners', ['name' => 'Layanan aneh', 'service' => 'Planet'])->assertUnprocessable()->assertJsonValidationErrors('service');
    }

    public function test_cannot_create_without_lead_input_right(): void
    {
        $noLead = $this->user('Business Operation');
        AccessOverride::create(['user_id' => $noLead->id, 'entity' => 'lead', 'level' => 'R']);
        $this->as($noLead)->postJson('/api/client-partners', ['name' => 'Lead Baru'])->assertForbidden();
        $this->as($this->user('Customer Service', Role::Viewer))->postJson('/api/client-partners', ['name' => 'Lead Baru'])->assertForbidden();
    }

    public function test_validation_messages_for_required_fields(): void
    {
        $this->as($this->user())->postJson('/api/client-partners', ['cEmail' => 'bukan-email'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'cEmail']);
    }

    public function test_update_cannot_change_status_division_or_pic(): void
    {
        $me = $this->user();
        $lead = $this->record($me);

        $this->as($me)->patchJson('/api/client-partners/'.$lead->id, ['cat' => 'Client', 'dept' => 'Executive', 'picId' => 1, 'city' => 'Garut'])->assertOk();

        $lead->refresh();
        $this->assertSame(Category::Lead, $lead->category);
        $this->assertSame($me->department_id, $lead->department_id);
        $this->assertSame($me->id, $lead->pic_id);
        $this->assertSame('Garut', $lead->city);
    }

    public function test_level_c_edits_own_record_only_and_changes_are_audited(): void
    {
        $me = $this->user('CampusNet');
        $own = $this->record($me);
        $other = $this->record($this->user('CampusNet'));

        $this->as($me)->patchJson('/api/client-partners/'.$other->id, ['city' => 'Garut'])->assertForbidden();
        $this->as($me)->patchJson('/api/client-partners/'.$own->id, ['city' => 'Garut', 'phone' => '0813-9999-0000'])
            ->assertOk()->assertJsonPath('data.city', 'Garut')->assertJsonPath('data.phone', '0813-9999-0000');

        $fields = collect($this->as($me)->getJson('/api/client-partners/'.$own->id.'/history')->assertOk()->json('data'))->pluck('field');
        $this->assertContains('Kota', $fields);
        $this->assertContains('Telepon kontak', $fields);
    }

    public function test_rate_card_is_for_kol_service_and_hidden_without_permission(): void
    {
        $dc = $this->user('Digital Campaign');
        $kol = $this->record($dc, Category::Lead, ['service_id' => Service::where('name', 'KOL')->value('id'), 'rate_card' => ['rate' => 6000000]]);

        $this->as($dc)->getJson('/api/client-partners/'.$kol->id)->assertJsonPath('data.rate', 6000000)->assertJsonPath('data.service', 'KOL');

        // Executive staf melihat lintas divisi; rate card Executive "Ya".
        $this->as($this->user('Executive'))->getJson('/api/client-partners/'.$kol->id)->assertJsonPath('data.rate', 6000000);
        // Rate card dari form diabaikan untuk layanan selain KOL.
        $res = $this->as($dc)->postJson('/api/client-partners', ['name' => 'Komunitas X', 'service' => 'Community', 'rate' => 5000000])->assertCreated();
        $this->assertNull(ClientPartner::find($res->json('data.id'))->rate_card);
    }

    public function test_archive_needs_level_m_and_reason_and_can_be_restored(): void
    {
        $record = $this->record($this->user('Customer Service'));

        $this->as($this->user('CampusNet'))->postJson('/api/client-partners/'.$record->id.'/archive', ['reason' => 'Tutup'])->assertForbidden();

        $manager = $this->user('Customer Service');
        $this->as($manager)->postJson('/api/client-partners/'.$record->id.'/archive', [])->assertUnprocessable();
        $this->as($manager)->postJson('/api/client-partners/'.$record->id.'/archive', ['reason' => 'Perusahaan tutup'])
            ->assertOk()->assertJsonPath('data.archived', true)->assertJsonPath('data.archiveReason', 'Perusahaan tutup');
        $this->assertDatabaseHas('client_partners', ['id' => $record->id]);

        $this->as($manager)->postJson('/api/client-partners/'.$record->id.'/restore')->assertOk()->assertJsonPath('data.archived', false);
    }

    public function test_manual_lead_to_client_conversion_does_not_exist(): void
    {
        $me = $this->user();
        $lead = $this->record($me);

        $this->as($me)->postJson('/api/client-partners/'.$lead->id.'/convert')->assertNotFound();
        $this->assertSame(Category::Lead, $lead->fresh()->category);
    }

    public function test_duplicate_check_covers_all_divisions_but_hides_details(): void
    {
        $visible = $this->record($this->user('Digital Campaign'), Category::Lead, ['name' => 'PT Sinar Kreasi Nusantara']);
        $hidden = $this->record($this->user('Customer Service'), Category::Lead, ['name' => 'Sinar Kreasi Nusantara Media']);
        $viewer = $this->user('Digital Campaign');

        $res = $this->as($viewer)->postJson('/api/client-partners/duplicates', ['name' => 'Sinar Kreasi Nusantara'])->assertOk();

        $this->assertSame([(string) $visible->id], array_column($res->json('matches'), 'id'));
        $this->assertSame(1, $res->json('hidden'));
        $this->assertNotContains((string) $hidden->id, array_column($res->json('matches'), 'id'));
    }

    public function test_duplicate_check_matches_email_and_phone(): void
    {
        $me = $this->user();
        $r = $this->record($me, Category::Lead, ['name' => 'Toko Abadi']);
        $contact = $r->contacts->first();

        $byEmail = $this->as($me)->postJson('/api/client-partners/duplicates', ['name' => 'Nama Lain Sekali', 'email' => strtoupper($contact->email)])->json('matches');
        $this->assertSame(['email sama'], $byEmail[0]['why']);

        $byPhone = $this->as($me)->postJson('/api/client-partners/duplicates', ['name' => 'Beda Lagi', 'phone' => '+62 '.$contact->phone])->json('matches');
        $this->assertSame(['telepon sama'], $byPhone[0]['why']);

        $self = $this->as($me)->postJson('/api/client-partners/duplicates', ['name' => 'Toko Abadi', 'excludeId' => $r->id])->json('matches');
        $this->assertSame([], $self);
    }

    public function test_contacts_add_edit_primary_and_release_without_hard_delete(): void
    {
        $me = $this->user();
        $record = $this->record($me);

        $added = $this->as($me)->postJson('/api/client-partners/'.$record->id.'/contacts', ['name' => 'Lina', 'email' => 'lina@contoh.test'])
            ->assertCreated()->json('data.contacts');
        $lina = collect($added)->firstWhere('name', 'Lina');
        $this->assertFalse($lina['primary']);

        $this->as($me)->patchJson('/api/contacts/'.$lina['id'], ['name' => 'Lina Marlina', 'title' => 'Procurement'])->assertOk();
        $this->as($me)->postJson('/api/contacts/'.$lina['id'].'/primary')->assertOk()->assertJsonPath('data.contact', 'Lina Marlina');

        $this->as($me)->deleteJson('/api/contacts/'.$lina['id'])->assertOk()->assertJsonCount(1, 'data.contacts');
        $this->assertSoftDeleted('contacts', ['id' => $lina['id']]);
        $this->assertTrue(Contact::find($record->contacts()->first()->id)->is_primary, 'kontak lain jadi utama lagi');

        $this->as($this->user('CampusNet'))->postJson('/api/client-partners/'.$record->id.'/contacts', ['name' => 'X'])->assertForbidden();
    }

    public function test_personal_override_can_hide_client_status(): void
    {
        $me = $this->user('Digital Campaign');
        $client = $this->record($me, Category::Client);
        AccessOverride::create(['user_id' => $me->id, 'entity' => 'client', 'level' => 'N']);

        $this->as($me)->getJson('/api/client-partners/'.$client->id)->assertForbidden();
    }
}
