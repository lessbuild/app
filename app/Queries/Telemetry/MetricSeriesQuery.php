<?php

declare(strict_types=1);

namespace App\Queries\Telemetry;

use App\Models\MetricSeries;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/** A project's metric series: one per metric name, resource and label set, newest first. */
final class MetricSeriesQuery
{
    /**
     * The project's metric series matching the filters, most recently received first, 25 to a page.
     *
     * @param  Project  $project
     * @param  array{q?: string, environment?: string, kind?: string, page?: int}  $filters
     * @return LengthAwarePaginator<int, MetricSeries>
     */
    public function handle(Project $project, array $filters): LengthAwarePaginator
    {
        $query = MetricSeries::query()->whereIn('environment_id', $project->environments()->select('id'))->with('environment')
            ->when(isset($filters['environment']), fn (Builder $query) => $query->where('environment_id', $filters['environment'] ?? ''))
            ->when(isset($filters['kind']), fn (Builder $query) => $query->where('kind', $filters['kind'] ?? ''));
        if (isset($filters['q'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                foreach (['name', 'unit', 'resource_label'] as $column) {
                    $query->orWhereRaw($column." LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }

        return $query->orderByDesc('last_received_at')->orderByDesc('id')
            ->paginate(25, ['id', 'environment_id', 'name', 'resource_label', 'unit', 'kind', 'descriptor', 'last_received_at'], 'page', $filters['page'] ?? 1);
    }

    /**
     * One of the project's metric series; 404 otherwise.
     *
     * @param  Project  $project
     * @param  string|int  $id
     * @return MetricSeries
     */
    public function find(Project $project, int|string $id): MetricSeries
    {
        return MetricSeries::query()->whereIn('environment_id', $project->environments()->select('id'))->with('environment')->findOrFail((int) $id);
    }
}
