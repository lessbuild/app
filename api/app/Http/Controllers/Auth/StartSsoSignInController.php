<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\Account;
use App\Models\User;
use App\Services\Identity\AccountSso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** `POST /api/app/auth/sso`. */
final class StartSsoSignInController
{
    /**
     * Start single sign-on for an email address: find the person's account that uses SSO for it, and answer with the
     * identity provider's address to send them to.
     *
     * @param  Request  $request
     * @param  AccountSso  $sso
     * @return JsonResponse
     *
     * @throws ValidationException
     */
    public function __invoke(Request $request, AccountSso $sso): JsonResponse
    {
        $email = mb_strtolower((string) $request->validate(['email' => ['required', 'email', 'max:254']])['email']);
        $user = User::query()->where('email', $email)->first();
        $account = $user === null ? null : $user->accounts()->get()
            ->sortByDesc(fn (Account $account): bool => $account->id === $user->current_account_id)
            ->first(fn (Account $account): bool => $account->hasSso() && $account->allowsEmail($email));
        if ($account === null) {
            throw ValidationException::withMessages(['email' => __('Single sign-on isn’t set up for that address. Sign in with your password instead.')]);
        }
        try {
            return response()->json(['redirect' => $sso->begin($request->session(), $account, 'login')]);
        } catch (RuntimeException $exception) {
            report($exception);

            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        }
    }
}
