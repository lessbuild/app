<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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

    public function test_status_pages_can_be_embedded_and_security_txt_says_where_to_report(): void
    {
        $this->get('/.well-known/security.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Contact: mailto:'.config('legal.contact_email'))->assertSee('Expires: '.now('UTC')->addYear()->format('Y-m-d'));
    }
}
