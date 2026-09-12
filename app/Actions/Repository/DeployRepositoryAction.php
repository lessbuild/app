<?php

namespace App\Actions\Repository;

use App\Models\Build;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use App\Services\DeploymentRequest;
use Illuminate\Support\Facades\DB;

class DeployRepositoryAction
{
    public function __construct(private readonly DeploymentRequest $deployments) {}

    /**
     * Create and dispatch one manual deployment while serializing website capacity and repository state.
     *
     * @param  Repository  $repository  Repository whose website and build queue are being updated.
     * @param  User  $requester  User attributed to the deployment request.
     * @return Build|null The created build, or null when another deployment owns the website.
     */
    public function handle(Repository $repository, User $requester): ?Build
    {
        $build = DB::transaction(function () use ($repository, $requester): ?Build {
            $website = Website::query()->lockForUpdate()->findOrFail($repository->website_id);
            $lockedRepository = Repository::query()->lockForUpdate()->findOrFail($repository->id);
            if ((int) $lockedRepository->website_id !== (int) $website->id) {
                return null;
            }

            if ($website->hasActiveDeployment()) {
                return null;
            }

            $lockedRepository->update(['setup_stage' => 0]);

            return $lockedRepository->builds()->create([
                'trigger_source' => Build::TRIGGER_MANUAL,
                ...$this->deployments->attributes($lockedRepository, $requester),
            ]);
        });

        if ($build) {
            $this->deployments->dispatch($build);
        }

        return $build;
    }
}
