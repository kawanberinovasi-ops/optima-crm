<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Department;
use App\Models\Interaction;
use App\Models\Pipeline;
use App\Models\Service;
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
        $this->assertSame(9, User::count());
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

    public function test_matches_client_preview_structure(): void
    {
        // Pratinjau klien (22 Okt 2026): Commercial dilebur ke CS, satu pipeline per divisi, deal ikut divisi relasinya.
        $this->assertFalse(Department::where('name', 'Commercial')->exists());
        $this->assertSame(Department::count(), Pipeline::whereNotNull('department_id')->count());
        $this->assertSame(['KOL', 'Community', 'Media', 'Digital Campaign', 'CampusNet', 'DietPartner'], Service::orderBy('position')->pluck('name')->all());
        foreach (Deal::with('pipeline', 'clientPartner', 'owner')->get() as $deal) {
            $this->assertSame($deal->clientPartner->department_id, $deal->pipeline->department_id, $deal->code);
            $this->assertSame($deal->clientPartner->department_id, $deal->owner->department_id, $deal->code.' PIC deal dari divisi relasi');
        }
        $this->assertSame(['Client', 'CampusNet', 'CampusNet', 'dimas@contoh.test'], (fn ($r) => [$r->category->value, $r->service->name, $r->department->name, $r->pic->email])(ClientPartner::where('code', 'CP-0001')->first()));
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
