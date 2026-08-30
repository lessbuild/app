<?php

namespace App\Http\Controllers;

use App\Actions\Repository\RecordBuildRevisionAction;
use App\Data\BuildRevisionResult;
use App\Models\Build;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BuildRevisionCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        Build $build,
        RecordBuildRevisionAction $record,
    ): Response|JsonResponse {
        $data = $request->validate([
            'revision' => ['required', 'string', 'regex:/\A[0-9a-f]{40,64}\z/i'],
            'commit_message' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $record->handle(
            $build,
            $data['revision'],
            $data['commit_message'] ?? null,
        );

        if ($result === BuildRevisionResult::MISMATCH) {
            return response()->json(['status' => 'revision_mismatch'], 409);
        }

        return response()->noContent();
    }
}
