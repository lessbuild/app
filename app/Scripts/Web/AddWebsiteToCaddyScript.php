<?php

namespace App\Scripts\Web;

use App\Abstracts\Scripts\WebsiteProvisioningScript;
use App\Models\Website;
use App\Services\WebsiteCaddyConfiguration;

class AddWebsiteToCaddyScript extends WebsiteProvisioningScript
{
    public function __construct(private readonly ?WebsiteCaddyConfiguration $caddy = null) {}

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
        $caddy = $this->caddy ?? new WebsiteCaddyConfiguration;
        $slug = $website->deployment_slug;
        $documentRoot = $website->deploymentPath('current').'/public';
        $siteAddress = $caddy->siteAddress($website->url);
        $progress = $this->progress($step, $website);
        $config = <<<CADDY
        {$siteAddress} {
            root * {$documentRoot}
            encode zstd gzip
            log {
                output file /var/log/caddy/{$slug}.access.log {
                    roll_size 20MiB
                    roll_keep 5
                    roll_keep_for 168h
                }
                format json
            }
            file_server
            php_fastcgi unix//var/run/php/php{{PHP_VERSION}}-fpm.sock
        }
        CADDY;
        $config = str_replace('{{PHP_VERSION}}', (string) config('lessbuild.default_php_version', '8.4'), $config);
        $encodedConfig = escapeshellarg(base64_encode($config));
        $configPath = escapeshellarg("/etc/caddy/websites/{$slug}.conf");
        $cronPath = escapeshellarg("/etc/cron.d/{$slug}");
        $logDirectory = escapeshellarg('/var/log/caddy');
        $accessLogPath = escapeshellarg("/var/log/caddy/{$slug}.access.log");

        return <<<SCRIPT

        rm -f -- {$cronPath}
        install -d -o caddy -g caddy -m 750 -- {$logDirectory}
        touch -- {$accessLogPath}
        chown caddy:caddy -- {$accessLogPath}
        chmod 640 -- {$accessLogPath}

        # Decode a fixed configuration payload rather than evaluating user input.
        printf '%s' {$encodedConfig} | base64 --decode > {$configPath}

        sudo caddy validate --config /etc/caddy/Caddyfile
        sudo systemctl reload caddy

        # Ping
        {$progress}
        SCRIPT;
    }
}
