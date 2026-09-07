<?php

namespace App\Actions\Repository;

use App\Data\BuildRedeploymentResult;
use App\Jobs\Repository\RollbackReleaseJob;
use App\Models\Build;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

class RollbackBuildAction
{
    /**
     * Lock the website and repository, validate a retained successful release, and queue a rollback build with the requester recorded as approver.
     *
     * @param  Build  $source  Successful build retaining the release name and path to reactivate.
     * @param  User  $requester  User already authorized by the caller to request and approve the rollback.
     * @return BuildRedeploymentResult The ineligible, unavailable, active, or queued result, including the rollback build when accepted.
     */
    public function handle(Build $source, User $requester): BuildRedeploymentResult
    {
        $result = DB::transaction(function () use ($source, $requester): BuildRedeploymentResult {
            $websiteId = Repository::query()->whereKey($source->repository_id)->value('website_id');
            $website = Website::query()->lockForUpdate()->findOrFail($websiteId);
            $repository = Repository::query()->lockForUpdate()->findOrFail($source->repository_id);
            $lockedSource = $repository->builds()->findOrFail($source->id);

            if ($lockedSource->status !== Build::STATUS_SUCCEEDED
                || ! $lockedSource->release_name
                || ! $lockedSource->release_path) {
                return new BuildRedeploymentResult(BuildRedeploymentResult::INELIGIBLE);
            }
            if (! $repository->isDeploymentReady()) {
                return new BuildRedeploymentResult(BuildRedeploymentResult::UNAVAILABLE);
            }
            if ((int) $repository->website_id !== (int) $website->id || $website->hasActiveDeployment()) {
                return new BuildRedeploymentResult(BuildRedeploymentResult::ACTIVE);
            }

            $build = $repository->builds()->create([
                'status' => Build::STATUS_QUEUED,
                'trigger_source' => Build::TRIGGER_ROLLBACK,
                'revision' => $lockedSource->revision,
                'commit_message' => $lockedSource->commit_message,
                'environment_id' => $lockedSource->environment_id,
                'requested_by' => $requester->id,
                'approved_by' => $requester->id,
                'approved_at' => now(),
                'release_name' => $lockedSource->release_name,
                'release_path' => $lockedSource->release_path,
                'rolled_back_from_build_id' => $lockedSource->id,
            ]);

            return new BuildRedeploymentResult(BuildRedeploymentResult::QUEUED, $build);
        });

        if ($result->build) {
            RollbackReleaseJob::dispatch($result->build);
        }

        return $result;
    }
}
