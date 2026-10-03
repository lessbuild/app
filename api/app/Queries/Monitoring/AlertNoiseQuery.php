<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Models\AlertDelivery;
use App\Models\Incident;
use App\Models\Project;
use Carbon\CarbonImmutable;

/** Which alerts are noisy: how often each rule and monitor fired, how often it flapped, and how often anyone acted. */
final class AlertNoiseQuery
{
    /**
     * How soon after opening an incident that recovers without anyone acknowledging it counts as a flap, in minutes.
     *
     * @var int
     */
    public const FLAP_MINUTES = 5;

    /**
     * Rank the project's alert rules and monitors by how many incidents they opened over the last days, with how many
     * flapped (recovered within five minutes, unacknowledged), the share nobody acknowledged, how many notifications
     * went out, the median time open, and a suggestion for tuning the noisy ones.
     *
     * @param  Project  $project
     * @param  int  $days
     * @return list<array{kind: string, id: int, name: string, fired: int, flapped: int, unacknowledged: int, unacknowledged_share: int, notifications: int, median_minutes: int|null, suggestion: string|null}>
     */
    public function handle(Project $project, int $days = 30): array
    {
        $since = CarbonImmutable::now()->subDays($days);
        $incidents = Incident::query()->where('project_id', $project->id)->where('opened_at', '>=', $since)
            ->with(['alertRule:id,name', 'monitor:id,name'])
            ->get(['id', 'alert_rule_id', 'monitor_id', 'title', 'opened_at', 'acknowledged_at', 'resolved_at', 'closure_reason']);
        $notifications = AlertDelivery::query()->whereIn('incident_id', $incidents->pluck('id'))->where('event', 'opened')
            ->toBase()->selectRaw('incident_id, COUNT(*) AS sent')->groupBy('incident_id')->pluck('sent', 'incident_id');

        $groups = [];
        foreach ($incidents as $incident) {
            $kind = $incident->alert_rule_id !== null ? 'rule' : 'monitor';
            $id = (int) ($incident->alert_rule_id ?? $incident->monitor_id);
            $key = $kind.':'.$id;
            $groups[$key] ??= ['kind' => $kind, 'id' => $id, 'name' => (string) ($incident->alertRule->name ?? $incident->monitor->name ?? $incident->title), 'incidents' => []];
            $groups[$key]['incidents'][] = $incident;
        }

        $rows = [];
        foreach ($groups as $group) {
            $fired = count($group['incidents']);
            $flapped = 0;
            $unacknowledged = 0;
            $sent = 0;
            $durations = [];
            foreach ($group['incidents'] as $incident) {
                $sent += (int) ($notifications[$incident->id] ?? 0);
                if ($incident->acknowledged_at === null) {
                    $unacknowledged++;
                }
                if ($incident->resolved_at !== null) {
                    $minutes = (int) $incident->opened_at->diffInMinutes($incident->resolved_at);
                    $durations[] = $minutes;
                    if ($incident->acknowledged_at === null && $incident->closure_reason === 'recovered' && $minutes < self::FLAP_MINUTES) {
                        $flapped++;
                    }
                }
            }
            sort($durations);
            $share = (int) round($unacknowledged / $fired * 100);
            $rows[] = [
                'kind' => $group['kind'], 'id' => $group['id'], 'name' => $group['name'],
                'fired' => $fired, 'flapped' => $flapped, 'unacknowledged' => $unacknowledged, 'unacknowledged_share' => $share,
                'notifications' => $sent,
                'median_minutes' => $durations === [] ? null : $durations[intdiv(count($durations), 2)],
                'suggestion' => $this->suggestion($group['kind'], $fired, $flapped, $share, $days),
            ];
        }
        usort($rows, fn (array $a, array $b): int => [$b['fired'], $b['flapped']] <=> [$a['fired'], $a['flapped']]);

        return $rows;
    }

    /**
     * Suggest how to quiet a noisy alert, or null when it looks fine.
     *
     * @param  string  $kind  rule or monitor
     * @param  int  $fired
     * @param  int  $flapped
     * @param  int  $unacknowledgedShare  percent
     * @param  int  $days
     * @return string|null
     */
    private function suggestion(string $kind, int $fired, int $flapped, int $unacknowledgedShare, int $days): ?string
    {
        if ($fired >= 3 && $flapped * 2 >= $fired) {
            return $kind === 'rule'
                ? (string) __('It mostly flaps: lengthen its window or raise its threshold.')
                : (string) __('It mostly flaps: confirm from more regions or check less often before alerting.');
        }
        if ($fired >= 5 && $unacknowledgedShare >= 80) {
            return (string) __('Nobody acts on it: send it somewhere quieter, or archive it.');
        }
        if ($fired >= $days) {
            return (string) __('It fires about daily: fix the cause, or schedule maintenance for known work.');
        }

        return null;
    }
}
