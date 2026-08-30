<?php

namespace App\Scripts\Web;

use App\Models\Website;

class DeleteWebsiteCaddy
{
    /**
     * Title of the script
     */
    public static string $title = 'Remove website from Caddy';

    /**
     * Description of the script
     */
    public static string $description = 'Remove Website from Caddy';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'removed-website';

    /**
     * Shell script to run
     *
     * @param  int  $step
     */
    public function script(Website $website): string
    {
        $configPath = escapeshellarg("/etc/caddy/websites/{$website->deployment_slug}.conf");
        $websitePath = escapeshellarg("/var/www/{$website->deployment_slug}");

        return <<<SCRIPT
            rm -f -- {$configPath}
            rm -rf -- {$websitePath}
            sudo systemctl reload caddy
        SCRIPT;
    }
}
