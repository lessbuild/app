<?php

namespace Tests\Unit\Core;

use App\Core\Services\ProductReadinessProbe;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ProductReadinessProbeTest extends TestCase
{
    public function test_it_accepts_the_expected_status_from_the_configured_product_host(): void
    {
        config(['platform.products.monitor.url' => 'https://monitor.example.test']);
        Http::fake([
            'https://monitor.example.test/api/health' => Http::response(['status' => 'ready']),
        ]);

        $this->assertTrue(app(ProductReadinessProbe::class)->isReady('monitor', '/api/health', 'ready'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://monitor.example.test/api/health');
    }

    public function test_it_rejects_unready_or_invalid_product_endpoints(): void
    {
        config(['platform.products.analytics.url' => 'https://analytics.example.test']);
        Http::fake([
            'https://analytics.example.test/ready' => Http::response(['status' => 'unavailable'], 503),
        ]);

        $probe = app(ProductReadinessProbe::class);

        $this->assertFalse($probe->isReady('analytics', '/ready', 'ok'));
        config(['platform.products.analytics.url' => 'file:///tmp/analytics']);
        $this->assertFalse($probe->isReady('analytics', '/ready', 'ok'));
    }

    public function test_it_returns_only_allowlisted_health_components_from_a_product_probe(): void
    {
        config(['platform.products.monitor.url' => 'https://monitor.example.test']);
        Http::fake([
            'https://monitor.example.test/api/health' => Http::response([
                'status' => 'unavailable',
                'checks' => [
                    'database' => true,
                    'background_processing' => false,
                    'secret_configuration' => 'must not be exposed',
                ],
            ], 503),
        ]);

        $components = app(ProductReadinessProbe::class)->components('monitor', '/api/health', 'ready');

        $this->assertSame(['Database', 'Background processing', 'Application readiness'], array_column($components, 'name'));
        $this->assertSame([true, false, false], array_column($components, 'operational'));
        $this->assertStringNotContainsString('secret_configuration', json_encode($components, JSON_THROW_ON_ERROR));
    }
}
