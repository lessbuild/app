<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Services\Identity\AccountSso;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class StartSsoVerificationController
{
    /**
     * Send a signed-in person to their account's identity provider to prove who they are (to test the settings, or
     * because the account requires single sign-on).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  AccountSso  $sso
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, AccountSso $sso): RedirectResponse
    {
        $account = $user->currentAccount ?? abort(404);
        try {
            return redirect()->away($sso->begin($request->session(), $account, 'verify'));
        } catch (RuntimeException $exception) {
            report($exception);

            return $user->can('update', $account)
                ? to_route('account.security')->with('error', $exception->getMessage())
                : to_route('settings.profile')->with('status', $exception->getMessage());
        }
    }
}
