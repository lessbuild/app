<?php

declare(strict_types=1);

namespace App\Auth\Listeners;

use App\Domain\Identity\Actions\RecordSignIn;
use App\Domain\Identity\Enums\SignInMethod;
use App\Domain\Identity\Enums\SocialProvider;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/** Turns framework auth events into sign-in history, working out the method from the route that signed the user in. */
final class RecordSignInActivity
{
    /** Session key naming the first factor while a two-factor challenge is pending (set for provider sign-ins). */
    public const PENDING_METHOD = 'login.method';

    public function __construct(private readonly Request $request, private readonly RecordSignIn $record) {}

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

    public function failed(Failed $event): void
    {
        if ($event->user instanceof User) {
            $this->record->handle($event->user, false, SignInMethod::Password, false, $this->request->ip(), $this->request->userAgent());
        }
    }

    public function twoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        if ($event->user instanceof User) {
            $this->record->handle($event->user, false, $this->pendingMethod(), true, $this->request->ip(), $this->request->userAgent());
        }
    }

    private function pendingMethod(): SignInMethod
    {
        $pending = $this->request->hasSession() ? $this->request->session()->get(self::PENDING_METHOD) : null;

        return (is_string($pending) ? SignInMethod::tryFrom($pending) : null) ?? SignInMethod::Password;
    }

    private function providerMethod(): ?SignInMethod
    {
        $provider = $this->request->route('provider');
        $provider = is_string($provider) ? SocialProvider::tryFrom($provider) : $provider;

        return $provider instanceof SocialProvider ? SignInMethod::fromProvider($provider) : null;
    }
}
