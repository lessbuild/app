<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\InviteMember;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Account\InviteMemberRequest;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class InviteMemberController
{
    public function __invoke(#[CurrentAccount] Account $account, InviteMemberRequest $request, #[CurrentUser] User $user, InviteMember $invite): RedirectResponse
    {
        $invitation = $invite->handle($user, $account, $request->toData());

        return to_route('account.members')->with('status', __('Invitation sent to :email.', ['email' => $invitation->email]));
    }
}
