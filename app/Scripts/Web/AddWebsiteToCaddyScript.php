<?php

namespace App\Scripts\Web;

use App\Models\Website;

class AddWebsiteToCaddyScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Add website to Caddy';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Add website and configure Caddy';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'added-website';

    /**
     * Shell script to run
     *
     * @param  int  $step
     * @param  \App\Models\Website  $website
     * @return string
     */
    public function script(int $step, Website $website): string
    {
        $name = $website->name;
        $url = $website->url;
        $callback = \Illuminate\Support\Facades\URL::signedRoute('callbacks.website', $website);

        return <<<SCRIPT

        rm -f /etc/cron.d/$name

        # Create a website in Caddy
        echo "
        $url {
            # Resolve the root directory for the app
            root * /var/www/{$name}/current/public

            # Provide Zstd and Gzip compression
            encode zstd gzip

            # Allow caddy to serve static files
            file_server

            # Enable PHP-FPM
            php_fastcgi unix//var/run/php/php8.1-fpm.sock
        }" > /etc/caddy/websites/{$name}.conf

        sudo systemctl reload caddy

        # Ping
        curl --insecure --user-agent "deployer" --data "status={$step}&website_id={$website->id}" {$callback}
        SCRIPT;
    }
}
