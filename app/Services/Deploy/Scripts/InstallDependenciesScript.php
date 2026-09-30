<?php

declare(strict_types=1);

namespace App\Services\Deploy\Scripts;

use App\Models\Build;
use App\Services\Deploy\ManagedResourceScript;
use App\Services\Deploy\SecurityGate;

class InstallDependenciesScript extends BuildProvisioningScript
{
    public const TITLE = 'Install Repository Dependencies';

    public const DESCRIPTION = 'Install the repository dependencies on the server';

    public const IDENTIFIER = 'installed-repository-dependencies';

    /**
     * Create a new InstallDependenciesScript instance.
     *
     * Installs the release's dependencies.
     *
     * @param  ManagedResourceScript  $resources  Renders managed-resource setup the dependencies step needs.
     */
    public function __construct(private readonly ManagedResourceScript $resources = new ManagedResourceScript) {}

    /**
     * Render the stage that prepares managed resources, installs the release's dependencies for its runtime, runs
     * its build command, passes the Security gate when the environment has one, and reports progress.
     *
     * @param  int  $step
     * @param  Build  $build
     * @return string
     */
    public function script(int $step, Build $build): string
    {
        $repository = $build->repository;
        $runtime = $build->environment_payload['runtime'] ?? [];
        $runtimeType = in_array($runtime['type'] ?? null, ['php', 'node', 'python', 'docker', 'compose'], true) ? $runtime['type'] : 'php';
        $setupPath = escapeshellarg($build->deploymentPath('setup'));
        $buildCommand = trim((string) ($runtime['build_command'] ?? ''));
        $encodedBuildCommand = escapeshellarg(base64_encode($buildCommand));
        $dockerfile = escapeshellarg((string) (($runtime['dockerfile_path'] ?? null) ?: 'Dockerfile'));
        $image = escapeshellarg("buildpusher/{$repository->website->deployment_slug}:build-{$build->id}");
        $composeFile = escapeshellarg((string) (($runtime['dockerfile_path'] ?? null) ?: 'compose.yaml'));
        $composeProject = escapeshellarg("buildpusher-{$repository->website->deployment_slug}");
        $runtimeVersion = escapeshellarg((string) ($runtime['version'] ?? ''));
        $resourcePreparation = $this->resources->render($build->environment_payload['resources'] ?? []);
        $progress = $this->progress($step, $build);
        $cache = $this->cache($build);
        $gate = app(SecurityGate::class)->commands($build);

        return <<<SCRIPT

            cd -- {$setupPath}

            {$cache}

            # Dependency hooks and migrations may need resources on the first deployment.
            # The normal resource stage still reconciles and reports its original callback.
            {$resourcePreparation}

            RUNTIME_TYPE={$runtimeType}
            RUNTIME_VERSION={$runtimeVersion}

            if [ "\$RUNTIME_TYPE" = php ] && [ -f composer.json ]; then
                composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader
            fi

            if [ "\$RUNTIME_TYPE" = node ] || { [ "\$RUNTIME_TYPE" = php ] && [ -f package.json ]; }; then
                if ! command -v node >/dev/null 2>&1; then
                    apt-get update -qq
                    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq nodejs npm
                fi
                if [ -n "\$RUNTIME_VERSION" ]; then
                    INSTALLED_NODE_MAJOR="$(node --version | sed -E 's/^v([0-9]+).*/\1/')"
                    REQUESTED_NODE_MAJOR="\${RUNTIME_VERSION%%.*}"
                    [ "\$INSTALLED_NODE_MAJOR" = "\$REQUESTED_NODE_MAJOR" ] || { echo "Requested Node \$RUNTIME_VERSION but server has $(node --version)"; false; }
                fi
                if [ -f pnpm-lock.yaml ]; then
                    command -v corepack >/dev/null 2>&1 && corepack enable
                    pnpm install --frozen-lockfile
                elif [ -f yarn.lock ]; then
                    command -v corepack >/dev/null 2>&1 && corepack enable
                    yarn install --immutable
                elif [ -f package-lock.json ]; then
                    npm ci --no-audit --no-fund
                else
                    npm install --no-audit --no-fund
                fi
            fi

            if [ "\$RUNTIME_TYPE" = python ]; then
                apt-get update -qq
                DEBIAN_FRONTEND=noninteractive apt-get install -y -qq python3 python3-pip python3-venv
                python3 -m venv .venv
                .venv/bin/pip install --disable-pip-version-check --no-input --upgrade pip wheel
                if [ -f requirements.txt ]; then .venv/bin/pip install --disable-pip-version-check --no-input -r requirements.txt; fi
                if [ -f pyproject.toml ]; then .venv/bin/pip install --disable-pip-version-check --no-input .; fi
            fi

            if [ "\$RUNTIME_TYPE" = docker ] || [ "\$RUNTIME_TYPE" = compose ]; then
                if ! command -v docker >/dev/null 2>&1; then
                    apt-get update -qq
                    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq docker.io
                    systemctl enable --now docker
                fi
            fi
            if [ "\$RUNTIME_TYPE" = compose ]; then
                if ! docker compose version >/dev/null 2>&1; then
                    apt-get update -qq
                    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq docker-compose-v2 || DEBIAN_FRONTEND=noninteractive apt-get install -y -qq docker-compose-plugin
                fi
                test -f {$composeFile}
                docker compose --project-name {$composeProject} --file {$composeFile} build --pull
            elif [ "\$RUNTIME_TYPE" = docker ]; then
                test -f {$dockerfile}
                docker build --pull --file {$dockerfile} --tag {$image} .
            elif [ -n {$encodedBuildCommand} ]; then
                BUILD_COMMAND="$(printf '%s' {$encodedBuildCommand} | base64 --decode)"
                /bin/bash -lc "\$BUILD_COMMAND"
            elif [ "\$RUNTIME_TYPE" = php ] && [ -f package.json ]; then
                npm run build --if-present
            fi

            {$gate}

            # Ping
            {$progress}

        SCRIPT;
    }

    /**
     * Render the lines that point Composer, npm, Yarn, pnpm and pip at the website's shared download cache (created
     * on first use, and replacing older versions after the cache is cleared), or keep them off it when the repository
     * turns the cache off.
     *
     * @param  Build  $build
     * @return string
     */
    private function cache(Build $build): string
    {
        $repository = $build->repository;
        $root = "/var/www/{$repository->website->deployment_slug}/cache";
        if (! $repository->build_cache_enabled) {
            return '# Build cache off for this repository.';
        }
        $version = max(1, (int) $repository->build_cache_version);
        $directory = escapeshellarg("{$root}/v{$version}");
        $rootArgument = escapeshellarg($root);
        $keep = escapeshellarg("v{$version}");

        return <<<SCRIPT
        # Shared download cache: reused by later deploys of this website; older versions are removed.
        BUILD_CACHE={$directory}
        install -d -m 755 -- "\$BUILD_CACHE"
        find {$rootArgument} -mindepth 1 -maxdepth 1 -type d ! -name {$keep} -exec rm -rf -- {} + 2>/dev/null || true
        export COMPOSER_CACHE_DIR="\$BUILD_CACHE/composer" npm_config_cache="\$BUILD_CACHE/npm" YARN_CACHE_FOLDER="\$BUILD_CACHE/yarn" npm_config_store_dir="\$BUILD_CACHE/pnpm" PIP_CACHE_DIR="\$BUILD_CACHE/pip"
        SCRIPT;
    }
}
