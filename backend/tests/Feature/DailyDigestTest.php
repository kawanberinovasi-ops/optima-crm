<?php

namespace Tests\Feature;

use App\Enums\Category;
use App\Mail\DailyDigest;
use App\Models\ClientPartner;
use App\Models\Interaction;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/** Email ringkasan follow-up 08.00 (SRS 4.2, 8.2; FR-INT-05). */
class DailyDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        config(['optima.mail_allowed_domains' => ['contoh.test'], 'optima.mail_daily_limit' => 200]);
    }

    private function followup(string $email, int $daysFromToday): User
    {
        static $n = 0;
        $user = User::where('email', $email)->first() ?? User::factory()->inDepartment('Commercial')->create(['email' => $email]);
        $record = ClientPartner::create(['code' => 'CP-G'.++$n, 'name' => 'Relasi '.$n, 'category' => Category::Client, 'pic_id' => $user->id, 'department_id' => $user->department_id]);
        Interaction::create(['code' => 'INT-G'.$n, 'client_partner_id' => $record->id, 'user_id' => $user->id, 'occurred_on' => today()->subDays(5),
            'type' => 'Telepon', 'result' => 'x', 'next_action' => 'Kirim proposal', 'followup_on' => today()->addDays($daysFromToday)]);

        return $user;
    }

    public function test_sends_digest_for_due_and_late_followups_only(): void
    {
        Mail::fake();
        $rina = $this->followup('rina@contoh.test', 0);
        $this->followup('rina@contoh.test', -3);
        $this->followup('rina@contoh.test', 4); // belum jatuh tempo
        $free = User::factory()->inDepartment('Commercial')->create(['email' => 'santai@contoh.test']);

        $this->artisan('optima:daily-digest')->assertSuccessful();

        Mail::assertSent(DailyDigest::class, fn (DailyDigest $m) => $m->hasTo('rina@contoh.test') && $m->items->count() === 2 && $m->late === 1);
        Mail::assertSent(DailyDigest::class, 1);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $rina->id, 'text' => 'Ringkasan hari ini: 2 follow-up jatuh tempo, 1 telat']);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $free->id]);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'Email ringkasan']);
    }

    public function test_addresses_outside_allowed_domains_get_in_app_notification_only(): void
    {
        Mail::fake();
        $client = $this->followup('budi@optima.co.id', 0);

        $this->artisan('optima:daily-digest')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertDatabaseHas('user_notifications', ['user_id' => $client->id]);
    }

    public function test_daily_mail_limit_is_respected(): void
    {
        Mail::fake();
        config(['optima.mail_daily_limit' => 2]);
        foreach (['a', 'b', 'c'] as $u) {
            $this->followup($u.'@contoh.test', 0);
        }

        $this->artisan('optima:daily-digest')->assertSuccessful();

        Mail::assertSent(DailyDigest::class, 2);
    }

    public function test_mail_failure_does_not_stop_other_users_or_notifications(): void
    {
        $this->followup('a@contoh.test', 0);
        $b = $this->followup('b@contoh.test', 0);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP mati'));

        $this->artisan('optima:daily-digest')->assertSuccessful();

        $this->assertDatabaseHas('user_notifications', ['user_id' => $b->id]);
    }

    public function test_cancelled_done_and_archived_followups_are_ignored(): void
    {
        Mail::fake();
        $u = $this->followup('rina@contoh.test', 0);
        Interaction::query()->update(['cancelled_at' => now(), 'cancel_reason' => 'salah']);

        $this->artisan('optima:daily-digest')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $u->id]);
    }

    public function test_digest_is_scheduled_at_eight_jakarta_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'optima:daily-digest'));

        $this->assertSame('0 8 * * *', $event->expression);
        $this->assertSame('Asia/Jakarta', $event->timezone);
    }
}
