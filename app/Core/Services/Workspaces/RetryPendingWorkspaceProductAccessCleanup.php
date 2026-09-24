<?php

namespace App\Core\Services\Workspaces;

use App\Core\Models\PlatformUser;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceMembershipEvent;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Identity\ProjectProductWorkspaceMembership;
use App\Core\Services\LegacyIdentityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RetryPendingWorkspaceProductAccessCleanup
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly ProjectProductWorkspaceMembership $productMemberships,
    ) {}

    public function pendingCount(): int
    {
        return $this->pendingQuery()->count();
    }

    /** @return array{completed:int,pending:int,skipped:int,processed:int} */
    public function retry(int $limit = 100): array
    {
        $grantIds = $this->pendingQuery()
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $results = ['completed' => 0, 'pending' => 0, 'skipped' => 0, 'processed' => 0];

        foreach ($grantIds as $grantId) {
            $outcome = $this->retryOne((string) $grantId);
            $results[$outcome]++;
            $results['processed']++;
        }

        return $results;
    }

    private function retryOne(string $grantId): string
    {
        return DB::connection('core')->transaction(function () use ($grantId): string {
            $grant = WorkspaceProductAccess::query()
                ->whereKey($grantId)
                ->where('status', 'revoked')
                ->where('metadata->local_membership_cleanup_pending', true)
                ->lockForUpdate()
                ->first();

            if ($grant === null) {
                return 'skipped';
            }

            $metadata = $grant->metadata ?? [];
            $projections = $metadata['managed_product_memberships'] ?? [];
            if (! is_array($projections) || $projections === []) {
                $this->complete($grant, $metadata, null, 0);

                return 'completed';
            }

            $membership = WorkspaceMembership::query()->find($grant->membership_id);
            $user = $membership?->user;
            if (! $membership instanceof WorkspaceMembership || ! $user instanceof PlatformUser) {
                return $this->leavePending($grant);
            }

            if (! $this->productMemberships->isAvailable($grant->product)) {
                return $this->leavePending($grant);
            }

            $sourceUserIds = $this->identities->sourceIdsFor($user, $grant->product);
            if (count($sourceUserIds) !== 1) {
                return $this->leavePending($grant);
            }

            try {
                $removed = $this->productMemberships->revoke(
                    $grant->product,
                    $sourceUserIds[0],
                    $projections,
                );
            } catch (LostConnectionException|QueryException $exception) {
                report($exception);

                return $this->leavePending($grant);
            }

            $this->complete($grant, $metadata, $membership, $removed);

            return 'completed';
        }, attempts: 3);
    }

    private function leavePending(WorkspaceProductAccess $grant): string
    {
        // Move this item behind older untouched work so a blocked identity or
        // unavailable module cannot monopolize every bounded scheduler batch.
        $grant->forceFill(['updated_at' => now()])->save();

        return 'pending';
    }

    /** @param array<string, mixed> $metadata */
    private function complete(
        WorkspaceProductAccess $grant,
        array $metadata,
        ?WorkspaceMembership $membership,
        int $removed,
    ): void {
        $metadata['managed_product_memberships'] = [];
        $metadata['local_membership_cleanup_pending'] = false;
        $grant->forceFill(['metadata' => $metadata])->save();

        if ($membership === null || ! Schema::connection('core')->hasTable('workspace_membership_events')) {
            return;
        }

        WorkspaceMembershipEvent::query()->create([
            'workspace_id' => $membership->workspace_id,
            'membership_id' => $membership->getKey(),
            'actor_user_id' => null,
            'subject_user_id' => $membership->user_id,
            'event' => 'product_access_cleanup_completed',
            'previous_role' => null,
            'new_role' => null,
            'metadata' => [
                'product' => $grant->product,
                'source' => 'automatic_retry',
                'removed_local_memberships' => $removed,
            ],
            'created_at' => now(),
        ]);
    }

    private function pendingQuery(): Builder
    {
        return WorkspaceProductAccess::query()
            ->where('status', 'revoked')
            ->whereNotNull('revoked_at')
            ->where('metadata->local_membership_cleanup_pending', true);
    }
}
