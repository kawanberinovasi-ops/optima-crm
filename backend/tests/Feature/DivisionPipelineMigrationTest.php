<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Department;
use App\Models\Pipeline;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migrasi berurutan untuk database yang sudah berisi data (Commercial, pipeline lama tanpa divisi):
 * struktur divisi 22 Okt, lalu revisi klien 5 Nov (pipeline Sales dan Campaign, jenis Partner).
 */
class DivisionPipelineMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_commercial_merges_into_cs_and_deals_end_in_sales_pipeline(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $revision = require database_path('migrations/2026_11_05_000002_client_revision_pipelines_partner.php');
        $perDivision = require database_path('migrations/2026_11_05_000003_pipeline_per_division.php');
        $migration = require database_path('migrations/2026_10_22_000002_division_pipelines.php');
        $revision->down();
        $migration->down();

        // Keadaan sebelum migrasi: department Commercial dan satu pipeline umum.
        DB::table('stages')->delete();
        DB::table('pipelines')->delete();
        $commercial = Department::create(['name' => 'Commercial']);
        AccessGrant::create(['department_id' => $commercial->id, 'entity' => 'deal', 'level' => 'M']);
        $umum = DB::table('pipelines')->insertGetId(['name' => 'Umum', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $stage = [];
        foreach (['Lead', 'Meeting', 'Proposal', 'Closing'] as $i => $name) {
            $stage[$name] = DB::table('stages')->insertGetId(['pipeline_id' => $umum, 'name' => $name, 'position' => $i, 'is_closing' => $name === 'Closing', 'created_at' => now(), 'updated_at' => now()]);
        }
        $sales = User::factory()->inDepartment('Commercial')->role(Role::Staf)->create();
        $record = ClientPartner::create(['code' => 'CP-OLD', 'name' => 'PT Lama', 'category' => 'Client', 'pic_id' => $sales->id, 'department_id' => $commercial->id]);
        $deal = fn (string $code, string $st, string $status) => DB::table('deals')->insertGetId(['code' => $code, 'title' => $code, 'client_partner_id' => $record->id,
            'pipeline_id' => $umum, 'stage_id' => $stage[$st], 'status' => $status, 'owner_id' => $sales->id, 'created_at' => now(), 'updated_at' => now()]
            + ($status === 'Won' ? ['deal_value' => 1000000, 'closed_at' => today()] : []));
        $open = $deal('DL-OLD1', 'Proposal', 'Open');
        $won = $deal('DL-OLD2', 'Closing', 'Won');

        $migration->up();
        $revision->up();

        $cs = Department::where('name', 'Customer Service')->first();
        $this->assertFalse(Department::where('name', 'Commercial')->exists());
        $this->assertSame($cs->id, $sales->fresh()->department_id);
        $this->assertSame($cs->id, $record->fresh()->department_id, 'ID data tetap, divisi pindah ke CS');
        $this->assertNotNull(DB::table('pipelines')->where('id', $umum)->value('archived_at'), 'pipeline lama diarsipkan, tidak dihapus');

        $active = Pipeline::whereNull('archived_at')->orderBy('position')->get();
        $this->assertSame(['Sales', 'Campaign'], $active->pluck('name')->all(), 'pipeline divisi lain digabung ke Sales');
        $sales = $active->first();
        $this->assertSame(['Proposal', 'Open'], [Deal::find($open)->stage->name, Deal::find($open)->status->value]);
        $this->assertSame($sales->id, Deal::find($open)->pipeline_id);
        $this->assertTrue(Deal::find($won)->stage->is_closing);
        $this->assertSame(['Brief', 'Konsep', 'Produksi', 'Tayang', 'Report', 'Closing'], $active->last()->stages()->pluck('name')->all());
        $this->assertSame(['Digital Campaign'], $active->last()->departments()->pluck('name')->all());
        $this->assertTrue($sales->departments()->whereKey($cs->id)->exists());
        $this->assertSame('M', AccessGrant::where(['department_id' => $cs->id, 'entity' => 'interaksi'])->value('level'));
        $this->assertSame('C', AccessGrant::where(['department_id' => Department::where('name', 'CampusNet')->value('id'), 'entity' => 'lead'])->value('level'));

        // Lanjutan revisi: Sales dipecah jadi pipeline per divisi; deal ikut divisi PIC relasinya.
        $perDivision->up();
        $active = Pipeline::with('departments')->whereNull('archived_at')->orderBy('position')->get();
        $this->assertSame('Customer Service', Pipeline::find($sales->id)->name, 'pipeline Sales lama dipakai Customer Service');
        $this->assertSame($sales->id, Deal::find($open)->pipeline_id, 'PIC deal di Customer Service');
        $this->assertSame('Proposal', Deal::find($open)->stage->name);
        foreach (Department::all() as $dept) {
            $this->assertCount(1, $active->filter(fn ($p) => $p->departments->contains('id', $dept->id)), $dept->name);
        }
        $this->assertSame(['Lead', 'Qualified', 'Meeting', 'Proposal', 'Negotiation', 'Closing'], $active->firstWhere('name', 'CampusNet')->stages()->pluck('name')->all());
    }
}
