<?php

namespace App\Core\Services\Deletion;

use App\Core\Data\Deletion\ProductDeletionAttempt;
use App\Core\Exceptions\Deletion\DeletionBlocked;
use App\Core\Models\DeletionRequest;
use App\Core\Models\DeletionStep;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;

/** Accepted cleanup survives intentional access revocation; its scope can never expand. */
final class DeletionAuthority
{
    public function __construct(private readonly DeletionPlanner $planner) {}

    public function assertAttempt(ProductDeletionAttempt $attempt, bool $lock = false): DeletionStep
    {
        $step = DeletionStep::query()->whereKey($attempt->stepId)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $request = DeletionRequest::query()->whereKey($attempt->requestId)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($step === null || $request === null || $request->accepted_at === null || $request->status === 'completed'
            || (string) $step->deletion_request_id !== $attempt->requestId || $step->status !== 'processing'
            || $step->attempts !== $attempt->generation || $step->phase !== $attempt->phase || $request->phase !== $attempt->phase
            || ! hash_equals((string) $step->lease_token, $attempt->leaseToken) || $step->lease_expires_at?->isFuture() !== true
            || ! hash_equals($step->payload_hash, $attempt->payloadHash) || $step->target !== $attempt->target->toArray()) {
            throw new DeletionBlocked('deletion_attempt_stale');
        }
        $this->assertRequest($request, $lock);
        if ($attempt->phase === 'purge' && $step->kind === 'account'
            && $request->steps()->where('kind', 'workspace')->where('status', '!=', 'completed')->exists()) {
            throw new DeletionBlocked('workspace_cleanup_incomplete');
        }

        return $step;
    }

    public function assertRequest(DeletionRequest $request, bool $lock = false): void
    {
        if ($request->accepted_at === null || $request->status === 'completed') {
            throw new DeletionBlocked('deletion_attempt_stale');
        }
        $actor = PlatformUser::query()->whereKey($request->actor_id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($actor === null || ($request->kind === 'account' ? $actor->status !== 'deleting' : $actor->status !== 'active')) {
            throw new DeletionBlocked('deletion_actor_changed');
        }
        $workspaces = Workspace::query()->whereIn('id', $request->workspace_ids)->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($workspaces->count() !== count($request->workspace_ids)
            || $workspaces->contains(fn (Workspace $workspace): bool => $workspace->status !== 'deleting'
                || (string) $workspace->owner_user_id !== (string) $request->actor_id
                || ($workspace->settings['deletion_request_id'] ?? null) !== (string) $request->getKey())) {
            throw new DeletionBlocked('deletion_workspace_changed');
        }
        foreach ($workspaces as $workspace) {
            if ($this->planner->billingBlockers((string) $workspace->getKey()) !== []) {
                throw new DeletionBlocked('settle_product_billing');
            }
        }
        if ($request->identity_bindings !== $this->planner->bindings((string) $request->actor_id, $request->workspace_ids, $lock)) {
            throw new DeletionBlocked('deletion_identity_changed');
        }
        if ($this->planner->projectionInProgress((string) $request->actor_id, $request->workspace_ids)) {
            throw new DeletionBlocked('identity_projection_in_progress');
        }
        if (WorkspaceMembership::query()->whereIn('workspace_id', $request->workspace_ids)->whereNotIn('status', ['revoked', 'removed'])->exists()
            || ($request->kind === 'account' && (Workspace::query()->where('owner_user_id', $request->actor_id)
                ->where('status', '!=', 'deleted')->whereNotIn('id', $request->workspace_ids)->exists()
                || WorkspaceMembership::query()->where('user_id', $request->actor_id)->whereNotIn('status', ['revoked', 'removed'])->exists()))) {
            throw new DeletionBlocked('deletion_workspace_changed');
        }
    }
}
