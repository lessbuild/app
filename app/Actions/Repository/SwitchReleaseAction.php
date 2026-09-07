<?php

namespace App\Actions\Repository;

use App\Models\Build;
use App\Services\Runner;
use RuntimeException;

class SwitchReleaseAction
{
    /**
     * Accept an optional SSH runner for activating a retained release.
     *
     * @param  Runner|null  $runner  Optional SSH runner; null creates the default runner for this operation.
     */
    public function __construct(private readonly ?Runner $runner = null) {}

    /**
     * Validate the retained release path and atomically switch the current symlink; attempt to restore the previous release if the configured health check fails.
     *
     * @param  Build  $build  Rollback build carrying the retained release name and exact website release path.
     * @return string Trimmed remote activation output after a successful release switch.
     *
     * @throws RuntimeException If release metadata is unsafe, the release is unavailable, or remote activation fails.
     */
    public function handle(Build $build): string
    {
        $website = $build->repository->website;
        $root = "/var/www/{$website->deployment_slug}";
        $releaseName = $build->release_name;
        $releasePath = $build->release_path;

        if (! is_string($releaseName) || ! preg_match('/\A[a-zA-Z0-9._-]+\z/D', $releaseName)) {
            throw new RuntimeException('The retained release identifier is invalid.');
        }
        if ($releasePath !== "{$root}/releases/{$releaseName}") {
            throw new RuntimeException('The retained release path is invalid.');
        }

        $deployRoot = escapeshellarg($root);
        $targetPath = escapeshellarg($releasePath);
        $healthCheck = '';
        if ($website->health_check_enabled) {
            $healthUrl = escapeshellarg("http://{$website->url}{$website->health_check_path}");
            $healthCheck = <<<BASH
            if ! curl --fail --silent --show-error --location \
                --connect-timeout 5 --max-time 15 --retry 5 --retry-delay 2 --retry-all-errors \
                --user-agent "BuildPusher-release-rollback" --output /dev/null {$healthUrl}; then
                if [ -n "\$PREVIOUS_PATH" ] && [ -d "\$PREVIOUS_PATH" ]; then
                    ln -sfn -- "\$PREVIOUS_PATH" "\$DEPLOY_ROOT/current.rollback"
                    mv -Tf -- "\$DEPLOY_ROOT/current.rollback" "\$DEPLOY_ROOT/current"
                fi
                echo "Rollback health check failed; previous release restored." >&2
                exit 1
            fi
            BASH;
        }

        $command = <<<BASH
        set -Eeuo pipefail
        DEPLOY_ROOT={$deployRoot}
        TARGET_PATH={$targetPath}
        CURRENT_PATH="\$DEPLOY_ROOT/current"
        NEXT_LINK="\$DEPLOY_ROOT/current.next"

        [ -d "\$TARGET_PATH" ] || { echo "Retained release is no longer available." >&2; exit 2; }
        PREVIOUS_PATH="$(readlink -f -- "\$CURRENT_PATH" 2>/dev/null || true)"
        ln -sfn -- "\$TARGET_PATH" "\$NEXT_LINK"
        mv -Tf -- "\$NEXT_LINK" "\$CURRENT_PATH"
        {$healthCheck}
        printf 'Activated retained release: %s\n' "\$TARGET_PATH"
        BASH;

        $result = ($this->runner ?? new Runner)
            ->server($website->server)
            ->create()
            ->execute($command);

        if (! $result->isSuccessful()) {
            throw new RuntimeException(trim($result->getErrorOutput() ?: $result->getOutput()) ?: 'Unable to activate the retained release.');
        }

        return trim($result->getOutput());
    }
}
