<?php

namespace App\Http\Controllers\Callbacks;

use App\Actions\Repository\RecordBuildFailureAction;
use App\Actions\Repository\RecordBuildStatusAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\BuildFailureCallbackRequest;
use App\Http\Requests\BuildStatusCallbackRequest;
use App\Models\Build;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class BuildCallbackController extends Controller
{
    /**
     * Record monotonic lifecycle progress from a signed callback.
     *
     * @param  BuildStatusCallbackRequest  $request  Signed callback input, validated before persistence.
     * @param  Build  $build  The route-bound lifecycle target.
     * @return Response Empty acknowledgement, including ignored stale callbacks.
     */
    public function status(
        BuildStatusCallbackRequest $request,
        Build $build,
        RecordBuildStatusAction $record,
    ): Response {
        $record->handle($build, $request->status(), $request->finalStage());

        return response()->noContent();
    }

    /**
     * Record a failure for the current signed lifecycle attempt.
     *
     * @param  BuildFailureCallbackRequest  $request  Signed callback input, validated before persistence.
     * @param  Build  $build  The route-bound lifecycle target.
     * @return Response Empty acknowledgement, including ignored stale callbacks.
     */
    public function failed(
        BuildFailureCallbackRequest $request,
        Build $build,
        RecordBuildFailureAction $record,
    ): Response {
        $record->handle($build, $request->message(), $request->exitCode());

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
