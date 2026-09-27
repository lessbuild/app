<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\Website;
use App\Services\Infrastructure\ServerShell;
use App\Services\Infrastructure\WebsiteCaddyConfiguration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/** Rewrites a live website's Caddy site with its current aliases and redirects, validates it and reloads Caddy. */
final class ApplyWebsiteDomains implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 120;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public readonly int $websiteId) {}

    public function uniqueId(): string
    {
        return (string) $this->websiteId;
    }

    public function handle(ServerShell $shell, WebsiteCaddyConfiguration $caddy): void
    {
        $website = Website::query()->with(['server', 'domains'])->find($this->websiteId);
        if ($website === null || $website->server === null || $website->provisioning_status !== Website::STATUS_ACTIVE) {
            return;
        }
        $config = escapeshellarg(base64_encode($caddy->php($website, $website->deploymentPath('current').'/public')));
        $path = escapeshellarg("/etc/caddy/websites/{$website->deployment_slug}.conf");
        $result = $shell->run($website->server, "set -Eeuo pipefail\nprintf '%s' {$config} | base64 --decode > {$path}\ncaddy validate --config /etc/caddy/Caddyfile\nsystemctl reload caddy");
        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput) !== '' ? trim($result->errorOutput) : 'Couldn’t apply the domains.');
        }
    }
}
