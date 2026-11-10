<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use App\Support\CsvSafe;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OpenAccessMatrix;
use Tests\TestCase;

/**
 * Pengetatan keamanan 10 Nov 2026: fitur pengembangan terkunci ke laptop, header browser ketat,
 * data pengguna diminimalkan, batas permintaan, dan export aman dari formula injection.
 */
class SecurityHardeningTest extends TestCase
{
    use OpenAccessMatrix, RefreshDatabase;

    private const TUNNEL = ['HTTP_HOST' => 'contoh-tunnel.trycloudflare.com', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->openAccessMatrix();
        config(['optima.dev_login' => true]);
    }

    public function test_dev_login_only_works_from_the_developer_laptop(): void
    {
        User::factory()->inDepartment('Customer Service')->create(['email' => 'rina@contoh.test']);

        $this->withServerVariables(self::TUNNEL)->postJson('/api/auth/dev-login', ['email' => 'rina@contoh.test'])->assertNotFound();
        $this->withServerVariables(self::TUNNEL)->getJson('/api/session')->assertJsonPath('devLogin', false)->assertJsonPath('accounts', []);
        $this->assertGuest();

        $this->serverVariables = [];
        $this->withServerVariables(['HTTP_HOST' => '127.0.0.1', 'REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/auth/dev-login', ['email' => 'rina@contoh.test'])->assertOk();
    }

    public function test_debug_details_never_leave_the_laptop(): void
    {
        config(['app.debug' => true]);

        $this->withServerVariables(self::TUNNEL)->getJson('/api/tidak-ada')->assertNotFound()->assertJsonMissingPath('exception');
    }

    public function test_page_has_strict_csp_with_nonce_for_the_only_inline_script(): void
    {
        $res = $this->get('/');
        $res->assertOk();
        $csp = $res->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([^']+)'/", $csp);
        preg_match("/'nonce-([^']+)'/", $csp, $m);
        $this->assertStringContainsString('<script nonce="'.$m[1].'">window.OPTIMA_API', $res->getContent());
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertSame(SecurityHeaders::csp(), $this->getJson('/api/session')->headers->get('Content-Security-Policy'));
    }

    public function test_session_cookie_is_secure_over_https_and_encrypted(): void
    {
        // Cloudflare Tunnel meneruskan ke http://127.0.0.1 dengan X-Forwarded-Proto: https.
        $this->withServerVariables(self::TUNNEL + ['HTTP_X_FORWARDED_PROTO' => 'https'])->get('/api/session');
        $this->assertTrue(config('session.secure'));
        $this->assertTrue(config('session.encrypt'));
    }

    public function test_other_users_emails_are_only_sent_to_super_admin(): void
    {
        $staf = User::factory()->inDepartment('Customer Service')->role(Role::Staf)->create(['email' => 'staf@contoh.test']);
        $lain = User::factory()->inDepartment('CampusNet')->create(['email' => 'lain@contoh.test']);
        $admin = User::factory()->inDepartment('Executive')->role(Role::SuperAdmin)->create();

        $users = collect($this->actingAs($staf)->withSession(['login_at' => now()->timestamp])->getJson('/api/bootstrap')->assertOk()->json('users'))->keyBy('id');
        $this->assertSame('staf@contoh.test', $users[(string) $staf->id]['email']);
        $this->assertSame('', $users[(string) $lain->id]['email']);
        $this->assertSame($lain->name, $users[(string) $lain->id]['name']);

        $users = collect($this->actingAs($admin)->withSession(['login_at' => now()->timestamp])->getJson('/api/bootstrap')->json('users'))->keyBy('id');
        $this->assertSame('lain@contoh.test', $users[(string) $lain->id]['email']);
    }

    public function test_login_endpoints_are_rate_limited(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/auth/dev-login', ['email' => 'tidak.ada@contoh.test']);
        }
        $this->postJson('/api/auth/dev-login', ['email' => 'tidak.ada@contoh.test'])->assertStatus(429);
    }

    public function test_csv_cells_cannot_become_formulas(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", CsvSafe::cell('=HYPERLINK("x")'));
        $this->assertSame("'+62812", CsvSafe::cell('+62812'));
        $this->assertSame("'@SUM(A1)", CsvSafe::cell('@SUM(A1)'));
        $this->assertSame('PT Aman', CsvSafe::cell('PT Aman'));
        $this->assertSame(12, CsvSafe::cell(12));
    }
}
