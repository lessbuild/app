<?php

namespace App\Scripts\Web;

use App\Abstracts\Scripts\WebsiteProvisioningScript;
use App\Models\Website;

class AddWebsiteToCaddyScript extends WebsiteProvisioningScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Add website to Caddy';

    /**
     * Description of the script
     */
    public static string $description = 'Add website and configure Caddy';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'added-website';

    /**
     * Shell script to run
     */
    public function script(int $step, Website $website): string
    {
        $slug = $website->deployment_slug;
        $progress = $this->progress($step, $website);
        $config = <<<CADDY
        {$website->url} {
            root * /var/www/{$slug}/current/public
            encode zstd gzip
            file_server
            php_fastcgi unix//var/run/php/php8.1-fpm.sock
        }
        CADDY;
        $encodedConfig = escapeshellarg(base64_encode($config));
        $configPath = escapeshellarg("/etc/caddy/websites/{$slug}.conf");
        $cronPath = escapeshellarg("/etc/cron.d/{$slug}");

        return <<<SCRIPT

        rm -f -- {$cronPath}

        # Decode a fixed configuration payload rather than evaluating user input.
        printf '%s' {$encodedConfig} | base64 --decode > {$configPath}

        sudo systemctl reload caddy

        # Ping
        {$progress}
        SCRIPT;
    }
}
