<?php

namespace Tests\Feature;

use App\Enums\Category;
use App\Enums\Role;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Interaction;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OpenAccessMatrix;
use Tests\TestCase;

/** Import (SRS 4.4, 8.3; FR-IMP-01..04) dan gabung data ganda (FR-REL-09). */
class ImportMergeTest extends TestCase
{
    use OpenAccessMatrix, RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->openAccessMatrix();
        $this->me = User::factory()->inDepartment('Customer Service')->role(Role::KepalaDepartment)->create(['email' => 'rina@contoh.test']);
    }

    private function as(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->me)->withSession(['login_at' => now()->timestamp]);
    }

    private function sheets(array $overrideRecord = []): array
    {
        return [
            'records' => [
                ['_row' => 6, 'kode sumber' => 'CP-0001', 'nama' => 'PT Contoh Sejahtera'], // baris contoh template, dilewati
                array_merge(['_row' => 7, 'kode sumber' => 'LEG-001', 'nama' => 'PT Maju Bersama', 'kategori' => 'client', 'email pic' => 'rina@contoh.test',
                    'department pemilik' => 'Customer Service', 'sumber' => 'website', 'nama kontak utama' => 'Andi', 'email kontak' => 'andi@maju.test', 'kota' => 'Bandung'], $overrideRecord),
                ['_row' => 8, 'kode sumber' => 'LEG-002', 'nama' => 'Komunitas Sepeda', 'kategori' => 'Planet', 'email pic' => 'rina@contoh.test', 'department pemilik' => 'Customer Service'],
            ],
            'interactions' => [
                ['_row' => 6, 'kode sumber' => 'LI-001', 'kode client & partner' => 'LEG-001', 'tanggal' => '05/10/2026', 'jenis' => 'meeting',
                    'hasil pembicaraan' => 'Presentasi', 'tanggal follow-up' => '2026-10-25', 'email pencatat' => 'rina@contoh.test'],
            ],
            'deals' => [
                ['_row' => 6, 'kode sumber' => 'LD-001', 'kode client & partner' => 'LEG-001', 'lini bisnis' => 'Umum', 'stage' => 'Proposal', 'status' => 'Open',
                    'email pic' => 'rina@contoh.test', 'layanan' => 'Website', 'nilai penawaran (rp)' => 'Rp 120.000.000', 'tanggal dibuat' => '2026-09-01'],
                ['_row' => 7, 'kode sumber' => 'LD-002', 'kode client & partner' => 'LEG-001', 'lini bisnis' => 'Umum', 'status' => 'Won', 'email pic' => 'rina@contoh.test'],
            ],
        ];
    }

    public function test_check_reports_problem_rows_without_saving_anything(): void
    {
        $res = $this->as()->postJson('/api/imports/check', ['filename' => 'data.xlsx', 'sheets' => $this->sheets()])->assertCreated()->json('data');

        $this->assertCount(1, $res['records']);
        $this->assertTrue($res['records'][0]['isNew']);
        $this->assertCount(1, $res['ints']);
        $this->assertCount(1, $res['deals']);
        $this->assertTrue($res['skippedExample']);
        $this->assertSame([
            'Sheet 7 baris 8: Kategori "Planet" ga dikenal. Pakai Lead atau Client; KOL/Community/Media isi di kolom Layanan.',
            'Sheet 9 baris 7: Deal Won wajib ada nilai deal dan tanggal closing.',
        ], $res['errors']);
        // Pencocokan SRS 8.3: baris di file = baris lolos + baris ditolak.
        $this->assertSame($res['totalRows'], count($res['records']) + count($res['ints']) + count($res['deals']) + count($res['errors']));
        $this->assertSame(0, ClientPartner::count(), 'pemeriksaan tidak menyimpan apa pun');
    }

    public function test_commit_saves_rows_and_reimport_does_not_duplicate(): void
    {
        $id = $this->as()->postJson('/api/imports/check', ['filename' => 'data.xlsx', 'sheets' => $this->sheets()])->json('data.id');
        $history = $this->as()->postJson('/api/imports/'.$id.'/commit')->assertOk()->json('data');

        $record = ClientPartner::where('code', 'LEG-001')->first();
        $this->assertSame(Category::Client, $record->category);
        $this->assertSame('Website', $record->source);
        $this->assertSame('Andi', $record->contacts()->first()->name);
        $this->assertSame('2026-10-05', Interaction::where('code', 'LI-001')->first()->occurred_on->toDateString());
        $deal = Deal::where('code', 'LD-001')->first();
        $this->assertSame('120000000.00', $deal->offer_value);
        $this->assertSame('2026-09-01', $deal->created_at->toDateString(), 'tanggal dibuat dari file dipakai untuk filter periode');
        $this->assertCount(1, $history['created']['r']);
        $this->as()->postJson('/api/imports/'.$id.'/commit')->assertStatus(409);

        // Perbaiki file lalu import ulang: data lama diperbarui, interaksi dan deal yang sama ditolak.
        $again = $this->as()->postJson('/api/imports/check', ['filename' => 'data-v2.xlsx', 'sheets' => $this->sheets(['kota' => 'Cimahi'])])->json('data');
        $this->assertFalse($again['records'][0]['isNew']);
        $this->assertContains('Sheet 8 baris 6: Interaksi LI-001 udah pernah diimport.', $again['errors']);
        $this->as()->postJson('/api/imports/'.$again['id'].'/commit')->assertOk();

        $this->assertSame(1, ClientPartner::where('code', 'LEG-001')->count());
        $this->assertSame('Cimahi', $record->fresh()->city);
    }

    public function test_undo_removes_created_rows_and_restores_updated_ones(): void
    {
        $first = $this->as()->postJson('/api/imports/check', ['filename' => 'a.xlsx', 'sheets' => $this->sheets()])->json('data.id');
        $this->as()->postJson('/api/imports/'.$first.'/commit');
        $second = $this->as()->postJson('/api/imports/check', ['filename' => 'b.xlsx', 'sheets' => ['records' => [$this->sheets(['kota' => 'Garut'])['records'][1]]]])->json('data.id');
        $this->as()->postJson('/api/imports/'.$second.'/commit');
        $this->assertSame('Garut', ClientPartner::where('code', 'LEG-001')->value('city'));

        $this->as()->postJson('/api/imports/'.$second.'/undo')->assertOk()->assertJsonPath('data.undone', true);
        $this->assertSame('Bandung', ClientPartner::where('code', 'LEG-001')->value('city'), 'data diperbarui dikembalikan');

        $this->as()->postJson('/api/imports/'.$first.'/undo')->assertOk();
        $this->assertSame(0, ClientPartner::where('code', 'LEG-001')->count());
        $this->assertSame(0, Deal::where('code', 'LD-001')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'Batal', 'entity_type' => 'Import']);
    }

    public function test_undo_is_refused_once_imported_data_is_used(): void
    {
        $id = $this->as()->postJson('/api/imports/check', ['filename' => 'a.xlsx', 'sheets' => $this->sheets()])->json('data.id');
        $this->as()->postJson('/api/imports/'.$id.'/commit');
        $record = ClientPartner::where('code', 'LEG-001')->first();
        $this->as()->postJson('/api/interactions', ['recordId' => $record->id, 'type' => 'Telepon', 'date' => today()->toDateString(), 'result' => 'Lanjutan'])->assertCreated();

        $this->as()->postJson('/api/imports/'.$id.'/undo')->assertStatus(422);
        $this->assertSame(1, ClientPartner::where('code', 'LEG-001')->count());
    }

    public function test_viewer_cannot_import(): void
    {
        $viewer = User::factory()->inDepartment('Business Operation')->role(Role::Viewer)->create();

        $this->as($viewer)->postJson('/api/imports/check', ['filename' => 'a.xlsx', 'sheets' => $this->sheets()])->assertForbidden();
    }

    public function test_merge_moves_interactions_deals_and_contacts_then_archives_duplicate(): void
    {
        $keep = ClientPartner::create(['code' => 'CP-K', 'name' => 'PT Sinar Kreasi Nusantara', 'category' => Category::Client, 'pic_id' => $this->me->id, 'department_id' => $this->me->department_id]);
        $keep->contacts()->create(['name' => 'Andi', 'email' => 'andi@sinar.test', 'is_primary' => true]);
        $dup = ClientPartner::create(['code' => 'CP-D', 'name' => 'Sinar Kreasi Nusantara', 'category' => Category::Lead, 'pic_id' => $this->me->id, 'department_id' => $this->me->department_id, 'city' => 'Bandung']);
        $dup->contacts()->create(['name' => 'Andi W.', 'email' => 'ANDI@sinar.test', 'is_primary' => true]);
        $dup->contacts()->create(['name' => 'Lina', 'email' => 'lina@sinar.test']);
        $this->as()->postJson('/api/interactions', ['recordId' => $dup->id, 'type' => 'Telepon', 'date' => today()->toDateString(), 'result' => 'Kenalan'])->assertCreated();

        $res = $this->as()->postJson('/api/client-partners/'.$keep->id.'/merge', ['srcId' => $dup->id])->assertOk()->json();

        $this->assertSame(['interactions' => 1, 'deals' => 0, 'contacts' => 1], $res['moved']);
        $this->assertSame('Bandung', $res['data']['city'], 'kolom kosong diisi dari data duplikat');
        $this->assertSame(['Andi', 'Lina'], $keep->contacts()->orderBy('id')->pluck('name')->all());
        $this->assertSame($keep->id, $dup->fresh()->merged_into_id);
        $this->assertNotNull($dup->fresh()->archived_at);
        $this->assertSame(1, Interaction::where('client_partner_id', $keep->id)->count());

        $staff = User::factory()->inDepartment('CampusNet')->create();
        $this->as($staff)->postJson('/api/client-partners/'.$keep->id.'/merge', ['srcId' => $dup->id])->assertForbidden();
    }

    public function test_client_and_partner_imports_are_separate_modules(): void
    {
        $row = fn (string $code, array $extra = []) => array_merge(['_row' => 6, 'kode sumber' => $code, 'nama' => 'Data '.$code, 'email pic' => 'rina@contoh.test',
            'department pemilik' => 'Customer Service'], $extra);

        // Modul Client menolak baris Partner.
        $client = $this->as()->postJson('/api/imports/check', ['filename' => 'c.xlsx', 'sheets' => ['records' => [$row('C-1', ['kategori' => 'Partner'])]]])->json('data');
        $this->assertStringContainsString('diimport dari menu Partner', $client['errors'][0]);

        // Modul Partner: semua baris jadi Partner, dengan jenis dan bidang partner; sheet deal diabaikan.
        $partner = $this->as()->postJson('/api/imports/check', ['filename' => 'p.xlsx', 'module' => 'partner', 'sheets' => [
            'records' => [$row('P-1', ['jenis partner' => 'Vendor', 'bidang' => 'Event'])],
            'deals' => [['_row' => 6, 'kode sumber' => 'PD-1', 'kode client & partner' => 'P-1']],
        ]])->json('data');
        $this->assertSame([], $partner['errors']);
        $this->assertCount(0, $partner['deals']);
        $this->as()->postJson('/api/imports/'.$partner['id'].'/commit')->assertOk();
        $saved = ClientPartner::where('code', 'P-1')->first();
        $this->assertSame(['Partner', 'Vendor', 'Event'], [$saved->category->value, $saved->partner_type, $saved->segment]);

        // Kode yang sudah dipakai Partner tidak bisa ditimpa lewat import Client.
        $again = $this->as()->postJson('/api/imports/check', ['filename' => 'c2.xlsx', 'sheets' => ['records' => [$row('P-1', ['kategori' => 'Lead'])]]])->json('data');
        $this->assertStringContainsString('sudah dipakai data Partner', $again['errors'][0]);
    }
}
