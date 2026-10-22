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

/** Migrasi struktur divisi untuk database yang sudah berisi data (Commercial, pipeline lama tanpa divisi). */
class DivisionPipelineMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_commercial_merges_into_cs_and_deals_move_to_division_pipelines(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $migration = require database_path('migrations/2026_10_22_000002_division_pipelines.php');
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

        $cs = Department::where('name', 'Customer Service')->first();
        $this->assertFalse(Department::where('name', 'Commercial')->exists());
        $this->assertSame($cs->id, $sales->fresh()->department_id);
        $this->assertSame($cs->id, $record->fresh()->department_id, 'ID data tetap, divisi pindah ke CS');
        $this->assertNotNull(DB::table('pipelines')->where('id', $umum)->value('archived_at'), 'pipeline lama diarsipkan, tidak dihapus');

        $line = Pipeline::where('department_id', $cs->id)->first();
        $this->assertSame('Customer Service', $line->name);
        $this->assertSame(['Proposal', 'Open'], [Deal::find($open)->stage->name, Deal::find($open)->status->value]);
        $this->assertSame($line->id, Deal::find($open)->pipeline_id);
        $this->assertTrue(Deal::find($won)->stage->is_closing);
        $this->assertSame(Department::count(), Pipeline::whereNotNull('department_id')->whereNull('archived_at')->count(), 'satu pipeline per divisi');
        $this->assertSame('M', AccessGrant::where(['department_id' => $cs->id, 'entity' => 'interaksi'])->value('level'));
        $this->assertSame('C', AccessGrant::where(['department_id' => Department::where('name', 'CampusNet')->value('id'), 'entity' => 'deal'])->value('level'));
    }
}
