<?php

namespace App\Modules\Deployer\Actions\Project;

use App\Modules\Deployer\Data\ApplicationTemplateDefinition;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\EnvironmentProcess;
use App\Modules\Deployer\Models\Project;

final class ApplyApplicationTemplate
{
    public function projectAttributes(ApplicationTemplateDefinition $template): array
    {
        return ['preset' => $template->key, 'template_version' => $template->version()];
    }

    /** Create the native template environment with the same defaults as project creation. */
    public function createEnvironment(Project $project, array $attributes, ApplicationTemplateDefinition $template): Environment
    {
        return $project->environments()->create([
            ...$attributes,
            'runtime_type' => $template->runtimeType,
            'build_command' => $template->buildCommand,
            'start_command' => $template->startCommand,
            'container_port' => $template->containerPort,
            'dockerfile_path' => $template->dockerfilePath,
            'branch' => $attributes['branch'] ?? 'main',
            'is_protected' => $attributes['is_protected'] ?? (($attributes['type'] ?? null) === 'production'),
            'requires_deployment_approval' => $attributes['requires_deployment_approval'] ?? (($attributes['type'] ?? null) === 'production'),
            'minimum_replicas' => $attributes['minimum_replicas'] ?? 1,
            'maximum_replicas' => $attributes['maximum_replicas'] ?? 1,
        ]);
    }

    /** Apply selected template runtime settings without replacing branch, safety, version, or scaling choices. */
    public function configureEnvironment(Environment $environment, array $attributes, ApplicationTemplateDefinition $template): void
    {
        $environment->forceFill([
            ...$attributes,
            'runtime_type' => $template->runtimeType,
            'build_command' => $template->buildCommand,
            'start_command' => $template->startCommand,
            'container_port' => $template->containerPort,
            'dockerfile_path' => $template->dockerfilePath,
        ])->save();
    }

    public function configureProcesses(Environment $environment, ApplicationTemplateDefinition $template, bool $workersAllowed): void
    {
        if (! $workersAllowed) {
            return;
        }

        foreach ($template->processes as $process) {
            if (! is_array($process)
                || ! is_string($process['name'] ?? null) || trim($process['name']) === ''
                || ! is_string($process['command'] ?? null) || trim($process['command']) === ''
                || ! in_array($process['type'] ?? null, EnvironmentProcess::TYPES, true)) {
                throw new \InvalidArgumentException('The application template has an invalid process definition.');
            }
            $record = EnvironmentProcess::query()->firstOrNew([
                'environment_id' => $environment->getKey(), 'name' => trim($process['name']),
            ]);
            if (! $record->exists) {
                $record->forceFill(['replicas' => 1, 'restart_policy' => 'always', 'restart_delay_seconds' => 5, 'is_enabled' => true]);
            }
            $record->forceFill(['type' => $process['type'], 'command' => $process['command']])->save();
        }
    }
}
