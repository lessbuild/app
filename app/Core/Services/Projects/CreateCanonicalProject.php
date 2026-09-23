<?php

namespace App\Core\Services\Projects;

use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectMembership;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateCanonicalProject
{
    public function __construct(private readonly WorkspaceProjectAccess $access) {}

    public function handle(
        Workspace $workspace,
        PlatformUser $creator,
        string $name,
        ?string $description = null,
    ): Project {
        return DB::connection('core')->transaction(function () use ($workspace, $creator, $name, $description): Project {
            $lockedWorkspace = Workspace::query()
                ->whereKey($workspace->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->access->canManageWorkspace($creator, $lockedWorkspace)) {
                throw new AuthorizationException;
            }

            $project = Project::query()->create([
                'workspace_id' => $lockedWorkspace->getKey(),
                'created_by_user_id' => $creator->getKey(),
                'name' => $name,
                'slug' => $this->uniqueSlug($lockedWorkspace, $name),
                'status' => 'active',
                'description' => $description,
            ]);

            $now = now();
            $members = WorkspaceMembership::query()
                ->where('workspace_id', $lockedWorkspace->getKey())
                ->currentlyActive()
                ->lockForUpdate()
                ->get(['user_id', 'role']);

            foreach ($members as $member) {
                ProjectMembership::query()->create([
                    'project_id' => $project->getKey(),
                    'user_id' => $member->user_id,
                    'role' => $member->role,
                    'status' => 'active',
                    'granted_by_user_id' => $creator->getKey(),
                    'granted_at' => $now,
                ]);
            }

            return $project;
        });
    }

    private function uniqueSlug(Workspace $workspace, string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $suffix = 2;

        while (Project::query()->where('workspace_id', $workspace->getKey())->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
