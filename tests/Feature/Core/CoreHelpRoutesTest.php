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
            ->assertSee('/api/v1/projects/{project}/configuration/plan')
            ->assertSee('/api/v1/projects/{project}/configuration/applications/{application}/operations/{operation}/retry')
            ->assertSee('OpenAPI 3.1.0')
            ->assertSee('Preview, review, and apply configuration')
            ->assertSee('secret_ref: token')
            ->assertSee('/projects/$PROJECT_ID/configuration/plan')
            ->assertSee('/projects/$PROJECT_ID/configuration/reviews/$REVIEW_ID/apply')
            ->assertSee('https://deployer.buildpusher.com/api/v1/environments/1/deploy')
            ->assertSee('Authorization: Bearer YOUR_TOKEN')
            ->assertSee('New tokens are bound to the active workspace')
            ->assertSee('Signed repository callbacks')
            ->assertSee('https://deployer.buildpusher.com/api/repositories/{repository}/webhook')
            ->assertSee('X-Hub-Signature-256')
            ->assertSee('webhook-timestamp')
            ->assertSee('X-Request-UUID')
            ->assertSee('https://deployer.buildpusher.com/api/github-app/webhook')
            ->assertSee('Duplicate push delivery IDs are acknowledged without creating another build')
            ->assertSee('maximum request size is configured per Deployer deployment');
    }

    public function test_deployer_openapi_endpoint_uses_the_configured_product_origin(): void
    {
        config([
            'platform.products.deployer.enabled' => true,
            'platform.products.deployer.host' => 'deployer.example.test',
            'platform.products.deployer.url' => 'https://deployer.example.test',
        ]);

        $this->get(route('openapi'))
            ->assertOk()
            ->assertJsonPath('servers.0.url', 'https://deployer.example.test/api/v1')
            ->assertJsonPath('paths./projects/{project}/configuration/plan.post.x-required-scope', 'manage');

        $this->get(route('core.help.deployer.api'))
            ->assertOk()
            ->assertSee('https://deployer.example.test/api/repositories/{repository}/webhook')
            ->assertSee('https://deployer.example.test/api/github-app/webhook');
    }

    public function test_deployer_openapi_documents_each_versioned_control_plane_route(): void
    {
        $document = json_decode((string) file_get_contents(public_path('openapi.json')), true, 512, JSON_THROW_ON_ERROR);
        $versionedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        $documentedOperations = collect($document['paths'])
            ->flatMap(fn (array $operations, string $path) => collect($operations)
                ->filter(fn (mixed $operation, string $method): bool => in_array(strtoupper($method), $versionedMethods, true))
                ->keys()
                ->map(fn (string $method): string => strtoupper($method).' /api/v1'.$path))
            ->sort()
            ->values()
            ->all();
        $registeredOperations = collect(app('router')->getRoutes())
            ->filter(fn ($route): bool => str_contains($route->getActionName(), 'ControlPlaneController@'))
            ->flatMap(fn ($route) => collect($route->methods())
                ->filter(fn (string $method): bool => in_array($method, $versionedMethods, true))
                ->map(fn (string $method): string => $method.' /'.$route->uri()))
            ->sort()
            ->values()
            ->all();

        $this->assertSame($registeredOperations, $documentedOperations);
    }

    public function test_monitor_api_reference_is_rendered_on_core_with_monitor_host_endpoints(): void
    {
        config([
            'platform.products.monitor.enabled' => true,
            'platform.products.monitor.host' => 'monitor.test',
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
            ->assertSee('Authorization: Bearer YOUR_ENVIRONMENT_TOKEN')
            ->assertSee('Signed alert webhooks')
            ->assertSee('X-Beacon-Delivery')
            ->assertSee('X-Beacon-Timestamp')
            ->assertSee('X-Beacon-Signature')
            ->assertSee('HMAC_SHA256(key, timestamp + "." + raw_body)')
            ->assertSee('up to five total attempts')
            ->assertSee('constant time')
            ->assertSee('240/min · environment token')
            ->assertSee('Responses: 200, 202, 400, 401, 413, 415, 422, 429')
            ->assertSee('X-Beacon-Token');
    }
}
