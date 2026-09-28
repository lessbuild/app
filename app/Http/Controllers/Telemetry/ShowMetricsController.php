<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Http\Requests\Telemetry\SearchMetricsRequest;
use App\Models\MetricSeries;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\MetricSeriesQuery;
use App\Services\Monitoring\TelemetryRedactor;
use App\Support\Telemetry\MetricCollectorProfiles;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** Every metric series the project's environments sent, and collector setups for common stacks. */
final class ShowMetricsController
{
    /**
     * The metric series list (redacted) and the collector profiles.
     *
     * @param  SearchMetricsRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  MetricSeriesQuery  $query
     * @param  TelemetryRedactor  $redactor
     * @param  MetricCollectorProfiles  $profiles
     * @return View
     */
    public function __invoke(SearchMetricsRequest $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, MetricSeriesQuery $query, TelemetryRedactor $redactor, MetricCollectorProfiles $profiles): View
    {
        $filters = $request->filters();
        $series = $query->handle($project, $filters)->appends($request->safe()->except('page'));
        $series->getCollection()->each(fn (MetricSeries $item): MetricSeries => $item->forceFill($redactor->redact($item->only(['name', 'resource_label', 'unit', 'descriptor']))));

        return view('telemetry.metrics', [
            'overview' => $overview->handle($project, $user),
            'series' => $series,
            'filters' => $filters,
            'kindOptions' => SearchMetricsRequest::KINDS,
            'profiles' => $profiles->all(route('api.otlp', ['signal' => 'metrics'])),
            'canManage' => $user->can('manageService', [$project, 'monitoring']),
        ]);
    }
}
