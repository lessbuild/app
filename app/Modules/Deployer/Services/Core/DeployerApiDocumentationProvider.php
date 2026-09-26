<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProductApiDocumentationProvider;
use App\Core\Data\Help\ProductApiReference;
use JsonException;

/** Publish Deployer's versioned contract through Core without moving API ownership out of Deployer. */
final class DeployerApiDocumentationProvider implements ProductApiDocumentationProvider
{
    public function reference(): ?ProductApiReference
    {
        if (! config('platform.products.deployer.enabled', false)) {
            return null;
        }

        $baseUrl = $this->baseUrl();
        $path = public_path('openapi.json');
        if ($baseUrl === null || ! is_file($path)) {
            return null;
        }

        try {
            $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($document)
            || ! is_string($document['openapi'] ?? null)
            || ! is_array($document['paths'] ?? null)) {
            return null;
        }

        $document['servers'] = [['url' => $baseUrl.'/api/v1']];

        return new ProductApiReference(
            product: 'Deployer',
            baseUrl: $baseUrl,
            openApiUrl: $baseUrl.'/openapi.json',
            ingestUrl: '',
            document: $document,
        );
    }

    private function baseUrl(): ?string
    {
        $url = trim((string) (config('platform.products.deployer.url') ?: 'https://deployer.buildpusher.com'));
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        $configuredHost = trim((string) config('platform.products.deployer.host', ''));
        $configuredParts = $configuredHost === '' ? null : parse_url('//'.$configuredHost);
        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && trim((string) $parts['path'], '/') !== '')
            || ($configuredHost !== '' && (! is_array($configuredParts)
                || ! isset($configuredParts['host'])
                || isset($configuredParts['user'])
                || isset($configuredParts['pass'])
                || isset($configuredParts['query'])
                || isset($configuredParts['fragment'])
                || (isset($configuredParts['path']) && trim((string) $configuredParts['path'], '/') !== '')
                || strtolower((string) $parts['host']) !== strtolower((string) $configuredParts['host'])))) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $origin = $scheme.'://'.strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $configuredPort = $configuredParts === null
            ? ($port ?? $defaultPort)
            : (isset($configuredParts['port']) ? (int) $configuredParts['port'] : $defaultPort);
        if (($port ?? $defaultPort) !== $configuredPort) {
            return null;
        }

        if ($port !== null && ! (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $origin .= ':'.$port;
        }

        return $origin;
    }
}
