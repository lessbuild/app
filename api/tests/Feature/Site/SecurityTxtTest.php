<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SecurityTxtTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that security.txt says where to report a vulnerability, and until when it holds.
     *
     * @return void
     */
    public function test_security_txt_says_where_to_report(): void
    {
        $this->get('/.well-known/security.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Contact: mailto:'.config('legal.contact_email'))->assertSee('Expires: '.now('UTC')->addYear()->format('Y-m-d'));
    }
}
