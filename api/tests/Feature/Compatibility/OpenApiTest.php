<?php

declare(strict_types=1);

namespace Tests\Feature\Compatibility;

use App\Enums\ApiScope;
use App\Support\Api\OpenApi;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class OpenApiTest extends TestCase
{
    public function test_the_description_documents_every_api_route_and_nothing_else(): void
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            // /api/app is the private browser API behind the Next.js frontend, not a public contract.
            if (! str_starts_with($route->uri(), 'api/') || str_starts_with($route->uri(), 'api/app/') || $route->getName() === 'docs.openapi') {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = $method.' /'.$route->uri();
            }
        }
        $documented = array_map(fn (array $operation): string => $operation['method'].' '.$operation['path'], OpenApi::operations());
        sort($routes);
        sort($documented);

        $this->assertSame([], array_values(array_diff($routes, $documented)), 'These API routes are missing from resources/openapi/v1.yaml.');
        $this->assertSame([], array_values(array_diff($documented, $routes)), 'These documented operations have no route.');
    }

    public function test_references_resolve_and_scopes_are_real_token_scopes(): void
    {
        $document = OpenApi::document();
        $refs = [];
        array_walk_recursive($document, function (mixed $value, int|string $key) use (&$refs): void {
            if ($key === '$ref' && is_string($value)) {
                $refs[] = $value;
            }
        });
        $this->assertNotEmpty($refs);
        foreach ($refs as $ref) {
            [, , $section, $name] = explode('/', $ref);
            $this->assertArrayHasKey($name, $document['components'][$section] ?? [], "{$ref} doesn't exist.");
        }
        $scopes = array_map(fn (ApiScope $scope): string => $scope->value, ApiScope::cases());
        foreach (OpenApi::operations() as $operation) {
            foreach ($operation['scopes'] as $scope) {
                $this->assertContains($scope, $scopes, "{$operation['method']} {$operation['path']} names an unknown scope.");
            }
        }
    }

    public function test_the_description_and_reference_are_served(): void
    {
        $this->getJson('/api/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0')->assertJsonPath('paths./v1/me.get.security.0.apiToken.0', 'deploy:read');
        $this->get('/docs/api')->assertOk()->assertSee('GET')->assertSee('/api/v1/environments/{environment}/deploy')->assertSee('deploy:write');
    }
}
