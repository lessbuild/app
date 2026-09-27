<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\AcceptInvitation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class AcceptInvitationController
{
    public function __invoke(#[CurrentUser] User $user, string $token, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $membership = $acceptInvitation->handle($user, $token);

        return redirect()->route('dashboard')->with('status', __('You joined :account.', ['account' => $membership->account->name]));
    }
}
