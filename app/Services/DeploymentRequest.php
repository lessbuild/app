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
    /**
     * Bind environment resolution and deployment risk assessment.
     *
     * @param  DeploymentEnvironmentResolver  $environments  Selects the repository's deployment environment.
     * @param  DeploymentPreflight  $preflight  Assesses the repository and environment before building.
     */
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

    /**
     * Capture approval status, preflight results and environment data for a new build.
     *
     * @param  Repository  $repository  The source repository and website for the deployment.
     * @param  Environment|null  $environment  The explicitly selected environment, or null for a website-only deployment.
     * @param  User|null  $requester  The account attributed to the request, if available.
     * @return array<string, mixed> Build attributes including a snapshot of variables, runtime, enabled processes and resources.
     */
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
                'base_environment' => (string) $repository->website?->environment,
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
                    'is_managed' => $resource->is_managed,
                    'configuration' => $resource->configuration,
                ])->values()->all(),
            ] : null,
        ];
    }

    /**
     * Wake a hibernated environment and enqueue publication for a queued build.
     *
     * @param  Build  $build  The persisted build whose status controls publication.
     * @return void No value; awakening and publication use their respective queued jobs.
     */
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
