<?php

declare(strict_types=1);

namespace App\Jobs\Deploy;

use App\Actions\Deploy\FinishBuild;
use App\Models\Build;
use App\Services\Deploy\RemoteDeployments;
use App\Services\Deploy\RepositoryDeploymentPlan;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/** Carries out a rollback build: switch `current` back to the retained release. */
final class SwitchRelease implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * One attempt: switching the `current` symlink either happened or failed, and the deploy is finished either way.
     */
    public int $tries = 1;

    /**
     * Makes an already-built release live again, for rollbacks and redeploys of a build whose release is still on the
     * server.
     *
     * @param  int  $buildId  The queued build to activate.
     */
    public function __construct(public readonly int $buildId) {}

    /**
     * Claims the build, points the website at its release and finishes the deploy as succeeded, or as failed with the
     * server's error.
     */
    public function handle(RemoteDeployments $remote, FinishBuild $finish, RepositoryDeploymentPlan $plan): void
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        if (Build::query()->whereKey($this->buildId)->where('status', Build::STATUS_QUEUED)->update(['status' => Build::STATUS_DEPLOYING, 'started_at' => $now, 'last_heartbeat_at' => $now]) === 0) {
            return;
        }
        $build = Build::query()->with('website.server')->findOrFail($this->buildId);
        try {
            $output = $remote->activate($build);
        } catch (RuntimeException $exception) {
            $finish->handle($build, Build::STATUS_FAILED, $exception->getMessage());

            return;
        }
        $build->forceFill(['setup_stage' => $plan->finalStage(), 'activated_at' => now()])->save();
        $finish->handle($build, Build::STATUS_SUCCEEDED, null, $output);
    }
}
