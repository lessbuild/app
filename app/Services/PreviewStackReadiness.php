<?php

namespace App\Services;

use App\Models\Build;
use App\Models\Environment;
use App\Models\EnvironmentResource;

class PreviewStackReadiness
{
    /**
     * Bind preview resource transitions to the existing deployment plan stage.
     *
     * @param  RepositoryDeploymentPlan  $plan  Supplies the stage at which the remote resource script reports success.
     */
    public function __construct(
        private readonly RepositoryDeploymentPlan $plan,
    ) {}

    /**
     * Mark newly declared preview resources as remotely initializing before a build is dispatched.
     *
     * @param  Build  $build  The deployment whose environment owns the preview stack.
     * @return void No value; non-preview deployments and already-ready resources are unchanged.
     */
    public function beginProvisioning(Build $build): void
    {
        if (! $this->isPreviewBuild($build)) {
            return;
        }

        EnvironmentResource::query()
            ->where('environment_id', $build->environment_id)
            ->whereIn('status', [EnvironmentResource::STATUS_PLANNED, EnvironmentResource::STATUS_FAILED])
            ->update(['status' => EnvironmentResource::STATUS_PROVISIONING]);
    }

    /**
     * Mark preview resources ready after the signed deployment reaches their configuration stage.
     *
     * @param  Build  $build  The deployment receiving the current callback.
     * @param  int  $status  The validated monotonic deployment stage.
     * @return void No value; callbacks before the resource stage retain the initializing state.
     */
    public function recordProgress(Build $build, int $status): void
    {
        if (! $this->isPreviewBuild($build) || $status < $this->plan->resourceStage()) {
            return;
        }

        EnvironmentResource::query()
            ->where('environment_id', $build->environment_id)
            ->whereIn('status', [EnvironmentResource::STATUS_PLANNED, EnvironmentResource::STATUS_PROVISIONING])
            ->update(['status' => EnvironmentResource::STATUS_READY]);
    }

    /**
     * Mark resources that were being initialized by a failed preview build as failed.
     *
     * The remote script is idempotent, so a later preview revision may retry the same
     * declarations. Existing ready resources are not downgraded when only application
     * deployment fails.
     *
     * @param  Build  $build  The failed deployment whose preview resources may be partial.
     * @return void No value; non-preview builds and ready resources are unchanged.
     */
    public function recordFailure(Build $build): void
    {
        if (! $this->isPreviewBuild($build)) {
            return;
        }

        EnvironmentResource::query()
            ->where('environment_id', $build->environment_id)
            ->where('status', EnvironmentResource::STATUS_PROVISIONING)
            ->update(['status' => EnvironmentResource::STATUS_FAILED]);
    }

    /**
     * Determine whether a persisted build targets a preview environment owned by an application.
     *
     * @param  Build  $build  The deployment being classified.
     * @return bool True only for a build with a preview environment.
     */
    private function isPreviewBuild(Build $build): bool
    {
        if (! $build->environment_id) {
            return false;
        }

        return Environment::query()
            ->whereKey($build->environment_id)
            ->where('type', 'preview')
            ->exists();
    }
}
