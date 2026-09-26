<?php

namespace App\Core\Services\Deletion;

use App\Core\Data\Deletion\ProductDeletionTarget;
use App\Core\Exceptions\Deletion\DeletionBlocked;
use App\Core\Models\AnalyticsCheckoutAttempt;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProductBillingEvent;
use App\Core\Models\ProductSubscription;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectProduct;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Read-only preparation; neither a preview nor a blocked request changes source data. */
final class DeletionPlanner
{
    public function __construct(private readonly ProductDeletionRegistry $providers) {}

    public function plan(PlatformUser $actor, ?Workspace $workspace = null): array
    {
        $actor = PlatformUser::query()->findOrFail($actor->getKey());
        if ($actor->status !== 'active') {
            throw new DeletionBlocked('account_not_active');
        }
        $workspaces = $workspace === null
            ? Workspace::query()->where('owner_user_id', $actor->getKey())->where('status', '!=', 'deleted')->orderBy('id')->get()
            : collect([Workspace::query()->findOrFail($workspace->getKey())]);
        $blockers = [];
        if ($this->projectionInProgress((string) $actor->getKey(), $workspaces->map(fn (Workspace $item): string => (string) $item->getKey())->all())) {
            $blockers[] = 'identity_projection_in_progress';
        }
        if ($workspace === null && WorkspaceMembership::query()->where('user_id', $actor->getKey())
            ->whereNotIn('status', ['revoked', 'removed'])->whereHas('workspace', fn ($query) => $query
            ->where('owner_user_id', '!=', $actor->getKey())->where('status', '!=', 'deleted'))->exists()) {
            $blockers[] = 'leave_shared_workspaces';
        }
        $targets = [];
        $sourceWorkspaces = [];
        $actorMaps = LegacyIdentityMap::query()->where('canonical_entity', 'user')->where('canonical_id', $actor->getKey())->get();
        foreach ($workspaces as $owned) {
            $this->assertOwned($actor, $owned);
            if (! in_array($owned->status, ['active', 'archived'], true)) {
                $blockers[] = 'workspace_lifecycle_in_progress';
            }
            if ($owned->memberships()->where('user_id', '!=', $actor->getKey())->whereNotIn('status', ['revoked', 'removed'])->exists()) {
                $blockers[] = 'remove_workspace_teammates';
            }
            $blockers = array_merge($blockers, $this->billingBlockers((string) $owned->getKey()));
            $maps = LegacyIdentityMap::query()->where('canonical_entity', 'workspace')->where('canonical_id', $owned->getKey())->get();
            $attachedProducts = ProjectProduct::query()->whereIn('project_id', $owned->projects()->select('id'))->pluck('product')->unique();
            foreach ($attachedProducts as $product) {
                if (! $maps->contains('source_product', $product)) {
                    $blockers[] = 'workspace_mapping_unresolved';
                }
            }
            foreach ($maps->groupBy('source_product') as $product => $group) {
                $map = $group->first();
                $userMaps = $actorMaps->where('source_product', $product)->where('source_entity', 'user');
                if ($group->count() !== 1 || $map->status !== 'reconciled'
                    || $map->source_entity !== ($product === 'deployer' ? 'organization' : 'workspace')
                    || $userMaps->count() !== 1 || $userMaps->first()->status !== 'reconciled') {
                    $blockers[] = 'workspace_mapping_unresolved';

                    continue;
                }
                $sourceWorkspaces[$product][] = (string) $map->source_id;
                $targets[] = new ProductDeletionTarget($product, 'workspace', (string) $map->source_id,
                    (string) $userMaps->first()->source_id, (string) $owned->getKey(), (string) $actor->getKey());
            }
        }
        if ($workspace === null) {
            foreach ($actorMaps->groupBy('source_product') as $product => $group) {
                $map = $group->first();
                if ($group->count() !== 1 || $map->source_entity !== 'user' || $map->status !== 'reconciled') {
                    $blockers[] = 'account_mapping_unresolved';

                    continue;
                }
                $targets[] = new ProductDeletionTarget($product, 'account', (string) $map->source_id,
                    (string) $map->source_id, (string) $actor->getKey(), (string) $actor->getKey(), $sourceWorkspaces[$product] ?? []);
            }
        }
        $details = [];
        $retained = ['Minimal deletion receipts and identity tombstones prevent deleted accounts from being linked again.',
            'Settled billing identifiers and necessary audit references remain; external infrastructure is not destroyed.'];
        foreach ($targets as $target) {
            $provider = $this->providers->get($target->product);
            if ($provider === null) {
                $blockers[] = 'product_cleanup_unavailable';

                continue;
            }
            try {
                $preview = $provider->inspect($target);
                $blockers = array_merge($blockers, $preview->blockers);
                $retained = array_merge($retained, $preview->retained);
                $details[] = ['product' => $target->product, 'kind' => $target->kind, 'counts' => $preview->counts];
            } catch (DeletionBlocked $exception) {
                $blockers[] = $exception->reasonCode;
            } catch (Throwable) {
                $blockers[] = 'product_cleanup_unavailable';
            }
        }
        $ids = $workspaces->map(fn (Workspace $item): string => (string) $item->getKey())->all();
        $bindings = $this->bindings((string) $actor->getKey(), $ids);
        $intent = ['actor' => (string) $actor->getKey(), 'kind' => $workspace === null ? 'account' : 'workspace',
            'target' => (string) ($workspace?->getKey() ?? $actor->getKey()), 'workspaces' => $ids,
            'bindings' => $bindings, 'targets' => array_map(fn (ProductDeletionTarget $target): array => $target->toArray(), $targets)];

        return ['intent' => $intent, 'fingerprint' => hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR)),
            'targets' => $targets, 'workspaces' => $workspaces, 'blockers' => array_values(array_unique($blockers)),
            'retained' => array_values(array_unique($retained)), 'details' => $details];
    }

    public function assertOwned(PlatformUser $actor, Workspace $workspace): void
    {
        if ((string) $workspace->owner_user_id !== (string) $actor->getKey()
            || ! $workspace->memberships()->where('user_id', $actor->getKey())->where('role', 'owner')->currentlyActive()->exists()) {
            throw new DeletionBlocked('workspace_owner_required');
        }
    }

    /** The exact set also detects a new mapping introduced after confirmation. */
    public function bindings(string $actorId, array $workspaceIds, bool $lock = false): array
    {
        $projects = Project::query()->whereIn('workspace_id', $workspaceIds)->pluck('id');
        $environments = ProjectEnvironment::query()->whereIn('project_id', $projects)->pluck('id');

        return LegacyIdentityMap::query()->where(function ($query) use ($actorId, $workspaceIds, $projects, $environments): void {
            $query->where(fn ($user) => $user->where('canonical_entity', 'user')->where('canonical_id', $actorId))
                ->orWhere(fn ($workspace) => $workspace->where('canonical_entity', 'workspace')->whereIn('canonical_id', $workspaceIds))
                ->orWhere(fn ($project) => $project->where('canonical_entity', 'project')->whereIn('canonical_id', $projects))
                ->orWhere(fn ($environment) => $environment->where('canonical_entity', 'project_environment')->whereIn('canonical_id', $environments));
        })->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'source_product', 'source_entity', 'source_id', 'canonical_entity', 'canonical_id', 'status'])->toArray();
    }

    public function billingBlockers(string $workspaceId): array
    {
        // An unsettled checkout can still turn into a charge after the user
        // requests deletion. Only a provider-confirmed expired session or a
        // projected subscription can remove this separate checkout blocker.
        if (AnalyticsCheckoutAttempt::query()->where('core_workspace_id', $workspaceId)
            ->whereNull('provider_subscription_id')
            ->where('status', '!=', 'expired')->exists()) {
            return ['pending_billing_reconciliation'];
        }

        $currentIds = CurrentProductSubscription::query()->where('workspace_id', $workspaceId)
            ->pluck('product_subscription_id')->map(fn ($id): string => (string) $id)->all();
        foreach (ProductSubscription::query()->where('workspace_id', $workspaceId)->get() as $subscription) {
            if ($subscription->product === 'analytics' && $subscription->status === 'superseded'
                && ! in_array((string) $subscription->getKey(), $currentIds, true)) {
                continue;
            }
            $paid = filled($subscription->provider_subscription_id);
            $terminal = in_array($subscription->status, ['canceled', 'cancelled', 'expired', 'incomplete_expired'], true);
            if (($paid && (! $terminal || $subscription->current_period_ends_at?->isFuture()))
                || (! $paid && ! in_array($subscription->status, ['active', 'trialing', 'free', 'legacy_access', 'canceled', 'cancelled', 'expired', 'incomplete_expired'], true))) {
                return ['settle_product_billing'];
            }
        }
        if (ProductBillingEvent::query()->where('workspace_id', $workspaceId)->whereNotIn('processing_status', ['applied', 'ignored'])->exists()) {
            return ['pending_billing_reconciliation'];
        }

        return [];
    }

    public function projectionInProgress(string $actorId, array $workspaceIds): bool
    {
        return DB::connection('core')->table('identity_projection_operations')
            ->where(fn ($query) => $query->where('actor_id', $actorId)
                ->orWhere(fn ($workspace) => $workspace->where('kind', 'workspace')->whereIn('canonical_id', $workspaceIds)))
            ->exists();
    }
}
