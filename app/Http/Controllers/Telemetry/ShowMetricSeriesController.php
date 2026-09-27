<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Http\Requests\Telemetry\SearchMetricsRequest;
use App\Models\MetricSeries;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use App\Services\Monitoring\MetricChart;
use App\Services\Monitoring\TelemetryRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** One metric series over time, with counter rates and (on paid tiers) unusual shifts marked. */
final class ShowMetricSeriesController
{
    /**
     * A metric series chart over the last hour, day or week. Asking for a rate on a series that isn't a counter is a
     * 422.
     */
    public function __invoke(SearchMetricsRequest $request, #[CurrentUser] User $user, Project $project, MetricSeries $series, ProjectOverviewQuery $overview, MetricChart $charts, TelemetryRedactor $redactor, Entitlements $entitlements): View
    {
        $filters = $request->filters();
        abort_if($filters['mode'] === 'rate' && ! $series->supportsRate(), 422, __('This series doesn’t support counter rates.'));
        $until = CarbonImmutable::now('UTC');
        $from = match ($filters['range']) {
            '24h' => $until->subDay(),
            '7d' => $until->subDays(7),
            default => $until->subHour(),
        };
        $chart = $charts->read($series, $from, $until, $filters['mode']);
        $series->forceFill($redactor->redact($series->only(['name', 'resource_label', 'unit', 'descriptor'])));

        return view('telemetry.metric', [
            'overview' => $overview->handle($project, $user),
            'series' => $series,
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
