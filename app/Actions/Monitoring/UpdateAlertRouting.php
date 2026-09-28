<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Exceptions\StateConflict;
use App\Models\AlertDestination;
use App\Models\AlertRule;
use App\Models\User;
use App\Services\Monitoring\MonitorChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateAlertRouting
{
    /**
     * Changes which destinations a monitor or rule alerts.
     *
     * @param  MonitorChanges  $changes  Locks the account's Monitoring configuration while it changes.
     * @param  RecordAuditEntry  $audit  Records the change.
     */
    public function __construct(private readonly MonitorChanges $changes, private readonly RecordAuditEntry $audit) {}

    /**
     * Choose which destinations hear about a rule's incidents, and for which events.
     *
     * @param  AlertRule  $rule
     * @param  User  $actor
     * @param  array{version: int|string, destinations?: list<int|string>, opened: bool|int|string, recovered: bool|int|string}  $data
     * @return void
     */
    public function handle(AlertRule $rule, User $actor, array $data): void
    {
        DB::transaction(function () use ($rule, $actor, $data): void {
            $project = $rule->environment->project;
            Gate::forUser($actor)->authorize('update', $rule);
            $environment = $this->changes->lockScope($project, $actor, $rule->environment_id);
            $rule = AlertRule::query()->whereBelongsTo($environment)->lockForUpdate()->findOrFail($rule->id);
            StateConflict::unlessVersion($rule->state_version, (int) $data['version'], __('This alert rule changed. Refresh before trying again.'));

            $ids = array_values(array_unique(array_map('intval', $data['destinations'] ?? [])));
            sort($ids);
            $destinations = AlertDestination::query()->where('account_id', $project->account_id)->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if (count($ids) > 5 || $destinations->count() !== count($ids)) {
                throw ValidationException::withMessages(['destinations' => 'Choose up to five destinations from this workspace.']);
            }
            if (array_intersect($ids, $rule->escalations()->pluck('alert_destination_id')->all()) !== []) {
                throw ValidationException::withMessages(['destinations' => __('A destination can’t be in both the routing and the escalation steps.')]);
            }
            if ($ids !== [] && ! $data['opened'] && ! $data['recovered']) {
                throw ValidationException::withMessages(['opened' => __('Choose at least one incident event.')]);
            }
            $routes = $destinations->mapWithKeys(fn (AlertDestination $destination): array => [
                $destination->id => ['opened' => (bool) $data['opened'], 'recovered' => (bool) $data['recovered']],
            ])->all();
            $rule->destinations()->sync($routes);
            $rule->forceFill(['state_version' => $rule->state_version + 1])->save();
            $this->audit->handle(AuditAction::AlertRoutingUpdated, $actor, $project->account_id, [
                'rule' => $rule->name, 'project' => $project->name, 'destinations' => count($ids),
            ], $project->id);
        }, attempts: 3);
    }
}
