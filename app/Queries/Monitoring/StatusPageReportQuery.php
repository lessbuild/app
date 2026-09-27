<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageComponent;
use App\Models\StatusUpdate;
use App\Services\Monitoring\UptimeHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * What a status page shows: each component's state and 30-day history, open monitor incidents,
 * and the team's status updates. Archived monitors are left off.
 *
 * @phpstan-import-type History from UptimeHistory
 *
 * @phpstan-type Component array{name: string, type: string, state: string, stateLabel: string, checkedAt: ?CarbonImmutable, incidents: list<Incident>, history: ?History}
 * @phpstan-type Report array{overall: string, overallLabel: string, components: list<Component>, incidents: list<Incident>, recentIncidents: list<Incident>, activeUpdates: list<StatusUpdate>, upcomingMaintenance: list<StatusUpdate>, pastUpdates: list<StatusUpdate>}
 */
final class StatusPageReportQuery
{
    public const HISTORY_DAYS = 30;

    /**
     * Builds what a public status page shows.
     *
     * @param  UptimeHistory  $history  Daily uptime for each component's monitor.
     */
    public function __construct(private readonly UptimeHistory $history) {}

    /**
     * The page's overall state, each component's state and 30-day history, open and recent incidents, and its posted
     * updates split into active, upcoming maintenance and past. Archived monitors are left off.
     *
     * @return Report
     */
    public function handle(StatusPage $page, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $page->loadMissing(['components.monitor']);
        $components = $page->components->filter(fn (StatusPageComponent $component): bool => $component->monitor !== null && ! $component->monitor->trashed())->values();
        $monitors = $components->map(fn (StatusPageComponent $component): Monitor => $component->monitor ?? throw new LogicException('Filtered above.'));
        $histories = $this->history->forMonitors($monitors, $now);
        $incidents = $this->incidents($page, array_values(array_map('intval', $monitors->modelKeys())), $now);
        $open = $incidents->filter(fn (Incident $incident): bool => $incident->status !== 'resolved');

        $rows = [];
        foreach ($components as $component) {
            $monitor = $component->monitor ?? throw new LogicException('Filtered above.');
            $state = match ($monitor->healthLabel()) {
                'Down' => 'major_outage',
                'Up' => 'operational',
                default => 'degraded',
            };
            $rows[] = [
                'name' => $component->label,
                'type' => (string) __($monitor->typeLabel()),
                'state' => $state,
                'stateLabel' => $this->label($state),
                'checkedAt' => $monitor->checked_at,
                'incidents' => array_values($open->where('monitor_id', $monitor->id)->all()),
                'history' => $histories[$monitor->id] ?? null,
            ];
        }

        $updates = $page->updates()->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', $now->subDays(self::HISTORY_DAYS)))
            ->orderByDesc('starts_at')->orderByDesc('id')->limit(50)->get();
        $activeUpdates = $updates->filter(fn (StatusUpdate $update): bool => ! $update->isClosed() && ($update->kind === 'incident' || $update->status === 'in_progress'))->values();
        $upcoming = $updates->filter(fn (StatusUpdate $update): bool => $update->kind === 'maintenance' && $update->status === 'scheduled')->sortBy('starts_at')->values();
        $overall = $this->overall($rows, $activeUpdates);

        return [
            'overall' => $overall,
            'overallLabel' => $this->label($overall),
            'components' => $rows,
            'incidents' => array_values($open->all()),
            'recentIncidents' => array_values($incidents->where('status', 'resolved')->all()),
            'activeUpdates' => array_values($activeUpdates->all()),
            'upcomingMaintenance' => array_values($upcoming->all()),
            'pastUpdates' => array_values($updates->filter(fn (StatusUpdate $update): bool => $update->isClosed())->all()),
        ];
    }

    /**
     * The words shown for a state.
     */
    public function label(string $state): string
    {
        return match ($state) {
            'major_outage' => __('Major outage'),
            'degraded' => __('Degraded performance'),
            'maintenance' => __('Under maintenance'),
            default => __('All systems operational'),
        };
    }

    /**
     * Open incidents, and ones resolved in the last 30 days, for the page's monitors.
     *
     * @param  list<int>  $monitorIds
     * @return Collection<int, Incident>
     */
    private function incidents(StatusPage $page, array $monitorIds, CarbonImmutable $now): Collection
    {
        if ($monitorIds === []) {
            return new Collection;
        }

        return Incident::query()->where('account_id', $page->account_id)->whereIn('monitor_id', $monitorIds)
            ->where(fn ($query) => $query->where('status', '!=', 'resolved')->orWhere('resolved_at', '>=', $now->subDays(self::HISTORY_DAYS)))
            ->orderByDesc('opened_at')->orderByDesc('id')->limit(50)
            ->get(['id', 'monitor_id', 'title', 'status', 'opened_at', 'resolved_at']);
    }

    /**
     * The page's headline state: a major outage when a component is down or a critical incident is posted, degraded for
     * any other trouble, maintenance while maintenance is in progress, operational otherwise.
     *
     * @param  list<array{state: string}>  $components
     * @param  \Illuminate\Support\Collection<int, StatusUpdate>  $activeUpdates
     */
    private function overall(array $components, \Illuminate\Support\Collection $activeUpdates): string
    {
        $states = array_column($components, 'state');
        $incidents = $activeUpdates->where('kind', 'incident');

        return match (true) {
            in_array('major_outage', $states, true) || $incidents->contains('severity', 'critical') => 'major_outage',
            in_array('degraded', $states, true) || $incidents->isNotEmpty() => 'degraded',
            $activeUpdates->contains('kind', 'maintenance') => 'maintenance',
            default => 'operational',
        };
    }
}
