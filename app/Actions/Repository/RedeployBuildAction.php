<?php

namespace App\Actions\Repository;

use App\Data\BuildRedeploymentResult;
use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Repository;
use Illuminate\Support\Facades\DB;

class RedeployBuildAction
{
    public function handle(Build $source): BuildRedeploymentResult
    {
        $result = DB::transaction(function () use ($source): BuildRedeploymentResult {
            $repository = Repository::query()->lockForUpdate()->findOrFail($source->repository_id);
            $lockedSource = $repository->builds()->findOrFail($source->id);

            if (! in_array($lockedSource->status, Build::TERMINAL_STATUSES, true)) {
                return new BuildRedeploymentResult(BuildRedeploymentResult::INELIGIBLE);
            }

            if (! $repository->isDeploymentReady()) {
                return new BuildRedeploymentResult(BuildRedeploymentResult::UNAVAILABLE);
            }

            $active = $repository->builds()->whereIn('status', Build::ACTIVE_STATUSES)->exists();
            if ($active) {
                return new BuildRedeploymentResult(BuildRedeploymentResult::ACTIVE);
            }

            $repository->update(['setup_stage' => 0]);
            $build = $repository->builds()->create([
                'status' => Build::STATUS_QUEUED,
                'trigger_source' => Build::TRIGGER_REDEPLOY,
                'revision' => $lockedSource->revision,
                'commit_message' => $lockedSource->commit_message,
                'redeployed_from_build_id' => $lockedSource->id,
            ]);

            return new BuildRedeploymentResult(BuildRedeploymentResult::QUEUED, $build);
        });

        if ($result->build) {
            PublishRepositoryJob::dispatch($result->build);
        }

        return $result;
    }
}
