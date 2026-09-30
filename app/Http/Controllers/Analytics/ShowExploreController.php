<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Analytics\Concerns\ReadsReportParameters;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\AttributionQuery;
use App\Queries\Analytics\ClickMapQuery;
use App\Queries\Analytics\FormsQuery;
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
    public const TABS = ['insights', 'paths', 'properties', 'items', 'attribution', 'clicks', 'forms', 'retention'];

    /**
     * Show a site's deeper reports, one tab at a time: automatic insights, path exploration, custom property
     * breakdowns, e-commerce items, attribution, click maps, form analytics and retention cohorts. Only the open tab is
     * worked out.
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
     * @param  AttributionQuery  $attribution
     * @param  ClickMapQuery  $clicks
     * @param  FormsQuery  $forms
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSitesQuery $sites, InsightsQuery $insights, PathExplorationQuery $paths, PropertyBreakdownQuery $properties, ItemsQuery $items, RetentionQuery $retention, AttributionQuery $attribution, ClickMapQuery $clicks, FormsQuery $forms): View
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
            'by' => $request->query('by') === 'campaign' ? 'campaign' : 'channel',
            'result' => $site === null || $period === null ? null : match ($tab) {
                'paths' => $paths->handle($site, $period, $path === '' ? null : mb_substr($path, 0, 2048)),
                'properties' => $properties->handle($site, $period, $event === '' ? null : $event, $property === '' ? null : $property),
                'items' => $items->handle($site, $period),
                'retention' => $retention->handle($site),
                'attribution' => $attribution->handle($site, $period, $request->query('by') === 'campaign' ? 'campaign' : 'channel'),
                'clicks' => $clicks->handle($site, $period, $path === '' ? null : mb_substr($path, 0, 2048)),
                'forms' => $forms->handle($site, $period),
                default => $insights->handle($site, $period),
            },
        ]);
    }
}
