<?php

namespace App\Jobs\Repository;

use App\Actions\Repository\SwitchReleaseAction;
use App\Models\Build;
use App\Services\RepositoryDeploymentPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RollbackReleaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Capture the queued rollback build carrying its retained release metadata.
     *
     * @param  Build  $build  Build record whose persisted deployment state and relationships are used by this operation.
     */
    public function __construct(public Build $build) {}

    /**
     * Claim the rollback, activate its retained release, and persist output and successful completion only while the build still owns the deploying state.
     *
     * @param  SwitchReleaseAction  $releases  Action that validates and activates a retained release on the managed server.
     */
    public function handle(SwitchReleaseAction $releases): void
    {
        $started = Build::query()
            ->whereKey($this->build->id)
            ->where('status', Build::STATUS_QUEUED)
            ->update(['status' => Build::STATUS_DEPLOYING, 'started_at' => now()]);
        if ($started === 0) {
            return;
        }

        $this->build->refresh();
        $output = $releases->handle($this->build);

        DB::transaction(function () use ($output): void {
            $locked = Build::query()->lockForUpdate()->findOrFail($this->build->id);
            if ($locked->status !== Build::STATUS_DEPLOYING) {
                return;
            }
            if ($output !== '') {
                $locked->logs()->updateOrCreate(
                    ['type' => Build::DEPLOYMENT_LOG_TYPE],
                    ['log' => $output],
                );
            }
            $locked->update([
                'status' => Build::STATUS_SUCCEEDED,
                'setup_stage' => app(RepositoryDeploymentPlan::class)->finalStage(),
                'activated_at' => now(),
                'built_at' => now(),
                'finished_at' => now(),
            ]);
        });
    }

    /**
     * Mark the rollback failed only while its build remains queued or deploying, preserving later terminal transitions.
     *
     * @param  \Throwable  $exception  Failure delivered by the queue after this job cannot complete successfully.
     */
    public function failed(\Throwable $exception): void
    {
        Build::query()
            ->whereKey($this->build->id)
            ->whereIn('status', [Build::STATUS_QUEUED, Build::STATUS_DEPLOYING])
            ->update([
                'status' => Build::STATUS_FAILED,
                'finished_at' => now(),
                'failure_message' => str($exception->getMessage())->limit(2000),
            ]);
    }
}
