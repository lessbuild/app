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

    /**
     * Capture the sleeping environment to inspect for requests received after hibernation.
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
     * For an entitled hibernated environment with a server, inspect the access-log modification time and queue a wake operation only when newer requests exist.
     *
     * @param  Runner  $runner  SSH runner used to execute commands on the selected managed server.
     * @param  Entitlements  $entitlements  Workspace entitlement evaluator for the requested automation capability.
     */
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
