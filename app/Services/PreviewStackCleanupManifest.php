<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\EnvironmentResource;
use App\Models\Website;
use Illuminate\Support\Str;

class PreviewStackCleanupManifest
{
    /**
     * Capture only safe remote unit names for processes explicitly owned by a preview stack.
     *
     * @param  Environment  $environment  Preview environment whose owned children are being closed.
     * @return list<array{name: string}>
     */
    public function processes(Environment $environment): array
    {
        $environment->loadMissing('processes');

        return $environment->processes
            ->where('is_preview_owned', true)
            ->map(fn ($process): array => ['name' => Str::slug((string) $process->name)])
            ->filter(fn (array $process): bool => preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/D', $process['name']) === 1)
            ->unique('name')
            ->values()
            ->all();
    }

    /**
     * Capture only safe remote identifiers for resources explicitly owned by a preview stack.
     *
     * Database passwords and other resource variables are deliberately omitted. A malformed
     * identifier is retained as an invalid entry so cleanup fails visibly instead of guessing.
     *
     * @param  Environment  $environment  Preview environment whose owned children are being closed.
     * @return list<array{type: string, name: string, status: string, database?: string, username?: string, container_name?: string, volume_name?: string, invalid?: bool}>
     */
    public function for(Environment $environment): array
    {
        $environment->loadMissing('resources');
        $website = Website::withTrashed()->find($environment->website_id);
        $databaseIdentifier = $website?->databaseIdentifier();

        return $environment->resources
            ->where('is_preview_owned', true)
            ->where('is_managed', true)
            ->map(fn (EnvironmentResource $resource): ?array => $this->resource($resource, $environment, $databaseIdentifier))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Convert one owned resource to a non-secret cleanup descriptor.
     *
     * @param  EnvironmentResource  $resource  Owned child being represented.
     * @param  Environment  $environment  Environment supplying the generated resource identity.
     * @param  string|null  $databaseIdentifier  Website-derived database and role name.
     * @return array{type: string, name: string, status: string, database?: string, username?: string, container_name?: string, volume_name?: string, invalid?: bool}|null
     */
    private function resource(EnvironmentResource $resource, Environment $environment, ?string $databaseIdentifier): ?array
    {
        $base = [
            'type' => (string) $resource->type,
            'name' => (string) $resource->name,
            'status' => (string) $resource->status,
        ];
        $variables = $resource->configuration['variables'] ?? [];

        if ($resource->type === 'postgresql') {
            $database = (string) ($variables['DB_DATABASE'] ?? '');
            $username = (string) ($variables['DB_USERNAME'] ?? '');
            $valid = $databaseIdentifier !== null
                && $database === $databaseIdentifier
                && $username === $databaseIdentifier
                && $this->identifier($database)
                && $this->identifier($username);

            return $valid
                ? [...$base, 'database' => $database, 'username' => $username]
                : [...$base, 'invalid' => true];
        }

        if ($resource->type === 'valkey') {
            $container = (string) ($resource->configuration['container_name'] ?? '');
            $expected = 'buildpusher-valkey-'.$environment->id.'-'.Str::slug($resource->name);
            $valid = $container !== '' && hash_equals($expected, $container);

            return $valid
                ? [...$base, 'container_name' => $container, 'volume_name' => $container.'-data']
                : [...$base, 'invalid' => true];
        }

        return [...$base, 'invalid' => true];
    }

    private function identifier(string $value): bool
    {
        return preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $value) === 1;
    }
}
