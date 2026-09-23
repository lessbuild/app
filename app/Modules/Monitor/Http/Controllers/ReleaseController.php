<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\SearchReleasesRequest;
use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Models\Release;
use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\ReleaseMetrics;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;

class ReleaseController extends Controller
{
    public function index(SearchReleasesRequest $request, CurrentWorkspace $workspace): Response
    {
        $workspace = $workspace->get();
        $filters = $request->filters();
        $applications = $workspace->applications()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
        abort_if(isset($filters['application']) && ! $applications->contains('id', (int) $filters['application']), 404);
        $query = Release::forWorkspace($workspace)->with('application:id,name')
            ->when(isset($filters['application']), fn (Builder $query): Builder => $query->where('application_id', $filters['application']));

        if (isset($filters['q'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                foreach (['version', 'service', 'service_namespace'] as $column) {
                    $query->orWhereRaw($query->getQuery()->getGrammar()->wrap($column)." LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
        $releases = $query->latest('created_at')->latest('id')
            ->paginate(25, ['*'], 'page', (int) ($filters['page'] ?? 1))->appends($request->safe()->except('page'));

        return response()->view('monitor::releases.index', compact('releases', 'applications', 'filters'))->header('Cache-Control', 'private, no-store');
    }

    public function show(SearchReleasesRequest $request, Release $release, CurrentWorkspace $workspace, ReleaseMetrics $metrics, TelemetryRedactor $redactor): Response
    {
        $workspace = $workspace->get();
        $filters = $request->filters();
        $environments = $release->application->environments()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
        $environmentId = isset($filters['environment']) ? (int) $filters['environment'] : null;
        abort_if($environmentId !== null && ! $environments->contains('id', $environmentId), 404);
        $baseline = isset($filters['baseline']) ? Release::forWorkspace($workspace)
            ->where('application_id', $release->application_id)->where('service_hash', $release->service_hash)
            ->where('id', '!=', $release->id)->findOrFail($filters['baseline']) : null;
        $baselineOptions = Release::forWorkspace($workspace)->where('application_id', $release->application_id)
            ->where('service_hash', $release->service_hash)->where('id', '!=', $release->id)
            ->latest('created_at')->latest('id')->limit(100)->get(['id', 'version']);
        if ($baseline !== null && ! $baselineOptions->contains('id', $baseline->id)) {
            $baselineOptions->push($baseline);
        }
        [$from, $until] = $metrics->window($filters['range']);
        $query = $metrics->events($workspace, $release, $environmentId, $from, $until);
        $currentMetrics = $metrics->summarize($query);
        $baselineMetrics = $baseline !== null ? $metrics->summarize($metrics->events($workspace, $baseline, $environmentId, $from, $until)) : null;
        $events = (clone $query)->summary()->with('environment:id,name')->latest('occurred_at')->latest('id')
            ->paginate(20, ['*'], 'events_page', (int) ($filters['events_page'] ?? 1))->appends($request->safe()->except('events_page'));
        $events->each(fn (TelemetryEvent $event): TelemetryEvent => $event->forceFill($redactor->redact($event->only(['name', 'route']))));
        $issues = Issue::forWorkspace($workspace)->where('application_id', $release->application_id)
            ->whereIn('id', (clone $query)->where('type', 'exception')->select('issue_id'))
            ->select(['id', 'application_id', 'title', 'status', 'severity'])->latest('id')
            ->paginate(20, ['*'], 'issues_page', (int) ($filters['issues_page'] ?? 1))->appends($request->safe()->except('issues_page'));
        $issues->each(fn (Issue $issue): Issue => $issue->forceFill($redactor->redact($issue->only(['title']))));
        $deployments = $release->deployments()->forWorkspace($workspace)->with('environment:id,application_id,name')
            ->when($environmentId !== null, fn (Builder $query): Builder => $query->where('environment_id', $environmentId))
            ->latest('deployed_at')->latest('id')->paginate(20, ['*'], 'deployments_page', (int) ($filters['deployments_page'] ?? 1))
            ->appends($request->safe()->except('deployments_page'));

        return response()->view('monitor::releases.show', [
            ...compact('release', 'filters', 'environments', 'baseline', 'baselineOptions', 'from', 'until', 'currentMetrics', 'baselineMetrics', 'events', 'issues', 'deployments'),
            'rangeOptions' => SearchReleasesRequest::RANGES,
        ])->header('Cache-Control', 'private, no-store');
    }
}
