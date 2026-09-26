<?php

namespace Tests\Modules\Analytics\Feature;

use App\Modules\Analytics\Services\Core\AnalyticsApiDocumentationProvider;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AnalyticsApiDocumentationProviderTest extends TestCase
{
    public function test_it_publishes_an_openapi_contract_matching_the_versioned_collection_limits(): void
    {
        config([
            'platform.products.analytics.enabled' => true,
            'platform.products.analytics.host' => 'analytics.example.test',
            'platform.products.analytics.url' => 'https://analytics.example.test',
            'analytics.collect_rate_per_minute' => 37,
        ]);

        $reference = (new AnalyticsApiDocumentationProvider)->reference();
        $this->assertNotNull($reference);
        $this->assertSame('Analytics', $reference->product);
        $this->assertSame('https://analytics.example.test/api/v1/openapi.json', $reference->openApiUrl);
        $this->assertSame('https://analytics.example.test/api/v1/collect/{publicId}', $reference->ingestUrl);
        $this->assertSame('3.1.0', $reference->document['openapi']);
        $this->assertSame('1.0.0', $reference->document['info']['version']);

        $operation = $reference->document['paths']['/api/v1/collect/{publicId}']['post'];
        $batchSchema = $reference->document['components']['schemas']['CollectionBatch'];
        $eventSchema = $reference->document['components']['schemas']['CollectionEvent'];
        $monthlyAllowanceResponse = $operation['responses']['429'];
        $usageSchema = $reference->document['components']['schemas']['Error']['properties']['usage'];

        $this->assertSame([], $operation['security']);
        $this->assertArrayHasKey('202', $operation['responses']);
        $this->assertArrayHasKey('403', $operation['responses']);
        $this->assertArrayHasKey('503', $operation['responses']);
        $this->assertArrayHasKey('413', $operation['responses']);
        $this->assertArrayHasKey('Retry-After', $operation['responses']['429']['headers']);
        $this->assertStringContainsString('duplicate retries do not add usage', $operation['description']);
        $this->assertStringContainsString('UTC calendar month', $operation['description']);
        $this->assertStringContainsString('atomically', $operation['description']);
        $this->assertStringContainsString('monthly accepted-event allowance', $monthlyAllowanceResponse['description']);
        $this->assertArrayHasKey('used', $usageSchema['properties']);
        $this->assertArrayHasKey('limit', $usageSchema['properties']);
        $this->assertSame('date', $usageSchema['properties']['period_start']['format']);
        $this->assertSame(32768, $operation['x-max-body-bytes']);
        $this->assertSame([37, 37], array_column($operation['x-rate-limits'], 'requests'));
        $this->assertSame(20, $batchSchema['properties']['events']['maxItems']);
        $this->assertSame('uuid', $eventSchema['properties']['id']['format']);
        $this->assertStringContainsString('server receipt time', $eventSchema['properties']['occurred_at']['description']);
        $this->assertArrayHasKey('429', $reference->document['paths']['/api/v1/collect/{publicId}']['options']['responses']);
        $this->assertArrayHasKey('Retry-After', $reference->document['paths']['/api/v1/collect/{publicId}']['options']['responses']['429']['headers']);

        $documented = collect($reference->document['paths']['/api/v1/collect/{publicId}'])
            ->only(['post', 'options'])
            ->keys()
            ->map(fn (string $method): string => strtoupper($method).' /api/v1/collect/{publicId}')
            ->sort()
            ->values()
            ->all();
        $registered = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => in_array($route->getName(), ['analytics.api.collect', 'analytics.api.collect.preflight'], true))
            ->flatMap(fn ($route) => collect($route->methods())
                ->reject(fn (string $method): bool => $method === 'HEAD')
                ->map(fn (string $method): string => $method.' /'.$route->uri()))
            ->sort()
            ->values()
            ->all();

        if ($registered === []) {
            Route::domain('analytics.example.test')
                ->middleware('api')
                ->prefix('api')
                ->as('analytics.')
                ->group(app_path('Modules/Analytics/Routes/api.php'));

            $registered = collect(Route::getRoutes()->getRoutes())
                ->filter(fn ($route): bool => in_array($route->getName(), ['analytics.api.collect', 'analytics.api.collect.preflight'], true))
                ->flatMap(fn ($route) => collect($route->methods())
                    ->reject(fn (string $method): bool => $method === 'HEAD')
                    ->map(fn (string $method): string => $method.' /'.$route->uri()))
                ->sort()
                ->values()
                ->all();
        }

        $this->assertSame($registered, $documented);
        $this->assertFileExists(public_path('tracker/v1.js'));
    }

    public function test_it_does_not_publish_a_reference_for_disabled_or_path_scoped_hosts(): void
    {
        config([
            'platform.products.analytics.enabled' => false,
            'platform.products.analytics.host' => 'analytics.example.test',
            'platform.products.analytics.url' => 'https://analytics.example.test',
        ]);
        $this->assertNull((new AnalyticsApiDocumentationProvider)->reference());

        config([
            'platform.products.analytics.enabled' => true,
            'platform.products.analytics.host' => 'analytics.example.test',
            'platform.products.analytics.url' => 'https://analytics.example.test/subpath',
        ]);
        $this->assertNull((new AnalyticsApiDocumentationProvider)->reference());

        config([
            'platform.products.analytics.enabled' => true,
            'platform.products.analytics.host' => 'another.example.test',
            'platform.products.analytics.url' => 'https://analytics.example.test',
        ]);
        $this->assertNull((new AnalyticsApiDocumentationProvider)->reference());

        config([
            'platform.products.analytics.enabled' => true,
            'platform.products.analytics.host' => 'analytics.example.test',
            'platform.products.analytics.url' => 'https://analytics.example.test?tenant=secret',
        ]);
        $this->assertNull((new AnalyticsApiDocumentationProvider)->reference());
    }
}
