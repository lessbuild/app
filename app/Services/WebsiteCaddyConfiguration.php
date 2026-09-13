<?php

namespace App\Services;

use App\Models\Website;

class WebsiteCaddyConfiguration
{
    /**
     * Render a PHP-FPM configuration for the website and its configured domains.
     *
     * @param  Website  $website  Website supplying hostnames, redirects and access-log identity.
     * @param  string  $documentRoot  Absolute public directory for the active service release.
     * @return string Complete Caddy configuration for the website.
     */
    public function php(Website $website, string $documentRoot): string
    {
        return $this->render($website, implode("\n", [
            "    root * {$documentRoot}",
            '    encode zstd gzip',
            $this->accessLog($website),
            '    file_server',
            '    php_fastcgi unix//var/run/php/php'.config('lessbuild.default_php_version', '8.4').'-fpm.sock',
        ]));
    }

    /**
     * Render a loopback reverse-proxy configuration for a non-PHP runtime.
     *
     * @param  Website  $website  Website supplying hostnames, redirects and access-log identity.
     * @param  int  $port  Loopback port retained by the deployment runtime.
     * @return string Complete Caddy configuration for the website.
     */
    public function reverseProxy(Website $website, int $port): string
    {
        return $this->render($website, implode("\n", [
            '    encode zstd gzip',
            "    reverse_proxy 127.0.0.1:{$port}",
            $this->accessLog($website),
        ]));
    }

    /**
     * Wrap application directives with aliases, the primary hostname and redirect domains.
     *
     * @param  Website  $website  Website whose domain records are rendered.
     * @param  string  $body  Caddy application directives without a hostname wrapper.
     * @return string Complete Caddy configuration ending with a newline.
     */
    private function render(Website $website, string $body): string
    {
        $website->loadMissing('domains');
        $hostnames = $website->domains->where('type', 'alias')->pluck('hostname')->prepend($website->url)->unique()->implode(', ');
        $blocks = ["{$hostnames} {\n{$body}\n}"];
        foreach ($website->domains->where('type', 'redirect') as $domain) {
            $blocks[] = "{$domain->hostname} {\n    redir ".rtrim((string) $domain->redirect_url, '/')."{uri} permanent\n}";
        }

        return implode("\n\n", $blocks)."\n";
    }

    /**
     * Render the shared structured access-log directives.
     *
     * @param  Website  $website  Website supplying the stable log filename.
     * @return string Caddy log directives.
     */
    private function accessLog(Website $website): string
    {
        return "    log {\n        output file /var/log/caddy/{$website->deployment_slug}.access.log {\n            roll_size 20MiB\n            roll_keep 5\n            roll_keep_for 168h\n        }\n        format json\n    }";
    }
}
