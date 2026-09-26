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

final class ChangeAlertRouting
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /** @param array{version: int|string, destinations?: list<int|string>, opened: bool|int|string, recovered: bool|int|string} $data */
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

            $ids = array_values(array_unique(array_map('intval', $data['destinations'] ?? [])));
            sort($ids);
            $destinations = AlertDestination::forWorkspace($workspace)->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if (count($ids) > 5 || $destinations->count() !== count($ids)) {
                throw ValidationException::withMessages(['destinations' => 'Choose up to five destinations from this workspace.']);
            }
            if (array_intersect($ids, $rule->escalations()->pluck('alert_destination_id')->all()) !== []) {
                throw ValidationException::withMessages(['destinations' => 'A destination cannot be used in both primary routing and escalation steps.']);
            }
            if ($ids !== [] && ! $data['opened'] && ! $data['recovered']) {
                throw ValidationException::withMessages(['opened' => 'Choose at least one incident event.']);
            }
            $routes = $destinations->mapWithKeys(fn (AlertDestination $destination): array => [
                $destination->id => ['opened' => (bool) $data['opened'], 'recovered' => (bool) $data['recovered']],
            ])->all();
            $rule->destinations()->sync($routes);
            $rule->forceFill(['state_version' => $rule->state_version + 1])->save();
            $this->audit->record($workspace, $actor, 'alert_routing.updated', $rule, [
                'label' => $rule->name, 'destinations' => $ids, 'opened' => (bool) $data['opened'], 'recovered' => (bool) $data['recovered'],
            ]);
        }, attempts: 3);
    }
}
