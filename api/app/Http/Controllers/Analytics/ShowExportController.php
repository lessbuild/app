<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Models\AnalyticsExport;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowExportController
{
    /**
     * Show a CSV export's progress, and where to download it once it's ready. The token is the export's link; another
     * project's exports are a 404.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $token
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, string $token, ProjectOverviewQuery $overview): JsonResponse
    {
        $export = AnalyticsExport::query()->where('token_hash', hash('sha256', $token))->whereHas('site', fn ($query) => $query->where('project_id', $project->id))->with('site')->firstOrFail();

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'site' => $export->site->name,
            'status' => $export->status,
            'expiresAt' => $export->expires_at->toIso8601String(),
            'downloadUrl' => $export->status === 'completed' ? route('app.analytics.exports.download', [$project, $token], false) : null,
        ]);
    }
}
