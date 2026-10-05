<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Data\Analytics\SiteRow;
use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\ProjectSitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowSitesController
{
    /**
     * List the project's Analytics sites, with the time zones a new site can report in.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectSitesQuery  $sites
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSitesQuery $sites): JsonResponse
    {
        $list = $sites->handle($project);
        // Each site's visitors on each of the last 30 days (summing the daily estimates), for the cards.
        $since = CarbonImmutable::today()->subDays(29)->toDateString();
        $visitors = AnalyticsDailyAggregate::query()->whereIn('site_id', array_map(fn (AnalyticsSite $site): int => $site->id, $list))
            ->where('dimension', 'all')->where('local_date', '>=', $since)
            ->selectRaw('site_id, sum(visitors) as total')->groupBy('site_id')->pluck('total', 'site_id');

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'sites' => array_map(fn (AnalyticsSite $site): array => [...(array) SiteRow::from($site), 'visitors' => (int) ($visitors[$site->id] ?? 0)], $list),
            'timezones' => DateTimeZone::listIdentifiers(),
            'canManage' => $user->can('manageService', [$project, 'analytics']),
        ]);
    }
}
