<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Http\Requests\Telemetry\SearchMetricsRequest;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\MetricSeriesQuery;
use App\Services\Billing\Entitlements;
use App\Services\Monitoring\MetricChart;
use App\Services\Monitoring\TelemetryRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** One metric series over time, with counter rates and (on paid tiers) unusual shifts marked. */
final class ShowMetricSeriesController
{
    public function __invoke(SearchMetricsRequest $request, #[CurrentUser] User $user, Project $project, string $series, ProjectOverviewQuery $overview, MetricSeriesQuery $query, MetricChart $charts, TelemetryRedactor $redactor, Entitlements $entitlements): View
    {
        $metric = $query->find($project, $series);
        $filters = $request->filters();
        abort_if($filters['mode'] === 'rate' && ! $metric->supportsRate(), 422, __('This series doesn’t support counter rates.'));
        $until = CarbonImmutable::now('UTC');
        $from = match ($filters['range']) {
            '24h' => $until->subDay(),
            '7d' => $until->subDays(7),
            default => $until->subHour(),
        };
        $chart = $charts->read($metric, $from, $until, $filters['mode']);
        $metric->forceFill($redactor->redact($metric->only(['name', 'resource_label', 'unit', 'descriptor'])));

        return view('telemetry.metric', [
            'overview' => $overview->handle($project, $user),
            'series' => $metric,
            'chart' => $chart,
            'filters' => $filters,
            'from' => $from,
            'until' => $until,
            'rangeOptions' => SearchMetricsRequest::RANGES,
            'anomalies' => $entitlements->for($project->account)->has('monitoring.anomalies'),
            'canManage' => $user->can('manageService', [$project, 'monitoring']),
        ]);
    }
}
