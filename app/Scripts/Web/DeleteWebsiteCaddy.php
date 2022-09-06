<?php

namespace App\Scripts\Web;

use App\Models\Website;

class DeleteWebsiteCaddy
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Remove website from Caddy';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Remove Website from Caddy';

    /**
     * Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'removed-website';

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
        $url = $website->url;
        $callback = config('app.url') . '/servers/add-website/callback/status';

        return <<<SCRIPT
            rm -f /etc/caddy/websites/{$name}.conf

            # Remove the website from the Caddy config
            sed -i -e '/import app {$url} /var/www/{$name}/d' /etc/caddy/Caddyfile

            # Ping
            curl --insecure --user-agent "deployer" --data "status={$step}&website_id={$website->id}" {$callback}
        SCRIPT;
    }
}
