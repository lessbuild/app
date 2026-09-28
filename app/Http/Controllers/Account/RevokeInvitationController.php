<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\RevokeInvitation;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RevokeInvitationController
{
    /**
     * Withdraw a pending invitation.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  string  $invitation
     * @param  RevokeInvitation  $revoke
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, string $invitation, RevokeInvitation $revoke): RedirectResponse
    {
        $revoke->handle($user, $account->invitations()->findOrFail($invitation));

        return to_route('account.members')->with('status', __('Invitation revoked.'));
    }
}
