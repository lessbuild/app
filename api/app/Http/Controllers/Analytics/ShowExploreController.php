<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Data\Analytics\SiteRow;
use App\Http\Controllers\Analytics\Concerns\ReadsReportParameters;
use App\Models\AnalyticsExperiment;
use App\Models\AnalyticsGoal;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\AttributionQuery;
use App\Queries\Analytics\ClickMapQuery;
use App\Queries\Analytics\ExperimentResultsQuery;
use App\Queries\Analytics\FormsQuery;
use App\Queries\Analytics\InsightsQuery;
use App\Queries\Analytics\ItemsQuery;
use App\Queries\Analytics\PathExplorationQuery;
use App\Queries\Analytics\ProjectSitesQuery;
use App\Queries\Analytics\PropertyBreakdownQuery;
use App\Queries\Analytics\RetentionQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Support\Analytics\ReportPayload;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowExploreController
{
    use ReadsReportParameters;

    /**
     * The tabs, in the order the page shows them.
     *
     * @var list<string>
     */
    public const TABS = ['insights', 'paths', 'properties', 'items', 'attribution', 'clicks', 'forms', 'experiments', 'retention'];

    /**
     * Show a site's deeper reports, one tab at a time: automatic insights, path exploration, custom property
     * breakdowns, items sold, attribution, click maps, forms, experiments and retention.
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
     * @param  ExperimentResultsQuery  $experiments
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSitesQuery $sites, InsightsQuery $insights, PathExplorationQuery $paths, PropertyBreakdownQuery $properties, ItemsQuery $items, RetentionQuery $retention, AttributionQuery $attribution, ClickMapQuery $clicks, FormsQuery $forms, ExperimentResultsQuery $experiments): JsonResponse
    {
        $site = $sites->selected($project, $request->query('site'));
        $period = $site === null ? null : $this->reportPeriod($request, $site);
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'insights';
        $path = $this->text($request, 'path', 2048);
        $event = $this->text($request, 'event', 80);
        $property = $this->text($request, 'property', 255);
        $by = $request->query('by') === 'campaign' ? 'campaign' : 'channel';

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'sites' => array_map(SiteRow::from(...), $sites->handle($project)),
            'site' => $site === null ? null : SiteRow::from($site),
            'customProperties' => $site === null ? [] : ($site->custom_properties ?? []),
            'tab' => $tab,
            'period' => $period === null ? null : ReportPayload::period($period),
            'path' => $path,
            'event' => $event,
            'property' => $property,
            'by' => $by,
            'goals' => $site === null ? [] : $site->goals()->orderBy('name')->get()->map(fn (AnalyticsGoal $goal): array => ['value' => (string) $goal->id, 'label' => $goal->name])->values(),
            'canManage' => $site !== null && $user->can('update', $site),
            'result' => $period === null ? null : match ($tab) {
                'paths' => $paths->handle($site, $period, $path),
                'properties' => $properties->handle($site, $period, $event, $property),
                'items' => $items->handle($site, $period),
                'retention' => $this->retention($retention->handle($site)),
                'attribution' => $attribution->handle($site, $period, $by),
                'clicks' => $clicks->handle($site, $period, $path),
                'forms' => $forms->handle($site, $period),
                'experiments' => $this->experiments($site, $experiments),
                default => $insights->handle($site, $period),
            },
        ]);
    }

    /**
     * Read a text parameter, trimmed and cut to a length; null when it's empty.
     *
     * @param  Request  $request
     * @param  string  $key
     * @param  int  $max
     * @return string|null
     */
    private function text(Request $request, string $key, int $max): ?string
    {
        $value = trim($request->string($key)->toString());

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * Describe the retention cohorts with their weeks as dates.
     *
     * @param  array{cohorts: list<array{week: \Carbon\CarbonImmutable, size: int, returned: list<int|null>}>, tracked: bool}  $retention
     * @return array{cohorts: list<array{week: string, size: int, returned: list<int|null>}>, tracked: bool}
     */
    private function retention(array $retention): array
    {
        return [
            'cohorts' => array_map(fn (array $cohort): array => [...$cohort, 'week' => $cohort['week']->toDateString()], $retention['cohorts']),
            'tracked' => $retention['tracked'],
        ];
    }

    /**
     * Describe the site's experiments, newest first, with each variant's results.
     *
     * @param  AnalyticsSite  $site
     * @param  ExperimentResultsQuery  $experiments
     * @return array<int, array<string, mixed>>
     */
    private function experiments(AnalyticsSite $site, ExperimentResultsQuery $experiments): array
    {
        return AnalyticsExperiment::query()->where('site_id', $site->id)->with('goal')->latest('id')->get()
            ->map(fn (AnalyticsExperiment $experiment): array => [
                'id' => $experiment->id,
                'key' => $experiment->key,
                'name' => $experiment->name,
                'goal' => $experiment->goal?->name,
                'status' => $experiment->status,
                'startedAt' => $experiment->started_at->toIso8601String(),
                'stoppedAt' => $experiment->stopped_at?->toIso8601String(),
                'variants' => array_map(fn (array $variant): array => [
                    'variant' => $variant['variant'],
                    'visitors' => $variant['visitors'],
                    'conversions' => $variant['conversions'],
                    'rate' => $variant['rate'],
                    'lift' => $variant['lift'],
                    'pValue' => $variant['p_value'],
                    'significant' => $variant['significant'],
                ], $experiments->handle($experiment)),
            ])->values()->all();
    }
}
