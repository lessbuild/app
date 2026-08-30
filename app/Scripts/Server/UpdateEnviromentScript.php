<?php

namespace App\Scripts\Server;

use App\Abstracts\Scripts\WebsiteProvisioningScript;
use App\Models\Website;

class UpdateEnviromentScript extends WebsiteProvisioningScript
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
        $progress = $this->progress($step, $website);

        return <<<SCRIPT
            # Update the environment file
            mkdir -p -- {$directory}
            printf '%s' {$environment} | base64 --decode > {$environmentPath}

            # Ping
            {$progress}
        SCRIPT;
    }
}
