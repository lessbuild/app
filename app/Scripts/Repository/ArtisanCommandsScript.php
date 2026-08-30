<?php

namespace App\Scripts\Repository;

use App\Models\Repository;

class ArtisanCommandsScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Run artisan commands';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Run the artisan commands';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'run-artisan-commands';

    /**
     * The script to run
     *
     * @param  int  $step
     * @param  \App\Models\Repository  $repository
     * @return string
     */
    public function script(int $step, Repository $repository): string
    {
        $callback = \Illuminate\Support\Facades\URL::signedRoute('callbacks.repository', $repository);
        $name = $repository->website->name;

        return <<<SCRIPT

        cd  /var/www/$name/current

        php artisan storage:link
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
        php artisan event:cache
        php artisan migrate --force
        php artisan horizon:terminate

        # Ping
        curl --insecure --user-agent "deployer" --data "status=$step&repository_id=$repository->id" $callback

        SCRIPT;
    }
}
