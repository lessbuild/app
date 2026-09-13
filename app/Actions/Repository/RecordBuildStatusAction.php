<?php

namespace App\Actions\Repository;

use App\Models\Build;
use App\Services\PreviewDeploymentLifecycle;
use App\Services\PreviewInitializationLifecycle;
use App\Services\PreviewStackReadiness;
use App\Services\RepositoryDeploymentPlan;
use Illuminate\Support\Facades\DB;

class RecordBuildStatusAction
{
    public function __construct(
        private readonly RepositoryDeploymentPlan $plan,
        private readonly PreviewDeploymentLifecycle $previews,
        private readonly PreviewStackReadiness $previewStack,
        private readonly PreviewInitializationLifecycle $previewInitialization,
    ) {}

    /**
     * Record monotonic deployment progress and reconcile a preview after completion.
     *
     * The final stage is supplied by the validated callback request so the request and
     * operation use the same plan snapshot without changing the existing plan lookup.
     *
     * @param  Build  $build  Deployment receiving the remote status callback.
     * @param  int  $status  Validated deployment stage.
     * @param  int  $finalStage  The plan boundary used by request validation.
     * @return void No value; stale or terminal callbacks are acknowledged as no-ops.
     */
    public function handle(Build $build, int $status, int $finalStage): void
    {
        $activationStage = $this->plan->activationStage();
        $finished = false;

        DB::transaction(function () use ($build, $status, $finalStage, $activationStage, &$finished): void {
            $locked = Build::query()->lockForUpdate()->findOrFail($build->id);
            if (! in_array($locked->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
                return;
            }

            $repository = $locked->repository;
            if ($status > $repository->setup_stage) {
                $repository->update(['setup_stage' => $status]);
            }

            $attributes = ['last_heartbeat_at' => now()];
            if ($status > $locked->setup_stage) {
                $attributes['setup_stage'] = $status;
            }
            if ($status >= $activationStage && $locked->activated_at === null) {
                $attributes['activated_at'] = now();
            }
            if ($status === $finalStage) {
                $finished = true;
                $attributes = array_merge($attributes, [
                    'status' => Build::STATUS_SUCCEEDED,
                    'remote_process_id' => null,
                    'remote_process_path' => null,
                    'built_at' => now(),
                    'finished_at' => now(),
                ]);
            }
            $locked->update($attributes);
            $this->previewStack->recordProgress($locked, $status);
            $this->previewInitialization->recordProgress($locked, $status);
        });

        if ($finished) {
            $this->previews->buildFinished($build->fresh());
        }
    }
}
