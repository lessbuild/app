<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustHosts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_security_headers_cover_public_authenticated_api_and_error_responses(): void
    {
        $this->assertSecurityHeaders($this->get('/')->assertSuccessful());
        $this->assertSecurityHeaders($this->get(route('login'))->assertSuccessful());
        $this->assertSecurityHeaders($this->get(route('health'))->assertSuccessful());
        $this->assertSecurityHeaders($this->get('/missing-page')->assertNotFound());

        $this->assertSecurityHeaders(
            $this->actingAs(User::factory()->create())
                ->get(route('dashboard'))
                ->assertSuccessful(),
        );
    }

    public function test_untrusted_hosts_are_rejected_while_application_and_loopback_hosts_work(): void
    {
        $this->get('http://attacker.example/')->assertStatus(400);
        $this->get('http://localhost/')->assertSuccessful();
        $this->get('http://127.0.0.1/api/health')->assertSuccessful();
    }

    public function test_configured_host_aliases_are_exact_matches(): void
    {
        config(['lessbuild.trusted_hosts' => ['control.example.test']]);
        $pattern = '^control\\.example\\.test$';
        $this->assertContains($pattern, app(TrustHosts::class)->hosts());

        $this->assertSame(1, preg_match('{'.$pattern.'}i', 'control.example.test'));
        $this->assertSame(0, preg_match('{'.$pattern.'}i', 'sub.control.example.test'));
    }

    public function test_hsts_is_sent_only_when_the_request_is_secure(): void
    {
        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/')
            ->assertSuccessful()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_only_the_loopback_reverse_proxy_can_assert_https(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('http://localhost/')
            ->assertSuccessful()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');

        $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('http://localhost/')
            ->assertSuccessful()
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    private function assertSecurityHeaders(TestResponse $response): void
    {
        $response
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }
}
