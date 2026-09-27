<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Exceptions\StateConflict;
use App\Models\AlertDestination;
use App\Models\AlertRule;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Monitoring\MonitorChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateAlertEscalations
{
    public function __construct(private readonly Entitlements $entitlements, private readonly MonitorChanges $changes, private readonly RecordAuditEntry $audit) {}

    /**
     * Replace a rule's escalation steps: destinations alerted later if its incident is still open.
     *
     * @param  array{version: int|string, escalations?: list<array{destination_id?: int|string|null, delay_minutes?: int|string|null}>}  $data
     */
    public function handle(AlertRule $rule, User $actor, array $data): void
    {
        DB::transaction(function () use ($rule, $actor, $data): void {
            $project = $rule->environment->project;
            Gate::forUser($actor)->authorize('update', $rule);
            $environment = $this->changes->lockScope($project, $actor, $rule->environment_id);
            $rule = AlertRule::query()->whereBelongsTo($environment)->lockForUpdate()->findOrFail($rule->id);
            StateConflict::unlessVersion($rule->state_version, (int) $data['version'], __('This alert rule changed. Refresh before trying again.'));

            $steps = collect($data['escalations'] ?? [])
                ->filter(fn (array $step): bool => filled($step['destination_id'] ?? null))
                ->values();
            $limit = $this->entitlements->for($project->account)->limit('monitoring.escalation_steps.max');
            if ($limit !== null && $steps->count() > $limit) {
                throw ValidationException::withMessages(['escalations' => $limit === 0
                    ? __('Escalation steps aren’t included in your Monitoring plan.')
                    : trans_choice('Your Monitoring plan allows :count escalation step.|Your Monitoring plan allows :count escalation steps.', $limit, ['count' => $limit])]);
            }

            $destinationIds = $steps->pluck('destination_id')->map(fn (int|string $id): int => (int) $id)->all();
            if (count($destinationIds) !== count(array_unique($destinationIds))) {
                throw ValidationException::withMessages(['escalations' => __('Each escalation step must use a different destination.')]);
            }

            $destinations = AlertDestination::query()->where('account_id', $project->account_id)->whereKey($destinationIds)->orderBy('id')->lockForUpdate()->get();
            if ($destinations->count() !== count($destinationIds)) {
                throw ValidationException::withMessages(['escalations' => 'Choose escalation destinations from this workspace.']);
            }

            $primaryDestinationIds = $rule->destinations()->pluck('alert_destinations.id')->all();
            if (array_intersect($destinationIds, $primaryDestinationIds) !== []) {
                throw ValidationException::withMessages(['escalations' => __('Use a destination that is not already in the primary routing policy.')]);
            }

            $delays = $steps->map(fn (array $step): int => (int) ($step['delay_minutes'] ?? 0))->all();
            if (count($delays) !== count(array_filter($delays, fn (int $delay): bool => $delay >= 1 && $delay <= 10080))) {
                throw ValidationException::withMessages(['escalations' => __('Each escalation delay must be between 1 minute and 7 days.')]);
            }
            if ($delays !== collect($delays)->sort()->values()->all()) {
                throw ValidationException::withMessages(['escalations' => __('Escalation delays must increase with each step.')]);
            }

            $rule->escalations()->delete();
            foreach ($steps as $position => $step) {
                $rule->escalations()->create([
                    'alert_destination_id' => (int) $step['destination_id'],
                    'delay_minutes' => (int) ($step['delay_minutes'] ?? 0),
                    'position' => $position,
                    'enabled' => true,
                ]);
            }
            $rule->forceFill(['state_version' => $rule->state_version + 1])->save();
            $this->audit->handle(AuditAction::AlertEscalationsUpdated, $actor, $project->account_id, [
                'rule' => $rule->name, 'project' => $project->name, 'steps' => $steps->count(),
            ], $project->id);
        }, attempts: 3);
    }
}
