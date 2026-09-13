<?php

namespace App\Actions\Repository;

use App\Models\Build;
use App\Services\AutomaticDeploymentRollback;
use App\Services\PreviewDeploymentLifecycle;
use App\Services\PreviewStackReadiness;
use Illuminate\Support\Facades\DB;

class RecordBuildFailureAction
{
    public function __construct(
        private readonly PreviewDeploymentLifecycle $previews,
        private readonly AutomaticDeploymentRollback $rollback,
        private readonly PreviewStackReadiness $previewStack,
    ) {}

    /**
     * Record a current deployment failure and evaluate automatic release recovery.
     *
     * @param  Build  $build  Deployment receiving the remote failure callback.
     * @param  string  $message  Validated remote failure message.
     * @param  int|null  $exitCode  Optional validated remote process exit code.
     * @return void No value; stale or terminal callbacks are acknowledged as no-ops.
     */
    public function handle(Build $build, string $message, ?int $exitCode): void
    {
        $finished = false;

        DB::transaction(function () use ($build, $message, $exitCode, &$finished): void {
            $locked = Build::query()->lockForUpdate()->findOrFail($build->id);
            if (! in_array($locked->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
                return;
            }

            $locked->update([
                'status' => Build::STATUS_FAILED,
                'remote_process_id' => null,
                'remote_process_path' => null,
                'finished_at' => now(),
                'failure_message' => $exitCode === null
                    ? $message
                    : "{$message} (exit code {$exitCode})",
            ]);
            $this->previewStack->recordFailure($locked);
            $finished = true;
        });

        if ($finished) {
            $current = $build->fresh();
            $this->previews->buildFinished($current);
            $this->rollback->attempt($current);
        }
    }
}
