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
use Illuminate\Contracts\Auth\Authenticatable;
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
    public function forDashboard(Dashboard $dashboard, Workspace $workspace, ?Authenticatable $principal = null): array
    {
        $dashboard->loadMissing('widgets');
        $metrics = null;

        $widgets = $dashboard->widgets->map(function (DashboardWidget $widget) use ($dashboard, $workspace, $principal, &$metrics): array {
            $widgetMetrics = in_array($widget->type, ['telemetry', 'event_mix'], true)
                ? ($metrics ??= $this->metrics->forWorkspace($workspace, $dashboard->range, $principal))
                : null;
            $data = match ($widget->type) {
                'telemetry' => ['metrics' => $widgetMetrics],
                'event_mix' => ['breakdown' => $widgetMetrics['eventBreakdown'], 'eventCount' => $widgetMetrics['eventCount']],
                'incidents' => ['incidents' => $this->incidents($workspace, $principal)],
                'monitors' => ['monitors' => $this->monitors($workspace, $principal)],
                'objectives' => ['objectives' => $this->objectives($workspace, $principal)],
                'applications' => ['applications' => $this->applications($workspace, $principal)],
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
    private function incidents(Workspace $workspace, ?Authenticatable $principal): Collection
    {
        $incidents = Incident::forWorkspace($workspace)->when($principal !== null, fn ($query) => $query->visibleTo($principal, $workspace))
            ->where('active_slot', true)
            ->with(['assignee:id,name'])
            ->latest('opened_at')->latest('id')->limit(8)->get();
        $incidents->each(fn (Incident $incident): Incident => $incident->forceFill($this->redactor->redact($incident->only('title'))));

        return $incidents;
    }

    /** @return Collection<int, Monitor> */
    private function monitors(Workspace $workspace, ?Authenticatable $principal): Collection
    {
        return Monitor::forWorkspace($workspace)->when($principal !== null, fn ($query) => $query->visibleTo($principal, $workspace))->with('environment.application')
            ->orderByDesc('enabled')->orderBy('name')->orderBy('id')->limit(12)->get();
    }

    /** @return Collection<int, array{objective: ServiceLevelObjective, report: array<string, mixed>}> */
    private function objectives(Workspace $workspace, ?Authenticatable $principal): Collection
    {
        return ServiceLevelObjective::forWorkspace($workspace)->when($principal !== null, fn ($query) => $query->visibleTo($principal, $workspace))->where('enabled', true)
            ->with('environment.application')->orderBy('name')->orderBy('id')->limit(8)->get()
            ->map(fn (ServiceLevelObjective $objective): array => [
                'objective' => $objective,
                'report' => $this->objectives->forObjective($objective),
            ]);
    }

    /** @return Collection<int, Application> */
    private function applications(Workspace $workspace, ?Authenticatable $principal): Collection
    {
        return $workspace->applications()->when($principal !== null, fn ($query) => $query->visibleTo($principal, $workspace))
            ->select(['id', 'workspace_id', 'name', 'framework', 'framework_version', 'accent'])
            ->withCount(['environments' => fn ($query) => $query->when($principal !== null, fn ($query) => $query->visibleTo($principal, $workspace))])->withMax(['environments as last_receipt_at' => fn ($query) => $query->when($principal !== null, fn ($query) => $query->visibleTo($principal, $workspace))], 'last_seen_at')
            ->withCasts(['last_receipt_at' => 'datetime'])
            ->orderBy('name')->orderBy('id')->limit(8)->get();
    }
}
