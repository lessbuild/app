<?php

namespace App\Jobs;

use App\Models\Environment;
use App\Services\Entitlements;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class WakeHibernatedEnvironmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 55;

    public function __construct(public readonly int $environmentId) {}

    public function uniqueId(): string
    {
        return (string) $this->environmentId;
    }

    public function handle(Runner $runner, Entitlements $entitlements): void
    {
        $environment = Environment::query()->with(['project.organization.owner', 'website.server'])->find($this->environmentId);
        if (! $environment?->hibernated_at || ! $environment->website?->server
            || ! $entitlements->allows($environment->project->organization, 'hibernation')) {
            return;
        }

        $path = escapeshellarg('/var/log/caddy/'.$environment->website->deployment_slug.'.access.log');
        $result = $runner->server($environment->website->server)->create()->execute("stat -c %Y {$path} 2>/dev/null || echo 0");
        $lastRequestAt = (int) trim($result->getOutput());
        if ($lastRequestAt <= $environment->hibernated_at->getTimestamp()) {
            return;
        }

        ApplyEnvironmentRuntimeStateJob::dispatch($environment->id, false);
    }
}
