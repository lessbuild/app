<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\StatusPage;
use App\Modules\Monitor\Models\StatusPageComponent;
use Illuminate\Support\Collection;

final class StatusPageReport
{
    public function __construct(
        private readonly StatusPageHistory $history,
        private readonly StatusPageIncidentHistory $incidentHistory,
    ) {}

    /**
     * @return array{overall: string, overallLabel: string, components: Collection<int, array<string, mixed>>, incidents: Collection<int, Incident>}
     */
    public function report(StatusPage $statusPage): array
    {
        $statusPage->loadMissing(['workspace', 'components.monitor.environment']);
        $monitorIds = $statusPage->components->pluck('monitor_id')->filter()->values();
        $history = $this->history->forMonitors($statusPage->components->pluck('monitor')->filter());
        $incidents = $this->incidentHistory->forStatusPage($statusPage, $monitorIds);
        $activeIncidents = $incidents['active']->groupBy('monitor_id');

        $components = $statusPage->components->map(function (StatusPageComponent $component) use ($activeIncidents, $history): array {
            $state = $this->state($component->monitor);

            return [
                'name' => $component->label,
                'type' => $component->monitor?->typeLabel(),
                'state' => $state,
                'stateLabel' => $this->label($state),
                'checkedAt' => $component->monitor?->checked_at,
                'incidents' => $activeIncidents->get($component->monitor_id, collect())->values(),
                'history' => $history->get($component->monitor_id),
            ];
        });
        $overall = $components->contains(fn (array $component): bool => $component['state'] === 'major_outage')
            ? 'major_outage'
            : ($components->contains(fn (array $component): bool => $component['state'] === 'degraded') ? 'degraded' : 'operational');

        return [
            'overall' => $overall,
            'overallLabel' => $this->label($overall),
            'components' => $components,
            'incidents' => $components->flatMap(fn (array $component): Collection => $component['incidents'])->unique('id')->values(),
            'recentIncidents' => $incidents['resolved'],
        ];
    }

    private function state(?Monitor $monitor): string
    {
        if ($monitor === null || $monitor->trashed()) {
            return 'degraded';
        }

        return match (mb_strtolower($monitor->healthLabel())) {
            'down' => 'major_outage',
            'up' => 'operational',
            default => 'degraded',
        };
    }

    private function label(string $state): string
    {
        return match ($state) {
            'major_outage' => 'Major outage',
            'degraded' => 'Degraded performance',
            default => 'All systems operational',
        };
    }
}
