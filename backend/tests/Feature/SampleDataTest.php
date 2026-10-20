<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Interaction;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Data contoh harus termuat lengkap, karena dipakai untuk demo dan UAT (SRS 8.3, syarat G2). */
class SampleDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_sample_data_matches_prototype(): void
    {
        $this->assertSame(7, User::count());
        $this->assertSame(19, ClientPartner::count());
        $this->assertSame(14, Interaction::count());
        $this->assertSame(25, Deal::count());
    }

    public function test_every_role_has_a_test_account(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertTrue(User::where('role', $role)->where('active', true)->exists(), $role->value);
        }
    }

    public function test_each_pipeline_ends_with_one_closing_stage(): void
    {
        foreach (Stage::query()->get()->groupBy('pipeline_id') as $stages) {
            $ordered = $stages->sortBy('position')->values();
            $this->assertTrue($ordered->last()->is_closing);
            $this->assertSame(1, $ordered->where('is_closing', true)->count());
        }
    }
}
