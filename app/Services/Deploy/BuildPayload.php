<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\EnvironmentProcess;
use App\Models\EnvironmentResource;
use App\Models\EnvironmentVariable;
use App\Models\Repository;

/**
 * What a build deploys with, captured (encrypted) when it's queued so later edits don't change a queued or retried build:
 * the website's `.env`, the repository subdirectory, and, with an environment, its runtime, variables, processes and
 * resources in the shape Deployer's scripts read.
 */
final class BuildPayload
{
    /** @return array<string, mixed> */
    public function for(Repository $repository): array
    {
        $payload = ['base_environment' => (string) $repository->website->env_file, 'repository_root' => $repository->deploymentRoot()];
        $environment = $repository->environment;
        if ($environment === null) {
            return $payload;
        }
        $environment->loadMissing(['variables', 'processes', 'resources']);
        $values = fn (array $scopes): array => $environment->variables->whereIn('scope', $scopes)->mapWithKeys(fn (EnvironmentVariable $variable): array => [$variable->key => $variable->value])->all();

        return [
            ...$payload,
            'runtime' => [
                'minimum_replicas' => $environment->minimum_replicas, 'maximum_replicas' => $environment->maximum_replicas, 'desired_replicas' => $environment->desired_replicas,
                'deployment_strategy' => $environment->deployment_strategy, 'rolling_pause_seconds' => $environment->rolling_pause_seconds,
                'type' => $environment->runtime_type, 'version' => $environment->runtime_version, 'build_command' => $environment->build_command,
                'start_command' => $environment->start_command, 'container_port' => $environment->container_port, 'dockerfile_path' => $environment->dockerfile_path,
            ],
            'variables' => $values(['runtime', 'all']),
            'build_variables' => $values(['build', 'all']),
            'processes' => $environment->processes->where('is_enabled', true)->map(fn (EnvironmentProcess $process): array => [
                'name' => $process->name, 'type' => $process->type, 'command' => $process->command, 'replicas' => $process->replicas,
                'restart_policy' => $process->restart_policy, 'restart_delay_seconds' => $process->restart_delay_seconds,
            ])->values()->all(),
            'resources' => $environment->resources->map(fn (EnvironmentResource $resource): array => [
                'name' => $resource->name, 'type' => $resource->type, 'is_managed' => $resource->is_managed, 'configuration' => $resource->configuration,
            ])->values()->all(),
        ];
    }
}
