<?php

namespace App\Scripts\Repository;

use App\Models\Repository;
use Illuminate\Support\Facades\URL;

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
    public function script(int $step, Repository $repository): string
    {
        $currentPath = escapeshellarg("/var/www/{$repository->website->deployment_slug}/current");
        $callback = escapeshellarg(URL::signedRoute('callbacks.repository', $repository));

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
        curl --insecure --user-agent "deployer" --data "status={$step}&repository_id={$repository->id}" {$callback}

        SCRIPT;
    }
}
