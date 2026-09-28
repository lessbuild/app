<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\Website;

/** The Caddy site block for a website: its primary domain and aliases serve it, and redirects get their own blocks. */
final class WebsiteCaddyConfiguration
{
    /**
     * The site for a PHP website served by PHP-FPM from the document root.
     *
     * @param  Website  $website
     * @param  string  $documentRoot
     * @return string
     */
    public function php(Website $website, string $documentRoot): string
    {
        return $this->render($website, implode("\n", [
            "    root * {$documentRoot}",
            '    encode zstd gzip',
            $this->accessLog($website),
            '    file_server',
            '    php_fastcgi unix//var/run/php/php'.config('infrastructure.default_php_version', '8.4').'-fpm.sock',
        ]));
    }

    /**
     * The site for a website served by an app listening on a local port.
     *
     * @param  Website  $website
     * @param  int  $port
     * @return string
     */
    public function reverseProxy(Website $website, int $port): string
    {
        return $this->render($website, implode("\n", ['    encode zstd gzip', "    reverse_proxy 127.0.0.1:{$port}", $this->accessLog($website)]));
    }

    /**
     * IP addresses get plain HTTP (no certificate can be issued for them).
     *
     * @param  string  $hostname
     * @return string
     */
    public function siteAddress(string $hostname): string
    {
        if (filter_var($hostname, FILTER_VALIDATE_IP) === false) {
            return $hostname;
        }

        return 'http://'.(str_contains($hostname, ':') ? "[{$hostname}]" : $hostname);
    }

    /**
     * The site block for the website's primary hostname and aliases, plus a permanent-redirect block for each redirect
     * domain.
     *
     * @param  Website  $website
     * @param  string  $body
     * @return string
     */
    private function render(Website $website, string $body): string
    {
        $website->loadMissing('domains');
        $hostnames = $website->domains->where('type', 'alias')->pluck('hostname')
            ->map(fn (string $hostname): string => $this->siteAddress($hostname))
            ->prepend($this->siteAddress($website->url))->unique()->implode(', ');
        $blocks = ["{$hostnames} {\n{$body}\n}"];
        foreach ($website->domains->where('type', 'redirect') as $domain) {
            $blocks[] = "{$domain->hostname} {\n    redir ".rtrim((string) $domain->redirect_url, '/')."{uri} permanent\n}";
        }

        return implode("\n\n", $blocks)."\n";
    }

    /**
     * The site's JSON access log, rotated at 20 MiB and kept for a week.
     *
     * @param  Website  $website
     * @return string
     */
    private function accessLog(Website $website): string
    {
        return "    log {\n        output file /var/log/caddy/{$website->deployment_slug}.access.log {\n            roll_size 20MiB\n            roll_keep 5\n            roll_keep_for 168h\n        }\n        format json\n    }";
    }
}
