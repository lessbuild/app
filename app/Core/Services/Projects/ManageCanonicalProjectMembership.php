<?php

namespace App\Core\Services\Projects;

use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectMembership;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceMembershipEvent;
use Illuminate\Support\Facades\DB;

final class ManageCanonicalProjectMembership
{
    public function grant(
        PlatformUser $actor,
        Workspace $workspace,
        Project $project,
        string $workspaceMembershipId,
    ): bool {
        return DB::connection('core')->transaction(function () use ($actor, $workspace, $project, $workspaceMembershipId): bool {
            [$lockedWorkspace, $lockedProject, $actorMembership] = $this->lockContext($actor, $workspace, $project);
            $target = $this->lockTargetMembership($lockedWorkspace, $workspaceMembershipId);

            $projectMembership = ProjectMembership::query()
                ->where('project_id', $lockedProject->getKey())
                ->where('user_id', $target->user_id)
                ->lockForUpdate()
                ->first();

            if ($projectMembership?->status === 'active' && $projectMembership->revoked_at === null) {
                return false;
            }

            $now = now();
            if ($projectMembership === null) {
                ProjectMembership::query()->create([
                    'project_id' => $lockedProject->getKey(),
                    'user_id' => $target->user_id,
                    'role' => $target->role,
                    'status' => 'active',
                    'granted_by_user_id' => $actor->getKey(),
                    'granted_at' => $now,
                ]);
            } else {
                $projectMembership->forceFill([
                    'role' => $target->role,
                    'status' => 'active',
                    'granted_by_user_id' => $actor->getKey(),
                    'granted_at' => $now,
                    'revoked_at' => null,
                ])->save();
            }

            $this->recordEvent($lockedWorkspace, $lockedProject, $actor, $target, 'project_access_granted');

            return true;
        }, attempts: 3);
    }

    public function revoke(
        PlatformUser $actor,
        Workspace $workspace,
        Project $project,
        string $workspaceMembershipId,
    ): bool {
        return DB::connection('core')->transaction(function () use ($actor, $workspace, $project, $workspaceMembershipId): bool {
            [$lockedWorkspace, $lockedProject, $actorMembership] = $this->lockContext($actor, $workspace, $project);
            $target = $this->lockTargetMembership($lockedWorkspace, $workspaceMembershipId);

            abort_if($target->role === 'owner', 403, 'A workspace owner always retains access to its projects.');
            abort_if($actorMembership->role !== 'owner' && $target->role === 'admin', 403);

            $projectMembership = ProjectMembership::query()
                ->where('project_id', $lockedProject->getKey())
                ->where('user_id', $target->user_id)
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if ($projectMembership === null) {
                return false;
            }

            $projectMembership->forceFill([
                'status' => 'revoked',
                'revoked_at' => now(),
            ])->save();

            $this->recordEvent($lockedWorkspace, $lockedProject, $actor, $target, 'project_access_revoked');

            return true;
        }, attempts: 3);
    }

    /** @return array{Workspace, Project, WorkspaceMembership} */
    private function lockContext(PlatformUser $actor, Workspace $workspace, Project $project): array
    {
        $lockedWorkspace = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
        abort_unless($lockedWorkspace->status === 'active' && $lockedWorkspace->archived_at === null, 404);

        $lockedProject = Project::query()
            ->whereKey($project->getKey())
            ->where('workspace_id', $lockedWorkspace->getKey())
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->firstOrFail();

        $actorMembership = WorkspaceMembership::query()
            ->where('workspace_id', $lockedWorkspace->getKey())
            ->where('user_id', $actor->getKey())
            ->currentlyActive()
            ->lockForUpdate()
            ->first();

        abort_unless($actorMembership !== null && in_array($actorMembership->role, ['owner', 'admin'], true), 403);

        return [$lockedWorkspace, $lockedProject, $actorMembership];
    }

    private function lockTargetMembership(Workspace $workspace, string $membershipId): WorkspaceMembership
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($membershipId)
            ->currentlyActive()
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function recordEvent(
        Workspace $workspace,
        Project $project,
        PlatformUser $actor,
        WorkspaceMembership $target,
        string $event,
    ): void {
        WorkspaceMembershipEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'membership_id' => $target->getKey(),
            'actor_user_id' => $actor->getKey(),
            'subject_user_id' => $target->user_id,
            'event' => $event,
            'metadata' => [
                'project_id' => $project->getKey(),
                'project_name' => $project->name,
                'project_slug' => $project->slug,
            ],
            'created_at' => now(),
        ]);
    }
}
