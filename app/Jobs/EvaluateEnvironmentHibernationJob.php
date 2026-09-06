<?php

namespace App\Jobs;

use App\Models\Build;
use App\Models\Environment;
use App\Services\Entitlements;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EvaluateEnvironmentHibernationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 240;

    public function __construct(public readonly int $environmentId) {}

    public function uniqueId(): string
    {
        return (string) $this->environmentId;
    }

    public function handle(Runner $runner, Entitlements $entitlements): void
    {
        $environment = Environment::query()->with(['project.organization.owner', 'website.server'])->find($this->environmentId);
        if (! $environment?->hibernate_after_minutes || $environment->hibernated_at || ! $environment->website?->server
            || ! $entitlements->allows($environment->project->organization, 'hibernation') || $environment->builds()->whereIn('status', Build::ACTIVE_STATUSES)->exists()) {
            return;
        }
        $log = escapeshellarg('/var/log/caddy/'.$environment->website->deployment_slug.'.access.log');
        $minutes = max(5, (int) $environment->hibernate_after_minutes);
        $result = $runner->server($environment->website->server)->create()->execute("find {$log} -mmin -{$minutes} -print 2>/dev/null || true");
        if (trim($result->getOutput()) !== '') {
            $environment->update(['last_activity_at' => now()]);

            return;
        }
        ApplyEnvironmentRuntimeStateJob::dispatch($environment->id, true);
    }
}
