<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\Backup;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Persiapan deploy: paket frontend, header keamanan, Super Admin pertama, backup dan pemulihan (SRS 8.1, 9.4). */
class DeployTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_frontend_copies_assets_and_versions_them(): void
    {
        $tmp = sys_get_temp_dir().'/optima-build-'.uniqid();
        $this->artisan('optima:build-frontend', ['--public' => $tmp.'/public', '--view' => $tmp.'/view'])->assertSuccessful();

        $this->assertFileExists($tmp.'/public/assets/api.js');
        $this->assertFalse(is_link($tmp.'/public/assets'));
        $html = file_get_contents($tmp.'/view/index.html');
        $this->assertMatchesRegularExpression('/src="assets\/api\.js\?v=[0-9a-f]{10}"/', $html);
        $this->assertMatchesRegularExpression('/href="assets\/app\.css\?v=[0-9a-f]{10}"/', $html);
        File::deleteDirectory($tmp);
    }

    public function test_frontend_page_gets_api_flag_and_security_headers(): void
    {
        $res = $this->get('/')->assertOk();

        $this->assertStringContainsString('window.OPTIMA_API = "\/api"', $res->getContent());
        $res->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/session')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_hsts_over_https_behind_proxy(): void
    {
        $this->get('/up', ['X-Forwarded-Proto' => 'https'])->assertHeader('Strict-Transport-Security');
    }

    public function test_no_hsts_over_plain_http(): void
    {
        $this->get('/up')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_create_first_super_admin(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $this->artisan('optima:create-admin', ['email' => 'Kepala@Optima.co.id', 'name' => 'Kepala OPTIMA'])->assertSuccessful();
        $this->artisan('optima:create-admin', ['email' => 'x@optima.co.id', 'name' => 'X', '--dept' => 'Tidak Ada'])->assertFailed();

        $user = User::where('email', 'kepala@optima.co.id')->first();
        $this->assertSame(Role::SuperAdmin, $user->role);
        $this->assertTrue($user->active);
    }

    public function test_backup_and_restore_round_trip_with_matching_checksums(): void
    {
        Storage::fake('backups');
        $this->seed(DatabaseSeeder::class);
        DB::table('client_partners')->where('code', 'CP-0019')->update(['merged_into_id' => DB::table('client_partners')->where('code', 'CP-0001')->value('id')]);
        $before = $this->snapshot();

        $this->artisan('optima:backup', ['--disk' => 'backups'])->assertSuccessful();
        $path = app(Backup::class)->latest('backups');
        $this->assertNotNull($path);

        // Kosongkan semua tabel (urutan terbalik foreign key), lalu pulihkan.
        foreach (array_reverse(Backup::TABLES) as $table) {
            DB::table($table)->delete();
        }
        $this->artisan('optima:restore', ['path' => $path, '--disk' => 'backups', '--force' => true])->assertSuccessful();

        $after = $this->snapshot();
        $this->assertSame(array_keys($before), array_keys($after));
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, $after[$table], $table);
        }
        // Sequence di-set ulang: data baru tidak bentrok dengan id lama.
        $this->assertGreaterThan(DB::table('client_partners')->max('id'), DB::selectOne("select nextval('client_partners_id_seq') as n")->n);
    }

    public function test_restore_refuses_non_empty_database_and_corrupted_files(): void
    {
        Storage::fake('backups');
        $this->seed(DatabaseSeeder::class);
        $this->artisan('optima:backup', ['--disk' => 'backups'])->assertSuccessful();
        $path = app(Backup::class)->latest('backups');

        $this->artisan('optima:restore', ['path' => $path, '--disk' => 'backups', '--force' => true])->assertFailed();

        // File yang diubah isinya ditolak oleh checksum, dan tidak ada data yang masuk.
        foreach (array_reverse(Backup::TABLES) as $table) {
            DB::table($table)->delete();
        }
        $lines = explode("\n", gzdecode(Storage::disk('backups')->get($path)));
        $this->assertStringContainsString('Executive', $lines[1]);
        $lines[1] = str_replace('Executive', 'Eksekutif', $lines[1]);
        Storage::disk('backups')->put('backups/rusak.ndjson.gz', gzencode(implode("\n", $lines)));

        $this->artisan('optima:restore', ['path' => 'backups/rusak.ndjson.gz', '--disk' => 'backups', '--force' => true])->assertFailed();
        $this->assertSame(0, DB::table('departments')->count());
    }

    public function test_old_backups_are_pruned(): void
    {
        Storage::fake('backups');
        Storage::disk('backups')->put(Backup::PREFIX.now()->subDays(40)->format('Ymd-His').'.ndjson.gz', 'lama');
        Storage::disk('backups')->put(Backup::PREFIX.now()->subDays(2)->format('Ymd-His').'.ndjson.gz', 'baru');

        $deleted = app(Backup::class)->prune(30, 'backups');

        $this->assertCount(1, $deleted);
        $this->assertCount(1, Storage::disk('backups')->files('backups'));
    }

    public function test_backup_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'optima:backup'));

        $this->assertSame('0 1 * * *', $event->expression);
        $this->assertSame('Asia/Jakarta', $event->timezone);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(): array
    {
        $out = [];
        foreach (Backup::TABLES as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $out;
    }
}
