<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Akun uji @contoh.test dihapus setelah akun internal dimuat; datanya dialihkan ke kepala divisinya. */
class ReplaceDemoAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_accounts_are_removed_and_their_data_moves_to_division_heads(): void
    {
        $this->seed(DatabaseSeeder::class);
        $file = tempnam(sys_get_temp_dir(), 'internal');
        // Email contoh untuk tes saja; daftar asli hanya ada di storage/app/private (tidak ikut git).
        file_put_contents($file, json_encode([
            ['department' => 'Executive', 'name' => 'Kepala Exec', 'email' => 'exec@kantor.test', 'alternate_emails' => ['exec.alt@kantor.test'], 'role' => 'Super Admin'],
            ['department' => 'Customer Service', 'name' => 'Kepala CS', 'email' => 'cs@kantor.test', 'alternate_emails' => []],
            ['department' => 'CampusNet', 'name' => 'Kepala CN', 'email' => 'cn@kantor.test', 'alternate_emails' => []],
        ]));
        $records = ClientPartner::count();
        $deals = Deal::count();

        $this->artisan('optima:sync-internal-users', ['file' => $file, '--replace-demo' => true])->assertSuccessful();
        unlink($file);

        $this->assertSame(0, User::where('email', 'like', '%@contoh.test')->count(), 'akun uji terhapus');
        $this->assertSame(3, User::count());
        $this->assertSame([$records, $deals], [ClientPartner::count(), Deal::count()], 'data contoh tidak hilang');
        $cn = User::where('email', 'cn@kantor.test')->first();
        $this->assertSame($cn->id, ClientPartner::where('code', 'CP-0001')->value('pic_id'), 'data CampusNet ke kepala CampusNet');
        $admin = User::where('email', 'exec@kantor.test')->first();
        $this->assertSame(Role::SuperAdmin, $admin->role);
        $this->assertSame($admin->id, User::findByAnyEmail('exec.alt@kantor.test')?->id, 'email alternatif tetap bisa login');
        // Divisi tanpa kepala (Digital Campaign) jatuh ke Super Admin.
        $this->assertSame(0, ClientPartner::whereNotIn('pic_id', User::pluck('id'))->count());
        $this->assertTrue(ClientPartner::where('department_id', Department::where('name', 'Digital Campaign')->value('id'))->where('pic_id', $admin->id)->exists());
    }

    public function test_accounts_can_be_read_from_an_environment_variable(): void
    {
        $this->seed(DatabaseSeeder::class);
        // Server tanpa akses file (Laravel Cloud): JSON disimpan di environment variable, boleh base64.
        $json = json_encode([['department' => 'Executive', 'name' => 'Kepala Exec', 'email' => 'exec@kantor.test', 'role' => 'Super Admin']]);
        putenv('OPTIMA_INTERNAL_USERS_JSON='.base64_encode($json));
        try {
            $this->artisan('optima:sync-internal-users', ['--from-env' => true])->assertSuccessful();
        } finally {
            putenv('OPTIMA_INTERNAL_USERS_JSON');
        }
        $this->assertSame(Role::SuperAdmin, User::where('email', 'exec@kantor.test')->first()?->role);

        $this->artisan('optima:sync-internal-users', ['--from-env' => true])->assertFailed();
    }
}
