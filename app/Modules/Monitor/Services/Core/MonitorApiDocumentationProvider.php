<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProductApiDocumentationProvider;
use App\Core\Data\Help\ProductApiReference;
use App\Modules\Monitor\Services\OpenApiDocument;

final class MonitorApiDocumentationProvider implements ProductApiDocumentationProvider
{
    public function __construct(private readonly OpenApiDocument $document) {}

    public function reference(): ?ProductApiReference
    {
        if (! config('platform.products.monitor.enabled', false)) {
            return null;
        }

        $baseUrl = $this->baseUrl();
        if ($baseUrl === null) {
            return null;
        }

        $baseUrl = rtrim($baseUrl, '/');

        return new ProductApiReference(
            product: 'Monitor',
            baseUrl: $baseUrl,
            openApiUrl: $baseUrl.'/api/v1/openapi.json',
            ingestUrl: $baseUrl.'/api/v1/ingest',
            document: $this->document->make($baseUrl, 'Monitor'),
        );
    }

    private function baseUrl(): ?string
    {
        $url = trim((string) config('platform.products.monitor.url', ''));
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower((string) $parts['scheme']), ['https', 'http'], true)
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || (isset($parts['path']) && trim((string) $parts['path'], '/') !== '')) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $origin = $scheme.'://'.strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ! (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $origin .= ':'.$port;
        }

        return $origin;
    }
}
