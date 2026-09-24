<?php

namespace App\Core\Http\Controllers;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Enums\ProductKey;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceInvitation;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceMembershipEvent;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Notifications\WorkspaceInvitationNotification;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\WorkspaceProjectAccess;
use App\Core\Services\Workspaces\CreateWorkspaceInvitation;
use App\Core\Services\Workspaces\ManageWorkspaceMembership;
use App\Core\Services\Workspaces\ManageWorkspaceProductAccess;
use App\Core\Services\Workspaces\RevokeWorkspaceInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class WorkspaceTeamController
{
    public function index(
        Request $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
        ProductPlanResolver $plans,
    ): View {
        $user = $this->platformUser($request, $platformUsers);
        abort_if($access->activeMembership($user, $workspace) === null, 404);

        $members = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->currentlyActive()
            ->with(['user', 'productAccess'])
            ->orderBy('created_at')
            ->paginate(25);

        $invitations = WorkspaceInvitation::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('status', ['pending', 'accepted', 'revoked'])
            ->with('invitedBy')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $workspaces = $user->workspaceMemberships()
            ->currentlyActive()
            ->whereHas('workspace', fn ($query) => $query
                ->where('status', 'active')
                ->whereNull('archived_at'))
            ->with('workspace')
            ->get()
            ->pluck('workspace')
            ->filter();
        $canManageMembers = $access->canManageWorkspace($user, $workspace);
        $actorRole = $access->activeMembership($user, $workspace)?->role;
        $productAccessSummary = collect(ProductKey::cases())->mapWithKeys(function (ProductKey $product) use ($workspace, $plans): array {
            $seatLimitKey = match ($product) {
                ProductKey::Deployer, ProductKey::Analytics => 'members',
                ProductKey::Monitor => 'seats',
            };
            $planTablesReady = Schema::connection('core')->hasTable('current_product_subscriptions');
            $plan = $planTablesReady
                ? $plans->resolve((string) $workspace->getKey(), $product)
                : null;
            $hasSeatLimit = $plan?->available === true && $plan->hasLimit($seatLimitKey);
            $usedSeats = WorkspaceProductAccess::query()
                ->where('product', $product->value)
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereHas('membership', fn ($query) => $query
                    ->where('workspace_id', $workspace->getKey())
                    ->currentlyActive())
                ->count();

            return [$product->value => [
                'label' => $product->name,
                'used' => $usedSeats,
                'limit' => $hasSeatLimit ? $plan?->limit($seatLimitKey) : null,
                'plan_ready' => $hasSeatLimit,
            ]];
        });
        $auditEvents = $canManageMembers
            ? WorkspaceMembershipEvent::query()
                ->where('workspace_id', $workspace->getKey())
                ->with(['actor', 'subject'])
                ->orderByDesc('created_at')
                ->limit(30)
                ->get()
            : collect();

        return view('core::workspaces.team', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $workspaces,
            'members' => $members,
            'invitations' => $invitations,
            'canManageMembers' => $canManageMembers,
            'canManageRoles' => $access->canManageWorkspace($user, $workspace),
            'canAssignAdminRole' => $access->activeMembership($user, $workspace)?->role === 'owner',
            'canManageProductAdmins' => $actorRole === 'owner',
            'productAccessSummary' => $productAccessSummary,
            'actorUserId' => (string) $user->getKey(),
            'auditEvents' => $auditEvents,
        ]);
    }

    public function updateRole(
        Request $request,
        Workspace $workspace,
        WorkspaceMembership $membership,
        ResolvePlatformUser $platformUsers,
        ManageWorkspaceMembership $manageMembership,
    ): RedirectResponse {
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(['admin', 'billing', 'member', 'viewer'])],
        ]);

        $manageMembership->updateRole(
            actor: $this->platformUser($request, $platformUsers),
            workspace: $workspace,
            membership: $membership,
            role: $data['role'],
        );

        return redirect()->route('core.workspace.team.index', $workspace)->with('success', __('Workspace role updated.'));
    }

    public function revokeMembership(
        Request $request,
        Workspace $workspace,
        WorkspaceMembership $membership,
        ResolvePlatformUser $platformUsers,
        ManageWorkspaceMembership $manageMembership,
    ): RedirectResponse {
        $manageMembership->revoke(
            actor: $this->platformUser($request, $platformUsers),
            workspace: $workspace,
            membership: $membership,
        );

        return redirect()->route('core.workspace.team.index', $workspace)->with('success', __('Workspace membership revoked.'));
    }

    public function updateProductAccess(
        Request $request,
        Workspace $workspace,
        WorkspaceMembership $membership,
        string $product,
        ResolvePlatformUser $platformUsers,
        ManageWorkspaceProductAccess $manageProductAccess,
    ): RedirectResponse {
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(['none', 'viewer', 'member', 'admin'])],
        ]);
        $productKey = ProductKey::tryFrom($product);
        abort_unless($productKey instanceof ProductKey, 404);

        $role = $data['role'] === 'none' ? null : $data['role'];
        $projectedWorkspaces = $manageProductAccess->update(
            actor: $this->platformUser($request, $platformUsers),
            workspace: $workspace,
            membership: $membership,
            product: $productKey,
            role: $role,
        );

        if ($role === null) {
            return redirect()->route('core.workspace.team.index', $workspace)
                ->with('success', __(':product access was revoked.', ['product' => $productKey->name]));
        }

        $message = __(':product access was granted with the :role role.', [
            'product' => $productKey->name,
            'role' => str($role)->headline(),
        ]);
        if ($projectedWorkspaces === 0) {
            $message .= ' '.__('No existing :product workspace is linked here yet, so this account will be added when one is connected.', [
                'product' => $productKey->name,
            ]);
        }

        return redirect()->route('core.workspace.team.index', $workspace)->with('success', $message);
    }

    public function storeInvitation(
        Request $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        CreateWorkspaceInvitation $createInvitation,
    ): RedirectResponse {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'role' => ['required', 'string', Rule::in(['admin', 'billing', 'member', 'viewer'])],
        ]);

        $user = $this->platformUser($request, $platformUsers);
        $created = $createInvitation->handle($user, $workspace, $data['email'], $data['role']);

        Notification::route('mail', $created->invitation->email)->notify(new WorkspaceInvitationNotification(
            workspaceName: $workspace->name,
            inviterName: $user->name ?: $user->email,
            role: $created->invitation->role,
            token: $created->token,
        ));

        return redirect()->route('core.workspace.team.index', $workspace)
            ->with('success', __('Invitation sent to :email.', ['email' => $created->invitation->email]));
    }

    public function revokeInvitation(
        Request $request,
        Workspace $workspace,
        WorkspaceInvitation $invitation,
        ResolvePlatformUser $platformUsers,
        RevokeWorkspaceInvitation $revokeInvitation,
    ): RedirectResponse {
        $revokeInvitation->handle($this->platformUser($request, $platformUsers), $workspace, $invitation);

        return redirect()->route('core.workspace.team.index', $workspace)->with('success', __('Invitation revoked.'));
    }

    private function platformUser(Request $request, ResolvePlatformUser $platformUsers): PlatformUser
    {
        $principal = $request->user('platform');
        abort_unless($principal !== null, 401);

        $user = $platformUsers->resolve($principal, 'deployer');
        abort_if($user === null, 403);

        return $user;
    }
}
