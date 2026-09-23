<?php

namespace App\Core\Services\Migration;

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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class ImportMonitorWorkspacesAndApplicationsIntoCore
{
    private const BATCH_KEY = 'monitor-workspace-application-import-v1';

    /**
     * Preview or import Monitor workspaces, memberships, invitations, applications, and environments.
     * Each source application becomes a distinct Core project; names are never used to merge records.
     *
     * @return array{workspaces_seen:int,workspaces_ready:int,workspaces_imported:int,workspaces_already_mapped:int,workspaces_blocked:int,applications_seen:int,applications_ready:int,applications_imported:int,applications_already_mapped:int,applications_blocked:int,environments_seen:int,environments_imported:int,environments_blocked:int,unmapped_members:int,invitations_seen:int,invitations_imported:int,review_records_created:int}
     */
    public function run(bool $apply = false): array
    {
        foreach (['users', 'workspaces', 'user_workspace', 'applications', 'environments'] as $table) {
            if (! Schema::connection('monitor')->hasTable($table)) {
                throw new RuntimeException("The Monitor {$table} table is unavailable on the monitor connection.");
            }
        }

        foreach ([
            'legacy_identity_maps', 'workspaces', 'workspace_memberships', 'workspace_product_access',
            'workspace_invitations', 'projects', 'project_environments', 'project_memberships',
            'project_products', 'project_resources',
        ] as $table) {
            if (! Schema::connection('core')->hasTable($table)) {
                throw new RuntimeException('Run the Core platform migration before importing Monitor workspaces and applications.');
            }
        }

        $workspaces = DB::connection('monitor')->table('workspaces')->orderBy('id')->get();
        $memberships = DB::connection('monitor')->table('user_workspace')
            ->orderBy('workspace_id')->orderBy('user_id')->get()
            ->groupBy(fn (object $membership): string => (string) $membership->workspace_id);
        $invitations = Schema::connection('monitor')->hasTable('workspace_invitations')
            ? DB::connection('monitor')->table('workspace_invitations')->orderBy('workspace_id')->orderBy('id')->get()
                ->groupBy(fn (object $invitation): string => (string) $invitation->workspace_id)
            : collect();
        $applications = DB::connection('monitor')->table('applications')->orderBy('workspace_id')->orderBy('id')->get();
        $environments = DB::connection('monitor')->table('environments')
            ->orderBy('application_id')->orderBy('id')->get()
            ->groupBy(fn (object $environment): string => (string) $environment->application_id);
        $userMaps = $this->mappingsFor('user');
        $workspaceMaps = $this->mappingsFor('workspace');
        $applicationMaps = $this->mappingsFor('application');
        $environmentMaps = $this->mappingsFor('environment');

        $report = [
            'workspaces_seen' => $workspaces->count(),
            'workspaces_ready' => 0,
            'workspaces_imported' => 0,
            'workspaces_already_mapped' => 0,
            'workspaces_blocked' => 0,
            'applications_seen' => $applications->count(),
            'applications_ready' => 0,
            'applications_imported' => 0,
            'applications_already_mapped' => 0,
            'applications_blocked' => 0,
            'environments_seen' => $environments->sum(fn (Collection $items): int => $items->count()),
            'environments_imported' => 0,
            'environments_blocked' => 0,
            'unmapped_members' => 0,
            'invitations_seen' => $invitations->sum(fn (Collection $items): int => $items->count()),
            'invitations_imported' => 0,
            'review_records_created' => 0,
        ];
        $workspacesReadyForPreview = [];

        foreach ($workspaces as $workspace) {
            $sourceId = (string) $workspace->id;
            $existing = $workspaceMaps->get($sourceId);

            if ($existing?->status === 'reconciled') {
                $report['workspaces_already_mapped']++;

                if ($apply) {
                    $coreWorkspace = Workspace::query()->find($existing->canonical_id);

                    if ($coreWorkspace !== null) {
                        DB::connection('core')->transaction(function () use ($coreWorkspace, $invitations, $sourceId, &$report): void {
                            $this->importInvitations($coreWorkspace, $invitations->get($sourceId, collect()), $report);
                        });
                    } else {
                        $report['workspaces_blocked']++;
                    }
                }

                continue;
            }

            if ($existing !== null && ($existing->status !== 'needs_review' || $existing->canonical_id !== null)) {
                $report['workspaces_blocked']++;

                continue;
            }

            $sourceMembers = $memberships->get($sourceId, collect());
            $reasons = $this->workspaceReviewReasons($workspace, $sourceMembers, $userMaps, $report);

            if ($reasons !== []) {
                $report['workspaces_blocked']++;
                if ($apply && $this->recordReview('workspace', $sourceId, $reasons)) {
                    $report['review_records_created']++;
                }

                continue;
            }

            $report['workspaces_ready']++;
            $workspacesReadyForPreview[$sourceId] = true;

            if ($apply && $this->importWorkspace($workspace, $sourceMembers, $invitations->get($sourceId, collect()), $userMaps, $report)) {
                $report['workspaces_imported']++;
            }
        }

        $workspaceMaps = $this->mappingsFor('workspace');

        foreach ($applications as $application) {
            $sourceId = (string) $application->id;
            $existing = $applicationMaps->get($sourceId);

            if ($existing?->status === 'reconciled') {
                $report['applications_already_mapped']++;

                continue;
            }

            if ($existing !== null && ($existing->status !== 'needs_review' || $existing->canonical_id !== null)) {
                $report['applications_blocked']++;

                continue;
            }

            $workspaceMapping = $workspaceMaps->get((string) ($application->workspace_id ?? ''));
            $workspaceReadyInPreview = ! $apply
                && isset($workspacesReadyForPreview[(string) ($application->workspace_id ?? '')])
                && ($workspaceMapping === null
                    || ($workspaceMapping->status === 'needs_review' && $workspaceMapping->canonical_id === null));

            if (($workspaceMapping === null || $workspaceMapping->status !== 'reconciled') && ! $workspaceReadyInPreview) {
                $report['applications_blocked']++;
                if ($apply && $this->recordReview('application', $sourceId, ['monitor_workspace_not_reconciled'])) {
                    $report['review_records_created']++;
                }

                continue;
            }

            $coreWorkspace = $workspaceMapping?->status === 'reconciled'
                ? Workspace::query()->find($workspaceMapping->canonical_id)
                : null;

            if ($coreWorkspace === null && ! $workspaceReadyInPreview) {
                $report['applications_blocked']++;
                if ($apply && $this->recordReview('application', $sourceId, ['canonical_workspace_missing'])) {
                    $report['review_records_created']++;
                }

                continue;
            }

            $applicationEnvironments = $environments->get($sourceId, collect());
            $reasons = $this->applicationReviewReasons($applicationEnvironments, $environmentMaps);

            if (ProjectResource::query()
                ->where('product', 'monitor')
                ->where('resource_type', 'application')
                ->where('resource_id', $sourceId)
                ->exists()) {
                $reasons[] = 'monitor_application_resource_already_mapped';
            }

            if ($reasons !== []) {
                $report['applications_blocked']++;
                $report['environments_blocked'] += $applicationEnvironments->count();
                if ($apply && $this->recordReview('application', $sourceId, array_values(array_unique($reasons)))) {
                    $report['review_records_created']++;
                }

                continue;
            }

            $report['applications_ready']++;

            if ($apply && $coreWorkspace !== null && $this->importApplication($application, $coreWorkspace, $applicationEnvironments, $report)) {
                $report['applications_imported']++;
            }
        }

        return $report;
    }

    /** @param Collection<int, object> $sourceMembers
     * @param  array<string, int>  $report
     * @return list<string>
     */
    private function workspaceReviewReasons(object $workspace, Collection $sourceMembers, Collection $userMaps, array &$report): array
    {
        $reasons = [];
        $ownerId = $this->canonicalUserId($workspace->owner_id, $userMaps);

        if ($ownerId === null) {
            $reasons[] = 'workspace_owner_identity_unmapped';
        }

        $ownerRows = $sourceMembers->filter(fn (object $member): bool => (string) $member->role === 'owner');

        if ($ownerRows->contains(fn (object $member): bool => (string) $member->user_id !== (string) $workspace->owner_id)) {
            $reasons[] = 'workspace_owner_role_conflicts_with_owner_id';
        }

        $mappedSourceMembers = [];

        foreach ($sourceMembers as $member) {
            $canonicalId = $this->canonicalUserId($member->user_id, $userMaps);

            if ($canonicalId === null) {
                $report['unmapped_members']++;
                $reasons[] = 'workspace_member_identity_unmapped';

                continue;
            }

            $role = (string) $member->role;
            if ((string) $member->user_id === (string) $workspace->owner_id || $canonicalId === $ownerId) {
                $role = 'owner';
            }

            if (! in_array($role, ['owner', 'admin', 'member', 'viewer'], true)) {
                $reasons[] = 'unsupported_workspace_role';
            }

            $mappedSourceMembers[$canonicalId][] = $role;
        }

        foreach ($mappedSourceMembers as $roles) {
            if (count(array_unique($roles)) > 1) {
                $reasons[] = 'multiple_source_members_map_to_one_core_user_with_different_roles';
            }
        }

        return array_values(array_unique($reasons));
    }

    /** @param Collection<int, object> $sourceMembers
     * @param  Collection<int, object>  $sourceInvitations
     * @param  Collection<string, LegacyIdentityMap>  $userMaps
     * @param  array<string, int>  $report
     */
    private function importWorkspace(object $source, Collection $sourceMembers, Collection $sourceInvitations, Collection $userMaps, array &$report): bool
    {
        $sourceId = (string) $source->id;
        $ownerId = $this->canonicalUserId($source->owner_id, $userMaps);

        if ($ownerId === null) {
            return false;
        }

        return DB::connection('core')->transaction(function () use ($source, $sourceId, $ownerId, $sourceMembers, $sourceInvitations, $userMaps, &$report): bool {
            $existingMap = $this->sourceMapping('workspace', $sourceId, lock: true);

            if ($existingMap !== null && ($existingMap->status !== 'needs_review' || $existingMap->canonical_id !== null)) {
                return false;
            }

            $now = now();
            $workspace = Workspace::query()->create([
                'owner_user_id' => $ownerId,
                'name' => $source->name ?: 'Monitor workspace '.$sourceId,
                'slug' => $this->workspaceSlug($source->slug ?? null, $sourceId),
                'status' => 'active',
                'settings' => [
                    'source_product' => 'monitor',
                    'source_workspace_id' => $sourceId,
                    'source_slug' => $source->slug ?? null,
                    'source_plan' => $source->plan ?? null,
                ],
            ]);
            $this->preserveTimestamps($workspace, $source);

            $canonicalMembers = [];
            foreach ($sourceMembers as $sourceMember) {
                $canonicalUserId = $this->canonicalUserId($sourceMember->user_id, $userMaps);

                if ($canonicalUserId === null) {
                    continue;
                }

                $role = (string) $sourceMember->role;
                if ((string) $sourceMember->user_id === (string) $source->owner_id || $canonicalUserId === $ownerId) {
                    $role = 'owner';
                }

                $canonicalMembers[$canonicalUserId] = ['role' => $role, 'source' => $sourceMember];
            }
            $canonicalMembers[$ownerId] ??= ['role' => 'owner', 'source' => null];

            foreach ($canonicalMembers as $canonicalUserId => $member) {
                $sourceMembership = $member['source'];
                $membership = WorkspaceMembership::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'user_id' => $canonicalUserId,
                    'role' => $member['role'],
                    'status' => 'active',
                    'joined_at' => $sourceMembership->created_at ?? $now,
                ]);

                if ($sourceMembership !== null) {
                    $this->preserveTimestamps($membership, $sourceMembership);
                }

                $access = WorkspaceProductAccess::query()->create([
                    'membership_id' => $membership->getKey(),
                    'product' => 'monitor',
                    'role' => $member['role'],
                    'status' => 'active',
                    'granted_by_user_id' => null,
                    'granted_at' => $sourceMembership->created_at ?? $now,
                ]);

                if ($sourceMembership !== null) {
                    $this->preserveTimestamps($access, $sourceMembership);
                }
            }

            $this->importInvitations($workspace, $sourceInvitations, $report);

            $mapAttributes = [
                'canonical_entity' => 'workspace',
                'canonical_id' => $workspace->getKey(),
                'status' => 'reconciled',
                'batch_key' => self::BATCH_KEY,
                'reconciliation_notes' => 'Imported as a distinct Core workspace; no name-based workspace merging was performed.',
                'metadata' => array_merge($this->reconciledMetadata($existingMap), [
                    'source_owner_id' => (string) $source->owner_id,
                    'source_slug' => $source->slug ?? null,
                    'source_plan' => $source->plan ?? null,
                ]),
                'imported_at' => $now,
                'reconciled_at' => $now,
            ];

            if ($existingMap !== null) {
                $existingMap->fill($mapAttributes)->save();
            } else {
                LegacyIdentityMap::query()->create([
                    'source_product' => 'monitor',
                    'source_entity' => 'workspace',
                    'source_id' => $sourceId,
                    ...$mapAttributes,
                ]);
            }

            return true;
        });
    }

    /** @param Collection<int, object> $sourceEnvironments
     * @param  Collection<string, LegacyIdentityMap>  $environmentMaps
     * @return list<string>
     */
    private function applicationReviewReasons(Collection $sourceEnvironments, Collection $environmentMaps): array
    {
        $reasons = [];

        foreach ($sourceEnvironments as $environment) {
            if (! in_array((string) $environment->status, ['active', 'paused'], true)) {
                $reasons[] = 'unsupported_monitor_environment_status';
            }

            $mapping = $environmentMaps->get((string) $environment->id);

            if ($mapping !== null && ($mapping->status !== 'needs_review' || $mapping->canonical_id !== null)) {
                $reasons[] = 'monitor_environment_has_existing_mapping';
            }

            if (ProjectResource::query()
                ->where('product', 'monitor')
                ->where('resource_type', 'environment')
                ->where('resource_id', (string) $environment->id)
                ->exists()) {
                $reasons[] = 'monitor_environment_resource_already_mapped';
            }
        }

        return array_values(array_unique($reasons));
    }

    /** @param Collection<int, object> $sourceEnvironments
     * @param  array<string, int>  $report
     */
    private function importApplication(object $source, Workspace $workspace, Collection $sourceEnvironments, array &$report): bool
    {
        $sourceId = (string) $source->id;
        $workspaceId = (string) $workspace->getKey();

        return DB::connection('core')->transaction(function () use ($source, $sourceId, $workspaceId, $sourceEnvironments, &$report): bool {
            $existingMap = $this->sourceMapping('application', $sourceId, lock: true);

            if ($existingMap !== null && ($existingMap->status !== 'needs_review' || $existingMap->canonical_id !== null)) {
                return false;
            }

            $now = now();
            $isDeleted = ($source->deleted_at ?? null) !== null;
            $project = Project::query()->create([
                'workspace_id' => $workspaceId,
                'created_by_user_id' => null,
                'name' => (string) $source->name,
                'slug' => $this->applicationSlug($source->slug ?? null, $workspaceId, $sourceId),
                'status' => $isDeleted ? 'archived' : 'active',
                'description' => null,
                'archived_at' => $isDeleted ? $source->deleted_at : null,
                'metadata' => [
                    'source_product' => 'monitor',
                    'source_workspace_id' => (string) ($source->workspace_id ?? ''),
                    'source_application_id' => $sourceId,
                    'source_slug' => $source->slug ?? null,
                    'framework' => $source->framework ?? null,
                    'framework_version' => $source->framework_version ?? null,
                    'accent' => $source->accent ?? null,
                    'deleted_at' => $source->deleted_at ?? null,
                ],
            ]);
            $this->preserveTimestamps($project, $source);

            ProjectProduct::query()->create([
                'project_id' => $project->getKey(),
                'product' => 'monitor',
                'status' => $isDeleted ? 'inactive' : 'active',
                'requested_by_user_id' => null,
                'activated_at' => $source->created_at ?? $now,
                'metadata' => ['migration_source' => 'monitor'],
            ]);

            $applicationResource = ProjectResource::query()->create([
                'project_id' => $project->getKey(),
                'product' => 'monitor',
                'resource_type' => 'application',
                'resource_id' => $sourceId,
                'name' => $source->name,
                'status' => $isDeleted ? 'archived' : 'active',
                'mapped_at' => $now,
                'metadata' => [
                    'source_workspace_id' => (string) ($source->workspace_id ?? ''),
                    'source_slug' => $source->slug ?? null,
                    'framework' => $source->framework ?? null,
                    'framework_version' => $source->framework_version ?? null,
                    'accent' => $source->accent ?? null,
                    'deleted_at' => $source->deleted_at ?? null,
                ],
            ]);
            $this->preserveTimestamps($applicationResource, $source);

            foreach (WorkspaceMembership::query()
                ->where('workspace_id', $workspaceId)
                ->where('status', 'active')
                ->get(['user_id', 'role']) as $membership) {
                ProjectMembership::query()->create([
                    'project_id' => $project->getKey(),
                    'user_id' => $membership->user_id,
                    'role' => $membership->role,
                    'status' => 'active',
                    'granted_by_user_id' => null,
                    'granted_at' => $now,
                ]);
            }

            foreach ($sourceEnvironments as $sourceEnvironment) {
                $this->importEnvironment($project, $sourceEnvironment, $isDeleted, $now);
                $report['environments_imported']++;
            }

            $mapAttributes = [
                'canonical_entity' => 'project',
                'canonical_id' => $project->getKey(),
                'status' => 'reconciled',
                'batch_key' => self::BATCH_KEY,
                'reconciliation_notes' => 'Imported as a Core project with its Monitor application resource mapped by source ID.',
                'metadata' => array_merge($this->reconciledMetadata($existingMap), [
                    'source_workspace_id' => (string) ($source->workspace_id ?? ''),
                    'project_resource_id' => $applicationResource->getKey(),
                ]),
                'imported_at' => $now,
                'reconciled_at' => $now,
            ];

            if ($existingMap !== null) {
                $existingMap->fill($mapAttributes)->save();
            } else {
                LegacyIdentityMap::query()->create([
                    'source_product' => 'monitor',
                    'source_entity' => 'application',
                    'source_id' => $sourceId,
                    ...$mapAttributes,
                ]);
            }

            return true;
        });
    }

    private function importEnvironment(Project $project, object $source, bool $parentDeleted, mixed $now): void
    {
        $sourceId = (string) $source->id;
        $existingMap = $this->sourceMapping('environment', $sourceId, lock: true);

        if ($existingMap !== null && ($existingMap->status !== 'needs_review' || $existingMap->canonical_id !== null)) {
            throw new RuntimeException("Monitor environment {$sourceId} already has a mapping and cannot be imported with its application.");
        }

        $isDeleted = $parentDeleted || ($source->deleted_at ?? null) !== null;
        $status = $isDeleted ? 'archived' : ((string) $source->status === 'paused' ? 'paused' : 'active');
        $environment = ProjectEnvironment::query()->create([
            'project_id' => $project->getKey(),
            'created_by_user_id' => null,
            'name' => (string) $source->name,
            'slug' => $this->environmentSlug((string) $source->slug, $sourceId),
            'environment_type' => $this->environmentType((string) $source->slug, (string) $source->name),
            'status' => $status,
            'metadata' => [
                'source_product' => 'monitor',
                'source_environment_id' => $sourceId,
                'source_application_id' => (string) $source->application_id,
                'source_status' => (string) $source->status,
                'event_count' => (int) ($source->event_count ?? 0),
                'last_seen_at' => $source->last_seen_at ?? null,
                'deleted_at' => $source->deleted_at ?? null,
                'ingest_credentials_remain_in_monitor_database' => true,
            ],
        ]);
        $this->preserveTimestamps($environment, $source);

        $resource = ProjectResource::query()->create([
            'project_id' => $project->getKey(),
            'environment_id' => $environment->getKey(),
            'product' => 'monitor',
            'resource_type' => 'environment',
            'resource_id' => $sourceId,
            'name' => $source->name,
            'status' => $status,
            'mapped_at' => now(),
            'metadata' => [
                'source_application_id' => (string) $source->application_id,
                'source_slug' => (string) $source->slug,
                'source_status' => (string) $source->status,
                'event_count' => (int) ($source->event_count ?? 0),
                'last_seen_at' => $source->last_seen_at ?? null,
                'deleted_at' => $source->deleted_at ?? null,
                'ingest_credentials_remain_in_monitor_database' => true,
            ],
        ]);
        $this->preserveTimestamps($resource, $source);

        $attributes = [
            'canonical_entity' => 'project_environment',
            'canonical_id' => $environment->getKey(),
            'status' => 'reconciled',
            'batch_key' => self::BATCH_KEY,
            'reconciliation_notes' => 'Imported as a Core project environment mapped by Monitor source ID.',
            'metadata' => array_merge($this->reconciledMetadata($existingMap), [
                'source_application_id' => (string) $source->application_id,
                'project_resource_id' => $resource->getKey(),
            ]),
            'imported_at' => now(),
            'reconciled_at' => now(),
        ];

        if ($existingMap !== null) {
            $existingMap->fill($attributes)->save();
        } else {
            LegacyIdentityMap::query()->create([
                'source_product' => 'monitor',
                'source_entity' => 'environment',
                'source_id' => $sourceId,
                ...$attributes,
            ]);
        }
    }

    /** @param Collection<int, object> $sourceInvitations
     * @param  array<string, int>  $report
     */
    private function importInvitations(Workspace $workspace, Collection $sourceInvitations, array &$report): void
    {
        foreach ($sourceInvitations as $source) {
            $sourceId = (string) $source->id;
            $existingMap = $this->sourceMapping('workspace_invitation', $sourceId, lock: true);

            if ($existingMap !== null && ($existingMap->status !== 'needs_review' || $existingMap->canonical_id !== null)) {
                continue;
            }

            if (! in_array((string) $source->role, ['admin', 'member', 'viewer'], true)) {
                if ($this->recordReviewInsideTransaction('workspace_invitation', $sourceId, ['unsupported_invitation_role'])) {
                    $report['review_records_created']++;
                }

                continue;
            }

            if (WorkspaceInvitation::query()->where('token_hash', (string) $source->token_hash)->exists()) {
                if ($this->recordReviewInsideTransaction('workspace_invitation', $sourceId, ['invitation_token_hash_already_exists_in_core'])) {
                    $report['review_records_created']++;
                }

                continue;
            }

            $email = trim((string) $source->email);
            $invitation = WorkspaceInvitation::query()->create([
                'workspace_id' => $workspace->getKey(),
                'invited_by_user_id' => null,
                'email' => $email,
                'email_normalized' => Str::lower($email),
                'role' => (string) $source->role,
                'token_hash' => (string) $source->token_hash,
                'status' => $source->accepted_at !== null
                    ? 'accepted'
                    : (now()->greaterThan($source->expires_at) ? 'expired' : 'pending'),
                'expires_at' => $source->expires_at,
                'accepted_at' => $source->accepted_at,
            ]);
            $this->preserveTimestamps($invitation, $source);

            $attributes = [
                'canonical_entity' => 'workspace_invitation',
                'canonical_id' => $invitation->getKey(),
                'status' => 'reconciled',
                'batch_key' => self::BATCH_KEY,
                'reconciliation_notes' => null,
                'metadata' => array_merge($this->reconciledMetadata($existingMap), [
                    'source_workspace_id' => (string) $source->workspace_id,
                ]),
                'imported_at' => now(),
                'reconciled_at' => now(),
            ];

            if ($existingMap !== null) {
                $existingMap->fill($attributes)->save();
            } else {
                LegacyIdentityMap::query()->create([
                    'source_product' => 'monitor',
                    'source_entity' => 'workspace_invitation',
                    'source_id' => $sourceId,
                    ...$attributes,
                ]);
            }

            $report['invitations_imported']++;
        }
    }

    /** @param list<string> $reasons */
    private function recordReview(string $entity, string $sourceId, array $reasons): bool
    {
        return DB::connection('core')->transaction(
            fn (): bool => $this->recordReviewInsideTransaction($entity, $sourceId, $reasons),
        );
    }

    /** @param list<string> $reasons */
    private function recordReviewInsideTransaction(string $entity, string $sourceId, array $reasons): bool
    {
        $existingMap = $this->sourceMapping($entity, $sourceId, lock: true);

        if ($existingMap !== null) {
            if ($existingMap->status === 'needs_review') {
                $metadata = $existingMap->metadata ?? [];
                if (isset($metadata['reason_codes']) && $metadata['reason_codes'] !== $reasons) {
                    $metadata['review_history'][] = [
                        'reason_codes' => $metadata['reason_codes'],
                        'notes' => $existingMap->reconciliation_notes,
                        'recorded_at' => now()->toISOString(),
                    ];
                }
                $metadata['reason_codes'] = $reasons;
                $existingMap->fill([
                    'reconciliation_notes' => implode('; ', $reasons),
                    'metadata' => $metadata,
                ])->save();
            }

            return false;
        }

        LegacyIdentityMap::query()->create([
            'source_product' => 'monitor',
            'source_entity' => $entity,
            'source_id' => $sourceId,
            'canonical_entity' => match ($entity) {
                'workspace' => 'workspace',
                'workspace_invitation' => 'workspace_invitation',
                'environment' => 'project_environment',
                default => 'project',
            },
            'canonical_id' => null,
            'status' => 'needs_review',
            'batch_key' => self::BATCH_KEY,
            'reconciliation_notes' => implode('; ', $reasons),
            'metadata' => ['reason_codes' => $reasons],
            'imported_at' => now(),
        ]);

        return true;
    }

    /** @return array<string, mixed> */
    private function reconciledMetadata(?LegacyIdentityMap $existingMap): array
    {
        $metadata = $existingMap?->metadata ?? [];

        if (isset($metadata['reason_codes'])) {
            $metadata['review_history'][] = [
                'reason_codes' => $metadata['reason_codes'],
                'notes' => $existingMap?->reconciliation_notes,
                'recorded_at' => $existingMap?->created_at?->toISOString(),
            ];
            unset($metadata['reason_codes']);
        }

        $metadata['imported_by'] = 'platform:import-monitor-workspaces-applications';

        return $metadata;
    }

    /** @return Collection<string, LegacyIdentityMap> */
    private function mappingsFor(string $entity): Collection
    {
        return LegacyIdentityMap::query()
            ->where('source_product', 'monitor')
            ->where('source_entity', $entity)
            ->get()
            ->keyBy(fn (LegacyIdentityMap $mapping): string => (string) $mapping->source_id);
    }

    private function sourceMapping(string $entity, string $sourceId, bool $lock = false): ?LegacyIdentityMap
    {
        $query = LegacyIdentityMap::query()
            ->where('source_product', 'monitor')
            ->where('source_entity', $entity)
            ->where('source_id', $sourceId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function canonicalUserId(int|string|null $sourceId, Collection $userMaps): ?string
    {
        if ($sourceId === null) {
            return null;
        }

        $mapping = $userMaps->get((string) $sourceId);

        return $mapping?->status === 'reconciled' && $mapping->canonical_entity === 'user'
            ? (string) $mapping->canonical_id
            : null;
    }

    private function preserveTimestamps(Model $target, object $source): void
    {
        $timestamps = [];

        foreach (['created_at', 'updated_at'] as $column) {
            if (isset($source->{$column})) {
                $timestamps[$column] = $source->{$column};
            }
        }

        if ($timestamps !== []) {
            $target->forceFill($timestamps)->saveQuietly();
        }
    }

    private function workspaceSlug(?string $sourceSlug, string $sourceId): string
    {
        $suffix = '-monitor-'.$sourceId;
        $base = Str::slug($sourceSlug ?: '') ?: 'monitor-workspace';

        return Str::limit($base, 120 - strlen($suffix), '').$suffix;
    }

    private function applicationSlug(?string $sourceSlug, string $workspaceId, string $sourceId): string
    {
        $suffix = '-m'.$workspaceId.'a'.$sourceId;
        $base = Str::slug($sourceSlug ?: '') ?: 'monitor-application';

        return Str::limit($base, 120 - strlen($suffix), '').$suffix;
    }

    private function environmentSlug(string $sourceSlug, string $sourceId): string
    {
        $suffix = '-m'.$sourceId;

        return Str::limit(Str::slug($sourceSlug) ?: 'environment', 120 - strlen($suffix), '').$suffix;
    }

    private function environmentType(string $slug, string $name): string
    {
        foreach ([
            'production' => ['production', 'prod'],
            'staging' => ['staging'],
            'preview' => ['preview'],
            'development' => ['development', 'dev'],
        ] as $type => $aliases) {
            if (in_array(Str::lower(trim($slug)), $aliases, true) || in_array(Str::lower(trim($name)), $aliases, true)) {
                return $type;
            }
        }

        return 'custom';
    }
}
