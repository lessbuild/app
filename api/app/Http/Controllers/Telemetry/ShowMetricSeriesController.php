<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Http\Requests\Telemetry\SearchMetricsRequest;
use App\Models\MetricSeries;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use App\Services\Monitoring\MetricAnomalyDetector;
use App\Services\Monitoring\MetricChart;
use App\Services\Monitoring\TelemetryRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowMetricSeriesController
{
    /**
     * Show one metric series as a chart over the chosen range (`?range=`) as recorded or as a rate (`?mode=`), with
     * unusual points marked on plans with anomaly detection.
     *
     * @param  SearchMetricsRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  MetricSeries  $series
     * @param  ProjectOverviewQuery  $overview
     * @param  MetricChart  $charts
     * @param  TelemetryRedactor  $redactor
     * @param  Entitlements  $entitlements
     * @return JsonResponse
     */
    public function __invoke(SearchMetricsRequest $request, #[CurrentUser] User $user, Project $project, MetricSeries $series, ProjectOverviewQuery $overview, MetricChart $charts, TelemetryRedactor $redactor, Entitlements $entitlements): JsonResponse
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

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'series' => [
                'id' => $series->id, 'name' => $series->name, 'environment' => $series->environment->name, 'resource' => $series->resource_label,
                'unit' => $series->unit, 'kind' => $series->kind, 'temporality' => $series->temporality, 'supportsRate' => $series->supportsRate(),
                'descriptor' => json_encode($series->descriptor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ],
            'chart' => $chart,
            'chartLimit' => MetricChart::LIMIT,
            'minimumBaseline' => MetricAnomalyDetector::MINIMUM_BASELINE_POINTS,
            'filters' => $filters,
            'from' => $from->toIso8601String(),
            'until' => $until->toIso8601String(),
            'ranges' => array_map(fn (string $label): string => __($label), SearchMetricsRequest::RANGES),
            'anomalies' => $entitlements->for($project->account)->has('monitoring.anomalies'),
            'canManage' => $user->can('manageService', [$project, 'monitoring']),
        ]);
    }
}
