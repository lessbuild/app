<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Data\Analytics\SiteRow;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsGoal;
use App\Models\AnalyticsGoalConversion;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\ProjectSitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Carbon\CarbonImmutable;
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
        // Each goal's conversions over the last 30 days.
        $conversions = $site === null ? collect() : AnalyticsGoalConversion::query()->whereIn('goal_id', $site->goals()->select('id'))
            ->whereIn('analytics_event_id', AnalyticsEvent::query()->where('site_id', $site->id)->where('occurred_at', '>=', CarbonImmutable::now('UTC')->subDays(30))->select('id'))
            ->toBase()->selectRaw('goal_id, COUNT(*) AS total')->groupBy('goal_id')->pluck('total', 'goal_id');

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
                'conversions' => (int) ($conversions[$goal->id] ?? 0),
            ])->values(),
            'canManage' => $user->can('manageService', [$project, 'analytics']),
        ]);
    }
}
