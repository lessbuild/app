<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Build;
use App\Models\Repository;
use App\Services\Deploy\DeploymentMarkers;
use App\Services\Deploy\Deployments;
use Illuminate\Support\Facades\DB;

final class FinishBuild
{
    public function __construct(private readonly DeploymentMarkers $markers, private readonly Deployments $deployments) {}

    /**
     * Mark an active build succeeded, failed or canceled. A live build becomes a Monitoring deployment marker; then a push
     * that arrived while it ran is deployed. Returns false when the build had already finished.
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
            $this->deployPendingPush($build->repository_id);
        }

        return $finished;
    }

    private function deployPendingPush(int $repositoryId): void
    {
        $repository = Repository::query()->find($repositoryId);
        if ($repository === null || ! $repository->webhook_pending || ! $repository->isDeploymentReady()) {
            return;
        }
        $build = $this->deployments->queue($repository, ['trigger_source' => 'webhook', 'revision' => $repository->webhook_pending_revision, 'commit_message' => $repository->webhook_pending_commit_message]);
        if ($build !== null) {
            $repository->forceFill(['webhook_pending' => false, 'webhook_pending_revision' => null, 'webhook_pending_commit_message' => null])->save();
        }
    }
}
