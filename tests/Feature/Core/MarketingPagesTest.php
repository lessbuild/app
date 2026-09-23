<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

final class MarketingPagesTest extends TestCase
{
    public function test_buildpusher_homepage_presents_all_apps_and_the_shared_workspace_model(): void
    {
        $html = view('core::marketing.home', [
            'products' => config('marketing.products'),
        ])->render();

        $this->assertStringContainsString('One workspace for the work behind your software.', $html);
        $this->assertStringContainsString('Deployer', $html);
        $this->assertStringContainsString('Monitor', $html);
        $this->assertStringContainsString('Analytics', $html);
        $this->assertStringContainsString('Projects carry across apps', $html);
        $this->assertStringContainsString('Separate plan', $html);
        $this->assertStringContainsString('Each app keeps its own operational database', $html);
    }

    public function test_each_buildpusher_product_page_renders_its_migrated_feature_description(): void
    {
        $products = config('marketing.products');

        $this->assertSame(36, collect($products['deployer']['groups'])->sum(fn (array $group): int => count($group['features'])));

        foreach ($products as $productKey => $product) {
            $html = view('core::marketing.product', [
                'productKey' => $productKey,
                'product' => $product,
            ])->render();

            $this->assertStringContainsString($product['name'], $html);
            $this->assertStringContainsString('Separate app plan', $html);

            foreach ($product['groups'] as $group) {
                $this->assertStringContainsString($group['title'], $html);

                foreach ($group['features'] as [$title]) {
                    $this->assertStringContainsString($title, $html);
                }
            }
        }
    }
}
