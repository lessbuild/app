<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

final class AnalyticsApiHelpTest extends TestCase
{
    public function test_core_help_lists_analytics_api_docs_and_the_versioned_reference_page(): void
    {
        config([
            'platform.products.analytics.enabled' => true,
            'platform.products.analytics.host' => 'analytics.example.test',
            'platform.products.analytics.url' => 'https://analytics.example.test',
        ]);

        $this->get(route('core.help'))
            ->assertOk()
            ->assertSee(route('core.help.analytics.api'))
            ->assertSee('Analytics tracker and API reference');

        $this->get(route('core.help.analytics.api'))
            ->assertOk()
            ->assertSee('https://analytics.example.test/api/v1/openapi.json')
            ->assertSee('/api/v1/collect/{publicId}')
            ->assertSee('SITE_PUBLIC_ID')
            ->assertDontSee('Authorization: Bearer');
    }
}
