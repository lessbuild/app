<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Data\Analytics\SiteRow;
use App\Models\AnalyticsGoal;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\ProjectSitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowGoalsController
{
    /**
     * List a site's goals (the one chosen with ?site=, else the first), newest first.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectSitesQuery  $sites
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSitesQuery $sites): JsonResponse
    {
        $site = $sites->selected($project, $request->query('site'));

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'sites' => array_map(SiteRow::from(...), $sites->handle($project)),
            'site' => $site === null ? null : SiteRow::from($site),
            'goals' => $site === null ? [] : $site->goals()->latest()->get()->map(fn (AnalyticsGoal $goal): array => [
                'id' => $goal->id,
                'name' => $goal->name,
                'kind' => $goal->kind,
                'matchType' => $goal->match_type,
                'matchValue' => $goal->match_value,
                'active' => $goal->active,
            ])->values(),
            'canManage' => $user->can('manageService', [$project, 'analytics']),
        ]);
    }
}
