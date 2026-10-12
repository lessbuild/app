<?php

declare(strict_types=1);

namespace App\Services\Deploy\Scripts;

use App\Models\Build;

class ActivateReleaseScript extends BuildProvisioningScript
{
    public const TITLE = 'Activate Release';

    public const DESCRIPTION = 'Activate the release on the server, and update the symlink';

    public const IDENTIFIER = 'activated-release';

    /**
     * Render the stage that switches the `current` symlink to the new release and reports progress.
     *
     * @param  int  $step
     * @param  Build  $build
     * @return string
     */
    public function script(int $step, Build $build): string
    {
        $repository = $build->repository;
        $root = escapeshellarg("/var/www/{$repository->website->deployment_slug}");
        $release = escapeshellarg($build->releaseIdentifier());
        $progress = $this->progress($step, $build);
        $subdirectory = $build->deploymentRoot();
        $appPath = $subdirectory === '.' ? '"$RELEASE_PATH"' : '"$RELEASE_PATH"/'.escapeshellarg($subdirectory);

        return <<<SCRIPT

            DEPLOY_ROOT={$root}
            RELEASE_NAME={$release}
            RELEASE_PATH="\$DEPLOY_ROOT/releases/\$RELEASE_NAME"
            CURRENT_PATH="\$DEPLOY_ROOT/current"
            NEXT_LINK="\$DEPLOY_ROOT/current.next"
            PREVIOUS_RELEASE_PATH=""

            if [ -L "\$CURRENT_PATH" ]; then
                PREVIOUS_RELEASE_PATH="$(readlink -f -- "\$CURRENT_PATH" || true)"
            fi

            mkdir -p -- "\$DEPLOY_ROOT/releases"
            mv -- "\$DEPLOY_ROOT/setup" "\$RELEASE_PATH"

            # Convert the legacy directory layout on its first deployment.
            if [ -d "\$CURRENT_PATH" ] && [ ! -L "\$CURRENT_PATH" ]; then
                PREVIOUS_RELEASE_PATH="\$DEPLOY_ROOT/releases/legacy-\$RELEASE_NAME"
                mv -- "\$CURRENT_PATH" "\$PREVIOUS_RELEASE_PATH"
            fi

            # A Laravel app's caches and storage link hold full paths, so they're built here, in the release's final place,
            # before it goes live.
            if [ -f {$appPath}/artisan ]; then
                (
                    cd -- {$appPath}
                    php artisan storage:link --force
                    php artisan config:cache
                    php artisan route:cache
                    php artisan view:cache
                    php artisan event:cache
                    chown -R www-data:www-data bootstrap/cache
                )
            fi

            ln -sfn -- "\$RELEASE_PATH" "\$NEXT_LINK"
            mv -Tf -- "\$NEXT_LINK" "\$CURRENT_PATH"
            rm -f -- "\$DEPLOY_ROOT/.build.env"

            # Ping
            {$progress}

        SCRIPT;
    }
}
