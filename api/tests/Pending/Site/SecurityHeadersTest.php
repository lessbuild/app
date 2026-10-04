<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Pending until the cut-over (docs/plan.md, step 13): the Nuxt pages get their security headers there, with a CSP nonce for
// the theme script. Laravel's SecurityHeaders only covers its own responses; security.txt is tested in Feature/Site.
final class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_carry_hardening_headers_and_a_nonce_based_content_security_policy(): void
    {
        $response = $this->get('/')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $nonce = preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $match) === 1 ? $match[1] : '';
        $response->assertSee('<script nonce="'.$nonce.'">', false);
        $this->assertNotSame($policy, (string) $this->get('/')->headers->get('Content-Security-Policy'));
    }
}
