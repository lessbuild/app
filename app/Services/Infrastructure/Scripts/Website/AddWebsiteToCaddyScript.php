<?php

declare(strict_types=1);

namespace App\Services\Infrastructure\Scripts\Website;

use App\Models\Website;
use App\Services\Infrastructure\WebsiteCaddyConfiguration;

final class AddWebsiteToCaddyScript extends WebsiteProvisioningScript
{
    public function __construct(private readonly WebsiteCaddyConfiguration $caddy) {}

    public function script(int $step, Website $website): string
    {
        $slug = $website->deployment_slug;
        $config = escapeshellarg(base64_encode($this->caddy->php($website, $website->deploymentPath('current').'/public')));
        $configPath = escapeshellarg("/etc/caddy/websites/{$slug}.conf");
        $cronPath = escapeshellarg("/etc/cron.d/{$slug}");
        $accessLog = escapeshellarg("/var/log/caddy/{$slug}.access.log");
        $progress = $this->progress($step, $website);

        return <<<SCRIPT
        rm -f -- {$cronPath}
        install -d -o caddy -g caddy -m 750 -- /var/log/caddy
        touch -- {$accessLog}
        chown caddy:caddy -- {$accessLog}
        chmod 640 -- {$accessLog}
        # Decode a fixed configuration rather than evaluating user input.
        printf '%s' {$config} | base64 --decode > {$configPath}
        sudo caddy validate --config /etc/caddy/Caddyfile
        sudo systemctl reload caddy
        {$progress}
        SCRIPT;
    }
}
