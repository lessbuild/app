<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\BuildProvisioningScript;
use App\Models\Build;
use App\Services\EnvironmentFile;

class SyncEnvironmentScript extends BuildProvisioningScript
{
    public static string $title = 'Sync environment configuration';

    public static string $description = 'Apply the immutable environment and attached-resource snapshot';

    public static string $identifier = 'synced-environment';

    public function script(int $step, Build $build): string
    {
        $payload = $build->environment_payload ?? [];
        $variables = is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
        foreach ($payload['resources'] ?? [] as $resource) {
            foreach (($resource['configuration']['variables'] ?? []) as $key => $value) {
                $variables[$key] = $value;
            }
        }
        // An explicitly empty snapshot must not pick up later website secrets.
        // Older builds without this key retain their historical fallback.
        $base = array_key_exists('base_environment', $payload)
            ? (string) $payload['base_environment']
            : (string) $build->repository->website->environment;
        $contents = app(EnvironmentFile::class)->merge($base, $variables);
        $encoded = escapeshellarg(base64_encode($contents));
        $environmentPath = escapeshellarg('/var/www/'.$build->repository->website->deployment_slug.'/.env');
        $buildVariables = is_array($payload['build_variables'] ?? null) ? $payload['build_variables'] : [];
        $buildContents = app(EnvironmentFile::class)->merge('', $buildVariables);
        $buildEncoded = escapeshellarg(base64_encode($buildContents));
        $buildEnvironmentPath = escapeshellarg('/var/www/'.$build->repository->website->deployment_slug.'/.build.env');
        $progress = $this->progress($step, $build);

        return <<<SCRIPT
        printf '%s' {$encoded} | base64 --decode > {$environmentPath}
        chown root:www-data {$environmentPath}
        chmod 640 {$environmentPath}
        printf '%s' {$buildEncoded} | base64 --decode > {$buildEnvironmentPath}
        chmod 600 {$buildEnvironmentPath}
        set -a
        . {$buildEnvironmentPath}
        set +a
        {$progress}
        SCRIPT;
    }
}
