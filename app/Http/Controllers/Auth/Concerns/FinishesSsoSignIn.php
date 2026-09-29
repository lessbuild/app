<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\User;
use App\Services\Identity\AccountSso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** The end of a single sign-on, shared by the OpenID Connect callback and the SAML assertion consumer. */
trait FinishesSsoSignIn
{
    /**
     * Finish signing in (or proving it's you) as the member the identity provider vouched for: they must belong to
     * the account; a verification must match whoever is signed in. Records it and marks the session verified.
     *
     * @param  Request  $request
     * @param  Account  $account
     * @param  string  $email
     * @param  string  $intent
     * @param  AccountSso  $sso
     * @param  RecordAuditEntry  $audit
     * @return RedirectResponse
     */
    private function finishSso(Request $request, Account $account, string $email, string $intent, AccountSso $sso, RecordAuditEntry $audit): RedirectResponse
    {
        $signedIn = $request->user();
        $user = User::query()->where('email', $email)->first();
        if ($user === null || ! $account->members()->whereKey($user->id)->exists()) {
            return $this->ssoFailed($request, __(':email isn’t a member of :account.', ['email' => $email, 'account' => $account->name]));
        }
        if ($intent === 'verify') {
            if (! $signedIn instanceof User || ! $signedIn->is($user)) {
                return $this->ssoFailed($request, __('The identity provider signed you in as :email, which isn’t you.', ['email' => $email]));
            }
        } else {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }
        if ($user->email_verified_at === null) {
            $user->markEmailAsVerified();
        }
        $user->forceFill(['current_account_id' => $account->id])->save();
        $sso->markVerified($request->session(), $account);
        $audit->handle(AuditAction::SsoSignedIn, $user, $account->id);

        return redirect()->intended(route('dashboard'))->with('status', __('Signed in with :account’s single sign-on.', ['account' => $account->name]));
    }

    /**
     * Send the person back with why single sign-on didn't work: to their profile when signed in, else to sign-in.
     *
     * @param  Request  $request
     * @param  string  $message
     * @return RedirectResponse
     */
    private function ssoFailed(Request $request, string $message): RedirectResponse
    {
        return $request->user() instanceof User
            ? to_route('settings.profile')->with('status', $message)
            : to_route('login')->withErrors(['social' => $message]);
    }
}
