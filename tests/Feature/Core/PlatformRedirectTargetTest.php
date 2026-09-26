<?php

namespace Tests\Feature\Core;

use App\Core\Services\Auth\PlatformRedirectTarget;
use Illuminate\Http\Request;
use Tests\TestCase;

final class PlatformRedirectTargetTest extends TestCase
{
    public function test_allowed_host_return_targets_keep_fragments_and_reject_embedded_credentials(): void
    {
        config(['platform.products.monitor.url' => 'https://monitor.example.test']);
        $request = Request::create('https://auth.example.test/login');
        $redirects = app(PlatformRedirectTarget::class);

        $this->assertSame(
            'https://monitor.example.test/incidents?window=7d#open',
            $redirects->resolve('https://monitor.example.test/incidents?window=7d#open', $request),
        );
        $this->assertNull($redirects->resolve('https://person@monitor.example.test/incidents', $request));
        $this->assertNull($redirects->resolve('https://person:secret@monitor.example.test/incidents', $request));
    }
}
