<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\BuildProvisioningScript;
use App\Models\Build;

class ConfigureResourcesScript extends BuildProvisioningScript
{
    public static string $title = 'Configure managed resources';

    public static string $description = 'Ensure locally managed databases and cache services are available';

    public static string $identifier = 'configured-resources';

    public function script(int $step, Build $build): string
    {
        // New snapshots explicitly record management intent. Historical snapshots
        // retain their existing host/container-based provisioning behavior.
        $managedResources = collect($build->environment_payload['resources'] ?? [])
            ->filter(fn (array $resource): bool => ! array_key_exists('is_managed', $resource) || $resource['is_managed'] === true);
        $managedRedis = $managedResources
            ->contains(fn (array $resource): bool => ($resource['type'] ?? null) === 'redis'
                && ($resource['configuration']['variables']['REDIS_HOST'] ?? null) === '127.0.0.1');
        $sections = [];
        if ($managedRedis) {
            $sections[] = <<<'BASH'
        if ! command -v redis-server >/dev/null 2>&1; then
            apt-get update -qq
            DEBIAN_FRONTEND=noninteractive apt-get install -y -qq redis-server
        fi
        systemctl enable --now redis-server
        BASH;
        }

        foreach ($managedResources->where('type', 'valkey') as $resource) {
            $variables = $resource['configuration']['variables'] ?? [];
            $container = (string) ($resource['configuration']['container_name'] ?? '');
            $port = (int) ($variables['VALKEY_PORT'] ?? 0);
            if (! preg_match('/\Abuildpusher-valkey-[a-zA-Z0-9_-]+\z/D', $container) || $port < 1024 || $port > 65535) {
                continue;
            }
            $containerArg = escapeshellarg($container);
            $sections[] = <<<BASH
            if ! command -v docker >/dev/null 2>&1; then
                apt-get update -qq
                DEBIAN_FRONTEND=noninteractive apt-get install -y -qq docker.io
                systemctl enable --now docker
            fi
            docker volume create {$containerArg}-data >/dev/null
            if ! docker container inspect {$containerArg} >/dev/null 2>&1; then
                docker run --detach --name {$containerArg} --restart unless-stopped --publish 127.0.0.1:{$port}:6379 --volume {$containerArg}-data:/data valkey/valkey:8-alpine valkey-server --appendonly yes
            else
                docker start {$containerArg} >/dev/null 2>&1 || true
            fi
            BASH;
        }

        foreach ($managedResources->where('type', 'postgresql') as $resource) {
            if (($resource['configuration']['variables']['DB_HOST'] ?? null) !== '127.0.0.1') {
                continue;
            }
            $variables = $resource['configuration']['variables'] ?? [];
            $database = (string) ($variables['DB_DATABASE'] ?? '');
            $username = (string) ($variables['DB_USERNAME'] ?? '');
            $password = (string) ($variables['DB_PASSWORD'] ?? '');
            if (! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $database)
                || ! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $username)
                || $password === '') {
                continue;
            }
            $sqlPassword = str_replace("'", "''", $password);
            $sections[] = <<<BASH
            if ! command -v psql >/dev/null 2>&1; then
                apt-get update -qq
                DEBIAN_FRONTEND=noninteractive apt-get install -y -qq postgresql postgresql-contrib
            fi
            systemctl enable --now postgresql
            sudo -u postgres psql --set=ON_ERROR_STOP=1 postgres <<'BUILDPUSHER_SQL'
            DO \$block\$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '{$username}') THEN
                    CREATE ROLE "{$username}" LOGIN;
                END IF;
            END
            \$block\$;
            ALTER ROLE "{$username}" WITH LOGIN PASSWORD '{$sqlPassword}';
            SELECT 'CREATE DATABASE "{$database}" OWNER "{$username}"' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '{$database}')\gexec
            ALTER DATABASE "{$database}" OWNER TO "{$username}";
            BUILDPUSHER_SQL
            BASH;
        }

        $configuration = $sections === []
            ? '# No locally managed runtime resources requested'
            : implode("\n", $sections);
        $progress = $this->progress($step, $build);

        return <<<SCRIPT
        {$configuration}
        {$progress}
        SCRIPT;
    }
}
