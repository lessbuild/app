<?php

namespace App\Services;

use App\Actions\Repository\RollbackBuildAction;
use App\Data\BuildRedeploymentResult;
use App\Models\Build;

class AutomaticDeploymentRollback
{
    public function __construct(private readonly RollbackBuildAction $rollback) {}

    public function attempt(Build $failed): ?Build
    {
        $failed->loadMissing(['environment', 'repository.user']);
        if ($failed->trigger_source === Build::TRIGGER_ROLLBACK
            || ! $failed->activated_at
            || ! $failed->environment?->automatic_rollback) {
            return null;
        }

        $source = $failed->repository->builds()
            ->where('status', Build::STATUS_SUCCEEDED)
            ->whereNotNull('release_name')
            ->whereNotNull('release_path')
            ->whereKeyNot($failed->id)
            ->latest('finished_at')
            ->first();
        $requester = $failed->requester ?? $failed->repository->user;
        if (! $source || ! $requester) {
            return null;
        }

        $result = $this->rollback->handle($source, $requester);
        if ($result->status !== BuildRedeploymentResult::QUEUED || ! $result->build) {
            return null;
        }

        $failed->update(['automatic_rollback_build_id' => $result->build->id]);

        return $result->build;
    }
}
