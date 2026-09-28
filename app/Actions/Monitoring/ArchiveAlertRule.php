<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Exceptions\StateConflict;
use App\Models\AlertRule;
use App\Models\User;
use App\Services\Monitoring\IncidentLifecycle;
use App\Services\Monitoring\MonitorChanges;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ArchiveAlertRule
{
    /**
     * Archives an alert rule.
     *
     * @param  IncidentLifecycle  $lifecycle  Closes the rule's open incidents.
     * @param  MonitorChanges  $changes  Locks the configuration while it changes.
     * @param  RecordAuditEntry  $audit  Records it.
     */
    public function __construct(
        private readonly IncidentLifecycle $lifecycle,
        private readonly MonitorChanges $changes,
        private readonly RecordAuditEntry $audit,
    ) {}

    /**
     * Stop evaluating a rule and archive it; an open incident closes as "rule archived".
     *
     * @param  AlertRule  $rule
     * @param  User  $actor
     * @param  int  $version
     * @return void
     */
    public function handle(AlertRule $rule, User $actor, int $version): void
    {
        DB::transaction(function () use ($rule, $actor, $version): void {
            $project = $rule->environment->project;
            Gate::forUser($actor)->authorize('delete', $rule);
            $environment = $this->changes->lockScope($project, $actor, $rule->environment_id);
            $rule = AlertRule::query()->whereBelongsTo($environment)->lockForUpdate()->findOrFail($rule->id);
            StateConflict::unlessVersion($rule->state_version, $version, __('This alert rule changed. Refresh before trying again.'));
            $incident = $rule->incidents()->where('active_slot', true)->lockForUpdate()->first();
            if ($incident !== null) {
                $this->lifecycle->close($incident, 'rule_archived', CarbonImmutable::now('UTC'), $actor);
            }
            $rule->forceFill(['enabled' => false, 'state_version' => $rule->state_version + 1])->save();
            $rule->delete();
            $this->audit->handle(AuditAction::AlertRuleArchived, $actor, $project->account_id, [
                'rule' => $rule->name, 'project' => $project->name, 'metric' => $rule->metric->value, 'environment' => $environment->name,
            ], $project->id);
        }, attempts: 3);
    }
}
