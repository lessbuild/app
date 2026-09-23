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

final class SetCanonicalProjectArchiveState
{
    public function __construct(private readonly WorkspaceProjectAccess $access) {}

    public function handle(PlatformUser $user, Workspace $workspace, Project $project, bool $archived): Project
    {
        return DB::connection('core')->transaction(function () use ($user, $workspace, $project, $archived): Project {
            $lockedProject = Project::query()
                ->whereKey($project->getKey())
                ->where('workspace_id', $workspace->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->access->canManageWorkspace($user, $workspace)) {
                throw new AuthorizationException;
            }

            $targetStatus = $archived ? 'archived' : 'active';
            $targetArchivedAt = $archived ? ($lockedProject->archived_at ?? now()) : null;

            if ($lockedProject->status === $targetStatus && $lockedProject->archived_at == $targetArchivedAt) {
                return $lockedProject;
            }

            $previousStatus = $lockedProject->status;
            $lockedProject->forceFill([
                'status' => $targetStatus,
                'archived_at' => $targetArchivedAt,
            ])->save();

            ProjectLifecycleEvent::query()->create([
                'project_id' => $lockedProject->getKey(),
                'actor_user_id' => $user->getKey(),
                'event_type' => $archived
                    ? ProjectLifecycleEventType::Archived->value
                    : ProjectLifecycleEventType::Restored->value,
                'details' => ['from_status' => $previousStatus, 'to_status' => $targetStatus],
                'occurred_at' => now(),
            ]);

            return $lockedProject;
        });
    }
}
