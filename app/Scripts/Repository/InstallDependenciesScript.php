<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\BuildProvisioningScript;
use App\Models\Build;

class InstallDependenciesScript extends BuildProvisioningScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Install Repository Dependencies';

    /**
     * Description of the script
     */
    public static string $description = 'Install the repository dependencies on the server';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'installed-repository-dependencies';

    /**
     * The script to run
     */
    public function script(int $step, Build $build): string
    {
        $repository = $build->repository;
        $runtime = $build->environment_payload['runtime'] ?? [];
        $runtimeType = in_array($runtime['type'] ?? null, ['php', 'node', 'python', 'docker'], true) ? $runtime['type'] : 'php';
        $setupPath = escapeshellarg("/var/www/{$repository->website->deployment_slug}/setup");
        $buildCommand = trim((string) ($runtime['build_command'] ?? ''));
        $encodedBuildCommand = escapeshellarg(base64_encode($buildCommand));
        $dockerfile = escapeshellarg((string) (($runtime['dockerfile_path'] ?? null) ?: 'Dockerfile'));
        $image = escapeshellarg("buildpusher/{$repository->website->deployment_slug}:build-{$build->id}");
        $runtimeVersion = escapeshellarg((string) ($runtime['version'] ?? ''));
        $progress = $this->progress($step, $build);

        return <<<SCRIPT

            cd -- {$setupPath}
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

            if [ "\$RUNTIME_TYPE" = docker ]; then
                if ! command -v docker >/dev/null 2>&1; then
                    apt-get update -qq
                    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq docker.io
                    systemctl enable --now docker
                fi
                test -f {$dockerfile}
                docker build --pull --file {$dockerfile} --tag {$image} .
            elif [ -n {$encodedBuildCommand} ]; then
                BUILD_COMMAND="$(printf '%s' {$encodedBuildCommand} | base64 --decode)"
                /bin/bash -lc "\$BUILD_COMMAND"
            elif [ "\$RUNTIME_TYPE" = php ] && [ -f package.json ]; then
                npm run build --if-present
            fi

            # Ping
            {$progress}

        SCRIPT;
    }
}
