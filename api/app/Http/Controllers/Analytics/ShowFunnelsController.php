<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Data\Analytics\SiteRow;
use App\Models\AnalyticsFunnel;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\FunnelReportQuery;
use App\Queries\Analytics\ProjectSitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowFunnelsController
{
    /**
     * List a site's funnels (the one chosen with ?site=, else the first), each with how many visitors reached each
     * step in the last 30 days.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectSitesQuery  $sites
     * @param  FunnelReportQuery  $reports
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSitesQuery $sites, FunnelReportQuery $reports): JsonResponse
    {
        $site = $sites->selected($project, $request->query('site'));
        $funnels = $site === null ? collect() : AnalyticsFunnel::query()->where('site_id', $site->id)->orderBy('name')->get();

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'sites' => array_map(SiteRow::from(...), $sites->handle($project)),
            'site' => $site === null ? null : SiteRow::from($site),
            'funnels' => $funnels->map(fn (AnalyticsFunnel $funnel): array => [
                'id' => $funnel->id,
                'name' => $funnel->name,
                'steps' => $funnel->steps,
                'report' => array_map(fn (array $step): array => [
                    'label' => $step['label'],
                    'visitors' => $step['visitors'],
                    'ofStart' => $step['of_start'],
                    'ofPrevious' => $step['of_previous'],
                ], $reports->handle($funnel)),
            ])->values(),
            'canManage' => $site !== null && $user->can('update', $site),
        ]);
    }
}
