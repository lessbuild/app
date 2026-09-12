<?php

namespace App\Actions\Repository;

use App\Models\Build;
use App\Services\BuildDeploymentLogCallbackValidator;
use Illuminate\Support\Facades\DB;

class RecordBuildLogAction
{
    public function __construct(private readonly BuildDeploymentLogCallbackValidator $validator) {}

    /**
     * Record bounded deployment output only while the build remains active.
     *
     * Validation deliberately occurs after the row lock and terminal-state check
     * so stale callbacks retain their existing acknowledgement behavior.
     *
     * @param  Build  $build  Deployment receiving the remote log callback.
     * @param  mixed  $log  Raw log input validated only after the active-build check.
     * @return void No value; stale or terminal callbacks are acknowledged as no-ops.
     */
    public function handle(Build $build, mixed $log): void
    {
        DB::transaction(function () use ($build, $log): void {
            $locked = Build::query()->lockForUpdate()->findOrFail($build->id);
            if (! in_array($locked->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
                return;
            }

            $validatedLog = $this->validator->validate($log);
            $locked->logs()->updateOrCreate(
                ['type' => Build::DEPLOYMENT_LOG_TYPE],
                ['log' => $validatedLog],
            );
            $locked->update(['last_heartbeat_at' => now()]);
        });
    }
}
