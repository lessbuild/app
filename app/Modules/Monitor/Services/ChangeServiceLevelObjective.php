<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\ServiceLevelObjective;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ChangeServiceLevelObjective
{
    public function __construct(private readonly TelemetryRedactor $redactor) {}

    /** @param array<string, mixed> $data */
    public function save(Workspace $workspace, User $actor, array $data, ?ServiceLevelObjective $objective = null): ServiceLevelObjective
    {
        return DB::connection('monitor')->transaction(function () use ($workspace, $actor, $data, $objective): ServiceLevelObjective {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::forUser($actor)->authorize('update', $workspace);
            abort_unless($actor->hasVerifiedEmail(), 403);
            $environment = Environment::forWorkspace($workspace)->findOrFail((int) $data['environment_id']);
            $application = Application::query()->whereBelongsTo($workspace)->lockForUpdate()->findOrFail($environment->application_id);
            $environment = Environment::query()->whereBelongsTo($application)->lockForUpdate()->findOrFail($environment->id);
            $isNew = $objective === null;
            if (! $isNew) {
                $objective = ServiceLevelObjective::forWorkspace($workspace)->lockForUpdate()->findOrFail($objective->id);
                abort_unless($objective->environment_id === $environment->id, 422, 'The environment cannot be changed. Create a separate objective.');
            }
            $objective ??= new ServiceLevelObjective;
            $redacted = $this->redactor->redact([
                'name' => $data['name'],
                'service' => $data['service'] ?? null,
                'route' => $data['route'] ?? null,
            ]);
            $objective->forceFill([
                'environment_id' => $environment->id,
                'name' => $redacted['name'],
                'indicator' => $data['indicator'],
                'service' => filled($redacted['service']) ? $redacted['service'] : null,
                'route' => filled($redacted['route']) ? $redacted['route'] : null,
                'target' => (float) $data['target'],
                'window_days' => (int) $data['window_days'],
                'latency_threshold_ms' => $data['indicator'] === 'latency' ? (float) $data['latency_threshold_ms'] : null,
                'status_min' => $data['indicator'] === 'availability' ? (int) $data['status_min'] : 200,
                'status_max' => $data['indicator'] === 'availability' ? (int) $data['status_max'] : 399,
                'enabled' => (bool) $data['enabled'],
            ])->save();

            return $objective;
        }, attempts: 3);
    }

    public function archive(ServiceLevelObjective $objective, Workspace $workspace, User $actor): void
    {
        DB::connection('monitor')->transaction(function () use ($objective, $workspace, $actor): void {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::forUser($actor)->authorize('update', $workspace);
            abort_unless($actor->hasVerifiedEmail(), 403);
            $objective = ServiceLevelObjective::forWorkspace($workspace)->lockForUpdate()->findOrFail($objective->id);
            $objective->delete();
        }, attempts: 3);
    }
}
