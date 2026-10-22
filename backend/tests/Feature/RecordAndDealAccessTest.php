<?php

namespace Tests\Feature;

use App\Enums\Category;
use App\Enums\DealStatus;
use App\Enums\Role;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\User;
use App\Services\Access;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Aturan per data: PIC, nilai deal, buka kembali deal, filter periode, dan penyaringan field. */
class RecordAndDealAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function access(): Access
    {
        return app(Access::class);
    }

    private function user(string $dept, Role $role = Role::Staf): User
    {
        return User::factory()->inDepartment($dept)->role($role)->create();
    }

    private function record(User $pic, Category $category = Category::Lead, array $attrs = []): ClientPartner
    {
        static $n = 0;

        return ClientPartner::create($attrs + [
            'code' => 'CP-T'.++$n, 'name' => 'Data uji '.$n, 'category' => $category,
            'pic_id' => $pic->id, 'department_id' => $pic->department_id,
        ]);
    }

    private function deal(User $owner, DealStatus $status = DealStatus::Open, array $attrs = []): Deal
    {
        static $n = 0;
        $pipeline = Pipeline::with('stages')->where('department_id', $owner->department_id)->firstOrFail();
        $closing = in_array($status, [DealStatus::Won], true);

        return Deal::create($attrs + [
            'code' => 'DL-T'.++$n, 'title' => 'Deal uji '.$n, 'client_partner_id' => $this->record($owner, Category::Client)->id,
            'pipeline_id' => $pipeline->id, 'stage_id' => $closing ? $pipeline->stages->last()->id : $pipeline->stages->first()->id,
            'status' => $status, 'owner_id' => $owner->id, 'offer_value' => 50_000_000,
            'deal_value' => $closing ? 45_000_000 : null, 'closed_at' => in_array($status, [DealStatus::Won, DealStatus::Lost], true) ? now() : null,
            'lost_reason' => $status === DealStatus::Lost ? 'Pilih vendor lain' : null,
            'hold_reason' => $status === DealStatus::OnHold ? 'Menunggu anggaran' : null,
            'review_date' => $status === DealStatus::OnHold ? now()->addWeek() : null,
        ]);
    }

    public function test_level_c_edits_only_records_where_user_is_pic(): void
    {
        $a = $this->user('CampusNet');
        $b = $this->user('CampusNet');
        $own = $this->record($a);
        $other = $this->record($b);

        $this->assertTrue($this->access()->canEditRecord($a, $own));
        $this->assertFalse($this->access()->canEditRecord($a, $other));
        $this->assertFalse($this->access()->canArchiveRecord($a, $own), 'arsip butuh level M');
    }

    public function test_level_m_edits_and_archives_any_record_in_own_division_only(): void
    {
        $commercial = $this->user('Customer Service');
        $sameDivision = $this->record($this->user('Customer Service'), Category::Client);
        $otherDivision = $this->record($this->user('CampusNet'), Category::Client);

        $this->assertTrue($this->access()->canEditRecord($commercial, $sameDivision));
        $this->assertTrue($this->access()->canArchiveRecord($commercial, $sameDivision));
        $this->assertFalse($this->access()->canEditRecord($commercial, $otherDivision));
        $this->assertFalse($this->access()->canViewRecord($commercial, $otherDivision));
    }

    public function test_handover_follows_edit_right(): void
    {
        $staff = $this->user('CampusNet');
        $coord = $this->user('CampusNet', Role::Koordinator);
        $staffRecord = $this->record($staff);
        $coordRecord = $this->record($coord);

        $this->assertTrue($this->access()->canHandover($staff, $staffRecord), 'staf C atas data yang PIC-nya dia');
        $this->assertFalse($this->access()->canHandover($staff, $coordRecord), 'staf C atas data rekan');
        $this->assertTrue($this->access()->canHandover($coord, $staffRecord), 'Koordinator atas data divisinya');
        $this->assertTrue($this->access()->canHandover($this->user('Customer Service'), $this->record($this->user('Customer Service'))), 'level M di divisinya');
        $this->assertFalse($this->access()->canHandover($this->user('Customer Service'), $staffRecord), 'data divisi lain');
    }

    public function test_commercial_staff_sees_value_only_of_own_deals(): void
    {
        $staff = $this->user('Customer Service');
        $own = $this->deal($staff);
        $other = $this->deal($this->user('Customer Service', Role::KepalaDepartment));

        $this->assertTrue($this->access()->canSeeDealValue($staff, $own));
        $this->assertFalse($this->access()->canSeeDealValue($staff, $other));
    }

    public function test_hidden_deal_values_are_not_sent(): void
    {
        $owner = $this->user('Customer Service', Role::KepalaDepartment);
        $deal = $this->deal($owner);
        $payload = $deal->only(['id', 'title', 'offer_value', 'deal_value']);

        $forStaff = $this->access()->filterDeal($this->user('Business Operation'), $deal, $payload);
        $this->assertArrayNotHasKey('offer_value', $forStaff);
        $this->assertArrayNotHasKey('deal_value', $forStaff);
        $this->assertTrue($forStaff['value_hidden']);

        $forOwner = $this->access()->filterDeal($owner, $deal, $payload);
        $this->assertArrayHasKey('offer_value', $forOwner);
    }

    public function test_rate_card_is_removed_for_users_without_permission(): void
    {
        $kol = $this->record($this->user('Digital Campaign'), Category::Lead, ['rate_card' => ['rate' => 6_000_000]]);
        $payload = $kol->only(['id', 'name', 'rate_card']);

        $this->assertArrayHasKey('rate_card', $this->access()->filterClientPartner($this->user('Digital Campaign'), $payload));
        $this->assertArrayNotHasKey('rate_card', $this->access()->filterClientPartner($this->user('Customer Service', Role::Koordinator), $payload));
        $this->assertArrayHasKey('rate_card', $this->access()->filterClientPartner($this->user('Executive'), $payload), 'Executive selalu melihat rate card');
    }

    public function test_won_deal_reopens_only_by_super_admin_or_department_head_who_can_edit_it(): void
    {
        $owner = $this->user('Customer Service');
        $deal = $this->deal($owner, DealStatus::Won);

        $this->assertTrue($this->access()->canReopenDeal($this->user('Executive', Role::SuperAdmin), $deal));
        $this->assertTrue($this->access()->canReopenDeal($this->user('Customer Service', Role::KepalaDepartment), $deal));
        $this->assertTrue($this->access()->canReopenDeal($this->user('Executive', Role::KepalaDepartment), $deal), 'Kepala Department Executive (lintas divisi)');
        $this->assertFalse($this->access()->canReopenDeal($this->user('CampusNet', Role::KepalaDepartment), $deal), 'Kepala Department divisi lain');
        $this->assertFalse($this->access()->canReopenDeal($this->user('Customer Service', Role::Koordinator), $deal));
        $this->assertFalse($this->access()->canReopenDeal($owner, $deal), 'PIC tidak bisa buka deal Won');
    }

    public function test_lost_and_on_hold_deals_reopen_by_pic_or_their_lead(): void
    {
        foreach ([DealStatus::Lost, DealStatus::OnHold] as $status) {
            $owner = $this->user('Customer Service');
            $deal = $this->deal($owner, $status);

            $this->assertTrue($this->access()->canReopenDeal($owner, $deal), $status->value.' PIC');
            $this->assertTrue($this->access()->canReopenDeal($this->user('Customer Service', Role::Koordinator), $deal), $status->value.' atasan');
            $this->assertFalse($this->access()->canReopenDeal($this->user('Customer Service'), $deal), $status->value.' staf lain');
            $this->assertFalse($this->access()->canReopenDeal($this->user('Business Operation', Role::KepalaDepartment), $deal), $status->value.' lain department');
        }
    }

    public function test_open_deal_cannot_be_reopened(): void
    {
        $this->assertFalse($this->access()->canReopenDeal($this->user('Executive', Role::SuperAdmin), $this->deal($this->user('Customer Service'))));
    }

    public function test_period_filter_uses_created_date_for_running_deals(): void
    {
        $owner = $this->user('Customer Service');
        $from = Carbon::parse('2026-10-01');
        $to = Carbon::parse('2026-10-31');

        $createdInOct = $this->deal($owner, DealStatus::Open, ['target_closing' => '2026-12-15']);
        $createdInOct->forceFill(['created_at' => '2026-10-05 09:00'])->save();
        $createdInSep = $this->deal($owner, DealStatus::Open, ['target_closing' => '2026-10-20']);
        $createdInSep->forceFill(['created_at' => '2026-09-10 09:00'])->save();
        $wonInOct = $this->deal($owner, DealStatus::Won, ['closed_at' => '2026-10-12']);
        $wonInOct->forceFill(['created_at' => '2026-06-01 09:00'])->save();

        $ids = Deal::inPeriod($from, $to)->pluck('id')->all();

        $this->assertContains($createdInOct->id, $ids);
        $this->assertNotContains($createdInSep->id, $ids, 'target closing Oktober tidak dihitung, yang dipakai tanggal dibuat');
        $this->assertContains($wonInOct->id, $ids);
    }

    public function test_only_super_admin_and_department_head_configure_pipeline(): void
    {
        $this->assertTrue($this->access()->canConfigurePipeline($this->user('Customer Service', Role::KepalaDepartment)));
        $this->assertFalse($this->access()->canConfigurePipeline($this->user('Customer Service', Role::Koordinator)));

        // Pipeline per divisi: Kepala Department hanya mengatur pipeline divisinya, Executive semua.
        $cs = Pipeline::whereRelation('department', 'name', 'Customer Service')->first();
        $dc = Pipeline::whereRelation('department', 'name', 'Digital Campaign')->first();
        $head = $this->user('Customer Service', Role::KepalaDepartment);
        $this->assertTrue($this->access()->canConfigurePipeline($head, $cs));
        $this->assertFalse($this->access()->canConfigurePipeline($head, $dc));
        $this->assertTrue($this->access()->canConfigurePipeline($this->user('Executive', Role::KepalaDepartment), $dc));
        $this->assertFalse($this->access()->canViewPipeline($this->user('Customer Service'), $dc));
    }
}
