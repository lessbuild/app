<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateEnvironmentDeploySettings
{
    /**
     * Changes an environment's deploy settings, within the plan.
     *
     * @param  Entitlements  $entitlements  Checks the plan includes scaling before replica settings change.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * How an environment's deploys run: approval, strategy, automatic rollback, observation, runtime and replicas.
     * More than one replica needs scaling on the Deploy plan.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  array{requires_deployment_approval: bool, deployment_strategy: string, rolling_pause_seconds: int, automatic_rollback: bool, post_deployment_observation_minutes: int|null, runtime_type: string, runtime_version: string|null, build_command: string|null, start_command: string|null, container_port: int|null, dockerfile_path: string|null, minimum_replicas: int, maximum_replicas: int, desired_replicas: int}  $data
     * @return void
     */
    public function handle(User $actor, Environment $environment, array $data): void
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        $scaling = $data['minimum_replicas'] !== $environment->minimum_replicas || $data['maximum_replicas'] !== $environment->maximum_replicas;
        if ($scaling && max($data['minimum_replicas'], $data['maximum_replicas']) > 1 && ! $this->entitlements->for($environment->project->account)->has('deploy.scaling')) {
            throw ValidationException::withMessages(['maximum_replicas' => __('More than one replica comes with scaling on the Business Deploy plan.')]);
        }
        if ($data['minimum_replicas'] > $data['maximum_replicas']) {
            throw ValidationException::withMessages(['minimum_replicas' => __('The minimum can’t be more than the maximum.')]);
        }
        if (in_array($data['runtime_type'], ['node', 'python'], true) && blank($data['start_command'])) {
            throw ValidationException::withMessages(['start_command' => __('Node and Python apps need a start command.')]);
        }
        $environment->forceFill([...$data, 'desired_replicas' => max($data['minimum_replicas'], min($data['maximum_replicas'], $data['desired_replicas']))])->save();
    }
}
