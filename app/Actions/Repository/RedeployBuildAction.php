<?php

namespace App\Actions\Repository;

use App\Data\BuildRedeploymentResult;
use App\Models\Build;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use App\Services\DeploymentRequest;
use Illuminate\Support\Facades\DB;

class RedeployBuildAction
{
    public function __construct(private readonly DeploymentRequest $deployments) {}

    public function handle(Build $source, ?User $requester = null): BuildRedeploymentResult
    {
        $result = DB::transaction(function () use ($source, $requester): BuildRedeploymentResult {
            $websiteId = Repository::query()->whereKey($source->repository_id)->value('website_id');
            $website = Website::query()->lockForUpdate()->findOrFail($websiteId);
            $repository = Repository::query()->lockForUpdate()->findOrFail($source->repository_id);
            $lockedSource = $repository->builds()->findOrFail($source->id);

            if ($lockedSource->statusEnum()?->isTerminal() !== true) {
                return new BuildRedeploymentResult(BuildRedeploymentResult::INELIGIBLE);
            }

            if (! $repository->isDeploymentReady()) {
                return new BuildRedeploymentResult(BuildRedeploymentResult::UNAVAILABLE);
            }

            if ((int) $repository->website_id !== (int) $website->id || $website->hasActiveDeployment()) {
                return new BuildRedeploymentResult(BuildRedeploymentResult::ACTIVE);
            }

            $repository->update(['setup_stage' => 0]);
            $build = $repository->builds()->create([
                'trigger_source' => Build::TRIGGER_REDEPLOY,
                'revision' => $lockedSource->revision,
                'commit_message' => $lockedSource->commit_message,
                'redeployed_from_build_id' => $lockedSource->id,
                ...$this->deployments->attributes($repository, $requester),
            ]);

            return new BuildRedeploymentResult(BuildRedeploymentResult::QUEUED, $build);
        });

        if ($result->build) {
            $this->deployments->dispatch($result->build);
        }

        return $result;
    }
}
