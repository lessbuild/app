<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Http\Requests\Telemetry\SearchMetricsRequest;
use App\Models\MetricSample;
use App\Models\MetricSeries;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\MetricSeriesQuery;
use App\Services\Monitoring\TelemetryRedactor;
use App\Support\Telemetry\MetricCollectorProfiles;
use Carbon\CarbonImmutable;
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
        $recent = $this->recent(collect($series->items())->pluck('id')->all());

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'series' => collect($series->items())->map(function (MetricSeries $item) use ($redactor, $recent): array {
                $item->forceFill($redactor->redact($item->only(['name', 'resource_label', 'unit', 'descriptor'])));

                return [
                    'id' => $item->id, 'name' => $item->name, 'environment' => $item->environment->name, 'resource' => $item->resource_label,
                    'kind' => $item->kind, 'unit' => $item->unit, 'lastReceivedAt' => $item->last_received_at->toIso8601String(),
                    ...($recent[$item->id] ?? ['values' => [], 'latest' => null]),
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

    /**
     * Read each series' numeric samples from the last day as up to twelve points (the average of each two hours, oldest
     * first, skipping hours without samples) and its latest value, for the sparklines in the list.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, array{values: list<float>, latest: float|null}>
     */
    private function recent(array $ids): array
    {
        $now = CarbonImmutable::now('UTC');
        $samples = MetricSample::query()->whereIn('metric_series_id', $ids)->whereNotNull('value')->where('occurred_at', '>=', $now->subDay())
            ->orderBy('occurred_at')->limit(20000)->get(['metric_series_id', 'value', 'occurred_at'])->groupBy('metric_series_id');

        return $samples->map(fn ($own): array => [
            'values' => array_values($own->groupBy(fn (MetricSample $sample): int => (int) floor($sample->occurred_at->diffInSeconds($now, true) / 7200))
                ->sortKeysDesc()->map(fn ($bucket): float => round((float) $bucket->avg('value'), 3))->all()),
            'latest' => $own->last() instanceof MetricSample ? (float) $own->last()->value : null,
        ])->all();
    }
}
