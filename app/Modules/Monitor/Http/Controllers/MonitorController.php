<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\ArchiveMonitorRequest;
use App\Modules\Monitor\Http\Requests\SaveMonitorRequest;
use App\Modules\Monitor\Http\Requests\SearchMonitorsRequest;
use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Services\ChangeMonitor;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\EvaluateQueueMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;

class MonitorController extends Controller
{
    public function index(SearchMonitorsRequest $request, CurrentWorkspace $workspace): Response
    {
        $state = $request->validated('state') ?? 'all';
        $checkType = $request->validated('check_type');
        $query = Monitor::forWorkspace($workspace->get())->with('environment.application');
        if ($checkType !== null) {
            $query->where('type', $checkType);
        }
        if ($state === 'archived') {
            $query->onlyTrashed();
        } elseif ($state !== 'all') {
            $query->where('enabled', $state === 'enabled');
        }
        $monitors = $query->latest('id')->paginate(25, ['*'], 'page', (int) ($request->validated('page') ?? 1))
            ->appends($request->safe()->except('page'));

        return response()->view('monitor::alerts.monitors', [
            'monitors' => $monitors, 'state' => $state, 'checkType' => $checkType,
            'canCreate' => Gate::allows('create', [Monitor::class, $workspace->get()]),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function create(SearchMonitorsRequest $request, CurrentWorkspace $workspace): Response
    {
        return $this->form($workspace, checkType: $request->validated('check_type') ?? 'http');
    }

    public function edit(SearchMonitorsRequest $request, Monitor $monitor, CurrentWorkspace $workspace): Response
    {
        return $this->form($workspace, $monitor);
    }

    private function form(CurrentWorkspace $workspace, ?Monitor $monitor = null, string $checkType = 'http'): Response
    {
        $checkType = $monitor?->type ?? $checkType;
        $environments = Environment::forWorkspace($workspace->get())->with('application:id,name')
            ->orderBy('application_id')->orderBy('name')->orderBy('id')->get(['id', 'application_id', 'name', 'status']);
        $environmentOptions = $environments->mapWithKeys(fn (Environment $environment): array => [
            $environment->id => $environment->application->name.' / '.$environment->name.($environment->status !== 'active' ? ' (paused)' : ''),
        ])->all();
        $destinations = AlertDestination::forWorkspace($workspace->get())->orderBy('name')->orderBy('id')->get(['id', 'name', 'enabled']);
        $routes = $monitor?->destinations()->where('workspace_id', $workspace->get()->id)->get(['alert_destinations.id']) ?? collect();

        return response()->view('monitor::alerts.monitor-form', compact('monitor', 'environmentOptions', 'destinations', 'routes', 'checkType'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(SearchMonitorsRequest $request, Monitor $monitor, EvaluateQueueMonitor $queueEvaluation): Response
    {
        $monitor->load('environment.application');
        if ($monitor->type === 'queue') {
            return $this->queueShow($request, $monitor, $queueEvaluation);
        }
        $checks = $monitor->checks()->latest('scheduled_at')->latest('id')
            ->paginate(25, ['*'], 'page', (int) ($request->validated('page') ?? 1));
        $recent = $monitor->checks()->where('config_revision', $monitor->config_revision)->where('scheduled_at', '>=', now('UTC')->subDay()->format('Y-m-d H:i:s.u'));
        $summary = (clone $recent)->toBase()->selectRaw("COUNT(*) AS total, SUM(CASE WHEN outcome = 'up' THEN 1 ELSE 0 END) AS passed, SUM(CASE WHEN outcome = 'down' THEN 1 ELSE 0 END) AS failed, SUM(CASE WHEN outcome = 'unknown' THEN 1 ELSE 0 END) AS unknown_count, SUM(skipped_intervals) AS skipped, AVG(duration_ms) AS mean_ms")->first();
        $measured = (int) $summary->passed + (int) $summary->failed;
        $successRate = $measured > 0 ? round((int) $summary->passed / $measured * 100, 2) : null;
        $chart = (clone $recent)->latest('scheduled_at')->latest('id')->limit(40)->get(['id', 'scheduled_at', 'duration_ms', 'outcome'])->reverse()->values();
        $chartMaximum = max(1, (float) $chart->max('duration_ms'));
        $incidents = $monitor->incidents()->latest('opened_at')->latest('id')
            ->paginate(10, ['*'], 'incidents_page', (int) ($request->validated('incidents_page') ?? 1));
        $runs = $monitor->type === 'heartbeat' ? $monitor->heartbeatRuns()->latest('id')
            ->paginate(25, ['*'], 'runs_page', (int) ($request->validated('runs_page') ?? 1)) : null;
        $runningCount = $monitor->type === 'heartbeat' ? $monitor->heartbeatRuns()->where('status', 'running')->count() : 0;
        $heartbeatSecret = null;
        $issued = $request->session()->get('issued_heartbeat_key');
        if ($monitor->type === 'heartbeat' && $request->user()->hasVerifiedEmail() && Gate::allows('update', $monitor)
            && is_array($issued) && ($issued['monitor_id'] ?? null) === $monitor->id
            && ($issued['token_hash'] ?? null) === $monitor->heartbeat_token_hash) {
            $heartbeatSecret = Crypt::decryptString($request->session()->pull('issued_heartbeat_key')['encrypted_secret']);
        }

        return response()->view('monitor::alerts.monitor-show', compact('monitor', 'checks', 'summary', 'successRate', 'chart', 'chartMaximum', 'incidents', 'runs', 'runningCount', 'heartbeatSecret'))
            ->header('Cache-Control', 'private, no-store');
    }

    private function queueShow(SearchMonitorsRequest $request, Monitor $monitor, EvaluateQueueMonitor $evaluation): Response
    {
        $now = CarbonImmutable::now('UTC');
        $state = $evaluation->inspect($monitor, $now);
        $snapshot = $state['snapshot'];
        $current = $state['result']->details;
        $snapshots = $monitor->queueSnapshots()->latest('id')
            ->paginate(25, ['*'], 'snapshots_page', (int) ($request->validated('snapshots_page') ?? 1));
        $workers = $monitor->queueWorkers()->latest('id')
            ->paginate(25, ['*'], 'workers_page', (int) ($request->validated('workers_page') ?? 1));
        $chart = $monitor->queueSnapshots()->where('config_revision', $monitor->config_revision)->where('applied', true)
            ->latest('observed_at')->latest('id')->limit(40)->get(['id', 'observed_at', 'pending'])->reverse()->values();
        $chartMaximum = max(1, (int) $chart->max('pending'));
        $checks = $monitor->checks()->latest('scheduled_at')->latest('id')
            ->paginate(25, ['*'], 'page', (int) ($request->validated('page') ?? 1));
        $incidents = $monitor->incidents()->latest('opened_at')->latest('id')
            ->paginate(10, ['*'], 'incidents_page', (int) ($request->validated('incidents_page') ?? 1));
        $queueSecret = null;
        $issued = $request->session()->get('issued_queue_key');
        if ($request->user()->hasVerifiedEmail() && Gate::allows('update', $monitor) && is_array($issued)
            && ($issued['monitor_id'] ?? null) === $monitor->id && ($issued['token_hash'] ?? null) === $monitor->queue_token_hash) {
            $queueSecret = Crypt::decryptString($request->session()->pull('issued_queue_key')['encrypted_secret']);
        }

        return response()->view('monitor::alerts.queue-monitor', compact('monitor', 'now', 'snapshot', 'current', 'snapshots', 'workers',
            'chart', 'chartMaximum', 'checks', 'incidents', 'queueSecret'))->header('Cache-Control', 'private, no-store');
    }

    public function store(SaveMonitorRequest $request, CurrentWorkspace $workspace, ChangeMonitor $changes): RedirectResponse
    {
        $monitor = $changes->save($workspace->get(), $request->user(), $request->validated());

        return to_route('monitor.monitors.show', $monitor)->with('status', in_array($monitor->type, ['heartbeat', 'queue'], true)
            ? 'Signal monitor created. Generate its key and send signals before the first deadline, or pause it during setup.'
            : 'Monitor created. Its first check will be scheduled within a minute when enabled.');
    }

    public function update(SaveMonitorRequest $request, Monitor $monitor, CurrentWorkspace $workspace, ChangeMonitor $changes): RedirectResponse
    {
        $changes->save($workspace->get(), $request->user(), $request->validated(), $monitor);

        return to_route('monitor.monitors.show', $monitor)->with('status', 'Monitor saved. Routing applies to future incident transitions only.');
    }

    public function destroy(ArchiveMonitorRequest $request, Monitor $monitor, CurrentWorkspace $workspace, ChangeMonitor $changes): RedirectResponse
    {
        $changes->archive($monitor, $workspace->get(), $request->user(), (int) $request->validated('version'));

        return to_route('monitor.monitors.index', ['state' => 'archived'])->with('status', 'Monitor archived. History retained; this does not indicate recovery.');
    }
}
