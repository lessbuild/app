<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\AlertDestination;
use App\Models\AlertRule;
use App\Models\User;
use App\Services\Monitoring\MonitorChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateAlertRouting
{
    public function __construct(private readonly MonitorChanges $changes, private readonly RecordAuditEntry $audit) {}

    /**
     * Choose which destinations hear about a rule's incidents, and for which events.
     *
     * @param  array{version: int|string, destinations?: list<int|string>, opened: bool|int|string, recovered: bool|int|string}  $data
     */
    public function handle(AlertRule $rule, User $actor, array $data): void
    {
        DB::transaction(function () use ($rule, $actor, $data): void {
            $project = $rule->environment->project;
            $environment = $this->changes->lockScope($project, $actor, $rule->environment_id);
            $rule = AlertRule::query()->whereBelongsTo($environment)->lockForUpdate()->findOrFail($rule->id);
            abort_unless($rule->state_version === (int) $data['version'], 409, __('This alert rule changed. Refresh before trying again.'));

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
