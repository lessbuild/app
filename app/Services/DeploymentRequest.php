<?php

namespace App\Services;

use App\Jobs\ApplyEnvironmentRuntimeStateJob;
use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Environment;
use App\Models\Repository;
use App\Models\User;

class DeploymentRequest
{
    public function __construct(
        private readonly DeploymentEnvironmentResolver $environments,
        private readonly DeploymentPreflight $preflight,
    ) {}

    /** @return array{status: string, environment_id: ?int, requested_by: ?int, environment_payload: ?array} */
    public function attributes(Repository $repository, ?User $requester = null): array
    {
        $environment = $this->environments->for($repository);

        return $this->attributesForEnvironment($repository, $environment, $requester);
    }

    public function attributesForEnvironment(Repository $repository, ?Environment $environment, ?User $requester = null): array
    {
        $environment?->loadMissing(['variables', 'processes', 'resources']);

        return [
            'status' => $environment?->requires_deployment_approval
                ? Build::STATUS_AWAITING_APPROVAL
                : Build::STATUS_QUEUED,
            'environment_id' => $environment?->id,
            'requested_by' => $requester?->id,
            'risk_assessment' => $this->preflight->assess($repository, $environment),
            'environment_payload' => $environment ? [
                'runtime' => [
                    'minimum_replicas' => $environment->minimum_replicas,
                    'maximum_replicas' => $environment->maximum_replicas,
                    'desired_replicas' => $environment->desired_replicas,
                    'hibernate_after_minutes' => $environment->hibernate_after_minutes,
                    'deployment_strategy' => $environment->deployment_strategy,
                    'rolling_pause_seconds' => $environment->rolling_pause_seconds,
                    'type' => $environment->runtime_type ?: 'php',
                    'version' => $environment->runtime_version,
                    'build_command' => $environment->build_command,
                    'start_command' => $environment->start_command,
                    'container_port' => $environment->container_port,
                    'dockerfile_path' => $environment->dockerfile_path,
                ],
                'variables' => $environment->variables
                    ->whereIn('scope', ['runtime', 'all'])
                    ->mapWithKeys(fn ($variable) => [$variable->key => $variable->value])->all(),
                'build_variables' => $environment->variables
                    ->whereIn('scope', ['build', 'all'])
                    ->mapWithKeys(fn ($variable) => [$variable->key => $variable->value])->all(),
                'processes' => $environment->processes->where('is_enabled', true)->map(fn ($process) => [
                    'name' => $process->name,
                    'type' => $process->type,
                    'command' => $process->command,
                    'replicas' => $process->replicas,
                    'restart_policy' => $process->restart_policy,
                    'restart_delay_seconds' => $process->restart_delay_seconds,
                ])->values()->all(),
                'resources' => $environment->resources->map(fn ($resource) => [
                    'name' => $resource->name,
                    'type' => $resource->type,
                    'configuration' => $resource->configuration,
                ])->values()->all(),
            ] : null,
        ];
    }

    public function dispatch(Build $build): void
    {
        if ($build->environment?->hibernated_at) {
            $build->environment->update(['hibernated_at' => null, 'last_activity_at' => now()]);
            ApplyEnvironmentRuntimeStateJob::dispatch($build->environment_id, false);
        }
        if ($build->status === Build::STATUS_QUEUED) {
            PublishRepositoryJob::dispatch($build);
        }
    }
}
