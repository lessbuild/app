<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Dashboard;
use App\Modules\Monitor\Models\DashboardWidget;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\ServiceLevelObjective;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Telemetry\DashboardMetrics;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Illuminate\Support\Collection;

class DashboardReport
{
    public function __construct(
        private readonly DashboardMetrics $metrics,
        private readonly ServiceObjectiveReport $objectives,
        private readonly TelemetryRedactor $redactor,
    ) {}

    /**
     * @return array{range: string, rangeLabel: string, widgets: Collection<int, array{type: string, label: string, data: array<string, mixed>}>}
     */
    public function forDashboard(Dashboard $dashboard, Workspace $workspace): array
    {
        $dashboard->loadMissing('widgets');
        $metrics = null;

        $widgets = $dashboard->widgets->map(function (DashboardWidget $widget) use ($dashboard, $workspace, &$metrics): array {
            $widgetMetrics = in_array($widget->type, ['telemetry', 'event_mix'], true)
                ? ($metrics ??= $this->metrics->forWorkspace($workspace, $dashboard->range))
                : null;
            $data = match ($widget->type) {
                'telemetry' => ['metrics' => $widgetMetrics],
                'event_mix' => ['breakdown' => $widgetMetrics['eventBreakdown'], 'eventCount' => $widgetMetrics['eventCount']],
                'incidents' => ['incidents' => $this->incidents($workspace)],
                'monitors' => ['monitors' => $this->monitors($workspace)],
                'objectives' => ['objectives' => $this->objectives($workspace)],
                'applications' => ['applications' => $this->applications($workspace)],
                default => [],
            };

            return [
                'type' => $widget->type,
                'label' => Dashboard::WIDGET_TYPES[$widget->type] ?? 'Dashboard widget',
                'data' => $data,
            ];
        });

        return [
            'range' => $dashboard->range,
            'rangeLabel' => Dashboard::RANGES[$dashboard->range] ?? 'Selected window',
            'widgets' => $widgets,
        ];
    }

    /** @return Collection<int, Incident> */
    private function incidents(Workspace $workspace): Collection
    {
        $incidents = Incident::forWorkspace($workspace)
            ->where('active_slot', true)
            ->with(['assignee:id,name'])
            ->latest('opened_at')->latest('id')->limit(8)->get();
        $incidents->each(fn (Incident $incident): Incident => $incident->forceFill($this->redactor->redact($incident->only('title'))));

        return $incidents;
    }

    /** @return Collection<int, Monitor> */
    private function monitors(Workspace $workspace): Collection
    {
        return Monitor::forWorkspace($workspace)->with('environment.application')
            ->orderByDesc('enabled')->orderBy('name')->orderBy('id')->limit(12)->get();
    }

    /** @return Collection<int, array{objective: ServiceLevelObjective, report: array<string, mixed>}> */
    private function objectives(Workspace $workspace): Collection
    {
        return ServiceLevelObjective::forWorkspace($workspace)->where('enabled', true)
            ->with('environment.application')->orderBy('name')->orderBy('id')->limit(8)->get()
            ->map(fn (ServiceLevelObjective $objective): array => [
                'objective' => $objective,
                'report' => $this->objectives->forObjective($objective),
            ]);
    }

    /** @return Collection<int, Application> */
    private function applications(Workspace $workspace): Collection
    {
        return $workspace->applications()
            ->select(['id', 'workspace_id', 'name', 'framework', 'framework_version', 'accent'])
            ->withCount('environments')->withMax('environments as last_receipt_at', 'last_seen_at')
            ->withCasts(['last_receipt_at' => 'datetime'])
            ->orderBy('name')->orderBy('id')->limit(8)->get();
    }
}
