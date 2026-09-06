<?php

namespace App\Jobs;

use App\Models\Build;
use App\Models\Environment;
use App\Models\Website;
use App\Services\Runner;
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

    public function __construct(public readonly int $websiteId) {}

    public function uniqueId(): string
    {
        return (string) $this->websiteId;
    }

    public function handle(Runner $runner): void
    {
        $website = Website::query()->with(['server', 'domains'])->find($this->websiteId);
        if (! $website?->server || $website->provisioning_status !== Website::STATUS_ACTIVE) {
            return;
        }

        $aliases = $website->domains->where('type', 'alias')->pluck('hostname')->prepend($website->url)->unique()->values();
        $environment = Environment::query()->where('website_id', $website->id)->latest('id')->first();
        $runtime = $environment?->runtime_type ?: 'php';
        $build = $environment ? Build::query()->where('environment_id', $environment->id)->where('status', Build::STATUS_SUCCEEDED)->latest('id')->first() : null;
        $body = $this->applicationBody($website, $runtime, $build);
        $blocks = [$aliases->implode(', ')." {\n{$body}\n}"];
        foreach ($website->domains->where('type', 'redirect') as $domain) {
            $blocks[] = $domain->hostname." {\n    redir ".rtrim((string) $domain->redirect_url, '/')."{uri} permanent\n}";
        }
        $config = implode("\n\n", $blocks)."\n";
        $encoded = escapeshellarg(base64_encode($config));
        $path = escapeshellarg("/etc/caddy/websites/{$website->deployment_slug}.conf");
        $script = "set -Eeuo pipefail\nprintf '%s' {$encoded} | base64 --decode > {$path}\ncaddy validate --config /etc/caddy/Caddyfile\nsystemctl reload caddy";
        $result = $runner->server($website->server)->create()->execute($script);
        if (! $result->isSuccessful()) {
            throw new RuntimeException(trim($result->getErrorOutput()) ?: 'Unable to apply domain routing.');
        }
    }

    private function applicationBody(Website $website, string $runtime, ?Build $build): string
    {
        $log = "    encode zstd gzip\n    log {\n        output file /var/log/caddy/{$website->deployment_slug}.access.log {\n            roll_size 20MiB\n            roll_keep 5\n            roll_keep_for 168h\n        }\n        format json\n    }";
        if (in_array($runtime, ['node', 'python', 'docker'], true) && $build) {
            $port = 20000 + (($website->id * 997 + $build->id) % 30000);

            return "    reverse_proxy 127.0.0.1:{$port}\n{$log}";
        }

        $phpVersion = (string) config('lessbuild.default_php_version', '8.4');
        return "    root * /var/www/{$website->deployment_slug}/current/public\n{$log}\n    file_server\n    php_fastcgi unix//var/run/php/php{$phpVersion}-fpm.sock";
    }
}
