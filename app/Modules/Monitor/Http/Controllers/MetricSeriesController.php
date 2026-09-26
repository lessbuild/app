<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\SearchMetricsRequest;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\MetricSeries;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\MetricChart;
use App\Modules\Monitor\Services\MetricCollectorProfiles;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use App\Modules\Monitor\Services\WorkspacePlanLimits;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;

class MetricSeriesController extends Controller
{
    public function index(SearchMetricsRequest $request, CurrentWorkspace $currentWorkspace, TelemetryRedactor $redactor, MetricCollectorProfiles $collectors): Response
    {
        $workspace = $currentWorkspace->get();
        $filters = $request->filters();
        $environments = Environment::forWorkspace($workspace)->visibleTo(request()->user(), $workspace)->with('application:id,name')->orderBy('application_id')->orderBy('name')->orderBy('id')->get(['id', 'application_id', 'name']);
        abort_if(isset($filters['environment']) && ! $environments->contains('id', (int) $filters['environment']), 404);
        $query = MetricSeries::forWorkspace($workspace)->visibleTo(request()->user(), $workspace)->with('environment.application')
            ->when(isset($filters['environment']), fn (Builder $query): Builder => $query->where('environment_id', $filters['environment']))
            ->when(isset($filters['kind']), fn (Builder $query): Builder => $query->where('kind', $filters['kind']));
        if (isset($filters['q'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("unit LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("resource_label LIKE ? ESCAPE '!'", [$pattern]);
            });
        }
        $series = $query->orderByDesc('last_received_at')->orderByDesc('id')
            ->paginate(25, ['id', 'environment_id', 'name', 'resource_label', 'unit', 'kind', 'descriptor', 'last_received_at'], 'page', (int) ($filters['page'] ?? 1))
            ->appends($request->safe()->except('page'));
        $series->each(fn (MetricSeries $item): MetricSeries => $item->forceFill($redactor->redact($item->only(['name', 'resource_label', 'unit', 'descriptor']))));
        $environmentOptions = $environments->mapWithKeys(fn (Environment $environment): array => [$environment->id => $environment->application->name.' / '.$environment->name])->all();

        $profiles = $collectors->all(route('monitor.api.otlp', ['signal' => 'metrics']));

        return response()->view('monitor::metrics.index', compact('series', 'filters', 'environmentOptions', 'profiles'))->header('Cache-Control', 'private, no-store');
    }

    public function show(SearchMetricsRequest $request, MetricSeries $metricSeries, MetricChart $charts, TelemetryRedactor $redactor, CurrentWorkspace $currentWorkspace, WorkspacePlanLimits $limits): Response
    {
        $metricSeries->load('environment.application');
        $filters = $request->filters();
        abort_if($filters['mode'] === 'rate' && ! $metricSeries->supportsRate(), 422, 'This series does not support counter rates.');
        $until = CarbonImmutable::now('UTC');
        $from = match ($filters['range']) {
            '24h' => $until->subDay(),
            '7d' => $until->subDays(7),
            default => $until->subHour(),
        };
        $chart = $charts->read($metricSeries, $from, $until, $filters['mode']);
        $metricSeries->forceFill($redactor->redact($metricSeries->only(['name', 'resource_label', 'unit', 'descriptor'])));
        $anomalyDetectionEnabled = $limits->anomalyDetectionEnabled($currentWorkspace->get());

        return response()->view('monitor::metrics.show', [
            'series' => $metricSeries, 'rangeOptions' => SearchMetricsRequest::RANGES, 'anomalyDetectionEnabled' => $anomalyDetectionEnabled,
            ...compact('chart', 'filters', 'from', 'until'),
        ])->header('Cache-Control', 'private, no-store');
    }
}
