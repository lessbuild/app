<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Queries\Telemetry\TelemetrySummaryQuery;
use App\Services\Monitoring\ServiceObjectiveReport;
use App\Services\Monitoring\TelemetryRedactor;

/**
 * The data behind each widget on a dashboard. The telemetry summary is read once however many widgets use it.
 *
 * @phpstan-import-type Summary from TelemetrySummaryQuery
 */
final class DashboardReportQuery
{
    public function __construct(
        private readonly TelemetrySummaryQuery $summaries,
        private readonly ServiceObjectiveReport $objectives,
        private readonly TelemetryRedactor $redactor,
    ) {}

    /** @return list<array{type: string, label: string, data: array<string, mixed>}> */
    public function handle(Dashboard $dashboard): array
    {
        $dashboard->loadMissing(['widgets', 'account']);
        $account = $dashboard->account;
        $summary = null;
        $widgets = [];
        foreach ($dashboard->widgets as $widget) {
            /** @var DashboardWidget $widget */
            $data = match ($widget->type) {
                'telemetry' => ['summary' => $summary ??= $this->summaries->handle($account, $dashboard->range)],
                'event_mix' => ['summary' => $summary ??= $this->summaries->handle($account, $dashboard->range)],
                'incidents' => ['incidents' => Incident::query()->forAccount($account)->where('status', '!=', 'resolved')->with('project')
                    ->orderByDesc('opened_at')->orderByDesc('id')->limit(8)->get()
                    ->each(fn (Incident $incident): Incident => $incident->forceFill(['title' => (string) ($this->redactor->redact(['title' => $incident->title])['title'] ?? '')]))],
                'monitors' => ['monitors' => Monitor::query()->forAccount($account)->with('environment.project')
                    ->orderByDesc('enabled')->orderBy('name')->orderBy('id')->limit(12)->get()],
                'objectives' => ['objectives' => ServiceLevelObjective::query()->forAccount($account)->where('enabled', true)->with('environment.project')
                    ->orderBy('name')->orderBy('id')->limit(8)->get()
                    ->map(fn (ServiceLevelObjective $objective): array => ['objective' => $objective, 'report' => $this->objectives->forObjective($objective)])->all()],
                'projects' => ['projects' => Project::query()->where('account_id', $account->id)
                    ->withCount('environments')->withMax('environments as last_received_at', 'telemetry_last_received_at')
                    ->orderBy('name')->orderBy('id')->limit(8)->get()],
                default => [],
            };
            $widgets[] = ['type' => $widget->type, 'label' => __(Dashboard::WIDGETS[$widget->type] ?? 'Widget'), 'data' => $data];
        }

        return $widgets;
    }
}
