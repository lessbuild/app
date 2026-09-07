<?php

namespace App\Services;

use App\Models\Build;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

class DeploymentLauncher
{
    /**
     * Bind build snapshot creation and environment deployment gates.
     *
     * @param  DeploymentRequest  $deployments  Captures build attributes and dispatches queued builds.
     * @param  DeploymentGate  $gate  Resolves environment-level deployment restrictions.
     */
    public function __construct(
        private readonly DeploymentRequest $deployments,
        private readonly DeploymentGate $gate,
    ) {}

    /**
     * Reserve a build under website/repository locks and dispatch it after the transaction.
     *
     * @param  Repository  $repository  Readiness and gates are checked before locking; website association and active deployments are rechecked under lock.
     * @param  User|null  $requester  The requesting account, or null for an unattended trigger.
     * @param  string  $source  The persisted trigger source, defaulting to manual.
     * @return Build|null The created build, or null if readiness, environment gates or active-deployment checks reject it.
     */
    public function launch(Repository $repository, ?User $requester, string $source = Build::TRIGGER_MANUAL): ?Build
    {
        if (! $repository->isDeploymentReady() || $this->gate->blockReason($repository)) {
            return null;
        }

        $build = DB::transaction(function () use ($repository, $requester, $source): ?Build {
            $website = Website::query()->lockForUpdate()->findOrFail($repository->website_id);
            $locked = Repository::query()->lockForUpdate()->findOrFail($repository->id);
            if ((int) $locked->website_id !== (int) $website->id || $website->hasActiveDeployment()) {
                return null;
            }
            $locked->update(['setup_stage' => 0]);

            return $locked->builds()->create([
                'trigger_source' => $source,
                ...$this->deployments->attributes($locked, $requester),
            ]);
        });

        if ($build) {
            $this->deployments->dispatch($build);
        }

        return $build;
    }
}
