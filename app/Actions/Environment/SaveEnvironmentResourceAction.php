<?php

namespace App\Actions\Environment;

use App\Models\Environment;
use App\Models\EnvironmentResource;
use Illuminate\Support\Str;

class SaveEnvironmentResourceAction
{
    /**
     * Persist an environment resource using its external variables or managed connection configuration.
     *
     * @param  array{name: string, type: string, is_managed: bool}  $data
     * @param  array<string, string>  $variables  Parsed external variables, or the initial values to replace for managed resources.
     */
    public function handle(Environment $environment, array $data, array $variables): EnvironmentResource
    {
        if ($data['is_managed'] && in_array($data['type'], ['mysql', 'postgresql'], true)) {
            $postgresql = $data['type'] === 'postgresql';
            $website = $environment->website;
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

        return $environment->resources()->updateOrCreate(['name' => $data['name']], [
            'type' => $data['type'],
            'is_managed' => $data['is_managed'],
            'configuration' => [
                'variables' => $variables,
                'container_name' => $data['is_managed'] && $data['type'] === 'valkey'
                    ? 'buildpusher-valkey-'.$environment->id.'-'.Str::slug($data['name'])
                    : null,
            ],
            'status' => 'ready',
        ]);
    }
}
