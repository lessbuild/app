<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Jobs\Deploy\ApplyEnvironmentRuntime;
use App\Models\Build;
use App\Models\Repository;
use App\Services\Deploy\DeploymentMarkers;
use App\Services\Deploy\Deployments;
use App\Services\Deploy\Previews;
use Illuminate\Support\Facades\DB;

final class FinishBuild
{
    /**
     * Create a new FinishBuild instance.
     *
     * Ends a deploy and does what follows from how it ended.
     *
     * @param  DeploymentMarkers  $markers  Records the deploy on the environment's Monitoring timeline.
     * @param  Deployments  $deployments  Rolls back automatically when needed and queues pushes that arrived during the deploy.
     * @param  Previews  $previews  Moves a preview on when one of its deploys finishes.
     */
    public function __construct(private readonly DeploymentMarkers $markers, private readonly Deployments $deployments, private readonly Previews $previews) {}

    /**
     * Mark an active build succeeded, failed or canceled. A live build becomes a Monitoring deployment marker; then a push
     * that arrived while it ran is deployed, a hibernated environment wakes, and a preview's deploy moves the preview on. Returns false when the build had
     * already finished.
     *
     * @param  Build  $build
     * @param  string  $status
     * @param  string|null  $message
     * @param  string|null  $log
     * @return bool
     */
    public function handle(Build $build, string $status, ?string $message = null, ?string $log = null): bool
    {
        $finished = DB::transaction(function () use ($build, $status, $message, $log): bool {
            $locked = Build::query()->lockForUpdate()->find($build->id);
            if ($locked === null || ! $locked->isActive()) {
                return false;
            }
            $locked->forceFill([
                'status' => $status, 'finished_at' => now(), 'remote_process_id' => null, 'remote_process_path' => null,
                'failure_message' => $status === Build::STATUS_FAILED ? $message : null,
                'activated_at' => $status === Build::STATUS_SUCCEEDED ? ($locked->activated_at ?? now()) : $locked->activated_at,
                ...($log === null ? [] : ['log' => $log]),
            ])->save();
            if ($status === Build::STATUS_SUCCEEDED) {
                $this->markers->record($locked);
            }

            return true;
        });
        if ($finished) {
            $finishedBuild = Build::query()->with('environment')->findOrFail($build->id);
            if ($status === Build::STATUS_SUCCEEDED && $finishedBuild->environment?->post_deployment_observation_minutes !== null && $finishedBuild->trigger_source !== 'rollback') {
                $minutes = $finishedBuild->environment->post_deployment_observation_minutes;
                $finishedBuild->forceFill(['observation_minutes' => $minutes, 'observation_status' => 'observing', 'observation_deadline_at' => now()->addMinutes($minutes)])->save();
            }
            if ($status === Build::STATUS_SUCCEEDED && $finishedBuild->environment !== null) {
                // A deploy is activity; one to a hibernated environment wakes it, since maintenance mode would outlive it.
                $finishedBuild->environment->forceFill(['last_activity_at' => now()])->save();
                if ($finishedBuild->environment->hibernated_at !== null) {
                    ApplyEnvironmentRuntime::dispatch($finishedBuild->environment->id, false);
                }
            }
            if ($status === Build::STATUS_FAILED && $finishedBuild->activated_at !== null) {
                $this->deployments->rollBackAutomatically($finishedBuild);
            }
            $this->deployPendingPush($build->repository_id);
            $this->previews->buildFinished($finishedBuild);
        }

        return $finished;
    }

    /**
     * Deploy the push that arrived while this deploy was running, if the repository can deploy now, and clears it so
     * it isn't deployed twice.
     *
     * @param  int  $repositoryId
     * @return void
     */
    private function deployPendingPush(int $repositoryId): void
    {
        $repository = Repository::query()->find($repositoryId);
        if ($repository === null || ! $repository->webhook_pending || ! $repository->isDeploymentReady() || $this->deployments->blockReason($repository) !== null) {
            return;
        }
        $build = $this->deployments->queue($repository, ['trigger_source' => 'webhook', 'revision' => $repository->webhook_pending_revision, 'commit_message' => $repository->webhook_pending_commit_message]);
        if ($build !== null) {
            $repository->forceFill(['webhook_pending' => false, 'webhook_pending_revision' => null, 'webhook_pending_commit_message' => null])->save();
        }
    }
}
