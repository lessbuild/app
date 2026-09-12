<?php

namespace App\Http\Controllers\Callbacks;

use App\Actions\Repository\RecordBuildFailureAction;
use App\Actions\Repository\RecordBuildLogAction;
use App\Actions\Repository\RecordBuildStatusAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\BuildFailureCallbackRequest;
use App\Http\Requests\BuildStatusCallbackRequest;
use App\Models\Build;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
    public function log(
        Request $request,
        Build $build,
        RecordBuildLogAction $record,
    ): Response {
        $record->handle($build, $request->input('log'));

        return response()->noContent();
    }
}
