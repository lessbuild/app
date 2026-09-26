<?php

namespace App\Core\Services\Projects;

use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateCanonicalEnvironment
{
    public function __construct(private readonly WorkspaceProjectAccess $access) {}

    public function handle(
        Workspace $workspace,
        Project $project,
        PlatformUser $creator,
        string $name,
        string $environmentType,
    ): ProjectEnvironment {
        return DB::connection('core')->transaction(function () use ($workspace, $project, $creator, $name, $environmentType): ProjectEnvironment {
            $lockedProject = Project::query()
                ->whereKey($project->getKey())
                ->where('workspace_id', $workspace->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->access->canViewProject($creator, $lockedProject)
                || ! $this->access->canManageWorkspace($creator, $workspace)) {
                throw new AuthorizationException;
            }

            $baseSlug = Str::limit(Str::slug($name) ?: 'environment', 110, '');
            $slug = $baseSlug;
            $suffix = 2;

            while (ProjectEnvironment::query()
                ->where('project_id', $lockedProject->getKey())
                ->where('slug', $slug)
                ->exists()) {
                $suffixText = '-'.$suffix++;
                $slug = Str::limit($baseSlug, 110 - strlen($suffixText), '').$suffixText;
            }

            return ProjectEnvironment::query()->create([
                'project_id' => $lockedProject->getKey(),
                'created_by_user_id' => $creator->getKey(),
                'name' => $name,
                'slug' => $slug,
                'environment_type' => $environmentType,
                'status' => 'active',
                'metadata' => [],
            ]);
        });
    }
}
