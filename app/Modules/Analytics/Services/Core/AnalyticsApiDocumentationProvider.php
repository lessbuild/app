<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\ProductApiDocumentationProvider;
use App\Core\Data\Help\ProductApiReference;

final class AnalyticsApiDocumentationProvider implements ProductApiDocumentationProvider
{
    public function reference(): ?ProductApiReference
    {
        if (! config('platform.products.analytics.enabled', false) || ! filled(config('platform.products.analytics.host'))) {
            return null;
        }

        $baseUrl = $this->baseUrl();

        if ($baseUrl === null) {
            return null;
        }

        return new ProductApiReference(
            product: 'Analytics',
            baseUrl: $baseUrl,
            openApiUrl: $baseUrl.'/api/v1/openapi.json',
            ingestUrl: $baseUrl.'/api/v1/collect/{publicId}',
            document: $this->document($baseUrl),
        );
    }

    private function baseUrl(): ?string
    {
        $url = trim((string) config('platform.products.analytics.url', ''));

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        $configuredHost = trim((string) config('platform.products.analytics.host', ''));
        $configuredParts = parse_url('//'.$configuredHost);

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && trim((string) $parts['path'], '/') !== '')
            || ! is_array($configuredParts)
            || ! isset($configuredParts['host'])
            || isset($configuredParts['user'])
            || isset($configuredParts['pass'])
            || isset($configuredParts['query'])
            || isset($configuredParts['fragment'])
            || (isset($configuredParts['path']) && trim((string) $configuredParts['path'], '/') !== '')
            || strtolower((string) $parts['host']) !== strtolower((string) $configuredParts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $origin = $scheme.'://'.strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $configuredPort = isset($configuredParts['port']) ? (int) $configuredParts['port'] : $defaultPort;
        $effectivePort = $port ?? $defaultPort;

        if ($configuredPort !== $effectivePort) {
            return null;
        }

        if ($port !== null && ! (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $origin .= ':'.$port;
        }

        return $origin;
    }

    /** @return array<string, mixed> */
    private function document(string $baseUrl): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Buildpusher Analytics Collection API',
                'version' => '1.0.0',
                'description' => 'Versioned browser and server-side event collection. Site IDs are public identifiers, not bearer credentials; registered site origins and collection availability are checked for each request.',
            ],
            'servers' => [['url' => $baseUrl]],
            'paths' => [
                '/api/v1/collect/{publicId}' => [
                    'parameters' => [[
                        'name' => 'publicId',
                        'in' => 'path',
                        'required' => true,
                        'description' => 'The 24-character public site ID shown in Analytics site setup.',
                        'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{24}$'],
                    ]],
                    'post' => [
                        'operationId' => 'collectAnalyticsEventsV1',
                        'summary' => 'Accept a batch of Analytics events',
                        'description' => 'Accepts one to twenty events. Event IDs are UUIDs and should remain unchanged when retrying. Events are processed asynchronously; the server records receipt time as occurred_at. Browser origins must match a domain registered for the site. The optional properties object is reduced to the supported name field before storage.',
                        'security' => [],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CollectionBatch']]],
                        ],
                        'responses' => [
                            '202' => ['description' => 'Batch accepted or duplicate event IDs ignored.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CollectionAccepted']]]],
                            '403' => ['description' => 'Request origin is not registered for this site.'],
                            '404' => ['description' => 'Site is missing or collection is unavailable.'],
                            '413' => ['description' => 'Request body exceeds the payload limit.'],
                            '422' => ['description' => 'Event payload failed validation.'],
                            '429' => ['description' => 'Collection rate limit exceeded.'],
                            '503' => ['description' => 'Collection is temporarily unavailable for the workspace plan.'],
                        ],
                    ],
                    'options' => [
                        'operationId' => 'checkAnalyticsCollectionOriginV1',
                        'summary' => 'Check whether a browser origin is allowed',
                        'description' => 'CORS preflight for the public collection endpoint. The Origin host must be registered for the site.',
                        'security' => [],
                        'responses' => [
                            '204' => ['description' => 'Origin is allowed.'],
                            '403' => ['description' => 'Origin is not registered for this site.'],
                            '404' => ['description' => 'Site is not available.'],
                            '429' => ['description' => 'Collection rate limit exceeded.'],
                        ],
                    ],
                ],
                '/tracker/v1.js' => [
                    'get' => [
                        'operationId' => 'getAnalyticsTrackerV1',
                        'summary' => 'Load the version 1 browser tracker',
                        'description' => 'Loads the tracker asset. Set its data-site attribute to the site public ID. The tracker honors window.buildpusherAnalyticsOptOut and an optional consent callback.',
                        'security' => [],
                        'responses' => ['200' => ['description' => 'JavaScript tracker asset.']],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'CollectionBatch' => [
                        'type' => 'object',
                        'required' => ['events'],
                        'properties' => [
                            'events' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => ['$ref' => '#/components/schemas/CollectionEvent']],
                        ],
                        'additionalProperties' => true,
                    ],
                    'CollectionEvent' => [
                        'type' => 'object',
                        'required' => ['id', 'type', 'path'],
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Stable event identity for retries and duplicate suppression.'],
                            'type' => ['type' => 'string', 'enum' => ['pageview', 'event']],
                            'occurred_at' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'Accepted for client compatibility; Analytics uses server receipt time.'],
                            'path' => ['type' => 'string', 'maxLength' => 2048],
                            'referrer_host' => ['type' => ['string', 'null'], 'maxLength' => 255],
                            'utm_source' => ['type' => ['string', 'null'], 'maxLength' => 100],
                            'utm_medium' => ['type' => ['string', 'null'], 'maxLength' => 100],
                            'utm_campaign' => ['type' => ['string', 'null'], 'maxLength' => 150],
                            'device' => ['type' => ['string', 'null'], 'maxLength' => 32],
                            'browser' => ['type' => ['string', 'null'], 'maxLength' => 64],
                            'os' => ['type' => ['string', 'null'], 'maxLength' => 64],
                            'visitor' => ['type' => ['string', 'null'], 'maxLength' => 64],
                            'session' => ['type' => ['string', 'null'], 'maxLength' => 64],
                            'properties' => ['type' => ['object', 'null'], 'maxProperties' => 8, 'description' => 'Custom event properties are reduced to a validated name field before storage.'],
                        ],
                        'additionalProperties' => true,
                    ],
                    'CollectionAccepted' => [
                        'type' => 'object',
                        'required' => ['batch_id', 'accepted'],
                        'properties' => [
                            'batch_id' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                            'accepted' => ['type' => 'integer', 'minimum' => 0],
                        ],
                    ],
                ],
            ],
        ];
    }
}
