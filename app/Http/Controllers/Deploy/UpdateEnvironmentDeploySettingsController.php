<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\UpdateEnvironmentDeploySettings;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateEnvironmentDeploySettingsController
{
    /**
     * Save how an environment's deploys run: strategy, observation, runtime, commands and scaling.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  UpdateEnvironmentDeploySettings  $update
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, UpdateEnvironmentDeploySettings $update): RedirectResponse
    {
        $data = $request->validate([
            'deployment_strategy' => ['required', 'in:blue_green,canary,rolling'],
            'rolling_pause_seconds' => ['required', 'integer', 'between:0,30'],
            'post_deployment_observation_minutes' => ['nullable', 'integer', 'in:5,10,15,30'],
            'rollback_error_rate_percent' => ['nullable', 'integer', 'in:1,2,5,10,25'],
            'rollback_latency_percent' => ['nullable', 'integer', 'in:25,50,100,200'],
            'rollback_conversion_drop_percent' => ['nullable', 'integer', 'in:10,20,30,50'],
            'runtime_type' => ['required', 'in:php,node,python,docker'],
            'runtime_version' => ['nullable', 'string', 'max:20', 'regex:/\A[0-9][0-9.]*\z/'],
            'build_command' => ['nullable', 'string', 'max:2000'],
            'start_command' => ['nullable', 'string', 'max:2000'],
            'container_port' => ['nullable', 'integer', 'between:1,65535'],
            'dockerfile_path' => ['nullable', 'string', 'max:255', 'regex:#\A[A-Za-z0-9._/-]+\z#', 'not_regex:#(\A|/)\.\.(/|\z)#'],
            'minimum_replicas' => ['required', 'integer', 'between:1,20'],
            'maximum_replicas' => ['required', 'integer', 'between:1,20'],
            'desired_replicas' => ['required', 'integer', 'between:1,20'],
            'autoscale_cpu_target' => ['nullable', 'integer', 'between:20,95'],
        ]);
        $update->handle($user, $environment, [
            'requires_deployment_approval' => $request->boolean('requires_deployment_approval'), 'automatic_rollback' => $request->boolean('automatic_rollback'), 'migration_safety' => $request->boolean('migration_safety'),
            'deployment_strategy' => $data['deployment_strategy'], 'rolling_pause_seconds' => (int) $data['rolling_pause_seconds'],
            'post_deployment_observation_minutes' => isset($data['post_deployment_observation_minutes']) ? (int) $data['post_deployment_observation_minutes'] : null,
            'rollback_error_rate_percent' => isset($data['rollback_error_rate_percent']) ? (int) $data['rollback_error_rate_percent'] : null,
            'rollback_latency_percent' => isset($data['rollback_latency_percent']) ? (int) $data['rollback_latency_percent'] : null,
            'rollback_conversion_drop_percent' => isset($data['rollback_conversion_drop_percent']) ? (int) $data['rollback_conversion_drop_percent'] : null,
            'runtime_type' => $data['runtime_type'], 'runtime_version' => $data['runtime_version'] ?? null, 'build_command' => $data['build_command'] ?? null,
            'start_command' => $data['start_command'] ?? null, 'container_port' => isset($data['container_port']) ? (int) $data['container_port'] : null,
            'dockerfile_path' => $data['dockerfile_path'] ?? null, 'minimum_replicas' => (int) $data['minimum_replicas'],
            'maximum_replicas' => (int) $data['maximum_replicas'], 'desired_replicas' => (int) $data['desired_replicas'],
            'autoscale_enabled' => $request->boolean('autoscale_enabled'), 'autoscale_cpu_target' => (int) ($data['autoscale_cpu_target'] ?? $environment->autoscale_cpu_target),
        ]);

        return to_route('deploy.environments.show', [$project, $environment])->with('status', __('Settings saved. They apply to the next deploy.'));
    }
}
