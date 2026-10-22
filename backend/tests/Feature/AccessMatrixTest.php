<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\Category;
use App\Enums\Role;
use App\Models\AccessOverride;
use App\Models\User;
use App\Services\Access;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Matriks hak akses awal: tabel SRS bagian 3 (halaman 4) dengan keputusan 22 Okt 2026 (mengikuti pratinjau klien).
 * - Kolom Community, KOL/KOC, dan Media dihapus: sekarang layanan, bukan status relasi.
 * - Commercial dilebur ke Customer Service. Executive dan CS mengelola (M), divisi lain mengelola datanya sendiri (C).
 * Tabel ditulis di sini, bukan dibaca dari seeder, supaya selisih dengan keputusan bisnis langsung ketahuan.
 */
class AccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    /** Department => [Lead, Client, Interaksi, Deal, Nilai deal, Rate card] */
    private const SRS = [
        'Executive' => ['M', 'M', 'M', 'M', 'Ya', 'M'],
        'Customer Service' => ['M', 'M', 'M', 'M', 'Ya*', 'Tidak'],
        'Digital Campaign' => ['C', 'C', 'C', 'C', 'Ya*', 'M'],
        'Creative & Media' => ['C', 'C', 'C', 'C', 'Ya*', 'Tidak'],
        'CampusNet' => ['C', 'C', 'C', 'C', 'Ya*', 'Tidak'],
        'DietPartner' => ['C', 'C', 'C', 'C', 'Ya*', 'Tidak'],
        'Business Operation' => ['C', 'C', 'C', 'C', 'Ya*', 'Tidak'],
    ];

    private const ENTITIES = ['lead', 'client', 'interaksi', 'deal'];

    public static function departments(): array
    {
        return array_map(fn ($d) => [$d], array_combine(array_keys(self::SRS), array_keys(self::SRS)));
    }

    private function access(): Access
    {
        return app(Access::class);
    }

    private function user(string $dept, Role $role = Role::Staf): User
    {
        return User::factory()->inDepartment($dept)->role($role)->create();
    }

    #[DataProvider('departments')]
    public function test_data_levels_match_srs_for_department_head(string $dept): void
    {
        $head = $this->user($dept, Role::KepalaDepartment);

        foreach (self::ENTITIES as $i => $entity) {
            $this->assertSame(self::SRS[$dept][$i], $this->access()->level($head, $entity)->value, "$dept / $entity");
        }
    }

    #[DataProvider('departments')]
    public function test_deal_value_and_rate_card_follow_srs_stars(string $dept): void
    {
        [$nilai, $rate] = array_slice(self::SRS[$dept], 4);
        $head = $this->user($dept, Role::KepalaDepartment);
        $coord = $this->user($dept, Role::Koordinator);
        $staff = $this->user($dept, Role::Staf);

        // Ya: semua role. Ya* dan Lihat*: hanya Kepala Department dan Koordinator. Tidak: tidak ada.
        $leadSees = in_array($nilai, ['Ya', 'Ya*', 'Lihat*'], true);
        $this->assertSame($leadSees, $this->access()->canSeeDealValue($head), "$dept head nilai");
        $this->assertSame($leadSees, $this->access()->canSeeDealValue($coord), "$dept koordinator nilai");
        $this->assertSame($nilai === 'Ya', $this->access()->canSeeDealValue($staff), "$dept staf nilai");

        $leadSeesRate = in_array($rate, ['Ya', 'M', 'R*'], true);
        $this->assertSame($leadSeesRate, $this->access()->canSeeRateCard($head), "$dept head rate");
        $this->assertSame(in_array($rate, ['Ya', 'M'], true), $this->access()->canSeeRateCard($staff), "$dept staf rate");
    }

    public function test_viewer_can_only_read_even_in_executive(): void
    {
        $viewer = $this->user('Executive', Role::Viewer);

        foreach (self::ENTITIES as $entity) {
            $this->assertSame(AccessLevel::R, $this->access()->level($viewer, $entity), $entity);
        }
        $this->assertFalse($this->access()->canCreateAnyRecord($viewer));
    }

    public function test_viewer_is_capped_at_read_even_with_personal_override(): void
    {
        $viewer = $this->user('Digital Campaign', Role::Viewer);
        $this->assertSame(AccessLevel::R, $this->access()->level($viewer, 'deal'));

        AccessOverride::create(['user_id' => $viewer->id, 'entity' => 'deal', 'level' => 'M']);
        $this->access()->forget($viewer);
        $this->assertSame(AccessLevel::R, $this->access()->level($viewer, 'deal'));
    }

    public function test_super_admin_always_has_full_access(): void
    {
        $admin = $this->user('Business Operation', Role::SuperAdmin);

        foreach (self::ENTITIES as $entity) {
            $this->assertSame(AccessLevel::M, $this->access()->level($admin, $entity));
        }
        $this->assertTrue($this->access()->canSeeDealValue($admin));
        $this->assertTrue($this->access()->canSeeRateCard($admin));
        $this->assertTrue($this->access()->canAdminister($admin));
    }

    public function test_only_super_admin_administers_users_and_access(): void
    {
        foreach ([Role::KepalaDepartment, Role::Koordinator, Role::Staf, Role::Viewer] as $role) {
            $this->assertFalse($this->access()->canAdminister($this->user('Executive', $role)), $role->value);
        }
        $this->assertTrue($this->access()->canViewAdmin($this->user('Customer Service', Role::KepalaDepartment)));
        $this->assertFalse($this->access()->canViewAdmin($this->user('Customer Service', Role::Koordinator)));
    }

    public function test_inactive_user_has_no_access_at_all(): void
    {
        $user = User::factory()->inDepartment('Executive')->role(Role::SuperAdmin)->inactive()->create();

        $this->assertSame(AccessLevel::N, $this->access()->level($user, 'client'));
        $this->assertFalse($this->access()->canSeeDealValue($user));
        $this->assertFalse($this->access()->canAdminister($user));
    }

    public function test_personal_override_beats_department_matrix(): void
    {
        $staff = $this->user('CampusNet');
        $this->assertSame(AccessLevel::C, $this->access()->level($staff, 'deal'));
        $this->assertFalse($this->access()->canSeeDealValue($staff));

        AccessOverride::create(['user_id' => $staff->id, 'entity' => 'deal', 'level' => 'M', 'reason' => 'Bantu tim CS']);
        AccessOverride::create(['user_id' => $staff->id, 'entity' => 'nilai', 'level' => 'Ya']);
        $this->access()->forget($staff);

        $this->assertSame(AccessLevel::M, $this->access()->level($staff, 'deal'));
        $this->assertTrue($this->access()->canSeeDealValue($staff));
    }

    public function test_visible_categories_filter_client_partner_queries(): void
    {
        $digital = $this->user('Digital Campaign');

        $this->assertSame(Category::cases(), $this->access()->visibleCategories($digital));

        AccessOverride::create(['user_id' => $digital->id, 'entity' => 'client', 'level' => 'N']);
        $this->access()->forget($digital);

        $this->assertNotContains(Category::Client, $this->access()->visibleCategories($digital));
    }
}
