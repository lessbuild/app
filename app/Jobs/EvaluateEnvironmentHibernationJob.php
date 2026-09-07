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

    /**
     * Capture the environment whose configured idle threshold should be evaluated.
     *
     * @param  int  $environmentId  Persisted environment identifier reloaded by the queue worker.
     */
    public function __construct(public readonly int $environmentId) {}

    /**
     * Coalesce queued instances of this job for the same environment.
     *
     * @return string The environment identifier used by Laravel's unique-job lock.
     */
    public function uniqueId(): string
    {
        return (string) $this->environmentId;
    }

    /**
     * For entitled idle environments without active builds, inspect access-log activity and queue hibernation; refresh the activity timestamp when recent requests exist.
     *
     * @param  Runner  $runner  SSH runner used to execute commands on the selected managed server.
     * @param  Entitlements  $entitlements  Workspace entitlement evaluator for the requested automation capability.
     */
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
