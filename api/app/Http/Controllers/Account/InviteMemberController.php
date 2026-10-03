<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\InviteMember;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Account\InviteMemberRequest;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class InviteMemberController
{
    /**
     * Send an invitation to join the account.
     *
     * @param  Account  $account
     * @param  InviteMemberRequest  $request
     * @param  User  $user
     * @param  InviteMember  $invite
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, InviteMemberRequest $request, #[CurrentUser] User $user, InviteMember $invite): JsonResponse
    {
        $invitation = $invite->handle($user, $account, $request->toData());

        return response()->json(['redirect' => route('account.members', [], false), 'message' => __('Invitation sent to :email.', ['email' => $invitation->email])]);
    }
}
