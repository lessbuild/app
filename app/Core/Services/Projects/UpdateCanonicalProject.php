<?php

namespace App\Core\Services\Projects;

use App\Core\Enums\ProjectLifecycleEventType;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectLifecycleEvent;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class UpdateCanonicalProject
{
    public function __construct(private readonly WorkspaceProjectAccess $access) {}

    public function handle(
        PlatformUser $user,
        Workspace $workspace,
        Project $project,
        string $name,
        ?string $description,
    ): Project {
        return DB::connection('core')->transaction(function () use ($user, $workspace, $project, $name, $description): Project {
            $lockedProject = Project::query()
                ->whereKey($project->getKey())
                ->where('workspace_id', $workspace->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->access->canManageWorkspace($user, $workspace)
                || $lockedProject->status !== 'active'
                || $lockedProject->archived_at !== null) {
                throw new AuthorizationException;
            }

            $lockedProject->fill([
                'name' => $name,
                'description' => $description,
            ]);
            $changedFields = array_keys($lockedProject->getDirty());
            $lockedProject->save();

            if ($changedFields !== []) {
                ProjectLifecycleEvent::query()->create([
                    'project_id' => $lockedProject->getKey(),
                    'actor_user_id' => $user->getKey(),
                    'event_type' => ProjectLifecycleEventType::Updated->value,
                    'details' => ['changed_fields' => $changedFields],
                    'occurred_at' => now(),
                ]);
            }

            return $lockedProject;
        });
    }
}
