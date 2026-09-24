<?php

namespace App\Core\Services\Workspaces;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceMembershipEvent;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Identity\ProjectProductWorkspaceMembership;
use App\Core\Services\LegacyIdentityResolver;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ManageWorkspaceMembership
{
    private const EDITABLE_ROLES = ['admin', 'billing', 'member', 'viewer'];

    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly ProjectProductWorkspaceMembership $productMemberships,
    ) {}

    public function updateRole(
        PlatformUser $actor,
        Workspace $workspace,
        WorkspaceMembership $membership,
        string $role,
    ): void {
        if (! in_array($role, self::EDITABLE_ROLES, true)) {
            throw ValidationException::withMessages(['role' => __('Choose a valid workspace role.')]);
        }

        DB::connection('core')->transaction(function () use ($actor, $workspace, $membership, $role): void {
            $lockedWorkspace = $this->lockWorkspace($workspace);
            $actorMembership = $this->activeManager($actor, $lockedWorkspace);

            $target = $this->lockMembership($lockedWorkspace, $membership);
            abort_unless($target->currentlyActive(), 409);
            abort_if($target->role === 'owner', 403, 'Workspace ownership must be transferred through the owner workflow.');
            abort_if($actorMembership->role !== 'owner' && ($target->role === 'admin' || $role === 'admin'), 403);

            $previousRole = $target->role;
            if ($previousRole === $role) {
                return;
            }

            $target->forceFill(['role' => $role])->save();

            WorkspaceMembershipEvent::query()->create([
                'workspace_id' => $lockedWorkspace->getKey(),
                'membership_id' => $target->getKey(),
                'actor_user_id' => $actor->getKey(),
                'subject_user_id' => $target->user_id,
                'event' => 'role_changed',
                'previous_role' => $previousRole,
                'new_role' => $role,
                'metadata' => [],
                'created_at' => now(),
            ]);
        }, attempts: 3);
    }

    public function revoke(
        PlatformUser $actor,
        Workspace $workspace,
        WorkspaceMembership $membership,
    ): void {
        DB::connection('core')->transaction(function () use ($actor, $workspace, $membership): void {
            $lockedWorkspace = $this->lockWorkspace($workspace);
            $actorMembership = $this->activeManager($actor, $lockedWorkspace);
            $target = $this->lockMembership($lockedWorkspace, $membership);

            abort_unless($target->currentlyActive(), 409);
            abort_if($target->role === 'owner', 403, 'A workspace owner cannot be removed through this workflow.');
            abort_if((string) $target->user_id === (string) $actor->getKey(), 403, 'You cannot remove your own workspace membership.');
            abort_if($actorMembership->role !== 'owner' && $target->role === 'admin', 403);

            $now = now();
            $productGrants = WorkspaceProductAccess::query()
                ->where('membership_id', $target->getKey())
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get();
            $revokedProducts = $productGrants
                ->pluck('product')
                ->unique()
                ->values()
                ->all();
            $pendingProductMembershipCleanup = [];

            foreach ($productGrants as $productGrant) {
                $metadata = $productGrant->metadata ?? [];
                $projections = $metadata['managed_product_memberships'] ?? [];

                if (is_array($projections) && $projections !== []) {
                    $productUserIds = $target->user instanceof PlatformUser
                        ? $this->identities->sourceIdsFor($target->user, $productGrant->product)
                        : [];

                    if (count($productUserIds) !== 1) {
                        $pendingProductMembershipCleanup[] = $productGrant->product;
                    } else {
                        try {
                            $this->productMemberships->revoke(
                                $productGrant->product,
                                $productUserIds[0],
                                $projections,
                            );
                        } catch (LostConnectionException|QueryException $exception) {
                            report($exception);
                            $pendingProductMembershipCleanup[] = $productGrant->product;
                        }
                    }
                }

                $revocation = [
                    'status' => 'revoked',
                    'revoked_at' => $now,
                ];

                if (Schema::connection('core')->hasColumn('workspace_product_access', 'metadata')) {
                    $cleanupPending = in_array($productGrant->product, $pendingProductMembershipCleanup, true);
                    if ($cleanupPending) {
                        $metadata['local_membership_cleanup_pending'] = true;
                    } else {
                        $metadata['managed_product_memberships'] = [];
                        unset($metadata['local_membership_cleanup_pending']);
                    }
                    $revocation['metadata'] = $metadata;
                }

                $productGrant->forceFill($revocation)->save();
            }

            $projectIds = DB::connection('core')->table('projects')
                ->select('id')
                ->where('workspace_id', $lockedWorkspace->getKey());

            $revokedProjectMemberships = DB::connection('core')->table('project_memberships')
                ->where('user_id', $target->user_id)
                ->whereIn('project_id', $projectIds)
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->update(['status' => 'revoked', 'revoked_at' => $now, 'updated_at' => $now]);

            $target->forceFill([
                'status' => 'revoked',
                'revoked_at' => $now,
            ])->save();

            WorkspaceMembershipEvent::query()->create([
                'workspace_id' => $lockedWorkspace->getKey(),
                'membership_id' => $target->getKey(),
                'actor_user_id' => $actor->getKey(),
                'subject_user_id' => $target->user_id,
                'event' => 'membership_revoked',
                'previous_role' => $target->role,
                'new_role' => null,
                'metadata' => [
                    'revoked_product_grants' => $revokedProducts,
                    'revoked_project_memberships' => $revokedProjectMemberships,
                    'pending_local_membership_cleanup' => array_values(array_unique($pendingProductMembershipCleanup)),
                ],
                'created_at' => $now,
            ]);
        }, attempts: 3);
    }

    private function lockWorkspace(Workspace $workspace): Workspace
    {
        $lockedWorkspace = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
        abort_unless($lockedWorkspace->status === 'active' && $lockedWorkspace->archived_at === null, 404);

        return $lockedWorkspace;
    }

    private function activeManager(PlatformUser $actor, Workspace $workspace): WorkspaceMembership
    {
        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $actor->getKey())
            ->currentlyActive()
            ->lockForUpdate()
            ->first();

        abort_unless($membership !== null && in_array($membership->role, ['owner', 'admin'], true), 403);

        return $membership;
    }

    private function lockMembership(Workspace $workspace, WorkspaceMembership $membership): WorkspaceMembership
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($membership->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
