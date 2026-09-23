<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\Invitation;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Notifications\WorkspaceInvitation;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function index(Request $request, Workspace $workspace, AnalyticsWorkspaceAccess $access): View
    {
        $this->authorizeMember($request->user(), $workspace, $access);
        $workspaceProductUserIds = $workspace->users()->pluck('users.id')->map(fn ($id): string => (string) $id)->all();
        $currentProductUserIds = array_values(array_intersect($access->productUserIds($request->user()), $workspaceProductUserIds));

        return view('analytics::workspaces.team', [
            'workspace' => $workspace,
            'members' => $workspace->users()->orderBy('name')->get(),
            'workspaceRole' => $access->roleFor($request->user(), $workspace),
            'currentProductUserIds' => $currentProductUserIds,
            'invitations' => $workspace->invitations()->whereNull('accepted_at')->where('expires_at', '>', now())->latest()->get(),
        ]);
    }

    public function invite(Request $request, Workspace $workspace, AnalyticsWorkspaceAccess $access): RedirectResponse
    {
        $this->authorizeManager($request->user(), $workspace, $access);
        $productUserId = $access->productUserIds($request->user())[0] ?? null;
        abort_if($productUserId === null, 403);
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', 'in:admin,viewer'],
        ]);
        $email = Str::lower($validated['email']);

        if ($workspace->users()->whereRaw('lower(email) = ?', [$email])->exists()) {
            return back()->withErrors(['email' => 'That person is already a workspace member.'])->withInput();
        }

        $token = Str::random(64);
        $invitation = DB::connection('analytics')->transaction(function () use ($request, $workspace, $email, $validated, $token, $productUserId): Invitation {
            $workspace->invitations()->where('email', $email)->whereNull('accepted_at')->delete();

            return $workspace->invitations()->create([
                'invited_by' => $productUserId,
                'email' => $email,
                'role' => $validated['role'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(config('analytics.invitation_expiry_days')),
            ]);
        });

        Notification::route('mail', $email)->notify(new WorkspaceInvitation($token));

        return back()->with('status', "Invitation sent to {$email}.");
    }

    public function updateRole(Request $request, Workspace $workspace, User $user, AnalyticsWorkspaceAccess $access): RedirectResponse
    {
        $this->authorizeOwner($request->user(), $workspace, $access);
        abort_unless($workspace->users()->whereKey($user->id)->exists(), 404);
        $validated = $request->validate(['role' => ['required', 'in:admin,viewer']]);
        $workspace->users()->updateExistingPivot($user->id, ['role' => $validated['role']]);

        return back()->with('status', 'Member role updated.');
    }

    public function remove(Request $request, Workspace $workspace, User $user, AnalyticsWorkspaceAccess $access): RedirectResponse
    {
        $this->authorizeManager($request->user(), $workspace, $access);
        abort_unless($workspace->users()->whereKey($user->id)->exists(), 404);
        abort_if($workspace->roleFor((int) $user->getKey()) === WorkspaceRole::Owner, 422, 'The workspace owner cannot be removed.');
        $workspace->users()->detach($user);

        return back()->with('status', 'Member removed.');
    }

    private function authorizeMember(Authenticatable $user, Workspace $workspace, AnalyticsWorkspaceAccess $access): void
    {
        abort_unless($access->hasAccess($user, $workspace), 403);
    }

    private function authorizeManager(Authenticatable $user, Workspace $workspace, AnalyticsWorkspaceAccess $access): void
    {
        abort_unless($access->roleFor($user, $workspace)?->canManageMembers() === true, 403);
    }

    private function authorizeOwner(Authenticatable $user, Workspace $workspace, AnalyticsWorkspaceAccess $access): void
    {
        abort_unless($access->roleFor($user, $workspace) === WorkspaceRole::Owner, 403);
    }
}
