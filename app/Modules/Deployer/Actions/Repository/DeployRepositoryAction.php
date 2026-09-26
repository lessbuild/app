<?php

namespace App\Modules\Deployer\Actions\Repository;

use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Services\DeploymentRequest;
use App\Modules\Deployer\Services\Entitlements;
use Illuminate\Support\Facades\DB;

class DeployRepositoryAction
{
    public function __construct(
        private readonly DeploymentRequest $deployments,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * Create and dispatch one manual deployment while serializing website capacity and repository state.
     *
     * @param  Repository  $repository  Repository whose website and build queue are being updated.
     * @param  User  $requester  User attributed to the deployment request.
     * @return Build|null The created build, or null when another deployment owns the website.
     */
    public function handle(Repository $repository, User $requester): ?Build
    {
        $build = DB::connection('deployer')->transaction(function () use ($repository, $requester): ?Build {
            $website = Website::query()->lockForUpdate()->findOrFail($repository->website_id);
            $lockedRepository = Repository::query()->lockForUpdate()->findOrFail($repository->id);
            if ((int) $lockedRepository->website_id !== (int) $website->id) {
                return null;
            }

            $this->entitlements->enforce($lockedRepository->organization ?: $requester, 'deployments');

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
