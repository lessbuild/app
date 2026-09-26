<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\PlatformStatusProvider;
use App\Core\Services\PlatformStatusProviderRegistry;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

final class CorePlatformStatusTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::forget('buildpusher:core-platform-status:v2');

        parent::tearDown();
    }

    public function test_core_status_page_and_json_report_include_enabled_product_health(): void
    {
        $this->enableAllProducts();
        $this->registerProvider('deployer', 'Release control plane', true);
        $this->registerProvider('monitor', 'Telemetry database', true);
        $this->registerProvider('analytics', 'Reporting database', true);
        Cache::forget('buildpusher:core-platform-status:v2');

        $this->get(route('core.status'))
            ->assertOk()
            ->assertSeeText('Deployer · Release control plane')
            ->assertSeeText('Monitor · Telemetry database')
            ->assertSeeText('Analytics · Reporting database')
            ->assertSeeText('Current operational status for Buildpusher and its enabled apps.');

        $this->getJson(route('core.status.report'))
            ->assertOk()
            ->assertJsonPath('status', 'operational')
            ->assertJsonCount(3, 'components');
    }

    public function test_a_product_status_failure_degrades_the_report_without_exposing_exception_details(): void
    {
        $this->enableAllProducts();
        $this->registerProvider('deployer', 'Release control plane', true);
        app(PlatformStatusProviderRegistry::class)->register('monitor', new class implements PlatformStatusProvider
        {
            public function components(): array
            {
                throw new RuntimeException('database password must stay private');
            }
        });
        $this->registerProvider('analytics', 'Reporting database', true);
        Cache::forget('buildpusher:core-platform-status:v2');

        $response = $this->getJson(route('core.status.report'))
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('components.1.status', 'Unavailable')
            ->assertJsonPath('components.1.operational', false)
            ->assertJsonPath('components.2.operational', true);

        $this->assertStringNotContainsString('database password', $response->getContent());
    }

    public function test_disabled_products_are_not_reported_as_outages(): void
    {
        config(['platform.products.monitor.enabled' => false]);
        config(['platform.products.analytics.enabled' => false]);
        $this->registerProvider('deployer', 'Release control plane', true);
        Cache::forget('buildpusher:core-platform-status:v2');

        $this->getJson(route('core.status.report'))
            ->assertOk()
            ->assertJsonPath('status', 'operational')
            ->assertJsonCount(1, 'components');
    }

    private function enableAllProducts(): void
    {
        config([
            'platform.products.deployer.enabled' => true,
            'platform.products.monitor.enabled' => true,
            'platform.products.analytics.enabled' => true,
        ]);
    }

    private function registerProvider(string $product, string $name, bool $operational): void
    {
        app(PlatformStatusProviderRegistry::class)->register($product, new class($name, $operational) implements PlatformStatusProvider
        {
            public function __construct(private readonly string $name, private readonly bool $operational) {}

            public function components(): array
            {
                return [[
                    'name' => $this->name,
                    'description' => 'A product-specific readiness summary.',
                    'operational' => $this->operational,
                ]];
            }
        });
    }
}
