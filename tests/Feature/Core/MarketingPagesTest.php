<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

final class MarketingPagesTest extends TestCase
{
    public function test_buildpusher_root_is_a_public_product_suite_page(): void
    {
        $this->get(route('core.entry'))
            ->assertOk()
            ->assertSee('Ship. Monitor. Understand.')
            ->assertSee('Deployer')
            ->assertSee('Monitor')
            ->assertSee('Analytics');
    }

    public function test_buildpusher_homepage_links_to_core_legal_pages(): void
    {
        $this->get(route('core.entry'))
            ->assertOk()
            ->assertSee(route('core.privacy'), false)
            ->assertSee(route('core.terms'), false);
    }

    public function test_core_legal_pages_are_public_complete_and_cross_linked(): void
    {
        $this->get(route('core.privacy'))
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('Information we process')
            ->assertSee('monitor check results')
            ->assertSee(config('legal.effective_date'))
            ->assertSee(config('legal.contact_email'))
            ->assertSee(route('core.terms'), false)
            ->assertSee(route('core.status'), false);

        $this->get(route('core.terms'))
            ->assertOk()
            ->assertSee('Terms of Service')
            ->assertSee('Product plans and billing')
            ->assertSee('changing one app plan does not change the other apps’ plans')
            ->assertSee(config('legal.effective_date'))
            ->assertSee(config('legal.contact_email'))
            ->assertSee(route('core.privacy'), false)
            ->assertSee(route('core.status'), false);
    }

    public function test_existing_deployer_legal_pages_remain_available(): void
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('Privacy Policy');

        $this->get(route('terms'))
            ->assertOk()
            ->assertSee('Terms of Service');
    }

    public function test_each_product_landing_page_is_available_on_core(): void
    {
        foreach (['deployer', 'monitor', 'analytics'] as $product) {
            $this->get(route('core.marketing.product', $product))
                ->assertOk()
                ->assertSee('At a glance')
                ->assertSee('What you can do');
        }
    }

    public function test_buildpusher_homepage_presents_all_apps_and_the_shared_workspace_model(): void
    {
        $html = view('core::marketing.home', [
            'products' => config('marketing.products'),
        ])->render();

        $this->assertStringContainsString('Ship. Monitor. Understand.', $html);
        $this->assertStringContainsString('product-hero', $html);
        $this->assertStringContainsString('product-suite-preview', $html);
        $this->assertStringContainsString('Illustrative project overview', $html);
        $this->assertStringContainsString('Latest release', $html);
        $this->assertStringContainsString('Service health', $html);
        $this->assertStringContainsString('Traffic estimates', $html);
        $this->assertStringContainsString('data-product-analytics-range', $html);
        $this->assertStringContainsString('Last 7 days', $html);
        $this->assertStringContainsString('Last 30 days', $html);
        $this->assertStringContainsString('Last 90 days', $html);
        $this->assertStringContainsString('Illustrative project', $html);
        $this->assertStringContainsString('product-icon-deploy', $html);
        $this->assertStringContainsString('product-icon-monitor', $html);
        $this->assertStringContainsString('product-icon-analytics', $html);
        $this->assertStringContainsString('Deployer', $html);
        $this->assertStringContainsString('Monitor', $html);
        $this->assertStringContainsString('Analytics', $html);
        $this->assertStringContainsString('Projects carry across apps', $html);
        $this->assertStringContainsString('Product-specific access', $html);
        $this->assertStringContainsString('Each app keeps its own operational database', $html);
        $this->assertStringContainsString('Deployer environments, Monitor applications, and Analytics sites', $html);
        $this->assertStringContainsString('What carries between products?', $html);
        $this->assertStringContainsString('Do the apps share product access or data?', $html);
        $this->assertStringContainsString('Product explorer', $html);
        $this->assertStringContainsString('A clear view for every kind of work.', $html);
        $this->assertStringContainsString('data-product-explorer', $html);
        $this->assertStringContainsString('data-product-tab="deployer"', $html);
        $this->assertStringContainsString('data-product-panel="monitor"', $html);
        $this->assertStringContainsString('product-preview-deploy', $html);
        $this->assertStringContainsString('HTTP, DNS, TLS, TCP, queue, and heartbeat monitors', $html);
        $this->assertStringContainsString('Pull-request preview environments', $html);
        $this->assertStringContainsString('OpenTelemetry telemetry with mapped Deployer release context', $html);
        $this->assertStringContainsString('Deployer release and Monitor incident annotations', $html);
        $this->assertStringContainsString('Connected product workflows', $html);
        $this->assertStringContainsString('Keep releases beside service health', $html);
        $this->assertStringContainsString('See when a release meets a traffic change', $html);
        $this->assertStringContainsString('Keep incident changes with site reports', $html);
        $this->assertStringContainsString('Bring traffic context into an investigation', $html);
        $this->assertStringContainsString('plan-bounded Analytics pageviews and distinct visitors', $html);

        foreach (config('marketing.products') as $product) {
            foreach ($product['suite_workflow']['features'] as $feature) {
                $this->assertStringContainsString($feature, $html);
            }
        }
    }

    public function test_buildpusher_homepage_describes_the_shared_core_workspace_features(): void
    {
        $html = $this->get(route('core.entry'))->assertOk()->getContent();

        $this->assertStringContainsString('Manage the shared workspace around your apps.', $html);
        $this->assertStringContainsString('A shared project directory', $html);
        $this->assertStringContainsString('Team membership with app-specific access', $html);
        $this->assertStringContainsString('An operational view for the team', $html);
        $this->assertStringContainsString('Workspace tools in one place', $html);
        $this->assertStringContainsString('Are subscriptions shared across apps?', $html);
        $this->assertStringContainsString(route('core.help'), $html);
        $this->assertStringContainsString(route('core.status'), $html);
    }

    public function test_each_buildpusher_product_page_renders_its_migrated_feature_description(): void
    {
        $products = config('marketing.products');

        $this->assertSame(38, collect($products['deployer']['groups'])->sum(fn (array $group): int => count($group['features'])));

        $productSpecificCopy = [
            'deployer' => ['Deployment #1841', 'Preflight checks', 'Connect. Provision. Release.', 'Configuration plans', 'Build comparison and promotion', 'protected-secret approval', 'Hetzner Cloud', 'Vultr', 'Cloudflare DNS'],
            'monitor' => ['Service health', 'OpenTelemetry traces', 'HTTP and network monitors', 'Queue and worker monitors', 'Cron and heartbeat monitors', 'Issue digests', 'Instrument. Detect. Respond.', 'Microsoft Teams', 'PagerDuty', 'Discord'],
            'analytics' => ['Site overview', 'Referrer attribution', 'Traffic and change context', 'Deployer release annotations', 'Measure. Explore. Learn.', 'Cookieless visitor estimates', 'Consent-aware browser tracker', 'Traffic trends and comparisons', 'UTM attribution', 'Filtered CSV exports'],
        ];

        foreach ($products as $productKey => $product) {
            $html = view('core::marketing.product', [
                'productKey' => $productKey,
                'product' => $product,
                'products' => $products,
            ])->render();

            $this->assertStringContainsString($product['name'], $html);
            $this->assertStringContainsString('Product-specific access', $html);
            $this->assertStringContainsString('App-specific access and plan state', $html);
            $this->assertStringContainsString('product-detail-preview', $html);
            $this->assertStringContainsString('Illustrative interface. Values and activity are fictional sample data.', $html);
            $this->assertLessThan(
                strpos($html, 'What you can do'),
                strpos($html, 'At a glance'),
                'The capability scan should be visible before the detailed feature catalog.'
            );
            $this->assertStringContainsString($product['highlights_heading'], $html);
            $this->assertStringContainsString($product['workflows_heading'], $html);

            $productConnections = collect(config('marketing.connections'))
                ->filter(fn (array $connection): bool => in_array($productKey, [$connection['source'], $connection['target']], true));

            foreach ($productConnections as $connection) {
                $this->assertStringContainsString($connection['title'], $html);
                $this->assertStringContainsString($connection['mode'], $html);
            }

            if ($productKey !== 'deployer') {
                $this->assertStringNotContainsString('Connect. Provision. Deploy.', $html);
            }

            foreach ($productSpecificCopy[$productKey] as $copy) {
                $this->assertStringContainsString($copy, $html);
            }

            foreach ($product['capabilities'] as $capability) {
                $this->assertStringContainsString($capability, $html);
            }

            foreach ($product['groups'] as $group) {
                $this->assertStringContainsString($group['title'], $html);

                foreach ($group['features'] as [$title]) {
                    $this->assertStringContainsString($title, $html);
                }
            }
        }
    }
}
