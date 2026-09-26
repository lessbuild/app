<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Data\DeploymentObservationConfiguration;
use App\Modules\Deployer\Jobs\ApplyEnvironmentRuntimeStateJob;
use App\Modules\Deployer\Jobs\Repository\PublishRepositoryJob;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\User;

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
     * @return array<string, mixed> Build attributes including a snapshot of variables, runtime, enabled processes, resources and non-default service roots.
     */
    public function attributesForEnvironment(Repository $repository, ?Environment $environment, ?User $requester = null): array
    {
        $repository->loadMissing('website');
        $environment?->loadMissing(['variables', 'processes', 'resources']);
        $payload = $environment ? [
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
        ] : null;
        if ($repository->deploymentRoot() !== '.') {
            $payload ??= [];
            $payload['repository_root'] = $repository->deploymentRoot();
        }

        $observation = $this->observationConfiguration($repository, $environment);
        if ($observation) {
            $payload ??= [];
            $payload['post_deployment_observation'] = $observation->toArray();
        }

        return [
            'status' => $environment?->requires_deployment_approval
                ? Build::STATUS_AWAITING_APPROVAL
                : Build::STATUS_QUEUED,
            'environment_id' => $environment?->id,
            'requested_by' => $requester?->id,
            'risk_assessment' => $this->preflight->assess($repository, $environment),
            'environment_payload' => $payload,
        ];
    }

    /**
     * Capture only a valid, opt-in target for the post-deployment observation window.
     *
     * @param  Repository  $repository  Repository whose website is the remote deployment target.
     * @param  Environment|null  $environment  Environment supplying the observation preference.
     * @return DeploymentObservationConfiguration|null The non-secret target snapshot, or null when disabled/incomplete.
     */
    private function observationConfiguration(Repository $repository, ?Environment $environment): ?DeploymentObservationConfiguration
    {
        $website = $repository->website;
        $duration = $environment?->post_deployment_observation_minutes;

        if (! $environment || ! $website || is_null($duration)
            || ! in_array((int) $duration, Environment::POST_DEPLOYMENT_OBSERVATION_MINUTES, true)
            || (int) $environment->website_id !== (int) $website->id
            || ! $website->server_id
            || blank($website->url)
            || blank($website->health_check_path)) {
            return null;
        }

        return new DeploymentObservationConfiguration(
            durationMinutes: (int) $duration,
            websiteId: (int) $website->id,
            serverId: (int) $website->server_id,
            websiteUrl: (string) $website->url,
            healthCheckPath: (string) $website->health_check_path,
        );
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
