<?php

namespace App\Core\Services\Migration;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\Project;
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

final class ImportAnalyticsWorkspacesAndSitesIntoCore
{
    private const BATCH_KEY = 'analytics-workspace-site-import-v1';

    /**
     * Preview or import Analytics workspaces, memberships, invitations, and sites.
     * Each Analytics workspace becomes a distinct Core workspace; names are never
     * used to merge it with a workspace imported from another product.
     *
     * @return array{workspaces_seen:int,workspaces_ready:int,workspaces_imported:int,workspaces_already_mapped:int,workspaces_blocked:int,sites_seen:int,sites_ready:int,sites_imported:int,sites_already_mapped:int,sites_blocked:int,unmapped_members:int,invitations_seen:int,invitations_imported:int,review_records_created:int}
     */
    public function run(bool $apply = false): array
    {
        foreach (['users', 'workspaces', 'workspace_user', 'sites'] as $table) {
            if (! Schema::connection('analytics')->hasTable($table)) {
                throw new RuntimeException("The Analytics {$table} table is unavailable on the analytics connection.");
            }
        }

        foreach ([
            'legacy_identity_maps', 'workspaces', 'workspace_memberships', 'workspace_product_access',
            'workspace_invitations', 'projects', 'project_memberships', 'project_products', 'project_resources',
        ] as $table) {
            if (! Schema::connection('core')->hasTable($table)) {
                throw new RuntimeException('Run the Core platform migration before importing Analytics workspaces and sites.');
            }
        }

        $workspaces = DB::connection('analytics')->table('workspaces')->orderBy('id')->get();
        $memberships = DB::connection('analytics')->table('workspace_user')
            ->orderBy('workspace_id')->orderBy('user_id')->get()
            ->groupBy(fn (object $membership): string => (string) $membership->workspace_id);
        $invitations = Schema::connection('analytics')->hasTable('invitations')
            ? DB::connection('analytics')->table('invitations')->orderBy('workspace_id')->orderBy('id')->get()
                ->groupBy(fn (object $invitation): string => (string) $invitation->workspace_id)
            : collect();
        $sites = DB::connection('analytics')->table('sites')->orderBy('workspace_id')->orderBy('id')->get();
        $userMaps = $this->mappingsFor('user');
        $workspaceMaps = $this->mappingsFor('workspace');
        $siteMaps = $this->mappingsFor('site');

        $report = [
            'workspaces_seen' => $workspaces->count(),
            'workspaces_ready' => 0,
            'workspaces_imported' => 0,
            'workspaces_already_mapped' => 0,
            'workspaces_blocked' => 0,
            'sites_seen' => $sites->count(),
            'sites_ready' => 0,
            'sites_imported' => 0,
            'sites_already_mapped' => 0,
            'sites_blocked' => 0,
            'unmapped_members' => 0,
            'invitations_seen' => $invitations->sum(fn (Collection $items): int => $items->count()),
            'invitations_imported' => 0,
            'review_records_created' => 0,
        ];

        foreach ($workspaces as $workspace) {
            $sourceId = (string) $workspace->id;
            $existing = $workspaceMaps->get($sourceId);

            if ($existing?->status === 'reconciled') {
                $report['workspaces_already_mapped']++;

                if ($apply) {
                    $coreWorkspace = Workspace::query()->find($existing->canonical_id);

                    if ($coreWorkspace !== null) {
                        DB::connection('core')->transaction(function () use ($coreWorkspace, $invitations, $sourceId, $userMaps, &$report): void {
                            $this->importInvitations(
                                $coreWorkspace,
                                $invitations->get($sourceId, collect()),
                                $userMaps,
                                $report,
                            );
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
            $reasons = $this->workspaceReviewReasons($sourceMembers, $userMaps, $report);

            if ($reasons !== []) {
                $report['workspaces_blocked']++;
                if ($apply && $this->recordReview('workspace', $sourceId, $reasons)) {
                    $report['review_records_created']++;
                }

                continue;
            }

            $report['workspaces_ready']++;

            if ($apply && $this->importWorkspace(
                $workspace,
                $sourceMembers,
                $invitations->get($sourceId, collect()),
                $userMaps,
                $report,
            )) {
                $report['workspaces_imported']++;
            }
        }

        $workspaceMaps = $this->mappingsFor('workspace');

        foreach ($sites as $site) {
            $sourceId = (string) $site->id;
            $existing = $siteMaps->get($sourceId);

            if ($existing?->status === 'reconciled') {
                $report['sites_already_mapped']++;

                continue;
            }

            if ($existing !== null && ($existing->status !== 'needs_review' || $existing->canonical_id !== null)) {
                $report['sites_blocked']++;

                continue;
            }

            $workspaceMapping = $workspaceMaps->get((string) $site->workspace_id);

            if ($workspaceMapping === null || $workspaceMapping->status !== 'reconciled') {
                $report['sites_blocked']++;
                if ($apply && $this->recordReview('site', $sourceId, ['analytics_workspace_not_reconciled'])) {
                    $report['review_records_created']++;
                }

                continue;
            }

            $coreWorkspace = Workspace::query()->find($workspaceMapping->canonical_id);

            if ($coreWorkspace === null) {
                $report['sites_blocked']++;
                if ($apply && $this->recordReview('site', $sourceId, ['canonical_workspace_missing'])) {
                    $report['review_records_created']++;
                }

                continue;
            }

            if (ProjectResource::query()
                ->where('product', 'analytics')
                ->where('resource_type', 'site')
                ->where('resource_id', $sourceId)
                ->exists()) {
                $report['sites_blocked']++;
                if ($apply && $this->recordReview('site', $sourceId, ['analytics_site_resource_already_mapped'])) {
                    $report['review_records_created']++;
                }

                continue;
            }

            $report['sites_ready']++;

            if ($apply && $this->importSite($site, $coreWorkspace)) {
                $report['sites_imported']++;
            }
        }

        return $report;
    }

    /** @param Collection<int, object> $sourceMembers
     * @param  array<string, int>  $report
     * @return list<string>
     */
    private function workspaceReviewReasons(Collection $sourceMembers, Collection $userMaps, array &$report): array
    {
        $reasons = [];
        $owners = $sourceMembers->filter(fn (object $member): bool => (string) $member->role === 'owner');

        if ($owners->count() !== 1) {
            $reasons[] = $owners->isEmpty() ? 'workspace_owner_missing' : 'workspace_has_multiple_owners';
        }

        foreach ($sourceMembers as $member) {
            if ($this->canonicalUserId($member->user_id, $userMaps) === null) {
                $report['unmapped_members']++;
                $reasons[] = (string) $member->role === 'owner'
                    ? 'workspace_owner_identity_unmapped'
                    : 'workspace_member_identity_unmapped';
            }

            if (! in_array((string) $member->role, ['owner', 'admin', 'viewer'], true)) {
                $reasons[] = 'unsupported_workspace_role';
            }
        }

        return array_values(array_unique($reasons));
    }

    /** @param Collection<int, object> $sourceMembers
     * @param  Collection<int, object>  $sourceInvitations
     * @param  Collection<string, LegacyIdentityMap>  $userMaps
     * @param  array<string, int>  $report
     */
    private function importWorkspace(
        object $source,
        Collection $sourceMembers,
        Collection $sourceInvitations,
        Collection $userMaps,
        array &$report,
    ): bool {
        $sourceId = (string) $source->id;
        $ownerSource = $sourceMembers->first(fn (object $member): bool => (string) $member->role === 'owner');
        $ownerId = $this->canonicalUserId($ownerSource?->user_id, $userMaps);

        if ($ownerId === null) {
            return false;
        }

        return DB::connection('core')->transaction(function () use (
            $source,
            $sourceId,
            $ownerId,
            $sourceMembers,
            $sourceInvitations,
            $userMaps,
            &$report,
        ): bool {
            $existingMap = $this->sourceMapping('workspace', $sourceId, lock: true);

            if ($existingMap !== null && ($existingMap->status !== 'needs_review' || $existingMap->canonical_id !== null)) {
                return false;
            }

            $now = now();
            $workspace = Workspace::query()->create([
                'owner_user_id' => $ownerId,
                'name' => $source->name ?: 'Analytics workspace '.$sourceId,
                'slug' => $this->workspaceSlug($source->slug ?? null, $sourceId),
                'status' => 'active',
                'settings' => [
                    'source_product' => 'analytics',
                    'source_workspace_id' => $sourceId,
                    'source_slug' => $source->slug ?? null,
                ],
            ]);
            $this->preserveTimestamps($workspace, $source);

            $canonicalMembers = [];

            foreach ($sourceMembers as $sourceMember) {
                $canonicalUserId = $this->canonicalUserId($sourceMember->user_id, $userMaps);

                if ($canonicalUserId === null) {
                    continue;
                }

                $canonicalMembers[$canonicalUserId] = [
                    'role' => (string) $sourceMember->role,
                    'source' => $sourceMember,
                ];
            }

            foreach ($canonicalMembers as $canonicalUserId => $member) {
                $sourceMembership = $member['source'];
                $membership = WorkspaceMembership::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'user_id' => $canonicalUserId,
                    'role' => $member['role'],
                    'status' => 'active',
                    'joined_at' => $sourceMembership->created_at ?? $now,
                ]);
                $this->preserveTimestamps($membership, $sourceMembership);

                $productAccess = WorkspaceProductAccess::query()->create([
                    'membership_id' => $membership->getKey(),
                    'product' => 'analytics',
                    'role' => $member['role'],
                    'status' => 'active',
                    'granted_by_user_id' => null,
                    'granted_at' => $sourceMembership->created_at ?? $now,
                ]);
                $this->preserveTimestamps($productAccess, $sourceMembership);
            }

            $this->importInvitations($workspace, $sourceInvitations, $userMaps, $report);

            $mapAttributes = [
                'canonical_entity' => 'workspace',
                'canonical_id' => $workspace->getKey(),
                'status' => 'reconciled',
                'batch_key' => self::BATCH_KEY,
                'reconciliation_notes' => 'Imported as a distinct Core workspace; no name-based workspace merging was performed.',
                'metadata' => array_merge(
                    $this->reconciledMetadata($existingMap, 'platform:import-analytics-workspaces-sites'),
                    [
                        'owner_user_id' => $ownerId,
                        'source_slug' => $source->slug ?? null,
                    ],
                ),
                'imported_at' => $now,
                'reconciled_at' => $now,
            ];

            if ($existingMap !== null) {
                $existingMap->fill($mapAttributes)->save();
            } else {
                LegacyIdentityMap::query()->create([
                    'source_product' => 'analytics',
                    'source_entity' => 'workspace',
                    'source_id' => $sourceId,
                    ...$mapAttributes,
                ]);
            }

            return true;
        });
    }

    private function importSite(object $source, Workspace $workspace): bool
    {
        $sourceId = (string) $source->id;
        $workspaceId = (string) $workspace->getKey();
        $ownerId = (string) $workspace->owner_user_id;

        return DB::connection('core')->transaction(function () use ($source, $sourceId, $workspaceId, $ownerId): bool {
            $existingMap = $this->sourceMapping('site', $sourceId, lock: true);

            if ($existingMap !== null && ($existingMap->status !== 'needs_review' || $existingMap->canonical_id !== null)) {
                return false;
            }

            if (ProjectResource::query()
                ->where('product', 'analytics')
                ->where('resource_type', 'site')
                ->where('resource_id', $sourceId)
                ->exists()) {
                return $this->recordReviewInsideTransaction('site', $sourceId, ['analytics_site_resource_already_mapped']);
            }

            $now = now();
            $isDeleted = ($source->deleted_at ?? null) !== null;
            $project = Project::query()->create([
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $ownerId,
                'name' => (string) $source->name,
                'slug' => $this->siteSlug($source->slug ?? null, $sourceId),
                'status' => $isDeleted ? 'archived' : 'active',
                'description' => null,
                'archived_at' => $isDeleted ? $source->deleted_at : null,
                'metadata' => [
                    'source_product' => 'analytics',
                    'source_workspace_id' => (string) $source->workspace_id,
                    'source_site_id' => $sourceId,
                    'source_slug' => $source->slug ?? null,
                ],
            ]);
            $this->preserveTimestamps($project, $source);

            ProjectProduct::query()->create([
                'project_id' => $project->getKey(),
                'product' => 'analytics',
                'status' => $isDeleted ? 'inactive' : 'active',
                'requested_by_user_id' => $ownerId,
                'activated_at' => $source->created_at ?? $now,
                'metadata' => ['migration_source' => 'analytics'],
            ]);

            $resourceStatus = $isDeleted
                ? 'archived'
                : ((bool) ($source->collection_enabled ?? true) ? 'active' : 'paused');
            $resource = ProjectResource::query()->create([
                'project_id' => $project->getKey(),
                'product' => 'analytics',
                'resource_type' => 'site',
                'resource_id' => $sourceId,
                'resource_public_id' => $source->public_id,
                'name' => $source->name,
                'status' => $resourceStatus,
                'mapped_at' => $now,
                'metadata' => [
                    'source_workspace_id' => (string) $source->workspace_id,
                    'source_slug' => $source->slug ?? null,
                    'domains' => $this->jsonArray($source->domains ?? null),
                    'excluded_paths' => $this->jsonArray($source->excluded_paths ?? null),
                    'timezone' => $source->timezone ?? 'UTC',
                    'verified_at' => $source->verified_at ?? null,
                    'collection_enabled' => (bool) ($source->collection_enabled ?? true),
                    'collection_paused_at' => $source->collection_paused_at ?? null,
                    'last_event_at' => $source->last_event_at ?? null,
                    'last_processed_at' => $source->last_processed_at ?? null,
                    'deleted_at' => $source->deleted_at ?? null,
                ],
            ]);
            $this->preserveTimestamps($resource, $source);

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

            $mapAttributes = [
                'canonical_entity' => 'project',
                'canonical_id' => $project->getKey(),
                'status' => 'reconciled',
                'batch_key' => self::BATCH_KEY,
                'reconciliation_notes' => 'Imported as a Core project with its Analytics site resource mapped by source ID.',
                'metadata' => [
                    'source_workspace_id' => (string) $source->workspace_id,
                    'project_resource_id' => $resource->getKey(),
                    'source_public_id' => (string) $source->public_id,
                ],
                'imported_at' => $now,
                'reconciled_at' => $now,
            ];

            if ($existingMap !== null) {
                $existingMap->fill($mapAttributes)->save();
            } else {
                LegacyIdentityMap::query()->create([
                    'source_product' => 'analytics',
                    'source_entity' => 'site',
                    'source_id' => $sourceId,
                    ...$mapAttributes,
                ]);
            }

            return true;
        });
    }

    /**
     * @param  Collection<int, object>  $sourceInvitations
     * @param  Collection<string, LegacyIdentityMap>  $userMaps
     * @param  array<string, int>  $report
     */
    private function importInvitations(
        Workspace $workspace,
        Collection $sourceInvitations,
        Collection $userMaps,
        array &$report,
    ): void {
        foreach ($sourceInvitations as $invitation) {
            $invitationResult = $this->importInvitation($workspace, $invitation, $userMaps);

            if ($invitationResult['imported']) {
                $report['invitations_imported']++;
            }

            if ($invitationResult['review_record_created']) {
                $report['review_records_created']++;
            }
        }
    }

    /**
     * @param  Collection<string, LegacyIdentityMap>  $userMaps
     * @return array{imported:bool,review_record_created:bool}
     */
    private function importInvitation(Workspace $workspace, object $source, Collection $userMaps): array
    {
        $sourceId = (string) $source->id;
        $existing = $this->sourceMapping('invitation', $sourceId, lock: true);

        if ($existing !== null && ($existing->status !== 'needs_review' || $existing->canonical_id !== null)) {
            return ['imported' => false, 'review_record_created' => false];
        }

        if (! in_array((string) $source->role, ['admin', 'member', 'viewer'], true)) {
            return [
                'imported' => false,
                'review_record_created' => $this->recordReviewInsideTransaction(
                    'invitation',
                    $sourceId,
                    ['unsupported_invitation_role'],
                ),
            ];
        }

        $email = trim((string) $source->email);
        $expiresAt = $source->expires_at;
        $status = $source->accepted_at !== null
            ? 'accepted'
            : (now()->greaterThan($expiresAt) ? 'expired' : 'pending');
        $now = now();
        $invitation = WorkspaceInvitation::query()->create([
            'workspace_id' => $workspace->getKey(),
            'invited_by_user_id' => $this->canonicalUserId($source->invited_by ?? null, $userMaps),
            'email' => $email,
            'email_normalized' => Str::lower($email),
            'role' => (string) $source->role,
            'token_hash' => (string) $source->token_hash,
            'status' => $status,
            'expires_at' => $expiresAt,
            'accepted_at' => $source->accepted_at,
        ]);
        $this->preserveTimestamps($invitation, $source);

        $mapAttributes = [
            'canonical_entity' => 'workspace_invitation',
            'canonical_id' => $invitation->getKey(),
            'status' => 'reconciled',
            'batch_key' => self::BATCH_KEY,
            'reconciliation_notes' => null,
            'metadata' => array_merge(
                $this->reconciledMetadata($existing, 'platform:import-analytics-workspaces-sites'),
                ['source_invited_by' => (string) ($source->invited_by ?? '')],
            ),
            'imported_at' => $now,
            'reconciled_at' => $now,
        ];

        if ($existing !== null) {
            $existing->fill($mapAttributes)->save();
        } else {
            LegacyIdentityMap::query()->create([
                'source_product' => 'analytics',
                'source_entity' => 'invitation',
                'source_id' => $sourceId,
                ...$mapAttributes,
            ]);
        }

        return ['imported' => true, 'review_record_created' => false];
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

                if (($metadata['reason_codes'] ?? []) !== $reasons && isset($metadata['reason_codes'])) {
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
            'source_product' => 'analytics',
            'source_entity' => $entity,
            'source_id' => $sourceId,
            'canonical_entity' => match ($entity) {
                'workspace' => 'workspace',
                'invitation' => 'workspace_invitation',
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
    private function reconciledMetadata(?LegacyIdentityMap $existingMap, string $command): array
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

        $metadata['imported_by'] = $command;

        return $metadata;
    }

    /** @return Collection<string, LegacyIdentityMap> */
    private function mappingsFor(string $entity): Collection
    {
        return LegacyIdentityMap::query()
            ->where('source_product', 'analytics')
            ->where('source_entity', $entity)
            ->get()
            ->keyBy(fn (LegacyIdentityMap $mapping): string => (string) $mapping->source_id);
    }

    private function sourceMapping(string $entity, string $sourceId, bool $lock = false): ?LegacyIdentityMap
    {
        $query = LegacyIdentityMap::query()
            ->where('source_product', 'analytics')
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

    /** @return array<mixed> */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function workspaceSlug(?string $sourceSlug, string $sourceId): string
    {
        $base = Str::slug($sourceSlug ?: '') ?: 'analytics-workspace';
        $suffix = '-analytics-'.$sourceId;

        return Str::limit($base, 120 - strlen($suffix), '').$suffix;
    }

    private function siteSlug(?string $sourceSlug, string $sourceId): string
    {
        $base = Str::slug($sourceSlug ?: '') ?: 'analytics-site';
        $suffix = '-analytics-'.$sourceId;

        return Str::limit($base, 120 - strlen($suffix), '').$suffix;
    }
}
