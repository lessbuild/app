<?php

declare(strict_types=1);

namespace App\Support\Api;

use Symfony\Component\Yaml\Yaml;

/** The public API's OpenAPI description, kept by hand in resources/openapi/v1.yaml and checked against the routes. */
final class OpenApi
{
    /**
     * Read the description.
     *
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        $document = Yaml::parseFile(resource_path('openapi/v1.yaml'));

        return is_array($document) ? $document : [];
    }

    /**
     * List each documented operation as its method and full path (with the server's `/api` prefix), e.g.
     * `GET /api/v1/me`.
     *
     * @return list<array{method: string, path: string, tag: string, summary: string, scopes: list<string>}>
     */
    public static function operations(): array
    {
        $document = self::document();
        $prefix = rtrim((string) ($document['servers'][0]['url'] ?? ''), '/');
        $operations = [];
        foreach ((array) ($document['paths'] ?? []) as $path => $methods) {
            foreach ((array) $methods as $method => $operation) {
                if (! is_array($operation)) {
                    continue;
                }
                $scopes = [];
                foreach ((array) ($operation['security'] ?? []) as $requirement) {
                    foreach ((array) $requirement as $names) {
                        $scopes = [...$scopes, ...array_map('strval', (array) $names)];
                    }
                }
                $operations[] = [
                    'method' => strtoupper((string) $method), 'path' => $prefix.$path, 'tag' => (string) ($operation['tags'][0] ?? ''),
                    'summary' => (string) ($operation['summary'] ?? ''), 'scopes' => array_values($scopes),
                ];
            }
        }

        return $operations;
    }
}
