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
use Illuminate\Http\JsonResponse;

final class ShowMetricsController
{
    /**
     * List the project's metric series (`?q=`, `?environment=`, `?kind=`, `?page=`), with ready-made collector setups.
     *
     * @param  SearchMetricsRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  MetricSeriesQuery  $query
     * @param  TelemetryRedactor  $redactor
     * @param  MetricCollectorProfiles  $profiles
     * @return JsonResponse
     */
    public function __invoke(SearchMetricsRequest $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, MetricSeriesQuery $query, TelemetryRedactor $redactor, MetricCollectorProfiles $profiles): JsonResponse
    {
        $filters = $request->filters();
        $series = $query->handle($project, $filters);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'series' => collect($series->items())->map(function (MetricSeries $item) use ($redactor): array {
                $item->forceFill($redactor->redact($item->only(['name', 'resource_label', 'unit', 'descriptor'])));

                return [
                    'id' => $item->id, 'name' => $item->name, 'environment' => $item->environment->name, 'resource' => $item->resource_label,
                    'kind' => $item->kind, 'unit' => $item->unit, 'lastReceivedAt' => $item->last_received_at->toIso8601String(),
                ];
            })->values(),
            'page' => $series->currentPage(),
            'lastPage' => $series->lastPage(),
            'filters' => $filters,
            'kinds' => array_map(fn (string $label): string => __($label), SearchMetricsRequest::KINDS),
            'profiles' => $profiles->all(route('api.otlp', ['signal' => 'metrics'])),
            'canManage' => $user->can('manageService', [$project, 'monitoring']),
        ]);
    }
}
