<?php

namespace App\Actions\Environment;

use App\Models\Environment;
use App\Models\EnvironmentResource;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaveEnvironmentResourceAction
{
    /**
     * Persist an environment resource using its external variables or managed connection configuration.
     *
     * @param  array{name: string, type: string, is_managed: bool|string, variables?: string|null, status?: string, is_preview_owned?: bool|string}  $data
     * @param  array<string, string>|null  $variables  Optional pre-parsed variables retained for non-HTTP callers.
     * @param  array<string, string>|null  $managedVariables  Optional internal variables for managed preview credentials.
     */
    public function handle(Environment $environment, array $data, ?array $variables = null, ?array $managedVariables = null): EnvironmentResource
    {
        if ($data['is_managed'] && $data['type'] === 'object_storage') {
            throw ValidationException::withMessages(['type' => __('Object storage must use externally supplied credentials.')]);
        }
        if ($variables === null) {
            $variables = $this->parseVariables((string) ($data['variables'] ?? ''));
        }
        if ($data['is_managed'] && in_array($data['type'], ['mysql', 'postgresql'], true)) {
            $postgresql = $data['type'] === 'postgresql';
            $website = $environment->website;
            if (! $website) {
                throw ValidationException::withMessages(['type' => __('Attach a website before adding its managed database.')]);
            }
            $variables = [
                'DB_CONNECTION' => $postgresql ? 'pgsql' : 'mysql',
                'DB_HOST' => $postgresql ? '127.0.0.1' : $website->server->public_ip,
                'DB_PORT' => $postgresql ? '5432' : '3306',
                'DB_DATABASE' => $website->databaseIdentifier(),
                'DB_USERNAME' => $website->databaseIdentifier(),
                'DB_PASSWORD' => $website->database_password,
            ];
        } elseif ($data['is_managed'] && $data['type'] === 'redis') {
            $variables = ['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '6379'];
        } elseif ($data['is_managed'] && $data['type'] === 'valkey') {
            $port = 16379 + ($environment->id % 10000);
            $variables = [
                'REDIS_HOST' => '127.0.0.1',
                'REDIS_PORT' => (string) $port,
                'VALKEY_HOST' => '127.0.0.1',
                'VALKEY_PORT' => (string) $port,
            ];
        }
        if ($data['is_managed'] && $managedVariables !== null) {
            $variables = array_replace($variables, $managedVariables);
        }

        $existing = $environment->resources()->where('name', $data['name'])->first();

        return $environment->resources()->updateOrCreate(['name' => $data['name']], [
            'type' => $data['type'],
            'is_managed' => $data['is_managed'],
            'is_preview_owned' => array_key_exists('is_preview_owned', $data)
                ? (bool) $data['is_preview_owned']
                : (bool) $existing?->is_preview_owned,
            'configuration' => [
                'variables' => $variables,
                'container_name' => $data['is_managed'] && $data['type'] === 'valkey'
                    ? 'buildpusher-valkey-'.$environment->id.'-'.Str::slug($data['name'])
                    : null,
            ],
            'status' => $data['status'] ?? EnvironmentResource::STATUS_READY,
        ]);
    }

    /**
     * Parse externally supplied resource variables without logging or echoing their values.
     *
     * @return array<string, string>
     */
    private function parseVariables(string $input): array
    {
        $variables = [];
        foreach (preg_split('/\R/', $input) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! preg_match('/\A([A-Z_][A-Z0-9_]*)=(.*)\z/', $line, $matches)) {
                throw ValidationException::withMessages([
                    'variables' => __('Each resource variable must use KEY=value on its own line.'),
                ]);
            }
            $variables[$matches[1]] = $matches[2];
        }

        return $variables;
    }
}
