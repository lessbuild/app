<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\EnvironmentResource;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use Illuminate\Support\Str;

class ApplicationConfigurationResourceConfiguration
{
    /**
     * Build the encrypted configuration persisted for one declared resource.
     *
     * External resources resolve reviewed secret versions while managed resources
     * derive the same local connection variables and container identity used by
     * the reconciler before this responsibility was extracted.
     *
     * @param  EnvironmentResource  $resource  The existing or unsaved resource whose legacy configuration may be preserved.
     * @param  Environment  $environment  The target environment used for managed connection details and deterministic ports.
     * @param  Project  $project  The reviewed project used to keep secret reads organization-scoped.
     * @param  string  $name  The logical resource name used in deterministic managed container identity.
     * @param  array<string, mixed>  $settings  The validated resource declaration.
     * @param  array<string, array{variable_id: int, version: int}>  $resolvedSecrets  Reviewed secret identities and versions keyed by logical reference.
     * @return array<string, mixed> The encrypted resource configuration to persist and snapshot.
     */
    public function build(
        EnvironmentResource $resource,
        Environment $environment,
        Project $project,
        string $name,
        array $settings,
        array $resolvedSecrets,
    ): array {
        $configuration = $resource->configuration ?? ['variables' => [], 'container_name' => null];

        if (! $settings['managed'] && array_key_exists('variable_refs', $settings)) {
            $resourceVariables = [];
            foreach ($settings['variable_refs'] as $key => $reference) {
                $source = $resolvedSecrets[$reference];
                $variable = EnvironmentVariable::query()->whereKey($source['variable_id'])->where('current_version', $source['version'])
                    ->where('is_secret', true)->whereIn('scope', ['runtime', 'all'])
                    ->whereHas('environment.project', fn ($query) => $query->where('organization_id', $project->organization_id))
                    ->lockForUpdate()->firstOrFail();
                $resourceVariables[$key] = $variable->value;
            }
            $configuration = ['variables' => $resourceVariables, 'container_name' => null];
        }

        if ($settings['managed']) {
            $type = $settings['type'];
            $variables = [];
            if (in_array($type, ['mysql', 'postgresql'], true)) {
                $website = $environment->website;
                $variables = ['DB_CONNECTION' => $type === 'postgresql' ? 'pgsql' : 'mysql',
                    'DB_HOST' => $type === 'postgresql' ? '127.0.0.1' : $website->server->public_ip,
                    'DB_PORT' => $type === 'postgresql' ? '5432' : '3306',
                    'DB_DATABASE' => $website->databaseIdentifier(), 'DB_USERNAME' => $website->databaseIdentifier(),
                    'DB_PASSWORD' => $website->database_password];
            } elseif ($type === 'redis') {
                $variables = ['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '6379'];
            } elseif ($type === 'valkey') {
                $port = (string) (16379 + ($environment->id % 10000));
                $variables = ['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => $port, 'VALKEY_HOST' => '127.0.0.1', 'VALKEY_PORT' => $port];
            }
            $configuration = ['variables' => $variables, 'container_name' => $type === 'valkey' ? 'buildpusher-valkey-'.$environment->id.'-'.Str::slug($name) : null];
        }

        return $configuration;
    }
}
