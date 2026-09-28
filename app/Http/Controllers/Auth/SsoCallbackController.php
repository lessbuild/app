<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\User;
use App\Services\Identity\AccountSso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class SsoCallbackController
{
    /**
     * Finish single sign-on: sign the person in, or record that a signed-in person proved who they are. The provider's
     * email must belong to a member of the account; nobody is created or added here.
     *
     * @param  Request  $request
     * @param  AccountSso  $sso
     * @param  RecordAuditEntry  $audit
     * @return RedirectResponse
     */
    public function __invoke(Request $request, AccountSso $sso, RecordAuditEntry $audit): RedirectResponse
    {
        $signedIn = $request->user();
        $failed = fn (string $message): RedirectResponse => $signedIn instanceof User
            ? to_route('settings.profile')->with('status', $message)
            : to_route('login')->withErrors(['social' => $message]);
        if (! is_string($request->query('code')) || ! is_string($request->query('state'))) {
            return $failed(is_string($request->query('error_description')) ? $request->query('error_description') : __('Single sign-on was cancelled.'));
        }
        try {
            ['account' => $account, 'email' => $email, 'intent' => $intent] = $sso->complete($request->session(), $request->query('code'), $request->query('state'));
        } catch (RuntimeException $exception) {
            report($exception);

            return $failed($exception->getMessage());
        }

        $user = User::query()->where('email', $email)->first();
        if ($user === null || ! $account->members()->whereKey($user->id)->exists()) {
            return $failed(__(':email isn’t a member of :account.', ['email' => $email, 'account' => $account->name]));
        }
        if ($intent === 'verify') {
            if (! $signedIn instanceof User || ! $signedIn->is($user)) {
                return $failed(__('The identity provider signed you in as :email, which isn’t you.', ['email' => $email]));
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
}
