<?php

namespace App\Actions\Repository;

use App\Models\Build;
use App\Services\PreviewDeploymentLifecycle;
use App\Services\Runner;
use Illuminate\Support\Facades\DB;

class CancelRunningDeploymentAction
{
    public function __construct(
        private readonly Runner $runner,
        private readonly PreviewDeploymentLifecycle $previews,
    ) {}

    /**
     * Stop the recorded remote process, then atomically finalize only the matching running attempt.
     *
     * @param  Build  $build  Running build whose saved remote process identity is being canceled.
     * @return bool Whether the build was still running with the same process identity after remote cancellation.
     *
     * @throws \RuntimeException If remote cancellation fails.
     */
    public function handle(Build $build): bool
    {
        $processId = $build->remote_process_id;
        $processPath = $build->remote_process_path;
        $partialLog = (new CancelDeploymentAction($build, $this->runner))->handle();

        $canceled = DB::transaction(function () use ($build, $processId, $processPath, $partialLog): bool {
            $locked = Build::query()
                ->whereKey($build->id)
                ->where('status', Build::STATUS_RUNNING)
                ->where('remote_process_id', $processId)
                ->where('remote_process_path', $processPath)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            if ($partialLog !== null) {
                $locked->logs()->updateOrCreate(
                    ['type' => Build::DEPLOYMENT_LOG_TYPE],
                    ['log' => $partialLog],
                );
            }

            $locked->update([
                'status' => Build::STATUS_CANCELED,
                'remote_process_id' => null,
                'remote_process_path' => null,
                'finished_at' => now(),
                'failure_message' => null,
            ]);

            return true;
        });

        if ($canceled) {
            $this->previews->deploymentStopped($build->fresh());
        }

        return $canceled;
    }
}
