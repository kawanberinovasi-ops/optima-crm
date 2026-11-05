<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\Concerns\OpenAccessMatrix;
use Tests\TestCase;

/** Login dan sesi (SRS 4.1, bagian 3; FR-AUT-01..03, 05). */
class AuthSessionTest extends TestCase
{
    use OpenAccessMatrix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->openAccessMatrix();
        config(['optima.dev_login' => true]);
    }

    private function user(array $attrs = []): User
    {
        return User::factory()->inDepartment('Customer Service')->role(Role::Staf)->create($attrs);
    }

    public function test_registered_user_logs_in_and_gets_bootstrap(): void
    {
        $user = $this->user(['email' => 'rina@contoh.test']);

        $this->postJson('/api/auth/dev-login', ['email' => 'RINA@contoh.test'])->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/bootstrap')->assertOk()
            ->assertJsonPath('me.email', 'rina@contoh.test')
            ->assertJsonPath('me.dept', 'Customer Service')
            ->assertJsonPath('access.Customer Service.deal', 'M');
        $this->assertDatabaseHas('audit_logs', ['action' => 'Login', 'user_id' => $user->id]);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_unregistered_email_is_refused_and_audited(): void
    {
        $this->postJson('/api/auth/dev-login', ['email' => 'asing@contoh.test'])
            ->assertForbidden()->assertJsonPath('code', 'not_registered');

        $this->assertGuest();
        $this->assertTrue(AuditLog::where('action', 'Login ditolak')->where('summary', 'like', '%asing@contoh.test%')->exists());
    }

    public function test_inactive_account_is_refused(): void
    {
        $this->user(['email' => 'lama@contoh.test', 'active' => false]);

        $this->postJson('/api/auth/dev-login', ['email' => 'lama@contoh.test'])
            ->assertForbidden()->assertJsonPath('code', 'inactive');
        $this->assertGuest();
    }

    public function test_dev_login_does_not_exist_outside_local(): void
    {
        config(['optima.dev_login' => false]);
        $this->user(['email' => 'rina@contoh.test']);

        $this->postJson('/api/auth/dev-login', ['email' => 'rina@contoh.test'])->assertNotFound();
        $this->getJson('/api/session')->assertOk()->assertJsonPath('devLogin', false)->assertJsonPath('accounts', []);
    }

    public function test_api_needs_login(): void
    {
        $this->getJson('/api/bootstrap')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/client-partners')->assertUnauthorized();
    }

    public function test_session_ends_twelve_hours_after_login_even_when_active(): void
    {
        $user = $this->user();

        $this->actingAs($user)->withSession(['login_at' => now()->subHours(11)->timestamp])
            ->getJson('/api/bootstrap')->assertOk();

        $this->actingAs($user)->withSession(['login_at' => now()->subHours(12)->subMinute()->timestamp])
            ->getJson('/api/bootstrap')->assertUnauthorized()->assertJsonPath('code', 'session_expired');
        $this->assertGuest();
    }

    public function test_idle_lifetime_is_sixty_minutes(): void
    {
        $this->assertSame(60, (int) config('session.lifetime'));
        $this->assertSame(12, (int) config('optima.session_absolute_hours'));
    }

    public function test_deactivated_user_is_signed_out_immediately(): void
    {
        $user = $this->user();
        DB::table('sessions')->insert(['id' => 'sesi-uji', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);

        $user->update(['active' => false, 'deactivated_at' => now()]);

        $this->assertDatabaseMissing('sessions', ['id' => 'sesi-uji']);
        $this->actingAs($user)->withSession(['login_at' => now()->timestamp])
            ->getJson('/api/bootstrap')->assertUnauthorized()->assertJsonPath('code', 'account_inactive');
    }

    public function test_logout_ends_session_and_is_audited(): void
    {
        $user = $this->user();

        $this->actingAs($user)->withSession(['login_at' => now()->timestamp])->postJson('/api/auth/logout')->assertOk();

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'Logout', 'user_id' => $user->id]);
    }

    public function test_google_redirect_without_client_id_returns_to_login_with_message(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/api/auth/google/redirect')->assertRedirect('/#/login?err=sso_not_configured');
    }

    public function test_google_callback_logs_in_registered_user(): void
    {
        $user = $this->user(['email' => 'ayu@contoh.test']);
        $this->fakeGoogle('Ayu@Contoh.test', 'google-sub-1');

        $this->get('/api/auth/google/callback')->assertRedirect('/#/mywork');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-sub-1', $user->fresh()->sso_subject);
    }

    public function test_google_callback_refuses_unregistered_email(): void
    {
        $this->fakeGoogle('orang.lain@gmail.com', 'google-sub-2');

        $this->get('/api/auth/google/callback')->assertRedirect('/#/login?err=not_registered');
        $this->assertGuest();
    }

    public function test_google_callback_enforces_workspace_domain_when_set(): void
    {
        config(['services.google.hosted_domain' => 'campusinnovate.id']);
        $this->user(['email' => 'ayu@contoh.test']);
        $this->fakeGoogle('ayu@contoh.test', 'google-sub-3', hd: null);

        $this->get('/api/auth/google/callback')->assertRedirect('/#/login?err=wrong_domain');
        $this->assertGuest();
    }

    private function fakeGoogle(string $email, string $id, ?string $hd = null): void
    {
        $google = (new GoogleUser)->setRaw(array_filter(['email' => $email, 'email_verified' => true, 'hd' => $hd]))
            ->map(['id' => $id, 'email' => $email, 'name' => 'Pengguna Uji']);
        Socialite::shouldReceive('driver->user')->andReturn($google);
    }
}
