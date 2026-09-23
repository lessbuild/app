<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Actions\Repository\RecordBuildRevisionAction;
use App\Modules\Deployer\Data\BuildRevisionResult;
use App\Modules\Deployer\Http\Requests\BuildRevisionCallbackRequest;
use App\Modules\Deployer\Models\Build;
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
