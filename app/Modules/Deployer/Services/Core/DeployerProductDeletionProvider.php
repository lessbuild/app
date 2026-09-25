<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProductDeletionProvider;
use App\Core\Data\Deletion\ProductDeletionAttempt;
use App\Core\Data\Deletion\ProductDeletionPreview;
use App\Core\Data\Deletion\ProductDeletionResult;
use App\Core\Data\Deletion\ProductDeletionTarget;
use App\Core\Exceptions\Deletion\DeletionBlocked;
use App\Core\Services\Deletion\DeletionAuthority;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\ProductDeletionFence;
use App\Modules\Deployer\Models\ProductDeletionReceipt;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Recipe;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\ServerCommandExecution;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Deployer's local portion of a Core coordinated account or workspace deletion. */
final class DeployerProductDeletionProvider implements ProductDeletionProvider
{
    private const RETAINED = [
        'Managed servers and external services remain running and are not contacted or changed.',
        'Remote backup data remains in place; Deployer removes its local records only.',
        'Settled subscriptions and minimal anonymous audit markers are retained; no billing provider is contacted.',
        'Historical records with no surviving ownership link remain for reconciliation.',
    ];

    public function product(): string
    {
        return 'deployer';
    }

    public function inspect(ProductDeletionTarget $target): ProductDeletionPreview
    {
        $blockers = [];
        $counts = [];
        $ids = $this->relatedWorkspaceIds($target);

        if ($target->kind === 'workspace') {
            $workspace = Organization::query()->find($target->sourceId);
            if (! $workspace) {
                $blockers[] = 'deployer_workspace_missing';
            } elseif ((string) $workspace->owner_id !== (string) $target->actorSourceId) {
                $blockers[] = 'deployer_workspace_not_owned_by_actor';
            } else {
                if (! DB::connection('deployer')->table('organization_user')->where('organization_id', $workspace->getKey())
                    ->where('user_id', $target->actorSourceId)->exists()) {
                    $blockers[] = 'deployer_workspace_owner_membership_missing';
                }
                if ($this->hasUnassignedActorRecords($target->actorSourceId)) {
                    $blockers[] = 'deployer_actor_has_unassigned_records';
                }
                if ($workspace->members()->where('users.id', '!=', $target->actorSourceId)->exists()) {
                    $blockers[] = 'deployer_workspace_has_other_members';
                }
                $counts['workspaces'] = 1;
            }
        } elseif ($target->kind === 'account') {
            $user = User::query()->find($target->sourceId);
            if (! $user) {
                $blockers[] = 'deployer_account_missing';
            }

            $owned = Organization::query()->where('owner_id', $target->sourceId)->pluck('id')->map(fn ($id): string => (string) $id)->all();
            sort($owned);
            $declared = array_map('strval', $target->sourceWorkspaceIds);
            sort($declared);
            if ($owned !== $declared) {
                $blockers[] = 'deployer_owned_workspaces_changed';
            }
            foreach ($owned as $workspaceId) {
                if (! DB::connection('deployer')->table('organization_user')->where('organization_id', $workspaceId)
                    ->where('user_id', $target->sourceId)->exists()) {
                    $blockers[] = 'deployer_workspace_owner_membership_missing';
                    break;
                }
            }
            if ($target->sourceWorkspaceIds !== [] && DB::connection('deployer')->table('organization_user')
                ->whereIn('organization_id', $target->sourceWorkspaceIds)->where('user_id', '!=', $target->sourceId)->exists()) {
                $blockers[] = 'deployer_workspace_has_other_members';
            }
            if (DB::connection('deployer')->table('organization_user')->where('user_id', $target->sourceId)
                ->whereNotIn('organization_id', $target->sourceWorkspaceIds)->exists()) {
                $blockers[] = 'deployer_account_has_shared_workspace_membership';
            }
            if ($user && $this->hasForeignOwnedReferences($target->sourceId, $target->sourceWorkspaceIds)) {
                $blockers[] = 'deployer_account_has_foreign_owned_records';
            }
            if ($user && $this->hasUnassignedActorRecords($target->sourceId)) {
                $blockers[] = 'deployer_actor_has_unassigned_records';
            }
            $counts['workspaces'] = count($owned);
        } else {
            $blockers[] = 'deployer_deletion_target_unsupported';
        }

        if ($ids !== []) {
            if (Build::query()->whereIn('status', Build::ACTIVE_STATUSES)->whereHas('repository', fn ($query) => $query->whereIn('organization_id', $ids))->exists()
                || ServerCommandExecution::query()->active()->whereHas('server', fn ($query) => $query->whereIn('organization_id', $ids))->exists()
                || $this->hasRunningDatabaseOperations($ids)
                || $this->hasRunningConfigurationOperations($ids)
                || $this->hasRunningScheduledTasks($ids)
                || $this->hasRunningBackupOperations($ids)
                || $this->hasPendingLoadBalancerOperations($ids)
                || $this->hasProvisioningResources($ids)
                || $this->hasPreviewCleanupOperations($ids)) {
                $blockers[] = 'deployer_active_operations';
            }
            $counts['servers'] = DB::connection('deployer')->table('servers')->whereIn('organization_id', $ids)->count();
            $counts['websites'] = DB::connection('deployer')->table('websites')->whereIn('organization_id', $ids)->count();
            $counts['repositories'] = DB::connection('deployer')->table('repositories')->whereIn('organization_id', $ids)->count();
        }

        if ($target->kind === 'account' && $this->hasLiveNativeBilling($target->sourceId)) {
            $blockers[] = 'deployer_active_billing';
        }
        if ($target->kind === 'workspace' && $this->hasLiveNativeBilling($target->actorSourceId)) {
            $blockers[] = 'deployer_active_billing';
        }
        if (! Schema::connection('deployer')->hasTable('product_deletion_activity_claims')) {
            $blockers[] = 'deployer_activity_claim_store_unavailable';
        } elseif ($this->hasUnresolvedActivityClaims($target)) {
            $blockers[] = 'deployer_activity_claims_open';
        }

        return new ProductDeletionPreview(array_values(array_unique($blockers)), self::RETAINED, $counts);
    }

    public function prepare(ProductDeletionAttempt $attempt): ProductDeletionResult
    {
        $this->assertAuthority($attempt, 'prepare');
        $this->assertTargetIntent($attempt->target);

        $preview = $this->inspect($attempt->target);
        $drainBlockers = ['deployer_active_operations', 'deployer_activity_claims_open'];
        $permanentBlockers = array_values(array_filter($preview->blockers, static fn (string $reason): bool => ! in_array($reason, $drainBlockers, true)));
        if ($permanentBlockers !== []) {
            return $this->receipt($attempt, 'prepare', new ProductDeletionResult('blocked', $permanentBlockers[0], self::RETAINED));
        }

        try {
            $blocker = DB::connection('deployer')->transaction(function () use ($attempt, $drainBlockers): ?string {
                $this->assertAuthority($attempt, 'prepare', true);
                $this->reserveNativeTarget($attempt->target);
                $lockedPreview = $this->inspect($attempt->target);
                $lockedPermanentBlockers = array_values(array_filter($lockedPreview->blockers, static fn (string $reason): bool => ! in_array($reason, $drainBlockers, true)));
                if ($lockedPermanentBlockers !== []) {
                    return $lockedPermanentBlockers[0];
                }
                $this->persistFence($attempt->target->kind, $attempt->target->sourceId, $attempt);
                $this->assertAuthority($attempt, 'prepare', true);

                return null;
            });
            if ($blocker !== null) {
                return $this->receipt($attempt, 'prepare', new ProductDeletionResult('blocked', $blocker, self::RETAINED));
            }
        } catch (DeletionBlocked $exception) {
            throw $exception;
        } catch (Throwable) {
            return $this->receipt($attempt, 'prepare', new ProductDeletionResult('blocked', 'deployer_fence_conflict', self::RETAINED));
        }

        $after = $this->inspect($attempt->target);
        $changedBlockers = array_values(array_filter($after->blockers, static fn (string $reason): bool => ! in_array($reason, $drainBlockers, true)));
        if ($changedBlockers !== []) {
            return $this->receipt($attempt, 'prepare', new ProductDeletionResult('blocked', $changedBlockers[0], self::RETAINED));
        }
        $remainingDrain = array_values(array_intersect($after->blockers, $drainBlockers));
        if ($remainingDrain !== []) {
            return $this->receipt($attempt, 'prepare', new ProductDeletionResult('waiting', $remainingDrain[0], self::RETAINED));
        }

        return $this->receipt($attempt, 'prepare', new ProductDeletionResult('ready', null, self::RETAINED));
    }

    public function purge(ProductDeletionAttempt $attempt): ProductDeletionResult
    {
        $this->assertAuthority($attempt, 'purge');
        if ($this->hasReceipt($attempt, 'purge', ['completed']) && $this->hasCompletedTombstones($attempt)) {
            return new ProductDeletionResult('completed', null, self::RETAINED);
        }
        if (! $this->hasReceipt($attempt, 'prepare', ['ready'])) {
            return new ProductDeletionResult('blocked', 'deployer_prepare_receipt_missing', self::RETAINED);
        }
        if (! $this->hasMatchingFences($attempt)) {
            return new ProductDeletionResult('blocked', 'deployer_fence_missing_or_changed', self::RETAINED);
        }

        $blocker = $this->purgeBlocker($attempt);
        if ($blocker !== null) {
            $status = in_array($blocker, ['deployer_active_operations', 'deployer_activity_claims_open'], true) ? 'waiting' : 'blocked';
            $reason = $blocker === 'deployer_active_operations' ? 'deployer_operations_draining' : $blocker;
            $result = new ProductDeletionResult($status, $reason, self::RETAINED);

            return $this->receipt($attempt, 'purge', $result);
        }

        try {
            $result = DB::connection('deployer')->transaction(function () use ($attempt): ProductDeletionResult {
                $this->assertAuthority($attempt, 'purge', true);
                $this->reserveNativeTarget($attempt->target);
                $this->assertAuthority($attempt, 'purge', true);
                $blocker = $this->purgeBlocker($attempt, true);
                if ($blocker !== null) {
                    return new ProductDeletionResult(
                        in_array($blocker, ['deployer_active_operations', 'deployer_activity_claims_open'], true) ? 'waiting' : 'blocked',
                        $blocker === 'deployer_active_operations' ? 'deployer_operations_draining' : $blocker,
                        self::RETAINED,
                    );
                }

                if ($attempt->target->kind === 'workspace') {
                    // Query-builder deletes intentionally bypass observers that stop servers or provision replacements.
                    $this->purgeWorkspace($attempt->target->sourceId);
                } else {
                    $this->purgeAccount($attempt->target->sourceId);
                }
                ProductDeletionFence::query()->where('kind', $attempt->target->kind)
                    ->where('source_id', $attempt->target->sourceId)->where('request_id', $attempt->requestId)
                    ->where('payload_hash', $attempt->payloadHash)->update(['state' => 'completed']);
                $this->assertAuthority($attempt, 'purge', true);
                $this->assertTargetPurged($attempt->target);
                $completed = new ProductDeletionResult('completed', null, self::RETAINED);
                $this->receipt($attempt, 'purge', $completed);

                return $completed;
            });
        } catch (DeletionBlocked $exception) {
            throw $exception;
        } catch (Throwable) {
            return $this->receipt($attempt, 'purge', new ProductDeletionResult('waiting', 'deployer_cleanup_retryable', self::RETAINED));
        }

        if ($result->status !== 'completed') {
            return $this->receipt($attempt, 'purge', $result);
        }

        return $result;
    }

    private function assertAuthority(ProductDeletionAttempt $attempt, string $phase, bool $lock = false): void
    {
        abort_unless($attempt->phase === $phase, 409, 'Deletion phase does not match the Deployer operation.');
        app(DeletionAuthority::class)->assertAttempt($attempt, $lock);
    }

    private function assertTargetIntent(ProductDeletionTarget $target): void
    {
        if (! in_array($target->kind, ['account', 'workspace'], true) || $target->product !== 'deployer') {
            throw new \InvalidArgumentException('Unsupported Deployer deletion target.');
        }
        if ($target->kind === 'account' && $target->sourceId !== $target->actorSourceId) {
            throw new \InvalidArgumentException('Deployer account deletion must target its accepted actor.');
        }
    }

    private function reserveNativeTarget(ProductDeletionTarget $target): void
    {
        $table = $target->kind === 'account' ? 'users' : 'organizations';
        $id = $target->sourceId;
        $db = DB::connection('deployer');
        $updated = $db->table($table)->where('id', $id)->update(['id' => DB::raw('id')]);
        if ($updated === 0 && ! $db->table($table)->where('id', $id)->exists()) {
            throw new \RuntimeException('The native deletion target disappeared before preparation.');
        }
        $db->table($table)->where('id', $id)->lockForUpdate()->first();
    }

    /** @return list<string> */
    private function relatedWorkspaceIds(ProductDeletionTarget $target): array
    {
        return $target->kind === 'workspace'
            ? [$target->sourceId]
            : array_values(array_unique(array_map('strval', $target->sourceWorkspaceIds)));
    }

    private function persistFence(string $kind, string $sourceId, ProductDeletionAttempt $attempt): void
    {
        $fence = ProductDeletionFence::query()->where('kind', $kind)->where('source_id', $sourceId)->lockForUpdate()->first();
        if ($fence && ((string) $fence->request_id !== $attempt->requestId || (string) $fence->payload_hash !== $attempt->payloadHash)) {
            throw new \RuntimeException('A different accepted deletion already fences this source.');
        }
        if ($fence?->state === 'completed') {
            return;
        }
        ProductDeletionFence::query()->updateOrCreate(
            ['kind' => $kind, 'source_id' => $sourceId],
            ['request_id' => $attempt->requestId, 'payload_hash' => $attempt->payloadHash, 'generation' => $attempt->generation, 'state' => 'prepared'],
        );
    }

    private function receipt(ProductDeletionAttempt $attempt, string $phase, ProductDeletionResult $result): ProductDeletionResult
    {
        $existing = ProductDeletionReceipt::query()->where('step_id', $attempt->stepId)
            ->where('request_id', $attempt->requestId)->where('payload_hash', $attempt->payloadHash)
            ->where('phase', $phase)->first();
        if ($existing && $existing->status === 'completed') {
            return new ProductDeletionResult((string) $existing->status, $existing->reason_code, $existing->retained ?? self::RETAINED);
        }
        if ($existing && $phase === 'prepare' && $existing->status === 'ready') {
            return $result->status === 'ready'
                ? new ProductDeletionResult((string) $existing->status, $existing->reason_code, $existing->retained ?? self::RETAINED)
                : $result;
        }
        ProductDeletionReceipt::query()->updateOrCreate(
            ['step_id' => $attempt->stepId, 'request_id' => $attempt->requestId, 'payload_hash' => $attempt->payloadHash, 'phase' => $phase],
            ['kind' => $attempt->target->kind, 'source_id' => $attempt->target->sourceId,
                'status' => $result->status, 'reason_code' => $result->reasonCode, 'retained' => $result->retained],
        );

        return $result;
    }

    /** @param list<string> $statuses */
    private function hasReceipt(ProductDeletionAttempt $attempt, string $phase, array $statuses): bool
    {
        return ProductDeletionReceipt::query()->where('step_id', $attempt->stepId)
            ->where('request_id', $attempt->requestId)->where('payload_hash', $attempt->payloadHash)
            ->where('kind', $attempt->target->kind)->where('source_id', $attempt->target->sourceId)
            ->where('phase', $phase)->whereIn('status', $statuses)->exists();
    }

    private function hasMatchingFences(ProductDeletionAttempt $attempt): bool
    {
        return ProductDeletionFence::query()->where('kind', $attempt->target->kind)
            ->where('source_id', $attempt->target->sourceId)->where('request_id', $attempt->requestId)
            ->where('payload_hash', $attempt->payloadHash)->whereIn('state', ['prepared', 'completed'])->exists()
            && ($attempt->target->kind !== 'account' || $this->hasCompletedWorkspaceTombstones($attempt));
    }

    private function hasCompletedWorkspaceTombstones(ProductDeletionAttempt $attempt): bool
    {
        foreach ($attempt->target->sourceWorkspaceIds as $workspaceId) {
            $fence = ProductDeletionFence::query()->where('kind', 'workspace')->where('source_id', (string) $workspaceId)
                ->where('request_id', $attempt->requestId)->where('state', 'completed')->first();
            if (! $fence || ! ProductDeletionReceipt::query()->where('kind', 'workspace')->where('source_id', (string) $workspaceId)
                ->where('request_id', $attempt->requestId)->where('payload_hash', $fence->payload_hash)
                ->where('phase', 'purge')->where('status', 'completed')->exists()) {
                return false;
            }
        }

        return true;
    }

    private function hasCompletedTombstones(ProductDeletionAttempt $attempt): bool
    {
        return ProductDeletionFence::query()->where('kind', $attempt->target->kind)
            ->where('source_id', $attempt->target->sourceId)->where('request_id', $attempt->requestId)
            ->where('payload_hash', $attempt->payloadHash)->where('state', 'completed')->exists()
            && $this->hasReceipt($attempt, 'purge', ['completed'])
            && ($attempt->target->kind !== 'account' || $this->hasCompletedWorkspaceTombstones($attempt));
    }

    private function hasUnresolvedActivityClaims(ProductDeletionTarget $target): bool
    {
        $claims = DB::connection('deployer')->table('product_deletion_activity_claims')->whereIn('status', ['claimed', 'recovery_required']);

        return $target->kind === 'account'
            ? $claims->where('actor_source_id', $target->sourceId)->exists()
            : $claims->where('workspace_source_id', $target->sourceId)->exists();
    }

    private function purgeBlocker(ProductDeletionAttempt $attempt, bool $lock = false): ?string
    {
        $target = $attempt->target;
        if ($target->kind === 'account') {
            $user = User::query()->whereKey($target->sourceId);
            if ($lock) {
                $user->lockForUpdate();
            }
            if (! $user->first()) {
                return 'deployer_account_missing';
            }
            if (! $this->hasCompletedWorkspaceTombstones($attempt)) {
                return 'deployer_workspace_purge_incomplete';
            }
            if (Organization::query()->where('owner_id', $target->sourceId)->exists()
                || DB::connection('deployer')->table('organization_user')->where('user_id', $target->sourceId)->exists()
                || ($target->sourceWorkspaceIds !== [] && DB::connection('deployer')->table('organization_user')
                    ->whereIn('organization_id', $target->sourceWorkspaceIds)->exists())) {
                return 'deployer_account_workspaces_remain';
            }
            if ($this->hasForeignOwnedReferences($target->sourceId, $target->sourceWorkspaceIds)) {
                return 'deployer_account_has_foreign_owned_records';
            }
            if ($this->hasLiveNativeBilling($target->sourceId)) {
                return 'deployer_active_billing';
            }
        } else {
            $workspaceQuery = Organization::query()->whereKey($target->sourceId);
            if ($lock) {
                $workspaceQuery->lockForUpdate();
            }
            $workspace = $workspaceQuery->first();
            if (! $workspace) {
                return 'deployer_workspace_missing';
            }
            if ((string) $workspace->owner_id !== (string) $target->actorSourceId) {
                return 'deployer_workspace_not_owned_by_actor';
            }
            if ($workspace->members()->where('users.id', '!=', $target->actorSourceId)->exists()) {
                return 'deployer_workspace_has_other_members';
            }
        }

        foreach ($this->inspect($target)->blockers as $reason) {
            if (in_array($reason, ['deployer_active_operations', 'deployer_activity_claims_open'], true)) {
                return $reason;
            }
            if ($target->kind === 'account' && $reason === 'deployer_owned_workspaces_changed'
                && $this->hasCompletedWorkspaceTombstones($attempt)
                && ! Organization::query()->where('owner_id', $target->sourceId)->exists()) {
                continue;
            }

            return $reason;
        }

        return null;
    }

    private function assertTargetPurged(ProductDeletionTarget $target): void
    {
        if ($target->kind === 'workspace') {
            if (Organization::query()->whereKey($target->sourceId)->exists()
                || DB::connection('deployer')->table('organization_user')->where('organization_id', $target->sourceId)->exists()) {
                throw new \RuntimeException('Workspace rows remain after purge.');
            }
        } elseif (User::query()->whereKey($target->sourceId)->exists()
            || Organization::query()->where('owner_id', $target->sourceId)->exists()
            || DB::connection('deployer')->table('organization_user')->where('user_id', $target->sourceId)->exists()
            || ($target->sourceWorkspaceIds !== [] && DB::connection('deployer')->table('organization_user')
                ->whereIn('organization_id', $target->sourceWorkspaceIds)->exists())) {
            throw new \RuntimeException('Account or workspace rows remain after purge.');
        }
    }

    private function hasRunningDatabaseOperations(array $workspaceIds): bool
    {
        $environments = DB::connection('deployer')->table('environments')->select('environments.id')
            ->join('projects', 'projects.id', '=', 'environments.project_id')->whereIn('projects.organization_id', $workspaceIds);
        $resources = DB::connection('deployer')->table('environment_resources')->select('id')->whereIn('environment_id', $environments);

        return (Schema::connection('deployer')->hasTable('database_operation_runs')
            && DB::connection('deployer')->table('database_operation_runs')->whereIn('status', ['queued', 'running'])
                ->whereIn('environment_resource_id', $resources)->exists())
            || (Schema::connection('deployer')->hasTable('database_clones') && DB::connection('deployer')->table('database_clones')
                ->whereIn('status', ['queued', 'running'])->where(function (Builder $query) use ($resources): void {
                    $query->whereIn('source_resource_id', $resources)->orWhereIn('target_resource_id', $resources);
                })->exists());
    }

    private function hasRunningConfigurationOperations(array $workspaceIds): bool
    {
        return Schema::connection('deployer')->hasTable('configuration_operations')
            && DB::connection('deployer')->table('configuration_operations')->whereIn('status', ['queued', 'running', 'applying', 'awaiting_dispatch'])
                ->whereIn('configuration_application_id', DB::connection('deployer')->table('configuration_applications')->select('id')
                    ->whereIn('configuration_review_id', DB::connection('deployer')->table('configuration_reviews')->select('id')
                        ->whereIn('project_id', DB::connection('deployer')->table('projects')->select('id')->whereIn('organization_id', $workspaceIds))))->exists();
    }

    private function hasRunningScheduledTasks(array $workspaceIds): bool
    {
        return Schema::connection('deployer')->hasTable('scheduled_task_runs')
            && DB::connection('deployer')->table('scheduled_task_runs')->whereIn('status', ['queued', 'running'])
                ->whereIn('scheduled_task_id', DB::connection('deployer')->table('scheduled_tasks')->select('id')
                    ->whereIn('environment_id', DB::connection('deployer')->table('environments')->select('environments.id')
                        ->join('projects', 'projects.id', '=', 'environments.project_id')->whereIn('projects.organization_id', $workspaceIds)))->exists();
    }

    private function hasRunningBackupOperations(array $workspaceIds): bool
    {
        if (! Schema::connection('deployer')->hasTable('website_backups')) {
            return false;
        }
        $websites = DB::connection('deployer')->table('websites')->select('id')->whereIn('organization_id', $workspaceIds);
        $backupIds = DB::connection('deployer')->table('website_backups')->select('id')
            ->whereIn('website_id', $websites);

        return DB::connection('deployer')->table('website_backups')->whereIn('status', ['queued', 'running'])->whereIn('website_id', $websites)->exists()
            || (Schema::connection('deployer')->hasTable('backup_restores') && DB::connection('deployer')->table('backup_restores')
                ->whereIn('status', ['queued', 'running'])->whereIn('website_backup_id', $backupIds)->exists())
            || (Schema::connection('deployer')->hasTable('backup_restore_verifications') && DB::connection('deployer')->table('backup_restore_verifications')
                ->whereIn('status', ['queued', 'running'])->whereIn('website_backup_id', $backupIds)->exists());
    }

    private function hasPendingLoadBalancerOperations(array $workspaceIds): bool
    {
        return Schema::connection('deployer')->hasTable('load_balancers')
            && DB::connection('deployer')->table('load_balancers')->whereIn('organization_id', $workspaceIds)
                ->whereIn('status', ['pending', 'removing'])->exists();
    }

    private function hasProvisioningResources(array $workspaceIds): bool
    {
        return (Schema::connection('deployer')->hasTable('servers') && DB::connection('deployer')->table('servers')
            ->whereIn('organization_id', $workspaceIds)->whereIn('provisioning_status', ['queued', 'waiting_for_ip', 'provisioning'])->exists())
            || (Schema::connection('deployer')->hasTable('websites') && DB::connection('deployer')->table('websites')
                ->whereIn('organization_id', $workspaceIds)->whereIn('provisioning_status', ['queued', 'provisioning'])->exists());
    }

    private function hasPreviewCleanupOperations(array $workspaceIds): bool
    {
        return Schema::connection('deployer')->hasTable('preview_stack_cleanups')
            && DB::connection('deployer')->table('preview_stack_cleanups')->whereIn('status', ['queued', 'running'])
                ->where(function (Builder $query) use ($workspaceIds): void {
                    $query->whereIn('website_id', DB::connection('deployer')->table('websites')->select('id')->whereIn('organization_id', $workspaceIds))
                        ->orWhereIn('environment_id', DB::connection('deployer')->table('environments')->select('id')
                            ->whereIn('project_id', DB::connection('deployer')->table('projects')->select('id')->whereIn('organization_id', $workspaceIds)));
                })->exists();
    }

    private function hasLiveNativeBilling(string $userId): bool
    {
        if (! Schema::connection('deployer')->hasTable('subscriptions')) {
            return false;
        }

        return DB::connection('deployer')->table('subscriptions')->where('user_id', $userId)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $active): void {
                    $active->whereNotIn('stripe_status', ['canceled', 'incomplete_expired'])
                        ->where(function (Builder $period): void {
                            $period->whereNull('ends_at')->orWhere('ends_at', '>', now())
                                ->orWhere('trial_ends_at', '>', now());
                        });
                })->orWhere('ends_at', '>', now())->orWhere('trial_ends_at', '>', now());
            })->exists();
    }

    /** Block account deletion when a user-owned row could cross or outlive workspace scope. */
    private function hasForeignOwnedReferences(string $userId, array $workspaceIds): bool
    {
        $db = DB::connection('deployer');
        foreach (['providers', 'servers', 'websites', 'repositories', 'recipes'] as $table) {
            if (! Schema::connection('deployer')->hasTable($table) || ! Schema::connection('deployer')->hasColumn($table, 'user_id')) {
                continue;
            }
            $query = $db->table($table)->where('user_id', $userId);
            if (Schema::connection('deployer')->hasColumn($table, 'organization_id')) {
                $query->where(function (Builder $scope) use ($workspaceIds): void {
                    $scope->whereNull('organization_id');
                    if ($workspaceIds !== []) {
                        $scope->orWhereNotIn('organization_id', $workspaceIds);
                    }
                });
            }
            if ($query->exists()) {
                return true;
            }
        }

        // Restrict actor attribution records whose owning workspace is outside the accepted account scope.
        foreach (['projects', 'alert_destinations', 'status_pages', 'backup_destinations', 'metric_alert_rules', 'load_balancers', 'product_feedback', 'server_import_assessments'] as $table) {
            if (! Schema::connection('deployer')->hasTable($table) || ! Schema::connection('deployer')->hasColumn($table, 'created_by') && ! Schema::connection('deployer')->hasColumn($table, 'user_id')) {
                continue;
            }
            $actorColumn = Schema::connection('deployer')->hasColumn($table, 'created_by') ? 'created_by' : 'user_id';
            $query = $db->table($table)->where($actorColumn, $userId);
            if (Schema::connection('deployer')->hasColumn($table, 'organization_id')) {
                $query->where(function (Builder $scope) use ($workspaceIds): void {
                    $scope->whereNull('organization_id');
                    if ($workspaceIds !== []) {
                        $scope->orWhereNotIn('organization_id', $workspaceIds);
                    }
                });
            }
            if ($query->exists()) {
                return true;
            }
        }
        if (Schema::connection('deployer')->hasTable('organization_invitations')
            && $db->table('organization_invitations')->where('invited_by', $userId)->whereNotIn('organization_id', $workspaceIds)->exists()) {
            return true;
        }

        foreach ([
            ['scheduled_tasks', 'created_by', 'environment_id', 'environments'],
            ['deployment_schedules', 'created_by', 'project_id', 'projects'],
            ['scaling_schedules', 'created_by', 'project_id', 'projects'],
            ['configuration_reviews', 'requested_by', 'project_id', 'projects'],
        ] as [$table, $actorColumn, $foreignKey, $parentTable]) {
            if (! Schema::connection('deployer')->hasTable($table) || ! Schema::connection('deployer')->hasColumn($table, $actorColumn)) {
                continue;
            }
            $parentIds = $db->table($parentTable)->select('id');
            if ($parentTable === 'environments') {
                $parentIds->whereIn('project_id', $db->table('projects')->select('id')->whereNotIn('organization_id', $workspaceIds));
            } else {
                $parentIds->whereNotIn('organization_id', $workspaceIds);
            }
            if ($db->table($table)->where($actorColumn, $userId)->whereIn($foreignKey, $parentIds)->exists()) {
                return true;
            }
        }

        $scopedActorQueries = [
            ['website_domains', 'created_by', 'website_id', 'websites', 'organization_id'],
            ['database_users', 'created_by', 'environment_resource_id', 'environment_resources', 'environment_id'],
            ['database_clones', 'requested_by', 'source_resource_id', 'environment_resources', 'environment_id'],
            ['server_command_executions', 'user_id', 'server_id', 'servers', 'organization_id'],
            ['server_troubleshooting_sessions', 'user_id', 'server_id', 'servers', 'organization_id'],
        ];
        foreach ($scopedActorQueries as [$table, $actorColumn, $foreignKey, $parentTable, $scopeColumn]) {
            if (! Schema::connection('deployer')->hasTable($table) || ! Schema::connection('deployer')->hasColumn($table, $actorColumn)
                || ! Schema::connection('deployer')->hasTable($parentTable)) {
                continue;
            }
            $parents = $db->table($parentTable)->select('id');
            if ($scopeColumn === 'environment_id') {
                $parents->whereIn('environment_id', $db->table('environments')->select('environments.id')
                    ->join('projects', 'projects.id', '=', 'environments.project_id')->whereNotIn('projects.organization_id', $workspaceIds));
            } else {
                $parents->where(function (Builder $scope) use ($workspaceIds): void {
                    $scope->whereNull('organization_id');
                    if ($workspaceIds !== []) {
                        $scope->orWhereNotIn('organization_id', $workspaceIds);
                    }
                });
            }
            if ($db->table($table)->where($actorColumn, $userId)->whereIn($foreignKey, $parents)->exists()) {
                return true;
            }
        }
        if (Schema::connection('deployer')->hasTable('database_clones')
            && $db->table('database_clones')->where('requested_by', $userId)->whereIn('target_resource_id', $db->table('environment_resources')->select('id')
                ->whereIn('environment_id', $db->table('environments')->select('environments.id')
                    ->join('projects', 'projects.id', '=', 'environments.project_id')->whereNotIn('projects.organization_id', $workspaceIds)))->exists()) {
            return true;
        }

        if (Schema::connection('deployer')->hasTable('backup_restores') && Schema::connection('deployer')->hasColumn('backup_restores', 'requested_by')
            && $db->table('backup_restores')->where('requested_by', $userId)->whereIn('website_backup_id', $db->table('website_backups')->select('id')
                ->whereIn('website_id', $db->table('websites')->select('id')->whereNotIn('organization_id', $workspaceIds)))->exists()) {
            return true;
        }
        if (Schema::connection('deployer')->hasTable('backup_restore_verifications')
            && Schema::connection('deployer')->hasColumn('backup_restore_verifications', 'requested_by')
            && $db->table('backup_restore_verifications')->where('requested_by', $userId)->whereIn('website_backup_id', $db->table('website_backups')->select('id')
                ->whereIn('website_id', $db->table('websites')->select('id')->whereNotIn('organization_id', $workspaceIds)))->exists()) {
            return true;
        }

        return false;
    }

    private function hasUnassignedActorRecords(string $userId): bool
    {
        $db = DB::connection('deployer');
        foreach (['providers', 'servers', 'websites', 'repositories', 'recipes'] as $table) {
            if (Schema::connection('deployer')->hasTable($table) && Schema::connection('deployer')->hasColumn($table, 'user_id')
                && Schema::connection('deployer')->hasColumn($table, 'organization_id')
                && $db->table($table)->where('user_id', $userId)->whereNull('organization_id')->exists()) {
                return true;
            }
        }
        if (Schema::connection('deployer')->hasTable('builds') && Schema::connection('deployer')->hasColumn('builds', 'requested_by')) {
            $repoIds = $db->table('repositories')->select('id');
            if ($db->table('builds')->where('requested_by', $userId)->where(function (Builder $query) use ($repoIds): void {
                $query->whereNull('repository_id')->orWhereNotIn('repository_id', $repoIds);
            })->exists()) {
                return true;
            }
        }

        return false;
    }

    private function purgeWorkspace(string $workspaceId): void
    {
        $db = DB::connection('deployer');
        if (! $db->table('organizations')->where('id', $workspaceId)->exists()) {
            return;
        }
        $projectIds = $db->table('projects')->where('organization_id', $workspaceId)->pluck('id')->all();
        $repositoryIds = $db->table('repositories')->where('organization_id', $workspaceId)->pluck('id')->all();
        $websiteIds = $db->table('websites')->where('organization_id', $workspaceId)->pluck('id')->all();
        $providerIds = $db->table('providers')->where('organization_id', $workspaceId)->pluck('id')->all();
        $serverIds = $db->table('servers')->where('organization_id', $workspaceId)->pluck('id')->all();
        $recipeIds = $db->table('recipes')->where('organization_id', $workspaceId)->pluck('id')->all();
        $commandIds = Schema::connection('deployer')->hasTable('server_command_executions') && $serverIds !== []
            ? $db->table('server_command_executions')->whereIn('server_id', $serverIds)->pluck('id')->all()
            : [];
        $buildIds = $repositoryIds === [] ? [] : $db->table('builds')->whereIn('repository_id', $repositoryIds)->pluck('id')->all();
        $loadBalancerIds = Schema::connection('deployer')->hasTable('load_balancers')
            ? $db->table('load_balancers')->where('organization_id', $workspaceId)->pluck('id')->all()
            : [];

        $this->deleteWhereInIfExists('deployment_succeeded_outbox_events', 'build_id', $buildIds);
        $this->deleteWhereInIfExists('repository_webhook_deliveries', 'repository_id', $repositoryIds);
        $morphs = [
            (new Provider)->getMorphClass() => $providerIds,
            Server::class => $serverIds,
            Website::class => $websiteIds,
            Repository::class => $repositoryIds,
            Build::class => $buildIds,
            Project::class => $projectIds,
            Recipe::class => $recipeIds,
            ServerCommandExecution::class => $commandIds,
            Organization::class => [$workspaceId],
        ];
        $this->deleteMorphRows('logs', 'parentable', $morphs);
        $this->anonymizeMorphEvents($workspaceId, $morphs);
        // Builds have a historical unconstrained repository_id and need explicit cleanup.
        $this->deleteWhereInIfExists('builds', 'id', $buildIds);
        // Remove restricted children in dependency order before the organization cascades its server and websites.
        $this->deleteWhereInIfExists('load_balancer_nodes', 'load_balancer_id', $loadBalancerIds);
        $this->deleteWhereInIfExists('load_balancers', 'id', $loadBalancerIds);
        $this->deleteWhereInIfExists('website_backup_schedules', 'website_id', $websiteIds);
        $this->deleteWhereInIfExists('website_backups', 'website_id', $websiteIds);
        // Organization cascades product-owned rows. Opaque queued jobs and remote services are deliberately untouched.
        $db->table('organizations')->where('id', $workspaceId)->delete();
        if (Schema::connection('deployer')->hasTable('product_deletion_activity_claims')) {
            $db->table('product_deletion_activity_claims')->where('workspace_source_id', $workspaceId)->whereIn('status', ['completed', 'operator_recovered'])->delete();
        }
    }

    /** @param list<int|string> $ids @param array<string, mixed> $extra */
    private function deleteWhereInIfExists(string $table, string $column, array $ids, array $extra = []): void
    {
        if ($ids === [] || ! Schema::connection('deployer')->hasTable($table)) {
            return;
        }
        $query = DB::connection('deployer')->table($table)->whereIn($column, $ids);
        foreach ($extra as $field => $value) {
            $query->where($field, $value);
        }
        $query->delete();
    }

    /** @param array<string, list<int|string>> $morphs */
    private function deleteMorphRows(string $table, string $typeColumn, array $morphs): void
    {
        if (! Schema::connection('deployer')->hasTable($table)) {
            return;
        }
        foreach ($morphs as $type => $ids) {
            if ($ids !== []) {
                DB::connection('deployer')->table($table)->where($typeColumn.'_type', $type)->whereIn($typeColumn.'_id', $ids)->delete();
            }
        }
    }

    /** @param array<string, list<int|string>> $morphs */
    private function anonymizeMorphEvents(string $workspaceId, array $morphs): void
    {
        if (! Schema::connection('deployer')->hasTable('events')) {
            return;
        }
        $events = DB::connection('deployer')->table('events');
        $hasScope = false;
        foreach ($morphs as $type => $ids) {
            if ($ids !== []) {
                $hasScope = true;
                $events->orWhere(function (Builder $query) use ($type, $ids): void {
                    $query->where('parentable_type', $type)->whereIn('parentable_id', $ids);
                });
            }
        }
        if (Schema::connection('deployer')->hasColumn('events', 'organization_id')) {
            $events->orWhere('organization_id', $workspaceId);
            $hasScope = true;
        }
        if (! $hasScope) {
            return;
        }
        $updates = [];
        foreach (['event' => 'resource_deleted', 'category' => 'retained_audit', 'user_id' => null, 'parentable_type' => 'deployer_deleted_resource', 'parentable_id' => 0] as $column => $value) {
            if (Schema::connection('deployer')->hasColumn('events', $column)) {
                $updates[$column] = $value;
            }
        }
        if ($updates !== []) {
            $events->update($updates);
        }
    }

    private function purgeAccount(string $userId): void
    {
        $db = DB::connection('deployer');
        if (! $db->table('users')->where('id', $userId)->exists()) {
            return;
        }
        if (Schema::connection('deployer')->hasTable('personal_access_tokens')) {
            $db->table('personal_access_tokens')->where('tokenable_id', $userId)
                ->whereIn('tokenable_type', array_unique([User::class, (new User)->getMorphClass()]))->delete();
        }
        foreach (['sessions' => ['user_id' => $userId]] as $table => $filters) {
            if (Schema::connection('deployer')->hasTable($table)) {
                foreach ($filters as $column => $value) {
                    $db->table($table)->where($column, $value)->delete();
                }
            }
        }
        if (Schema::connection('deployer')->hasTable('password_reset_tokens')) {
            $email = $db->table('users')->where('id', $userId)->value('email');
            if ($email !== null) {
                $db->table('password_reset_tokens')->where('email', $email)->delete();
            }
        }
        if (Schema::connection('deployer')->hasTable('password_resets')) {
            $email = $db->table('users')->where('id', $userId)->value('email');
            $db->table('password_resets')->where('email', $email)->delete();
        }
        if (Schema::connection('deployer')->hasTable('notifications')) {
            $db->table('notifications')->whereIn('notifiable_type', array_unique([User::class, (new User)->getMorphClass()]))
                ->where('notifiable_id', $userId)->delete();
        }
        // Retain only an anonymous audit marker; event details can contain actor or resource PII.
        if (Schema::connection('deployer')->hasTable('events')) {
            $updates = [];
            if (Schema::connection('deployer')->hasColumn('events', 'user_id')) {
                $updates['user_id'] = null;
            }
            foreach (['event' => 'account_deleted', 'category' => 'retained_audit', 'parentable_type' => 'deployer_deleted_actor', 'parentable_id' => 0] as $column => $value) {
                if (Schema::connection('deployer')->hasColumn('events', $column)) {
                    $updates[$column] = $value;
                }
            }
            $db->table('events')->where(function (Builder $query) use ($userId): void {
                if (Schema::connection('deployer')->hasColumn('events', 'user_id')) {
                    $query->where('user_id', $userId)->orWhere(function (Builder $morph) use ($userId): void {
                        $morph->where('parentable_type', (new User)->getMorphClass())->where('parentable_id', $userId);
                    });
                } else {
                    $query->where('parentable_type', (new User)->getMorphClass())->where('parentable_id', $userId);
                }
            })->update($updates);
        }
        $this->deleteMorphRows('logs', 'parentable', [(new User)->getMorphClass() => [$userId]]);
        // Subscription rows remain as billing evidence; user deletion never invokes Cashier or Stripe.
        $db->table('users')->where('id', $userId)->delete();
        if (Schema::connection('deployer')->hasTable('product_deletion_activity_claims')) {
            $db->table('product_deletion_activity_claims')->where('actor_source_id', $userId)->whereIn('status', ['completed', 'operator_recovered'])->delete();
        }
    }
}
