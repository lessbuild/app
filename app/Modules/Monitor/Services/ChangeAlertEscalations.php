<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChangeAlertEscalations
{
    public function __construct(private readonly WorkspacePlanLimits $limits, private readonly RecordAuditLog $audit) {}

    /** @param array{version: int|string, escalations?: list<array{destination_id?: int|string|null, delay_minutes?: int|string|null}>} $data */
    public function update(Workspace $workspace, User $actor, AlertRule $rule, array $data): void
    {
        DB::connection('monitor')->transaction(function () use ($workspace, $actor, $rule, $data): void {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::forUser($actor)->authorize('update', $workspace);
            $environment = Environment::forWorkspace($workspace)->visibleTo($actor, $workspace)->findOrFail($rule->environment_id);
            $application = Application::query()->whereBelongsTo($workspace)->lockForUpdate()->findOrFail($environment->application_id);
            $environment = Environment::query()->whereBelongsTo($application)->lockForUpdate()->findOrFail($environment->id);
            Gate::forUser($actor)->authorize('update', $environment);
            $rule = AlertRule::query()->whereBelongsTo($environment)->lockForUpdate()->findOrFail($rule->id);
            abort_unless($rule->state_version === (int) $data['version'], 409, 'This alert rule changed. Refresh before trying again.');

            $steps = collect($data['escalations'] ?? [])
                ->filter(fn (array $step): bool => filled($step['destination_id'] ?? null))
                ->values();
            $this->limits->assertEscalationCapacity($workspace, $steps->count());

            $destinationIds = $steps->pluck('destination_id')->map(fn (int|string $id): int => (int) $id)->all();
            if (count($destinationIds) !== count(array_unique($destinationIds))) {
                throw ValidationException::withMessages(['escalations' => 'Each escalation step must use a different destination.']);
            }

            $destinations = AlertDestination::forWorkspace($workspace)->whereKey($destinationIds)->orderBy('id')->lockForUpdate()->get();
            if ($destinations->count() !== count($destinationIds)) {
                throw ValidationException::withMessages(['escalations' => 'Choose escalation destinations from this workspace.']);
            }

            $primaryDestinationIds = $rule->destinations()->pluck('alert_destinations.id')->all();
            if (array_intersect($destinationIds, $primaryDestinationIds) !== []) {
                throw ValidationException::withMessages(['escalations' => 'Use a destination that is not already in the primary routing policy.']);
            }

            $delays = $steps->map(fn (array $step): int => (int) ($step['delay_minutes'] ?? 0))->all();
            if (count($delays) !== count(array_filter($delays, fn (int $delay): bool => $delay >= 1 && $delay <= 10080))) {
                throw ValidationException::withMessages(['escalations' => 'Each escalation delay must be between 1 minute and 7 days.']);
            }
            if ($delays !== collect($delays)->sort()->values()->all()) {
                throw ValidationException::withMessages(['escalations' => 'Escalation delays must increase with each step.']);
            }

            $rule->escalations()->delete();
            foreach ($steps as $position => $step) {
                $rule->escalations()->create([
                    'alert_destination_id' => (int) $step['destination_id'],
                    'delay_minutes' => (int) $step['delay_minutes'],
                    'position' => $position,
                    'enabled' => true,
                ]);
            }
            $rule->forceFill(['state_version' => $rule->state_version + 1])->save();
            $this->audit->record($workspace, $actor, 'alert_escalations.updated', $rule, [
                'label' => $rule->name,
                'steps' => $steps->map(fn (array $step): array => ['destination_id' => (int) $step['destination_id'], 'delay_minutes' => (int) $step['delay_minutes']])->all(),
            ]);
        }, attempts: 3);
    }
}
