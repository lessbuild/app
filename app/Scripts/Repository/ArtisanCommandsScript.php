<?php

namespace App\Scripts\Repository;

use App\Models\Build;
use App\Services\ProvisioningCallbackUrl;

class ArtisanCommandsScript
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
        $callback = escapeshellarg(ProvisioningCallbackUrl::buildStatus($build));

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
        curl --insecure --user-agent "deployer" --data "status={$step}&build_id={$build->id}" {$callback}

        SCRIPT;
    }
}
