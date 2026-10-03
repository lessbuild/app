<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\RevokeInvitation;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class RevokeInvitationController
{
    /**
     * Withdraw a pending invitation.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  string  $invitation
     * @param  RevokeInvitation  $revoke
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, string $invitation, RevokeInvitation $revoke): JsonResponse
    {
        $revoke->handle($user, $account->invitations()->findOrFail($invitation));

        return response()->json(['redirect' => route('account.members', [], false), 'message' => __('Invitation revoked.')]);
    }
}
