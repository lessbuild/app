<?php

namespace App\Http\Controllers\Callbacks;

use App\Http\Controllers\Controller;
use App\Models\Build;
use App\Services\AutomaticDeploymentRollback;
use App\Services\PreviewDeploymentLifecycle;
use App\Services\RepositoryDeploymentPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class BuildCallbackController extends Controller
{
    /**
     * Record monotonic lifecycle progress from a signed callback.
     *
     * @param  Request  $request  Signed callback input, validated before persistence.
     * @param  Build  $build  The route-bound lifecycle target.
     * @return Response Empty acknowledgement, including ignored stale callbacks.
     */
    public function status(Request $request, Build $build): Response
    {
        $plan = app(RepositoryDeploymentPlan::class);
        $finalStage = $plan->finalStage();
        $activationStage = $plan->activationStage();
        $data = $request->validate(['status' => "required|integer|min:0|max:{$finalStage}"]);
        $data['status'] = (int) $data['status'];
        $finished = false;
        DB::transaction(function () use ($build, $data, $finalStage, $activationStage, &$finished): void {
            $locked = Build::query()->lockForUpdate()->findOrFail($build->id);
            if (! in_array($locked->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
                return;
            }

            $repository = $locked->repository;
            if ($data['status'] > $repository->setup_stage) {
                $repository->update(['setup_stage' => $data['status']]);
            }

            $attributes = ['last_heartbeat_at' => now()];
            if ($data['status'] > $locked->setup_stage) {
                $attributes['setup_stage'] = $data['status'];
            }
            if ($data['status'] >= $activationStage && $locked->activated_at === null) {
                $attributes['activated_at'] = now();
            }
            if ($data['status'] === $finalStage) {
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
        });
        if ($finished) {
            app(PreviewDeploymentLifecycle::class)->buildFinished($build->fresh());
        }

        return response()->noContent();
    }

    /**
     * Record a failure for the current signed lifecycle attempt.
     *
     * @param  Request  $request  Signed callback input, validated before persistence.
     * @param  Build  $build  The route-bound lifecycle target.
     * @return Response Empty acknowledgement, including ignored stale callbacks.
     */
    public function failed(Request $request, Build $build): Response
    {
        $data = $request->validate([
            'exit_code' => 'nullable|integer',
            'message' => 'required|string|max:2000',
        ]);

        $finished = false;
        DB::transaction(function () use ($build, $data, &$finished): void {
            $locked = Build::query()->lockForUpdate()->findOrFail($build->id);
            if (! in_array($locked->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
                return;
            }

            $locked->update([
                'status' => Build::STATUS_FAILED,
                'remote_process_id' => null,
                'remote_process_path' => null,
                'finished_at' => now(),
                'failure_message' => isset($data['exit_code'])
                    ? "{$data['message']} (exit code {$data['exit_code']})"
                    : $data['message'],
            ]);
            $finished = true;
        });
        if ($finished) {
            app(PreviewDeploymentLifecycle::class)->buildFinished($build->fresh());
            app(AutomaticDeploymentRollback::class)->attempt($build->fresh());
        }

        return response()->noContent();
    }

    /**
     * Store bounded callback output without accepting stale attempts.
     *
     * @param  Request  $request  Signed callback input, validated before persistence.
     * @param  Build  $build  The route-bound lifecycle target.
     * @return Response Empty acknowledgement, including ignored stale callbacks.
     */
    public function log(Request $request, Build $build): Response
    {
        DB::transaction(function () use ($request, $build): void {
            $locked = Build::query()->lockForUpdate()->findOrFail($build->id);
            if (! in_array($locked->status, [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING], true)) {
                return;
            }

            $data = $request->validate([
                'log' => ['required', 'string', 'max:'.max(1, (int) config('lessbuild.deployment_log_max_characters'))],
            ]);

            $locked->logs()->updateOrCreate(
                ['type' => Build::DEPLOYMENT_LOG_TYPE],
                ['log' => $data['log']],
            );
            $locked->update(['last_heartbeat_at' => now()]);
        });

        return response()->noContent();
    }
}
