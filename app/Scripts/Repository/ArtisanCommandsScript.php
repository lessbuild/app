<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\BuildProvisioningScript;
use App\Models\Build;

class ArtisanCommandsScript extends BuildProvisioningScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Run artisan commands';

    /**
     * Description of the script
     */
    public static string $description = 'Run the artisan commands';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'run-artisan-commands';

    /**
     * The script to run
     */
    public function script(int $step, Build $build): string
    {
        $repository = $build->repository;
        $currentPath = escapeshellarg("/var/www/{$repository->website->deployment_slug}/current");
        $progress = $this->progress($step, $build);

        return <<<SCRIPT

        cd -- {$currentPath}

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
