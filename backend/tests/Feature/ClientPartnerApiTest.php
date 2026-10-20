<?php

namespace Tests\Feature;

use App\Enums\Category;
use App\Enums\Role;
use App\Models\AccessOverride;
use App\Models\AuditLog;
use App\Models\ClientPartner;
use App\Models\Contact;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** API Client & Partner (SRS 4.2, FR-REL-01..08) dan hak akses di server untuk setiap cara mengakses data. */
class ClientPartnerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function user(string $dept = 'Commercial', Role $role = Role::Staf): User
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
            'pic_id' => $pic->id, 'department_id' => $pic->department_id,
        ]);
        $r->contacts()->create(['name' => 'Kontak '.$n, 'email' => 'kontak'.$n.'@contoh.test', 'phone' => '0812-0000-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'is_primary' => true]);

        return $r;
    }

    public function test_list_only_contains_visible_categories(): void
    {
        $owner = $this->user();
        $this->record($owner, Category::Lead, ['name' => 'Lead A']);
        $this->record($owner, Category::Media, ['name' => 'Media B']);
        $viewer = $this->user('Digital Campaign');
        AccessOverride::create(['user_id' => $viewer->id, 'entity' => 'media', 'level' => 'N']);

        $names = collect($this->as($viewer)->getJson('/api/client-partners?all=1')->assertOk()->json('data'))->pluck('name');

        $this->assertContains('Lead A', $names);
        $this->assertNotContains('Media B', $names);
    }

    public function test_opening_a_record_without_permission_by_url_is_refused(): void
    {
        $owner = $this->user();
        $media = $this->record($owner, Category::Media);
        $viewer = $this->user('Digital Campaign');
        AccessOverride::create(['user_id' => $viewer->id, 'entity' => 'media', 'level' => 'N']);

        $this->as($viewer)->getJson('/api/client-partners/'.$media->id)->assertForbidden();
        $this->as($viewer)->getJson('/api/client-partners/'.$media->id.'/history')->assertForbidden();
    }

    public function test_create_record_with_primary_contact_code_and_audit(): void
    {
        $me = $this->user();

        $res = $this->as($me)->postJson('/api/client-partners', [
            'name' => 'PT Cahaya Baru', 'cat' => 'Client', 'segment' => 'Korporat', 'city' => 'Bandung',
            'contact' => 'Budi', 'title' => 'Manager', 'cEmail' => 'budi@contoh.test', 'phone' => '0812-1111-2222',
        ])->assertCreated();

        $res->assertJsonPath('data.cat', 'Client')->assertJsonPath('data.picId', (string) $me->id)
            ->assertJsonPath('data.dept', 'Commercial')->assertJsonPath('data.contact', 'Budi')
            ->assertJsonPath('data.cEmail', 'budi@contoh.test');
        $id = (int) $res->json('data.id');
        $this->assertSame('CP-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT), $res->json('data.code'));
        $this->assertTrue(AuditLog::where(['action' => 'Tambah', 'entity_type' => 'Client & Partner', 'entity_id' => $id])->exists());
    }

    public function test_cannot_create_in_category_without_level_c(): void
    {
        $this->as($this->user('Digital Campaign'))
            ->postJson('/api/client-partners', ['name' => 'Klien Baru', 'cat' => 'Client'])
            ->assertForbidden();
    }

    public function test_validation_messages_for_required_fields(): void
    {
        $this->as($this->user())->postJson('/api/client-partners', ['cat' => 'Lead', 'cEmail' => 'bukan-email'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'cEmail']);
    }

    public function test_level_c_edits_own_record_only_and_changes_are_audited(): void
    {
        $me = $this->user('CampusNet');
        $own = $this->record($me);
        $other = $this->record($this->user('CampusNet'));

        $this->as($me)->patchJson('/api/client-partners/'.$other->id, ['city' => 'Garut'])->assertForbidden();
        $this->as($me)->patchJson('/api/client-partners/'.$own->id, ['city' => 'Garut', 'phone' => '0813-9999-0000'])
            ->assertOk()->assertJsonPath('data.city', 'Garut')->assertJsonPath('data.phone', '0813-9999-0000');

        $log = AuditLog::where('entity_id', $own->id)->where('action', 'Ubah')->whereNotNull('after->city')->first();
        $this->assertSame('Garut', $log->after['city']);
        $this->assertArrayHasKey('city', $log->before);

        $fields = collect($this->as($me)->getJson('/api/client-partners/'.$own->id.'/history')->assertOk()->json('data'))->pluck('field');
        $this->assertContains('Kota', $fields);
        $this->assertContains('Telepon kontak', $fields);
    }

    public function test_rate_card_is_hidden_from_users_without_permission(): void
    {
        $dc = $this->user('Digital Campaign');
        $kol = $this->record($dc, Category::KolKoc, ['rate_card' => ['rate' => 6000000]]);

        $this->as($dc)->getJson('/api/client-partners/'.$kol->id)->assertJsonPath('data.rate', 6000000);
        $this->as($this->user('Commercial'))->getJson('/api/client-partners/'.$kol->id)
            ->assertJsonPath('data.rate', '')->assertJsonPath('data.rateHidden', true);

        // Staf Commercial (R*) tidak bisa mengubah rate card walaupun mengirimkannya.
        $commercialM = $this->user('Commercial', Role::Staf);
        AccessOverride::create(['user_id' => $commercialM->id, 'entity' => 'kol', 'level' => 'M']);
        $this->as($commercialM)->patchJson('/api/client-partners/'.$kol->id, ['rate' => 1])->assertOk();
        $this->assertSame(6000000, $kol->fresh()->rate_card['rate']);
    }

    public function test_archive_needs_level_m_and_reason_and_can_be_restored(): void
    {
        $record = $this->record($this->user('CampusNet'));

        $this->as($this->user('CampusNet'))->postJson('/api/client-partners/'.$record->id.'/archive', ['reason' => 'Tutup'])->assertForbidden();

        $manager = $this->user('Commercial');
        $this->as($manager)->postJson('/api/client-partners/'.$record->id.'/archive', [])->assertUnprocessable();
        $this->as($manager)->postJson('/api/client-partners/'.$record->id.'/archive', ['reason' => 'Perusahaan tutup'])
            ->assertOk()->assertJsonPath('data.archived', true)->assertJsonPath('data.archiveReason', 'Perusahaan tutup');
        $this->assertDatabaseHas('client_partners', ['id' => $record->id]);

        $this->as($manager)->postJson('/api/client-partners/'.$record->id.'/restore')->assertOk()->assertJsonPath('data.archived', false);
    }

    public function test_convert_lead_to_client(): void
    {
        $me = $this->user();
        $lead = $this->record($me);

        $this->as($me)->postJson('/api/client-partners/'.$lead->id.'/convert')->assertOk()->assertJsonPath('data.cat', 'Client');
        $this->assertNotNull($lead->fresh()->converted_at);
        $this->as($me)->postJson('/api/client-partners/'.$lead->id.'/convert')->assertStatus(409);
    }

    public function test_move_pic_needs_reason_and_notifies_new_pic(): void
    {
        $me = $this->user('Commercial', Role::Koordinator);
        $record = $this->record($me);
        $next = $this->user();

        $this->as($me)->postJson('/api/client-partners/'.$record->id.'/move-pic', ['picId' => $next->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->as($me)->postJson('/api/client-partners/'.$record->id.'/move-pic', ['picId' => $next->id, 'reason' => 'Pembagian wilayah'])
            ->assertOk()->assertJsonPath('data.picId', (string) $next->id);

        $this->assertDatabaseHas('user_notifications', ['user_id' => $next->id]);
        $this->assertTrue(AuditLog::where('entity_id', $record->id)->where('summary', 'like', '%Pembagian wilayah%')->exists());
    }

    public function test_duplicate_check_covers_all_data_but_hides_details(): void
    {
        $owner = $this->user();
        $visible = $this->record($owner, Category::Lead, ['name' => 'PT Sinar Kreasi Nusantara']);
        $hidden = $this->record($owner, Category::Media, ['name' => 'Sinar Kreasi Nusantara Media']);
        $viewer = $this->user('Digital Campaign');
        AccessOverride::create(['user_id' => $viewer->id, 'entity' => 'media', 'level' => 'N']);

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
}
