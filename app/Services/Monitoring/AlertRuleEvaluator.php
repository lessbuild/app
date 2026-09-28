<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\AlertRule;
use App\Models\Environment;
use App\Models\Incident;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Evaluates due alert rules once a minute and opens or recovers their incidents. */
final class AlertRuleEvaluator
{
    /**
     * Evaluates alert rules.
     *
     * @param  AlertObservation  $observations  Measures each rule.
     * @param  IncidentLifecycle  $incidents  Closes incidents on recovery.
     * @param  AlertDispatcher  $deliveries  Queues alerts for opened and recovered incidents.
     * @param  MaintenanceWindowState  $maintenance  Holds back incidents during maintenance windows.
     */
    public function __construct(
        private readonly AlertObservation $observations,
        private readonly IncidentLifecycle $incidents,
        private readonly AlertDispatcher $deliveries,
        private readonly MaintenanceWindowState $maintenance,
    ) {}

    /**
     * Evaluates up to `$limit` due rules in projects with Monitoring on, and returns how many were evaluated.
     *
     * @param  int  $limit
     * @return int
     */
    public function evaluate(int $limit = 100): int
    {
        $now = CarbonImmutable::now('UTC');
        $candidates = AlertRule::query()->where('enabled', true)->where('next_evaluation_at', '<=', $now->format('Y-m-d H:i:s.u'))
            ->whereHas('environment.project.enabledServices', fn (Builder $query): Builder => $query->where('service', 'monitoring'))
            ->with('environment:id,project_id')
            ->orderBy('next_evaluation_at')->orderBy('id')->limit(max(1, min(1000, $limit)))->get(['id', 'environment_id']);

        return $candidates->filter(fn (AlertRule $candidate): bool => $this->evaluateOne($candidate, $now))->count();
    }

    /**
     * Evaluates one rule for the minute that just ended, under lock: during maintenance it only records that; otherwise
     * it counts consecutive breaches and recoveries, opens an incident (and alerts) after enough breaches and closes it
     * after enough recoveries.
     *
     * @param  AlertRule  $candidate
     * @param  CarbonImmutable  $now
     * @return bool
     */
    private function evaluateOne(AlertRule $candidate, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($candidate, $now): bool {
            $project = Project::query()->lockForUpdate()->find($candidate->environment->project_id);
            if ($project === null) {
                return false;
            }
            $environment = Environment::query()->whereBelongsTo($project)->lockForUpdate()->find($candidate->environment_id);
            if ($environment === null || ! $project->hasService('monitoring')) {
                return false;
            }
            $rule = AlertRule::query()->whereBelongsTo($environment)->lockForUpdate()->find($candidate->id);
            $until = $now->startOfMinute()->subMinute();
            if ($rule === null || ! $rule->enabled || $rule->next_evaluation_at->greaterThan($now) || $rule->evaluated_until?->greaterThanOrEqualTo($until)) {
                return false;
            }
            if ($this->maintenance->isActiveForAccountId($project->account_id, $now)) {
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
                    'account_id' => $project->account_id, 'project_id' => $project->id,
                    'alert_rule_id' => $rule->id, 'active_slot' => true, 'title' => $rule->name, 'status' => 'open',
                    'rule_snapshot' => $rule->snapshot(), 'opening_observation' => $observation, 'latest_observation' => $observation,
                    'opened_at' => $now, 'last_breached_at' => $now,
                ])->save();
                $incident->activities()->create(['action' => 'opened', 'metadata' => ['observation' => $observation]]);
                $this->deliveries->record($incident, 'opened');
            }

            return true;
        }, attempts: 3);
    }
}
