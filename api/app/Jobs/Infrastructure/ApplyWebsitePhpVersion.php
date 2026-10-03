<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\Website;
use App\Services\Infrastructure\Scripts\Languages\InstallPHPScript;
use App\Services\Infrastructure\ServerShell;
use App\Services\Infrastructure\WebsiteCaddyConfiguration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * Moves a website to its chosen PHP version: installs that version's FPM beside any others on the server (with the
 * PHP repository added if the server lacks it), points the website's Caddy site at it, validates and reloads.
 */
final class ApplyWebsitePhpVersion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Seconds the install may take.
     *
     * @var int
     */
    public int $timeout = 900;

    /**
     * Create a new ApplyWebsitePhpVersion instance.
     *
     * @param  int  $websiteId  The website, read again so the version is current.
     */
    public function __construct(public readonly int $websiteId) {}

    /**
     * Install and switch; a failure is thrown so the job's failure is recorded, and the site keeps its old config
     * because Caddy validates before reloading.
     *
     * @param  ServerShell  $shell
     * @param  WebsiteCaddyConfiguration  $caddy
     * @return void
     */
    public function handle(ServerShell $shell, WebsiteCaddyConfiguration $caddy): void
    {
        $website = Website::query()->with(['server', 'domains'])->find($this->websiteId);
        if ($website === null || $website->server === null || $website->provisioning_status !== Website::STATUS_ACTIVE) {
            return;
        }
        $version = $website->phpVersion();
        $config = escapeshellarg(base64_encode($caddy->php($website, $website->deploymentPath('current').'/public')));
        $path = escapeshellarg("/etc/caddy/websites/{$website->deployment_slug}.conf");
        $fpm = escapeshellarg("php{$version}-fpm");
        $install = InstallPHPScript::install($version);
        $script = <<<SCRIPT
        set -Eeuo pipefail
        apt_wait () { while fuser /var/lib/dpkg/lock /var/lib/dpkg/lock-frontend /var/lib/apt/lists/lock >/dev/null 2>&1; do sleep 5; done; }
        if [ ! -x /usr/sbin/php-fpm{$version} ]; then
            grep -rqs ondrej/php /etc/apt/sources.list.d || { apt_wait; add-apt-repository -y ppa:ondrej/php; }
            apt_wait
            apt-get update -qq
            {$install}
        fi
        systemctl enable --now {$fpm}
        printf '%s' {$config} | base64 --decode > {$path}
        caddy validate --config /etc/caddy/Caddyfile
        systemctl reload caddy
        SCRIPT;
        $result = $shell->run($website->server, $script, true);
        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput) !== '' ? trim($result->errorOutput) : 'Couldn’t switch the PHP version.');
        }
    }
}
