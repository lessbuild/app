<?php

namespace App\Scripts\Server;

use App\Models\Website;

class UpdateEnviromentScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Update Environment';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Update Environment file';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'updated-env';

    /**
     * Shell script to run
     *
     * @param int $step
     * @param \App\Models\Website $website
     * @return string
     */
    public function script(int $step, Website $website): string
    {
        $name = $website->name;
        $env = $website->environment;
        $callback = config('app.url') . '/servers/add-website/callback/status';

        return <<<SCRIPT
            # Update the environment file
            mkdir -p /var/www/$name
            echo '$env' > /var/www/$name/.env

            # Ping
            curl --insecure --user-agent "deployer" --data "status={$step}&website_id={$website->id}" {$callback}
        SCRIPT;
    }
}
