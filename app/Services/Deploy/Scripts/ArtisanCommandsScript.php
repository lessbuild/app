<?php

declare(strict_types=1);

namespace App\Services\Deploy\Scripts;

use App\Models\Build;

class ArtisanCommandsScript extends BuildProvisioningScript
{
    public const TITLE = 'Run artisan commands';

    public const DESCRIPTION = 'Run the artisan commands';

    public const IDENTIFIER = 'run-artisan-commands';

    /**
     * Render the stage that runs a Laravel release's artisan commands (storage link, caches and migrations, and a
     * Horizon restart) and reports progress.
     *
     * @param  int  $step
     * @param  Build  $build
     * @return string
     */
    public function script(int $step, Build $build): string
    {
        $repository = $build->repository;
        $candidatePath = escapeshellarg($build->deploymentPath('setup'));
        $progress = $this->progress($step, $build);

        return <<<SCRIPT

        cd -- {$candidatePath}

        if [ -f artisan ]; then
            php artisan storage:link --force
            php artisan config:cache
            php artisan route:cache
            php artisan view:cache
            php artisan event:cache
            php artisan migrate --force

            if php artisan list --raw | grep -qx 'horizon:terminate'; then
                php artisan horizon:terminate
            fi
        fi

        # Ping
        {$progress}

        SCRIPT;
    }
}
