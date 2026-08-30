<?php

namespace App\Scripts\Server;

use App\Models\Website;
use App\Services\ProvisioningCallbackUrl;

class UpdateEnviromentScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Update Environment';

    /**
     * Description of the script
     */
    public static string $description = 'Update Environment file';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'updated-env';

    /**
     * Shell script to run
     */
    public function script(int $step, Website $website): string
    {
        $directory = escapeshellarg("/var/www/{$website->deployment_slug}");
        $environmentPath = escapeshellarg("/var/www/{$website->deployment_slug}/.env");
        $environment = escapeshellarg(base64_encode($website->environment));
        $callback = escapeshellarg(ProvisioningCallbackUrl::websiteStatus($website));

        return <<<SCRIPT
            # Update the environment file
            mkdir -p -- {$directory}
            printf '%s' {$environment} | base64 --decode > {$environmentPath}

            # Ping
            curl --insecure --user-agent "deployer" --data "status={$step}&website_id={$website->id}" {$callback}
        SCRIPT;
    }
}
