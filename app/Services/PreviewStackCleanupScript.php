<?php

namespace App\Services;

use App\Models\PreviewStackCleanup;
use RuntimeException;

class PreviewStackCleanupScript
{
    /**
     * Render an idempotent command for only the captured preview process and resource identities.
     *
     * @param  PreviewStackCleanup  $cleanup  Cleanup record containing safe generated identifiers.
     * @return string Bash source executed on the captured server.
     *
     * @throws RuntimeException If the captured deployment or resource identity is unsafe.
     */
    public function render(PreviewStackCleanup $cleanup): string
    {
        $slug = $cleanup->deployment_slug;
        if (! preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/D', $slug)) {
            throw new RuntimeException('The preview deployment identifier is invalid.');
        }

        $sections = [$this->processCleanup($slug, $cleanup->process_manifest ?? [])];
        foreach ($cleanup->resource_manifest ?? [] as $resource) {
            if (! is_array($resource) || ($resource['invalid'] ?? false)) {
                throw new RuntimeException('The preview cleanup contains an invalid resource identity.');
            }

            $sections[] = match ($resource['type'] ?? null) {
                'postgresql' => $this->postgresqlCleanup($resource),
                'valkey' => $this->valkeyCleanup($resource),
                default => throw new RuntimeException('The preview cleanup contains an unsupported resource type.'),
            };
        }

        return "#!/bin/bash\nset -Eeuo pipefail\n".implode("\n", $sections);
    }

    /** @param  list<array{name: string}>  $processes */
    private function processCleanup(string $slug, array $processes): string
    {
        $units = [];
        foreach ($processes as $process) {
            $name = $process['name'] ?? null;
            if (! is_string($name) || ! preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/D', $name)) {
                throw new RuntimeException('The preview process identity is invalid.');
            }
            for ($replica = 1; $replica <= 20; $replica++) {
                $units[] = 'buildpusher-'.$slug.'-'.$name.'-'.$replica.'.service';
            }
        }

        $commands = '';
        foreach ($units as $unit) {
            $path = $this->shell('/etc/systemd/system/'.$unit);
            $commands .= "systemctl disable --now {$this->shell($unit)} >/dev/null 2>&1 || true\nrm -f -- {$path}\n";
        }

        return <<<BASH
        {$commands}
        systemctl daemon-reload
        BASH;
    }

    /** @param  array<string, mixed>  $resource */
    private function postgresqlCleanup(array $resource): string
    {
        $database = $this->identifier($resource['database'] ?? null);
        $username = $this->identifier($resource['username'] ?? null);
        $databaseSql = '"'.$database.'"';
        $usernameSql = '"'.$username.'"';

        return <<<BASH
        if command -v psql >/dev/null 2>&1; then
            sudo -u postgres psql --set=ON_ERROR_STOP=1 postgres --command={$this->shell("DROP DATABASE IF EXISTS {$databaseSql}; DROP ROLE IF EXISTS {$usernameSql};")}
        elif [ "{$this->status($resource)}" != planned ]; then
            exit 1
        fi
        BASH;
    }

    /** @param  array<string, mixed>  $resource */
    private function valkeyCleanup(array $resource): string
    {
        $container = $this->container($resource['container_name'] ?? null);
        $volume = $this->container($resource['volume_name'] ?? null);

        return <<<BASH
        if command -v docker >/dev/null 2>&1; then
            if docker container inspect {$this->shell($container)} >/dev/null 2>&1; then
                docker rm --force {$this->shell($container)} >/dev/null
            fi
            if docker volume inspect {$this->shell($volume)} >/dev/null 2>&1; then
                docker volume rm {$this->shell($volume)} >/dev/null
            fi
        elif [ "{$this->status($resource)}" != planned ]; then
            exit 1
        fi
        BASH;
    }

    private function identifier(mixed $value): string
    {
        $value = is_string($value) ? $value : '';
        if (! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $value)) {
            throw new RuntimeException('The preview database identity is invalid.');
        }

        return $value;
    }

    private function container(mixed $value): string
    {
        $value = is_string($value) ? $value : '';
        if (! preg_match('/\Abuildpusher-valkey-[a-zA-Z0-9_-]+(?:-data)?\z/D', $value)) {
            throw new RuntimeException('The preview cache identity is invalid.');
        }

        return $value;
    }

    /** @param  array<string, mixed>  $resource */
    private function status(array $resource): string
    {
        return ($resource['status'] ?? null) === 'planned' ? 'planned' : 'active';
    }

    private function shell(string $value): string
    {
        return escapeshellarg($value);
    }
}
