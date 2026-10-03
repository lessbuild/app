<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Users\RecordSignIn;
use App\Enums\SignInMethod;
use App\Enums\SocialProvider;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/** Turns framework auth events into sign-in history, working out the method from the route that signed the user in. */
final class RecordSignInActivity
{
    /** Session key naming the first factor while a two-factor challenge is pending (set for provider sign-ins). */
    public const PENDING_METHOD = 'login.method';

    /**
     * Create a new RecordSignInActivity instance.
     *
     * Records successful and failed sign-ins for the person's sign-in activity list.
     *
     * @param  Request  $request  The request the sign-in happened in, which says how it happened and where from.
     * @param  RecordSignIn  $record  Stores the sign-in.
     */
    public function __construct(private readonly Request $request, private readonly RecordSignIn $record) {}

    /**
     * Record a successful sign-in and how it was done, worked out from the route that finished it. After a two-factor
     * challenge, the first factor comes from the session.
     *
     * @param  Login  $event
     * @return void
     */
    public function login(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $twoFactor = $this->request->routeIs('two-factor.login.store');
        $method = match (true) {
            $twoFactor => $this->pendingMethod(),
            $this->request->routeIs('login.store') => SignInMethod::Password,
            $this->request->routeIs('passkey.login') => SignInMethod::Passkey,
            $this->request->routeIs('register.store') => SignInMethod::Registration,
            $this->request->routeIs('social.callback') => $this->providerMethod(),
            $event->remember => SignInMethod::Remembered,
            default => null,
        };
        if ($this->request->hasSession()) {
            $this->request->session()->forget(self::PENDING_METHOD);
        }

        $this->record->handle($event->user, true, $method, $twoFactor, $this->request->ip(), $this->request->userAgent());
    }

    /**
     * Record a wrong password for a known account. Unknown emails aren't recorded, since there's nobody to show them
     * to.
     *
     * @param  Failed  $event
     * @return void
     */
    public function failed(Failed $event): void
    {
        if ($event->user instanceof User) {
            $this->record->handle($event->user, false, SignInMethod::Password, false, $this->request->ip(), $this->request->userAgent());
        }
    }

    /**
     * Record a wrong two-factor code, with the first factor the person had already passed.
     *
     * @param  TwoFactorAuthenticationFailed  $event
     * @return void
     */
    public function twoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->record->handle($event->user, false, $this->pendingMethod(), true, $this->request->ip(), $this->request->userAgent());
    }

    /**
     * Get the first factor of a sign-in waiting on its two-factor code. Provider sign-ins store theirs in the session;
     * otherwise it was a password.
     *
     * @return SignInMethod
     */
    private function pendingMethod(): SignInMethod
    {
        $pending = $this->request->hasSession() ? $this->request->session()->get(self::PENDING_METHOD) : null;

        return (is_string($pending) ? SignInMethod::tryFrom($pending) : null) ?? SignInMethod::Password;
    }

    /**
     * Get the sign-in method for the provider named in the callback URL, or null for an unknown provider.
     *
     * @return SignInMethod|null
     */
    private function providerMethod(): ?SignInMethod
    {
        $provider = $this->request->route('provider');
        $provider = is_string($provider) ? SocialProvider::tryFrom($provider) : $provider;

        return $provider instanceof SocialProvider ? SignInMethod::fromProvider($provider) : null;
    }
}
