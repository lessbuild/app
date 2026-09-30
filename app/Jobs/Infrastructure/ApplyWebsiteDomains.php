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

    /**
     * Seconds during which another apply for the same website isn't queued, so several quick domain changes apply once.
     *
     * @var int
     */
    public int $uniqueFor = 120;

    /**
     * Reloading Caddy can fail briefly, so it gets three tries.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Seconds between tries.
     *
     * @var int
     */
    public int $backoff = 10;

    /**
     * Create a new ApplyWebsiteDomains instance.
     *
     * Writes a website's domains into its Caddy site and reloads Caddy.
     *
     * @param  int  $websiteId  The website.
     */
    public function __construct(public readonly int $websiteId) {}

    /**
     * Get the job's unique ID, so there's one apply per website at a time.
     *
     * @return string
     */
    public function uniqueId(): string
    {
        return (string) $this->websiteId;
    }

    /**
     * Write the site configuration, validates the whole Caddyfile, and reloads. Validation first means a bad domain
     * can't take down other websites on the server.
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
        $config = escapeshellarg(base64_encode($caddy->php($website, $website->deploymentPath('current').'/public')));
        $path = escapeshellarg("/etc/caddy/websites/{$website->deployment_slug}.conf");
        // The old file is put back if Caddy refuses the new one, so one bad change never breaks the other sites.
        $result = $shell->run($website->server, implode("\n", [
            'set -Eeuo pipefail',
            "if [ -f {$path} ]; then cp {$path} {$path}.previous; fi",
            "printf '%s' {$config} | base64 --decode > {$path}",
            "if ! caddy validate --config /etc/caddy/Caddyfile 2>&1; then if [ -f {$path}.previous ]; then mv {$path}.previous {$path}; else rm -f {$path}; fi; exit 1; fi",
            "rm -f {$path}.previous",
            'systemctl reload caddy',
        ]));
        if (! $result->successful()) {
            $error = trim($result->errorOutput) !== '' ? trim($result->errorOutput) : (trim($result->output) !== '' ? trim($result->output) : 'Couldn’t apply the configuration.');
            $website->forceFill(['caddy_error' => str($error)->limit(2000)->toString()])->save();
            throw new RuntimeException($error);
        }
        if ($website->caddy_error !== null) {
            $website->forceFill(['caddy_error' => null])->save();
        }
    }
}
