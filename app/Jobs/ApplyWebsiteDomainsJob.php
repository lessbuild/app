<?php

namespace App\Jobs;

use App\Models\Build;
use App\Models\Environment;
use App\Models\Website;
use App\Services\Runner;
use App\Services\WebsiteCaddyConfiguration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class ApplyWebsiteDomainsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 120;

    /**
     * Capture the website whose domain routing will be rebuilt from current configuration.
     *
     * @param  int  $websiteId  Website identifier retained for lookup when the job runs.
     */
    public function __construct(public readonly int $websiteId) {}

    /**
     * Coalesce queued instances of this job for the same website.
     *
     * @return string The website identifier used by Laravel's unique-job lock.
     */
    public function uniqueId(): string
    {
        return (string) $this->websiteId;
    }

    /**
     * Rebuild alias and redirect Caddy blocks for an active website and reload routing; skip unavailable websites and throw when remote application fails.
     *
     * @param  Runner  $runner  SSH runner used to execute commands on the selected managed server.
     * @param  WebsiteCaddyConfiguration  $caddy  Renderer for the website's complete routing configuration.
     */
    public function handle(Runner $runner, ?WebsiteCaddyConfiguration $caddy = null): void
    {
        $caddy ??= new WebsiteCaddyConfiguration;
        $website = Website::query()->with(['server', 'domains'])->find($this->websiteId);
        if (! $website?->server || $website->provisioning_status !== Website::STATUS_ACTIVE) {
            return;
        }

        $environment = Environment::query()->where('website_id', $website->id)->latest('id')->first();
        $runtime = $environment?->runtime_type ?: 'php';
        $build = $environment ? Build::query()->where('environment_id', $environment->id)->where('status', Build::STATUS_SUCCEEDED)->latest('id')->first() : null;
        $documentRoot = $build
            ? $build->deploymentPath('current').'/public'
            : $website->deploymentPath('current').'/public';
        $config = in_array($runtime, ['node', 'python', 'docker'], true) && $build
            ? $caddy->reverseProxy($website, 20000 + (($website->id * 997 + $build->id) % 30000))
            : $caddy->php($website, $documentRoot);
        $encoded = escapeshellarg(base64_encode($config));
        $path = escapeshellarg("/etc/caddy/websites/{$website->deployment_slug}.conf");
        $script = "set -Eeuo pipefail\nprintf '%s' {$encoded} | base64 --decode > {$path}\ncaddy validate --config /etc/caddy/Caddyfile\nsystemctl reload caddy";
        $result = $runner->server($website->server)->create()->execute($script);
        if (! $result->isSuccessful()) {
            throw new RuntimeException(trim($result->getErrorOutput()) ?: 'Unable to apply domain routing.');
        }
    }
}
