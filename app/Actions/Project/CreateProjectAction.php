<?php

namespace App\Actions\Project;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateProjectAction
{
    public function __construct(
        private readonly Entitlements $entitlements,
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
        $templates = config('application-templates', []);
        $preset = $attributes['preset'] ?? null;

        if (! is_string($preset) || ! array_key_exists($preset, $templates)) {
            throw new InvalidArgumentException('The application preset is not configured.');
        }

        $template = $templates[$preset];
        $slug = $this->uniqueSlug($organization->id, $attributes['name']);

        return DB::transaction(function () use ($organization, $actor, $attributes, $template, $slug): Project {
            $project = $organization->projects()->create([
                ...$attributes,
                'slug' => $slug,
                'created_by' => $actor->id,
            ]);
            $environment = $project->environments()->create([
                'name' => 'Production',
                'slug' => 'production',
                'type' => 'production',
                'branch' => 'main',
                'runtime_type' => $template['runtime_type'],
                'build_command' => $template['build_command'],
                'start_command' => $template['start_command'],
                'container_port' => $template['container_port'],
                'dockerfile_path' => $template['dockerfile_path'],
                'is_protected' => true,
                'requires_deployment_approval' => true,
            ]);
            if ($this->entitlements->allows($organization, 'workers')) {
                foreach ($template['processes'] as $process) {
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
