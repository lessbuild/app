<?php

namespace App\Http\Controllers;

use App\Actions\Repository\RecordBuildRevisionAction;
use App\Data\BuildRevisionResult;
use App\Http\Requests\BuildRevisionCallbackRequest;
use App\Models\Build;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class BuildRevisionCallbackController extends Controller
{
    /**
     * Validate the callback's commit SHA and optional message against the build's immutable revision.
     *
     * @return Response|JsonResponse An empty acknowledgement, or HTTP 409 when the revision conflicts.
     */
    public function __invoke(
        BuildRevisionCallbackRequest $request,
        Build $build,
        RecordBuildRevisionAction $record,
    ): Response|JsonResponse {
        $result = $record->handle(
            $build,
            $request->revision(),
            $request->commitMessage(),
        );

        if ($result === BuildRevisionResult::MISMATCH) {
            return response()->json(['status' => 'revision_mismatch'], 409);
        }

        return response()->noContent();
    }
}
