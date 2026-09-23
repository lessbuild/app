<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Services\Connections\RecordProjectConnectionIncidentOutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EvaluateAlertRules
{
    public function __construct(
        private readonly AlertObservation $observations,
        private readonly ChangeIncident $incidents,
        private readonly RecordAlertDeliveries $deliveries,
        private readonly RecordProjectConnectionIncidentOutboxEvent $connectionEvents,
        private readonly MaintenanceWindowState $maintenance,
    ) {}

    public function evaluate(int $limit = 100): int
    {
        $now = CarbonImmutable::now('UTC');
        $candidates = AlertRule::query()->where('enabled', true)->where('next_evaluation_at', '<=', $now->format('Y-m-d H:i:s.u'))
            ->whereHas('environment', fn (Builder $query): Builder => $query->where('status', 'active')->whereHas('application'))
            ->with('environment:id,application_id')
            ->orderBy('next_evaluation_at')->orderBy('id')->limit(max(1, min(1000, $limit)))->get(['id', 'environment_id']);

        return $candidates->filter(fn (AlertRule $candidate): bool => $this->evaluateOne($candidate, $now))->count();
    }

    private function evaluateOne(AlertRule $candidate, CarbonImmutable $now): bool
    {
        if ($candidate->environment === null) {
            return false;
        }

        return DB::connection('monitor')->transaction(function () use ($candidate, $now): bool {
            $application = Application::query()->lockForUpdate()->find($candidate->environment->application_id);
            if ($application === null) {
                return false;
            }
            $environment = Environment::query()->whereBelongsTo($application)->lockForUpdate()->find($candidate->environment_id);
            if ($environment === null || $environment->status !== 'active') {
                return false;
            }
            $rule = AlertRule::query()->whereBelongsTo($environment)->lockForUpdate()->find($candidate->id);
            $until = $now->startOfMinute()->subMinute();
            if ($rule === null || ! $rule->enabled || $rule->next_evaluation_at->greaterThan($now) || $rule->evaluated_until?->greaterThanOrEqualTo($until)) {
                return false;
            }
            if ($this->maintenance->isActiveForWorkspaceId($application->workspace_id, $now)) {
                $window = ['from' => $until->subMinutes($rule->window_minutes)->toISOString(), 'until' => $until->toISOString()];
                $rule->forceFill([
                    'observation' => ['state' => 'maintenance', 'value' => null, 'samples' => 0, ...$window],
                    'evaluation_state' => 'maintenance', 'breach_streak' => 0, 'recovery_streak' => 0,
                    'evaluated_until' => $until, 'checked_at' => $now, 'next_evaluation_at' => $now->startOfMinute()->addMinute(),
                ])->save();

                return true;
            }
            $rule->load('metricSeries:id,name,resource_label');

            $observation = $this->observations->measure($rule, $until);
            $consecutive = $rule->evaluated_until?->addMinute()->equalTo($until) ?? false;
            $breaches = $observation['state'] === 'breaching' ? min($rule->trigger_checks, ($consecutive ? $rule->breach_streak : 0) + 1) : 0;
            $recoveries = $observation['state'] === 'healthy' ? min($rule->recovery_checks, ($consecutive ? $rule->recovery_streak : 0) + 1) : 0;
            $rule->forceFill([
                'observation' => $observation, 'evaluation_state' => $observation['state'],
                'breach_streak' => $breaches, 'recovery_streak' => $recoveries,
                'evaluated_until' => $until, 'checked_at' => $now, 'next_evaluation_at' => $now->startOfMinute()->addMinute(),
            ])->save();

            $incident = $rule->incidents()->where('active_slot', true)->lockForUpdate()->first();
            if ($incident !== null) {
                $incident->forceFill(['latest_observation' => $observation]);
                if ($observation['state'] === 'breaching') {
                    $incident->forceFill(['last_breached_at' => $now]);
                }
                $incident->save();
                if ($recoveries >= $rule->recovery_checks) {
                    $this->incidents->close($incident, 'recovered', $now);
                }
            } elseif ($breaches >= $rule->trigger_checks) {
                $incident = new Incident;
                $incident->forceFill([
                    'alert_rule_id' => $rule->id, 'active_slot' => true, 'title' => $rule->name, 'status' => 'open',
                    'rule_snapshot' => $rule->snapshot(), 'opening_observation' => $observation, 'latest_observation' => $observation,
                    'opened_at' => $now, 'last_breached_at' => $now,
                ])->save();
                $incident->activities()->create(['action' => 'opened', 'metadata' => ['observation' => $observation]]);
                $this->deliveries->record($incident, 'opened');
                $this->connectionEvents->record($incident, 'opened');
            }

            return true;
        }, attempts: 3);
    }
}
