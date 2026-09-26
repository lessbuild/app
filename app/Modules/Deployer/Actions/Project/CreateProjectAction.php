<?php

namespace App\Modules\Deployer\Actions\Project;

use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ApplicationTemplateCatalog;
use App\Modules\Deployer\Services\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateProjectAction
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly ApplicationTemplateCatalog $templates,
        private readonly ApplyApplicationTemplate $applyTemplate,
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
        unset($attributes['preset']);

        return DB::connection('deployer')->transaction(function () use ($organization, $actor, $attributes, $template, $slug): Project {
            $project = $organization->projects()->create([
                ...$attributes,
                ...$this->applyTemplate->projectAttributes($template),
                'slug' => $slug,
                'created_by' => $actor->id,
            ]);
            $environment = $this->applyTemplate->createEnvironment($project, [
                'name' => 'Production',
                'slug' => 'production',
                'type' => 'production',
            ], $template);
            $this->applyTemplate->configureProcesses($environment, $template, $this->entitlements->allows($organization, 'workers'));

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
