<?php

namespace Tests\Feature\Core;

use App\Core\Services\Billing\PlatformProductBillingLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class WorkspaceSubscriptionBillingLinksTest extends TestCase
{
    public function test_same_origin_billing_destinations_use_each_product_route_directly(): void
    {
        $this->ensureBillingRoutesExist();

        foreach ([
            'deployer' => 'billing.index',
            'monitor' => 'monitor.settings.billing',
        ] as $product => $routeName) {
            $this->assertTrue(Route::has($routeName));

            $workspaceParameter = $product === 'deployer' ? 'organization_id' : 'workspace_id';
            $workspaceId = 123;
            $target = route($routeName, [$workspaceParameter => $workspaceId]);
            $origin = $this->originOf($target);
            Config::set("platform.products.{$product}.url", $origin);

            $links = new PlatformProductBillingLinks(Request::create($origin.'/workspaces'));

            $this->assertSame($target, $links->for($product, $workspaceId));
        }
    }

    public function test_billing_link_is_hidden_when_configured_product_origin_disagrees_with_its_route(): void
    {
        $this->ensureBillingRoutesExist();

        foreach ([
            'deployer' => 'billing.index',
            'monitor' => 'monitor.settings.billing',
        ] as $product => $routeName) {
            $this->assertTrue(Route::has($routeName));

            $workspaceId = 123;
            $workspaceParameter = $product === 'deployer' ? 'organization_id' : 'workspace_id';
            $targetOrigin = $this->originOf(route($routeName, [$workspaceParameter => $workspaceId]));
            $configuredOrigin = $targetOrigin === 'https://billing-origin-mismatch.invalid'
                ? 'https://another-billing-origin.invalid'
                : 'https://billing-origin-mismatch.invalid';
            Config::set("platform.products.{$product}.url", $configuredOrigin);

            $links = new PlatformProductBillingLinks(Request::create('https://dashboard.example.test/workspaces'));

            $this->assertNull($links->for($product, $workspaceId));
        }
    }

    public function test_billing_link_requires_a_valid_product_workspace_id(): void
    {
        foreach (['deployer', 'monitor'] as $product) {
            $links = new PlatformProductBillingLinks(Request::create('https://dashboard.example.test/workspaces'));

            $this->assertNull($links->for($product));
            $this->assertNull($links->for($product, 'workspace-slug'));
            $this->assertNull($links->for($product, 0));
        }
    }

    public function test_analytics_has_no_billing_destination_until_its_own_billing_route_exists(): void
    {
        $links = new PlatformProductBillingLinks(Request::create('https://dashboard.example.test/workspaces'));

        $this->assertNull($links->for('analytics'));
    }

    private function originOf(string $url): string
    {
        $parts = parse_url($url);
        $this->assertIsArray($parts);
        $this->assertArrayHasKey('scheme', $parts);
        $this->assertArrayHasKey('host', $parts);

        $origin = strtolower($parts['scheme'].'://'.$parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($port !== null && ! (($parts['scheme'] === 'https' && $port === 443) || ($parts['scheme'] === 'http' && $port === 80))) {
            $origin .= ':'.$port;
        }

        return $origin;
    }

    private function ensureBillingRoutesExist(): void
    {
        if (! Route::has('billing.index')) {
            Route::get('/billing', static fn () => null)->name('billing.index');
        }

        if (! Route::has('monitor.settings.billing')) {
            Route::get('/monitor/settings/billing', static fn () => null)->name('monitor.settings.billing');
        }
    }
}
