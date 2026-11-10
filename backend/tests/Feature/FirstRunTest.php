<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** optima:first-run dipanggil setiap container start di hosting tanpa console, jadi harus aman diulang. */
class FirstRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_an_empty_database_once_and_syncs_internal_accounts(): void
    {
        putenv('OPTIMA_INTERNAL_USERS_JSON='.json_encode([['department' => 'Executive', 'name' => 'Kepala Exec', 'email' => 'exec@kantor.test', 'role' => 'Super Admin']]));
        try {
            $this->artisan('optima:first-run')->assertSuccessful();
            $departments = Department::count();
            $this->artisan('optima:first-run')->assertSuccessful();
        } finally {
            putenv('OPTIMA_INTERNAL_USERS_JSON');
        }

        $this->assertGreaterThan(0, $departments);
        $this->assertSame($departments, Department::count(), 'seed tidak diulang');
        $this->assertSame(Role::SuperAdmin, User::where('email', 'exec@kantor.test')->first()?->role);
        $this->assertSame(0, User::where('email', 'like', '%@contoh.test')->count(), 'akun uji diganti akun internal');
    }

    public function test_without_account_list_it_only_seeds(): void
    {
        $this->artisan('optima:first-run')->assertSuccessful();
        $this->assertTrue(Department::query()->exists());
    }
}
