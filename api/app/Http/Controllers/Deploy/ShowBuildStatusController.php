<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Build;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

final class ShowBuildStatusController
{
    /**
     * Report a deploy's progress as JSON (status, stage and the end of its log), so its page can follow it live.
     *
     * @param  Project  $project
     * @param  Build  $build
     * @return JsonResponse
     */
    public function __invoke(Project $project, Build $build): JsonResponse
    {
        return response()->json([
            'status' => $build->status,
            'badge' => view('deploy._build-status', ['status' => $build->status])->render(),
            'stage' => $build->setup_stage,
            'log' => $build->log !== null ? mb_substr($build->log, -65536) : null,
            'finished' => ! $build->isActive(),
        ])->header('Cache-Control', 'no-store');
    }
}
