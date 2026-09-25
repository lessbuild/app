<?php

namespace Tests\Modules\Analytics\Feature;

use App\Modules\Analytics\Services\Core\AnalyticsApiDocumentationProvider;
use Tests\TestCase;

final class AnalyticsApiDocumentationProviderTest extends TestCase
{
    public function test_it_publishes_an_openapi_contract_matching_the_versioned_collection_limits(): void
    {
        config([
            'platform.products.analytics.enabled' => true,
            'platform.products.analytics.host' => 'analytics.example.test',
            'platform.products.analytics.url' => 'https://analytics.example.test',
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

        $this->assertSame([], $operation['security']);
        $this->assertArrayHasKey('202', $operation['responses']);
        $this->assertArrayHasKey('403', $operation['responses']);
        $this->assertArrayHasKey('503', $operation['responses']);
        $this->assertSame(20, $batchSchema['properties']['events']['maxItems']);
        $this->assertSame('uuid', $eventSchema['properties']['id']['format']);
        $this->assertStringContainsString('server receipt time', $eventSchema['properties']['occurred_at']['description']);
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
