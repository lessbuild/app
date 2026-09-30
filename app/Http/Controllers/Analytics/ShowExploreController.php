<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Analytics\Concerns\ReadsReportParameters;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\InsightsQuery;
use App\Queries\Analytics\ItemsQuery;
use App\Queries\Analytics\PathExplorationQuery;
use App\Queries\Analytics\ProjectSitesQuery;
use App\Queries\Analytics\PropertyBreakdownQuery;
use App\Queries\Analytics\RetentionQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowExploreController
{
    use ReadsReportParameters;

    /**
     * The Explore page's tabs.
     *
     * @var list<string>
     */
    public const TABS = ['insights', 'paths', 'properties', 'items', 'retention'];

    /**
     * Show a site's deeper reports, one tab at a time: automatic insights, path exploration, custom property
     * breakdowns, e-commerce items and retention cohorts. Only the open tab is worked out.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectSitesQuery  $sites
     * @param  InsightsQuery  $insights
     * @param  PathExplorationQuery  $paths
     * @param  PropertyBreakdownQuery  $properties
     * @param  ItemsQuery  $items
     * @param  RetentionQuery  $retention
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSitesQuery $sites, InsightsQuery $insights, PathExplorationQuery $paths, PropertyBreakdownQuery $properties, ItemsQuery $items, RetentionQuery $retention): View
    {
        $site = $sites->selected($project, $request->query('site'));
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'insights';
        $period = $site === null ? null : $this->reportPeriod($request, $site);
        $path = trim($request->string('path')->toString());
        $event = trim($request->string('event')->toString());
        $property = trim($request->string('property')->toString());

        return view('analytics.explore', [
            'overview' => $overview->handle($project, $user),
            'sites' => $sites->handle($project),
            'site' => $site,
            'tab' => $tab,
            'period' => $period,
            'path' => $path === '' ? null : mb_substr($path, 0, 2048),
            'event' => $event === '' ? null : mb_substr($event, 0, 80),
            'property' => $property === '' ? null : $property,
            'result' => $site === null || $period === null ? null : match ($tab) {
                'paths' => $paths->handle($site, $period, $path === '' ? null : mb_substr($path, 0, 2048)),
                'properties' => $properties->handle($site, $period, $event === '' ? null : $event, $property === '' ? null : $property),
                'items' => $items->handle($site, $period),
                'retention' => $retention->handle($site),
                default => $insights->handle($site, $period),
            },
        ]);
    }
}
