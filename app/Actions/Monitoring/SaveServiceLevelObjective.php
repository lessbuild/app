<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use App\Services\Monitoring\MonitorChanges;
use App\Services\Monitoring\TelemetryRedactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveServiceLevelObjective
{
    /**
     * Create a new SaveServiceLevelObjective instance.
     *
     * Creates or changes an SLO.
     *
     * @param  TelemetryRedactor  $redactor  Redacts its name, service and route.
     * @param  MonitorChanges  $changes  Locks the configuration while it changes.
     */
    public function __construct(private readonly TelemetryRedactor $redactor, private readonly MonitorChanges $changes) {}

    /**
     * Create or change a service level objective: an availability or latency target over a rolling window.
     *
     * @param  Project  $project
     * @param  User  $actor
     * @param  array<string, mixed>  $data  validated by ServiceLevelObjectiveRequest
     * @param  ServiceLevelObjective|null  $objective
     * @return ServiceLevelObjective
     */
    public function handle(Project $project, User $actor, array $data, ?ServiceLevelObjective $objective = null): ServiceLevelObjective
    {
        return DB::transaction(function () use ($project, $actor, $data, $objective): ServiceLevelObjective {
            Gate::forUser($actor)->authorize($objective === null ? 'create' : 'update', $objective ?? [ServiceLevelObjective::class, $project]);
            $environment = $this->changes->lockScope($project, $actor, (string) $data['environment_id']);
            if ($objective !== null) {
                $objective = ServiceLevelObjective::query()->lockForUpdate()->findOrFail($objective->id);
                if ($objective->environment_id !== $environment->id) {
                    throw ValidationException::withMessages(['environment_id' => __('The environment can’t be changed. Create a separate objective.')]);
                }
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
}
