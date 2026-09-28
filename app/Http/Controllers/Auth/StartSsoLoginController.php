<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\Account;
use App\Models\User;
use App\Services\Identity\AccountSso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class StartSsoLoginController
{
    /**
     * Find the single sign-on for a work email address (an account the person belongs to that has it set up and
     * allows their domain) and send them to its identity provider.
     *
     * @param  Request  $request
     * @param  AccountSso  $sso
     * @return RedirectResponse
     */
    public function __invoke(Request $request, AccountSso $sso): RedirectResponse
    {
        $email = mb_strtolower((string) $request->validate(['email' => ['required', 'email', 'max:254']])['email']);
        $user = User::query()->where('email', $email)->first();
        $account = $user === null ? null : $user->accounts()->get()
            ->sortByDesc(fn (Account $account): bool => $account->id === $user->current_account_id)
            ->first(fn (Account $account): bool => $account->hasSso() && $account->allowsEmail($email));
        if ($account === null) {
            return back()->withInput()->withErrors(['email' => __('Single sign-on isn’t set up for that address. Sign in with your password instead.')]);
        }
        try {
            return redirect()->away($sso->begin($request->session(), $account, 'login'));
        } catch (RuntimeException $exception) {
            report($exception);

            return back()->withInput()->withErrors(['email' => $exception->getMessage()]);
        }
    }
}
