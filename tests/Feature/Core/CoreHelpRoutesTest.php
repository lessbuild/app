<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

final class CoreHelpRoutesTest extends TestCase
{
    public function test_legacy_deployer_documentation_urls_handoff_to_the_core_help_pages(): void
    {
        $this->get(route('docs'))
            ->assertRedirect(route('core.help.deployer'));

        $this->get(route('api-docs'))
            ->assertRedirect(route('core.help.deployer.api'));
    }

    public function test_core_help_index_and_deployer_guide_render_product_documentation(): void
    {
        $this->get(route('core.help'))
            ->assertOk()
            ->assertSee('Help and guides')
            ->assertSee(route('core.help.deployer'));

        $this->get(route('core.help.deployer'))
            ->assertOk()
            ->assertSee('First deployment')
            ->assertSee('Recovery drill')
            ->assertSee(route('core.help.deployer.api'));

        $this->get(route('core.help.deployer.api'))
            ->assertOk()
            ->assertSee('/api/v1/environments/{environment}/deploy')
            ->assertSee('Authorization: Bearer YOUR_TOKEN');
    }

    public function test_monitor_api_reference_is_rendered_on_core_with_monitor_host_endpoints(): void
    {
        config([
            'platform.products.monitor.enabled' => true,
            'platform.products.monitor.url' => 'https://monitor.test',
        ]);

        $this->get(route('core.help'))
            ->assertOk()
            ->assertSee(route('core.help.monitor.api'));

        $this->get(route('core.help.monitor.api'))
            ->assertOk()
            ->assertSee('Monitor API reference')
            ->assertSee('https://monitor.test/api/v1/openapi.json')
            ->assertSee('POST /api/v1/ingest')
            ->assertSee('POST /api/v1/otlp/v1/{signal}')
            ->assertSee('Authorization: Bearer YOUR_ENVIRONMENT_TOKEN');
    }
}
