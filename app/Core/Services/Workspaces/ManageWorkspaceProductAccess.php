<?php

namespace App\Core\Services\Workspaces;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Enums\ProductKey;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceMembershipEvent;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Identity\EnsurePlatformProductPrincipal;
use App\Core\Services\Identity\EnsureProductWorkspaceMapping;
use App\Core\Services\Identity\ProjectProductWorkspaceMembership;
use App\Core\Services\LegacyIdentityResolver;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ManageWorkspaceProductAccess
{
    /** @var array<string, string> */
    private const SEAT_LIMITS = [
        'deployer' => 'members',
        'monitor' => 'seats',
        'analytics' => 'members',
    ];

    public function __construct(
        private readonly ProductPlanResolver $plans,
        private readonly EnsurePlatformProductPrincipal $principals,
        private readonly EnsureProductWorkspaceMapping $productWorkspaces,
        private readonly LegacyIdentityResolver $identities,
        private readonly ProjectProductWorkspaceMembership $productMemberships,
    ) {}

    /**
     * A null role revokes app access. Returns the number of legacy workspace
     * memberships projected for a grant (or removed for a revocation).
     */
    public function update(
        PlatformUser $actor,
        Workspace $workspace,
        WorkspaceMembership $membership,
        ProductKey $product,
        ?string $role,
    ): int {
        if ($role !== null && ! in_array($role, ['viewer', 'member', 'admin'], true)) {
            throw ValidationException::withMessages(['role' => __('Choose a valid application access level.')]);
        }

        return DB::connection('core')->transaction(function () use ($actor, $membership, $product, $role, $workspace): int {
            $lockedWorkspace = Workspace::query()
                ->whereKey($workspace->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedWorkspace->status === 'active' && $lockedWorkspace->archived_at === null, 404);

            $actorMembership = WorkspaceMembership::query()
                ->where('workspace_id', $lockedWorkspace->getKey())
                ->where('user_id', $actor->getKey())
                ->currentlyActive()
                ->lockForUpdate()
                ->first();
            abort_unless($actorMembership !== null && in_array($actorMembership->role, ['owner', 'admin'], true), 403);

            $target = WorkspaceMembership::query()
                ->where('workspace_id', $lockedWorkspace->getKey())
                ->whereKey($membership->getKey())
                ->currentlyActive()
                ->lockForUpdate()
                ->firstOrFail();

            $grant = WorkspaceProductAccess::query()
                ->where('membership_id', $target->getKey())
                ->where('product', $product->value)
                ->lockForUpdate()
                ->first();
            $currentlyGranted = $this->isActive($grant);
            $subject = $target->user;
            abort_unless($subject instanceof PlatformUser, 409, 'The shared workspace member account is unavailable.');

            if ($role === null) {
                $metadata = $grant?->metadata ?? [];
                $cleanupPending = ($metadata['local_membership_cleanup_pending'] ?? false) === true;
                if (! $currentlyGranted && ! $cleanupPending) {
                    return 0;
                }

                if ($currentlyGranted) {
                    $this->protectProductAdministrator($actorMembership, $grant, null);
                }

                $projections = $metadata['managed_product_memberships'] ?? [];
                $sourceUserIds = $this->identities->sourceIdsFor($subject, $product->value);
                $wasPending = $cleanupPending;
                $cleanupPending = false;
                $removed = 0;
                if (is_array($projections) && $projections !== []) {
                    if (count($sourceUserIds) !== 1) {
                        $cleanupPending = true;
                    } else {
                        try {
                            $removed = $this->productMemberships->revoke(
                                $product->value,
                                $sourceUserIds[0],
                                $projections,
                            );
                        } catch (LostConnectionException|QueryException $exception) {
                            report($exception);
                            $cleanupPending = true;
                        }
                    }
                }

                $grant->forceFill([
                    'status' => 'revoked',
                    'revoked_at' => $grant->revoked_at ?? now(),
                    'metadata' => array_merge($metadata, [
                        'managed_product_memberships' => $cleanupPending ? $projections : [],
                        'local_membership_cleanup_pending' => $cleanupPending,
                    ]),
                ])->save();

                if ($currentlyGranted) {
                    $this->recordChange($lockedWorkspace, $target, $actor, $product, $grant->role, null, $cleanupPending);
                } elseif ($wasPending) {
                    $this->recordCleanupOutcome($lockedWorkspace, $target, $actor, $product, $cleanupPending);
                }

                return $removed;
            }

            $this->protectProductAdministrator($actorMembership, $grant, $role);
            $this->assertSeatAvailable($lockedWorkspace, $target, $product, $currentlyGranted);

            $this->principals->handle($product->value, $subject);
            $this->productWorkspaces->handle($product->value, $lockedWorkspace);
            $projection = $this->productMemberships->grant(
                product: $product->value,
                user: $subject,
                workspace: $lockedWorkspace,
                role: $role,
                previous: ($grant?->metadata['managed_product_memberships'] ?? []),
            );

            $previousRole = $currentlyGranted ? (string) $grant->role : null;
            $metadata = $grant?->metadata ?? [];
            $attributes = [
                'role' => $role,
                'status' => 'active',
                'granted_by_user_id' => $actor->getKey(),
                'granted_at' => $currentlyGranted ? $grant->granted_at : now(),
                'expires_at' => null,
                'revoked_at' => null,
                'metadata' => array_merge($metadata, ['managed_product_memberships' => $projection]),
            ];

            if ($grant === null) {
                $grant = new WorkspaceProductAccess;
                $grant->membership_id = $target->getKey();
                $grant->product = $product->value;
            }

            $grant->forceFill($attributes)->save();

            if ($previousRole !== $role) {
                $this->recordChange($lockedWorkspace, $target, $actor, $product, $previousRole, $role);
            }

            return count($projection);
        }, attempts: 3);
    }

    private function assertSeatAvailable(
        Workspace $workspace,
        WorkspaceMembership $target,
        ProductKey $product,
        bool $currentlyGranted,
    ): void {
        $plan = $this->plans->resolve((string) $workspace->getKey(), $product);
        if (! $plan->available) {
            throw ValidationException::withMessages([
                'role' => __(':product does not have an active workspace plan. Resolve its plan before granting access.', [
                    'product' => $product->name,
                ]),
            ]);
        }

        $limitKey = self::SEAT_LIMITS[$product->value];
        if (! $plan->hasLimit($limitKey)) {
            throw ValidationException::withMessages([
                'role' => __('The :product plan does not define a member-seat limit yet.', ['product' => $product->name]),
            ]);
        }

        $seatLimit = $plan->limit($limitKey);
        if ($seatLimit === null || $currentlyGranted) {
            return;
        }

        $usedSeats = WorkspaceProductAccess::query()
            ->where('product', $product->value)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('membership', fn ($query) => $query
                ->where('workspace_id', $workspace->getKey())
                ->currentlyActive())
            ->count();

        if ($usedSeats >= $seatLimit) {
            throw ValidationException::withMessages([
                'role' => __('The :product plan has reached its :count-seat limit. Upgrade that app’s plan or revoke one of its seats.', [
                    'product' => $product->name,
                    'count' => $seatLimit,
                ]),
            ]);
        }
    }

    private function protectProductAdministrator(
        WorkspaceMembership $actor,
        ?WorkspaceProductAccess $grant,
        ?string $nextRole,
    ): void {
        if ($grant?->role === 'owner' && $nextRole !== null) {
            throw ValidationException::withMessages([
                'role' => __('Transfer ownership in :product before assigning a lower shared-workspace role.', [
                    'product' => str($grant->product)->headline(),
                ]),
            ]);
        }

        if ($actor->role === 'owner') {
            return;
        }

        abort_if($nextRole === 'admin' || in_array($grant?->role, ['admin', 'owner'], true), 403);
    }

    private function isActive(?WorkspaceProductAccess $grant): bool
    {
        return $grant !== null
            && $grant->status === 'active'
            && $grant->revoked_at === null
            && ($grant->expires_at === null || $grant->expires_at->isFuture());
    }

    private function recordChange(
        Workspace $workspace,
        WorkspaceMembership $membership,
        PlatformUser $actor,
        ProductKey $product,
        ?string $previousRole,
        ?string $newRole,
        bool $cleanupPending = false,
    ): void {
        WorkspaceMembershipEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'membership_id' => $membership->getKey(),
            'actor_user_id' => $actor->getKey(),
            'subject_user_id' => $membership->user_id,
            'event' => 'product_access_changed',
            'previous_role' => $previousRole,
            'new_role' => $newRole,
            'metadata' => [
                'product' => $product->value,
                'local_membership_cleanup_pending' => $cleanupPending,
            ],
            'created_at' => now(),
        ]);
    }

    private function recordCleanupOutcome(
        Workspace $workspace,
        WorkspaceMembership $membership,
        PlatformUser $actor,
        ProductKey $product,
        bool $cleanupPending,
    ): void {
        WorkspaceMembershipEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'membership_id' => $membership->getKey(),
            'actor_user_id' => $actor->getKey(),
            'subject_user_id' => $membership->user_id,
            'event' => $cleanupPending ? 'product_access_cleanup_pending' : 'product_access_cleanup_completed',
            'metadata' => ['product' => $product->value],
            'created_at' => now(),
        ]);
    }
}
