<?php

declare(strict_types=1);

namespace App\Services\Deploy\Scripts;

use App\Models\Build;
use App\Services\Deploy\EnvironmentFile;

class SyncEnvironmentScript extends BuildProvisioningScript
{
    public const TITLE = 'Sync environment configuration';

    public const DESCRIPTION = 'Apply the immutable environment and attached-resource snapshot';

    public const IDENTIFIER = 'synced-environment';

    /**
     * Render runtime and build environment files from the captured deployment payload.
     *
     * @param  int  $step  The provisioning stage reported when these commands succeed.
     * @param  Build  $build  The build supplying the immutable environment snapshot and website identity.
     * @return string Shell source for the remote provisioning runner.
     */
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
            : (string) $build->website->env_file;
        $contents = app(EnvironmentFile::class)->merge($base, $variables);
        $encoded = escapeshellarg(base64_encode($contents));
        $environmentPath = escapeshellarg('/var/www/'.$build->repository->website->deployment_slug.'/.env');
        $buildEncoded = escapeshellarg(base64_encode($this->buildEnvironment($build)));
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

    /**
     * Get the build-time variables as an env file, exported while dependencies install and the build runs.
     *
     * @param  Build  $build
     * @return string
     */
    public function buildEnvironment(Build $build): string
    {
        $payload = $build->environment_payload ?? [];
        $variables = is_array($payload['build_variables'] ?? null) ? $payload['build_variables'] : [];

        return app(EnvironmentFile::class)->merge('', $variables);
    }
}
