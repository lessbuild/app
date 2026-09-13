<?php

namespace App\Actions\Project;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ApplicationTemplateCatalog;
use App\Services\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateProjectAction
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly ApplicationTemplateCatalog $templates,
    ) {}

    /**
     * Create an application with its protected production environment and entitled template processes atomically.
     *
     * @param  Organization  $organization  Workspace that owns the application.
     * @param  User  $actor  Account recorded as the application's creator.
     * @param  array{name: string, description?: string|null, preset: string}  $attributes  Validated application attributes.
     */
    public function handle(Organization $organization, User $actor, array $attributes): Project
    {
        $preset = $attributes['preset'] ?? null;
        $template = $this->templates->for(is_string($preset) ? $preset : '');
        $slug = $this->uniqueSlug($organization->id, $attributes['name']);

        return DB::transaction(function () use ($organization, $actor, $attributes, $template, $slug): Project {
            $project = $organization->projects()->create([
                ...$attributes,
                'template_version' => $template->version(),
                'slug' => $slug,
                'created_by' => $actor->id,
            ]);
            $environment = $project->environments()->create([
                'name' => 'Production',
                'slug' => 'production',
                'type' => 'production',
                'branch' => 'main',
                'runtime_type' => $template->runtimeType,
                'build_command' => $template->buildCommand,
                'start_command' => $template->startCommand,
                'container_port' => $template->containerPort,
                'dockerfile_path' => $template->dockerfilePath,
                'is_protected' => true,
                'requires_deployment_approval' => true,
            ]);
            if ($this->entitlements->allows($organization, 'workers')) {
                foreach ($template->processes as $process) {
                    $environment->processes()->create([
                        ...$process, 'replicas' => 1, 'restart_policy' => 'always', 'restart_delay_seconds' => 5, 'is_enabled' => true,
                    ]);
                }
            }

            return $project;
        });
    }

    /**
     * Find an available application slug within the workspace, adding numeric suffixes when the name collides.
     */
    private function uniqueSlug(int $organizationId, string $name): string
    {
        $base = Str::slug($name) ?: 'application';
        $slug = $base;
        $suffix = 2;
        while (Project::query()->where('organization_id', $organizationId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
