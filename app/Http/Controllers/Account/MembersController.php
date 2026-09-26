<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Accounts\Actions\ChangeMemberRole;
use App\Domain\Accounts\Actions\InviteMember;
use App\Domain\Accounts\Actions\RemoveMember;
use App\Domain\Accounts\Actions\RevokeInvitation;
use App\Domain\Accounts\Actions\SetServiceAccess;
use App\Domain\Accounts\Enums\AccountRole;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Queries\MembersOverviewQuery;
use App\Domain\Identity\Models\User;
use App\Http\Requests\Account\InviteMemberRequest;
use App\Platform\ServiceRegistry;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Members of the signed-in user's current account; the actions enforce who may do what. */
final class MembersController
{
    public function __construct(private readonly ServiceRegistry $services) {}

    public function index(#[CurrentUser] User $user, MembersOverviewQuery $query): View
    {
        $account = $this->account($user);
        Gate::authorize('view', $account);

        return view('account.members', ['account' => $account, 'overview' => $query->handle($account, $user), 'services' => $this->services->all()]);
    }

    public function invite(InviteMemberRequest $request, #[CurrentUser] User $user, InviteMember $invite): RedirectResponse
    {
        $invitation = $invite->handle($user, $this->account($user), $request->toData());

        return to_route('account.members')->with('status', __('Invitation sent to :email.', ['email' => $invitation->email]));
    }

    public function revokeInvitation(#[CurrentUser] User $user, string $invitation, RevokeInvitation $revoke): RedirectResponse
    {
        $revoke->handle($user, $this->account($user)->invitations()->findOrFail($invitation));

        return to_route('account.members')->with('status', __('Invitation revoked.'));
    }

    public function updateRole(Request $request, #[CurrentUser] User $user, string $membership, ChangeMemberRole $change): RedirectResponse
    {
        $validated = $request->validate(['role' => ['required', Rule::enum(AccountRole::class)]]);
        $updated = $change->handle($user, $this->account($user)->memberships()->findOrFail($membership), AccountRole::from($validated['role']));

        return to_route('account.members')->with('status', __(':name is now :role.', ['name' => $updated->user->name, 'role' => $updated->role->label()]));
    }

    public function updateServices(Request $request, #[CurrentUser] User $user, string $membership, SetServiceAccess $setAccess): RedirectResponse
    {
        $validated = $request->validate([
            'access' => ['required', Rule::in(['all', 'some'])],
            'services' => ['array'],
            'services.*' => ['string', Rule::in($this->services->keys())],
        ]);
        /** @var list<string> $services */
        $services = $validated['services'] ?? [];
        $updated = $setAccess->handle($user, $this->account($user)->memberships()->findOrFail($membership), $validated['access'] === 'all' ? null : $services);

        return to_route('account.members')->with('status', __('Service access saved for :name.', ['name' => $updated->user->name]));
    }

    public function remove(#[CurrentUser] User $user, string $membership, RemoveMember $remove): RedirectResponse
    {
        $account = $this->account($user);
        $target = $account->memberships()->with('user')->findOrFail($membership);
        $remove->handle($user, $target);

        return $target->user_id === $user->id
            ? to_route('dashboard')->with('status', __('You left :account.', ['account' => $account->name]))
            : to_route('account.members')->with('status', __(':name was removed.', ['name' => $target->user->name]));
    }

    private function account(User $user): Account
    {
        return $user->currentAccount ?? abort(404);
    }
}
