<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\AcceptInvitation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class AcceptInvitationController
{
    /**
     * Join the invitation's account as the signed-in person and takes them to its dashboard.
     *
     * @param  User  $user
     * @param  string  $token
     * @param  AcceptInvitation  $acceptInvitation
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, string $token, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $membership = $acceptInvitation->handle($user, $token);

        return redirect()->route('dashboard')->with('status', __('You joined :account.', ['account' => $membership->account->name]));
    }
}
