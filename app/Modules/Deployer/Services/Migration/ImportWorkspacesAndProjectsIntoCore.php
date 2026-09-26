<?php

namespace App\Modules\Deployer\Services\Migration;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceInvitation;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ImportWorkspacesAndProjectsIntoCore
{
    /**
     * Import each Deployer organization and project as a distinct Core resource.
     * Similar names are never treated as evidence that two records are the same.
     *
     * @return array{workspaces_seen:int,workspaces_ready:int,workspaces_imported:int,workspaces_already_mapped:int,workspaces_blocked:int,projects_seen:int,projects_ready:int,projects_imported:int,projects_already_mapped:int,projects_blocked:int,unmapped_members:int,invitations_seen:int,invitations_imported:int,environments_imported:int}
     */
    public function run(bool $apply = false): array
    {
        $organizations = DB::connection('deployer')->table('organizations')->orderBy('id')->get();
        $organizationMembers = DB::connection('deployer')->table('organization_user')
            ->orderBy('organization_id')
            ->orderBy('user_id')
            ->get()
            ->groupBy(fn (object $membership): string => (string) $membership->organization_id);
        $organizationInvitations = DB::connection('deployer')->table('organization_invitations')
            ->orderBy('organization_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (object $invitation): string => (string) $invitation->organization_id);
        $projects = DB::connection('deployer')->table('projects')->orderBy('id')->get();
        $environments = DB::connection('deployer')->table('environments')
            ->orderBy('project_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (object $environment): string => (string) $environment->project_id);

        $userMaps = $this->mappingsFor('user');
        $workspaceMaps = $this->mappingsFor('organization');
        $projectMaps = $this->mappingsFor('project');

        $report = [
            'workspaces_seen' => $organizations->count(),
            'workspaces_ready' => 0,
            'workspaces_imported' => 0,
            'workspaces_already_mapped' => 0,
            'workspaces_blocked' => 0,
            'projects_seen' => $projects->count(),
            'projects_ready' => 0,
            'projects_imported' => 0,
            'projects_already_mapped' => 0,
            'projects_blocked' => 0,
            'unmapped_members' => 0,
            'invitations_seen' => $organizationInvitations->sum(fn (Collection $invitations): int => $invitations->count()),
            'invitations_imported' => 0,
            'environments_imported' => 0,
        ];
        $eligibleOrganizationIds = [];

        foreach ($organizations as $organization) {
            $sourceId = (string) $organization->id;
            $existing = $workspaceMaps->get($sourceId);

            if ($existing !== null) {
                if ($existing->status === 'reconciled') {
                    $report['workspaces_already_mapped']++;
                } else {
                    $report['workspaces_blocked']++;
                }

                continue;
            }

            if (! $this->canonicalUserId($organization->owner_id, $userMaps)) {
                $report['workspaces_blocked']++;

                continue;
            }

            $eligibleOrganizationIds[$sourceId] = true;

            $report['unmapped_members'] += $organizationMembers
                ->get($sourceId, collect())
                ->filter(fn (object $membership): bool => $this->canonicalUserId($membership->user_id, $userMaps) === null)
                ->count();

            $report['workspaces_ready']++;

            if ($apply && $this->importWorkspace(
                $organization,
                $organizationMembers->get($sourceId, collect()),
                $organizationInvitations->get($sourceId, collect()),
                $userMaps,
                $report,
            )) {
                $report['workspaces_imported']++;
            }
        }

        $workspaceMaps = $this->mappingsFor('organization');

        foreach ($projects as $sourceProject) {
            $sourceId = (string) $sourceProject->id;
            $existing = $projectMaps->get($sourceId);

            if ($existing !== null) {
                if ($existing->status === 'reconciled') {
                    $report['projects_already_mapped']++;
                } else {
                    $report['projects_blocked']++;
                }

                continue;
            }

            $workspaceMapping = $workspaceMaps->get((string) $sourceProject->organization_id);

            if ($workspaceMapping === null || $workspaceMapping->status !== 'reconciled') {
                if (! $apply && isset($eligibleOrganizationIds[(string) $sourceProject->organization_id])) {
                    // In preview mode the parent workspace has not been written yet.
                    $report['projects_ready']++;
                } else {
                    $report['projects_blocked']++;
                }

                continue;
            }

            $report['projects_ready']++;

            if ($apply && $this->importProject(
                $sourceProject,
                $workspaceMapping,
                $userMaps,
                $organizationMembers->get((string) $sourceProject->organization_id, collect()),
                $environments->get($sourceId, collect()),
                $report,
            )) {
                $report['projects_imported']++;
            }
        }

        return $report;
    }

    private function importWorkspace(
        object $source,
        Collection $sourceMembers,
        Collection $sourceInvitations,
        Collection $userMaps,
        array &$report,
    ): bool {
        $sourceId = (string) $source->id;
        $ownerId = $this->canonicalUserId($source->owner_id, $userMaps);

        if ($ownerId === null) {
            return false;
        }

        return DB::connection('core')->transaction(function () use ($source, $sourceId, $ownerId, $sourceMembers, $sourceInvitations, $userMaps, &$report): bool {
            $existingMap = $this->sourceMapping('organization', $sourceId, lock: true);

            if ($existingMap !== null) {
                return false;
            }

            $now = now();
            $workspace = Workspace::query()->create([
                'owner_user_id' => $ownerId,
                'name' => $source->name ?: 'Deployer workspace '.$sourceId,
                'slug' => $this->workspaceSlug($source->slug ?? null, $sourceId),
                'status' => 'active',
                'settings' => [
                    'source_product' => 'deployer',
                    'source_organization_id' => $sourceId,
                    'source_slug' => $source->slug ?? null,
                ],
            ]);
            $this->preserveTimestamps($workspace, $source);

            $roles = [];
            foreach ($sourceMembers as $sourceMember) {
                $canonicalUserId = $this->canonicalUserId($sourceMember->user_id, $userMaps);

                if ($canonicalUserId === null) {
                    continue;
                }

                $role = (string) ($sourceMember->role ?: 'viewer');
                if ((string) $sourceMember->user_id === (string) $source->owner_id) {
                    $role = 'owner';
                }

                $roles[$canonicalUserId] = $role;
            }

            // Legacy owners are authorized even if a damaged pivot row is missing.
            $roles[$ownerId] = 'owner';

            foreach ($roles as $canonicalUserId => $role) {
                $membership = WorkspaceMembership::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'user_id' => $canonicalUserId,
                    'role' => $role,
                    'status' => 'active',
                    'joined_at' => $now,
                ]);

                WorkspaceProductAccess::query()->create([
                    'membership_id' => $membership->getKey(),
                    'product' => 'deployer',
                    'role' => $role,
                    'status' => 'active',
                    'granted_at' => $now,
                ]);
            }

            foreach ($sourceInvitations as $sourceInvitation) {
                if ($this->importInvitation($workspace, $sourceInvitation, $userMaps)) {
                    $report['invitations_imported']++;
                }
            }

            LegacyIdentityMap::query()->create([
                'source_product' => 'deployer',
                'source_entity' => 'organization',
                'source_id' => $sourceId,
                'canonical_entity' => 'workspace',
                'canonical_id' => $workspace->getKey(),
                'status' => 'reconciled',
                'batch_key' => 'deployer-workspace-project-import-v1',
                'metadata' => ['source_slug' => $source->slug ?? null],
                'imported_at' => $now,
                'reconciled_at' => $now,
            ]);

            return true;
        });
    }

    private function importProject(
        object $source,
        LegacyIdentityMap $workspaceMapping,
        Collection $userMaps,
        Collection $sourceOrganizationMembers,
        Collection $sourceEnvironments,
        array &$report,
    ): bool {
        $sourceId = (string) $source->id;
        $creatorId = $this->canonicalUserId($source->created_by ?? null, $userMaps);

        return DB::connection('core')->transaction(function () use (
            $source,
            $sourceId,
            $creatorId,
            $workspaceMapping,
            $sourceEnvironments,
            &$report,
        ): bool {
            $existingMap = $this->sourceMapping('project', $sourceId, lock: true);

            if ($existingMap !== null) {
                return false;
            }

            $now = now();
            $workspaceId = (string) $workspaceMapping->canonical_id;
            $project = Project::query()->create([
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $creatorId,
                'name' => $source->name,
                'slug' => $this->projectSlug($source->slug, $source->organization_id, $sourceId),
                'status' => 'active',
                'description' => $source->description,
                'metadata' => [
                    'source_product' => 'deployer',
                    'source_project_id' => $sourceId,
                    'source_organization_id' => (string) $source->organization_id,
                    'source_slug' => $source->slug,
                ],
            ]);
            $this->preserveTimestamps($project, $source);

            ProjectProduct::query()->create([
                'project_id' => $project->getKey(),
                'product' => 'deployer',
                'status' => 'active',
                'requested_by_user_id' => $creatorId,
                'activated_at' => $source->created_at ?? $now,
                'metadata' => ['migration_source' => 'deployer'],
            ]);

            ProjectResource::query()->create([
                'project_id' => $project->getKey(),
                'product' => 'deployer',
                'resource_type' => 'project',
                'resource_id' => $sourceId,
                'name' => $source->name,
                'status' => 'active',
                'mapped_at' => $now,
                'metadata' => ['source_slug' => $source->slug],
            ]);

            LegacyIdentityMap::query()->create([
                'source_product' => 'deployer',
                'source_entity' => 'project',
                'source_id' => $sourceId,
                'canonical_entity' => 'project',
                'canonical_id' => $project->getKey(),
                'status' => 'reconciled',
                'batch_key' => 'deployer-workspace-project-import-v1',
                'metadata' => ['source_organization_id' => (string) $source->organization_id],
                'imported_at' => $now,
                'reconciled_at' => $now,
            ]);

            $workspaceMembers = WorkspaceMembership::query()
                ->where('workspace_id', $workspaceId)
                ->where('status', 'active')
                ->get(['user_id', 'role']);

            foreach ($workspaceMembers as $workspaceMember) {
                ProjectMembership::query()->create([
                    'project_id' => $project->getKey(),
                    'user_id' => $workspaceMember->user_id,
                    'role' => $workspaceMember->role,
                    'status' => 'active',
                    'granted_by_user_id' => $creatorId,
                    'granted_at' => $now,
                ]);
            }

            foreach ($sourceEnvironments as $sourceEnvironment) {
                $canonicalEnvironment = ProjectEnvironment::query()->create([
                    'project_id' => $project->getKey(),
                    'created_by_user_id' => $creatorId,
                    'name' => $sourceEnvironment->name,
                    'slug' => $this->environmentSlug($sourceEnvironment->slug, $sourceEnvironment->id),
                    'environment_type' => in_array($sourceEnvironment->type, ['production', 'staging', 'preview', 'development'], true)
                        ? $sourceEnvironment->type
                        : 'custom',
                    'status' => (string) $sourceEnvironment->status,
                    'metadata' => [
                        'source_product' => 'deployer',
                        'source_environment_id' => (string) $sourceEnvironment->id,
                        'source_type' => $sourceEnvironment->type,
                        'source_status' => $sourceEnvironment->status,
                        'branch' => $sourceEnvironment->branch,
                    ],
                ]);
                $this->preserveTimestamps($canonicalEnvironment, $sourceEnvironment);

                ProjectResource::query()->create([
                    'project_id' => $project->getKey(),
                    'environment_id' => $canonicalEnvironment->getKey(),
                    'product' => 'deployer',
                    'resource_type' => 'environment',
                    'resource_id' => (string) $sourceEnvironment->id,
                    'name' => $sourceEnvironment->name,
                    'status' => 'active',
                    'mapped_at' => $now,
                    'metadata' => [
                        'source_type' => $sourceEnvironment->type,
                        'branch' => $sourceEnvironment->branch,
                        'is_protected' => (bool) $sourceEnvironment->is_protected,
                    ],
                ]);

                LegacyIdentityMap::query()->create([
                    'source_product' => 'deployer',
                    'source_entity' => 'environment',
                    'source_id' => (string) $sourceEnvironment->id,
                    'canonical_entity' => 'project_environment',
                    'canonical_id' => $canonicalEnvironment->getKey(),
                    'status' => 'reconciled',
                    'batch_key' => 'deployer-workspace-project-import-v1',
                    'metadata' => ['source_project_id' => $sourceId],
                    'imported_at' => $now,
                    'reconciled_at' => $now,
                ]);

                $report['environments_imported']++;
            }

            return true;
        });
    }

    private function mappingsFor(string $entity): Collection
    {
        return LegacyIdentityMap::query()
            ->where('source_product', 'deployer')
            ->where('source_entity', $entity)
            ->get()
            ->keyBy(fn (LegacyIdentityMap $mapping): string => (string) $mapping->source_id);
    }

    private function sourceMapping(string $entity, string $sourceId, bool $lock = false): ?LegacyIdentityMap
    {
        $query = LegacyIdentityMap::query()
            ->where('source_product', 'deployer')
            ->where('source_entity', $entity)
            ->where('source_id', $sourceId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function canonicalUserId(string|int|null $sourceId, Collection $userMaps): ?string
    {
        if ($sourceId === null) {
            return null;
        }

        $mapping = $userMaps->get((string) $sourceId);

        return $mapping?->status === 'reconciled' && $mapping->canonical_entity === 'user'
            ? (string) $mapping->canonical_id
            : null;
    }

    private function importInvitation(Workspace $workspace, object $source, Collection $userMaps): bool
    {
        $sourceId = (string) $source->id;

        if ($this->sourceMapping('organization_invitation', $sourceId, lock: true) !== null) {
            return false;
        }

        $invitedByUserId = $this->canonicalUserId($source->invited_by, $userMaps);
        $email = trim((string) $source->email);
        $status = $source->accepted_at !== null
            ? 'accepted'
            : (now()->greaterThan($source->expires_at) ? 'expired' : 'pending');
        $now = now();
        $invitation = WorkspaceInvitation::query()->create([
            'workspace_id' => $workspace->getKey(),
            'invited_by_user_id' => $invitedByUserId,
            'email' => $email,
            'email_normalized' => Str::lower($email),
            'role' => (string) $source->role,
            'token_hash' => $source->token_hash,
            'status' => $status,
            'expires_at' => $source->expires_at,
            'accepted_at' => $source->accepted_at,
        ]);

        $this->preserveTimestamps($invitation, $source);

        LegacyIdentityMap::query()->create([
            'source_product' => 'deployer',
            'source_entity' => 'organization_invitation',
            'source_id' => $sourceId,
            'canonical_entity' => 'workspace_invitation',
            'canonical_id' => $invitation->getKey(),
            'status' => 'reconciled',
            'batch_key' => 'deployer-workspace-project-import-v1',
            'metadata' => [
                'source_organization_id' => (string) $source->organization_id,
                'source_invited_by' => (string) $source->invited_by,
            ],
            'imported_at' => $now,
            'reconciled_at' => $now,
        ]);

        return true;
    }

    private function workspaceSlug(?string $sourceSlug, string $sourceId): string
    {
        $base = Str::slug($sourceSlug ?: 'deployer-workspace');
        $suffix = '-deployer-'.$sourceId;

        return Str::limit($base, 120 - strlen($suffix), '').$suffix;
    }

    private function projectSlug(string $sourceSlug, string $organizationId, string $projectId): string
    {
        $suffix = '-d'.$organizationId.'p'.$projectId;

        return Str::limit(Str::slug($sourceSlug), 120 - strlen($suffix), '').$suffix;
    }

    private function environmentSlug(string $sourceSlug, int|string $environmentId): string
    {
        $suffix = '-d'.$environmentId;

        return Str::limit(Str::slug($sourceSlug), 120 - strlen($suffix), '').$suffix;
    }

    private function preserveTimestamps(object $canonical, object $source): void
    {
        $canonical->timestamps = false;
        $canonical->created_at = $source->created_at ?? now();
        $canonical->updated_at = $source->updated_at ?? $canonical->created_at;
        $canonical->save();
    }
}
