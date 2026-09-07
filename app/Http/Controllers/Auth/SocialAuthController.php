<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\PersonalOrganization;
use App\Services\RegistrationAccess;
use App\Services\SignInRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialAuthController extends Controller
{
    /**
     * Record account-linking activity and completed provider sign-ins through shared audit services.
     */
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly SignInRecorder $signIns,
    ) {}

    /**
     * @return list<string>
     */
    public static function providers(): array
    {
        return array_keys(User::SOCIAL_PROVIDER_COLUMNS);
    }

    /**
     * Start sign-in for a route-allowed provider, clearing connection intent; redirect to login if configuration is missing.
     */
    public function redirect(string $provider): RedirectResponse
    {
        request()->session()->forget('social_connect_provider');

        if (! self::configured($provider)) {
            return redirect()->route('login')->withErrors([
                'social_auth' => __(':provider sign-in has not been configured yet.', [
                    'provider' => ucfirst($provider),
                ]),
            ]);
        }

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Start linking a route-allowed provider to the authenticated account and remember that intent in the session.
     *
     * Already-linked or unconfigured providers redirect back with an explanation.
     */
    public function connect(string $provider): RedirectResponse
    {
        if (! self::configured($provider)) {
            return back()->with('social_error', __(':provider connection has not been configured yet.', [
                'provider' => ucfirst($provider),
            ]));
        }

        $column = User::SOCIAL_PROVIDER_COLUMNS[$provider];
        if (filled(request()->user()->{$column})) {
            return back()->with('social_status', __('That social account is already connected.'));
        }

        request()->session()->put('social_connect_provider', $provider);

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Resolve the provider identity as a deliberate account connection or a registration-aware sign-in.
     *
     * @return RedirectResponse Account settings, an authentication error, a two-factor challenge, or the intended page.
     */
    public function callback(string $provider, RegistrationAccess $registration, PersonalOrganization $organizations): RedirectResponse
    {
        $connecting = Auth::check()
            && request()->session()->pull('social_connect_provider') === $provider;

        if (Auth::check() && ! $connecting) {
            return redirect()->route('account.index')->with(
                'social_error',
                __('Start social account connections from your account settings.'),
            );
        }

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (Throwable $exception) {
            report($exception);

            return $this->socialFailure(
                $connecting,
                __('Unable to authenticate with :provider. Please try again.', [
                    'provider' => ucfirst($provider),
                ]),
            );
        }

        $providerId = trim((string) $socialUser->getId());
        $email = Str::lower(trim((string) $socialUser->getEmail()));

        if ($providerId === '' || $email === '') {
            return $this->socialFailure(
                $connecting,
                __('Your :provider account must provide an email address.', [
                    'provider' => ucfirst($provider),
                ]),
            );
        }

        $providerColumn = User::SOCIAL_PROVIDER_COLUMNS[$provider];
        if ($connecting) {
            return $this->connectAuthenticatedUser($provider, $providerColumn, $providerId);
        }

        $resolution = $registration->synchronized(function () use (
            $email,
            $provider,
            $providerColumn,
            $providerId,
            $registration,
            $socialUser,
        ): array {
            $user = User::query()->where($providerColumn, $providerId)->first();

            if ($user) {
                return ['status' => 'resolved', 'user' => $user];
            }

            if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                return ['status' => 'existing_email', 'user' => null];
            }

            if (! $registration->allowsNewUser()) {
                return ['status' => 'closed', 'user' => null];
            }

            return ['status' => 'resolved', 'user' => User::create([
                'name' => $socialUser->getName() ?: $socialUser->getNickname() ?: Str::before($email, '@'),
                'email' => $email,
                $providerColumn => $providerId,
                'auth_type' => $provider,
                'password' => Hash::make(Str::password(40)),
                'password_set_at' => null,
                'email_verified_at' => now(),
            ])];
        });

        if ($resolution['status'] === 'existing_email') {
            return redirect()->route('login')->withErrors([
                'social_auth' => __('An account already uses this email. Sign in first, then connect :provider from account settings.', [
                    'provider' => ucfirst($provider),
                ]),
            ]);
        }

        if ($resolution['status'] === 'closed') {
            return redirect()->route('login')->withErrors([
                'social_auth' => __('No account matches this :provider identity, and registration is closed.', [
                    'provider' => ucfirst($provider),
                ]),
            ]);
        }

        $user = $resolution['user'];
        $organizations->ensure($user);
        Auth::login($user);
        request()->session()->regenerate();
        if ($user->twoFactorEnabled()) {
            request()->session()->put([
                'two_factor_login_user_id' => $user->id,
                'two_factor_login_remember' => false,
                'two_factor_login_method' => $provider,
            ]);
            Auth::logout();

            return redirect()->route('two-factor.login');
        }
        $this->signIns->record($user, $provider, request());

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Return whether the selected provider has client ID, secret, and redirect URI configured.
     */
    public static function configured(string $provider): bool
    {
        return filled(config("services.{$provider}.client_id"))
            && filled(config("services.{$provider}.client_secret"))
            && filled(config("services.{$provider}.redirect"));
    }

    /**
     * Attach the verified provider identity under an account lock unless another user owns it.
     *
     * @param  string  $providerColumn  A trusted column from User::SOCIAL_PROVIDER_COLUMNS.
     * @return RedirectResponse Account settings with the connection or ownership-conflict result.
     */
    private function connectAuthenticatedUser(
        string $provider,
        string $providerColumn,
        string $providerId,
    ): RedirectResponse {
        $result = DB::transaction(function () use ($providerColumn, $providerId): string {
            $user = User::query()->lockForUpdate()->findOrFail(Auth::id());
            $owner = User::query()->where($providerColumn, $providerId)->first();

            if ($owner && ! $owner->is($user)) {
                return 'owned';
            }

            $user->forceFill([$providerColumn => $providerId])->save();

            return 'connected';
        });

        if ($result === 'connected') {
            $this->activity->recordAccount(Auth::user(), ucfirst($provider).' sign-in was connected.');
        }

        return $result === 'connected'
            ? redirect()->route('account.index')->with('social_status', __(':provider connected.', [
                'provider' => ucfirst($provider),
            ]))
            : redirect()->route('account.index')->with(
                'social_error',
                __('That social identity is already connected to another account.'),
            );
    }

    /**
     * Return the supplied authentication error to account settings for linking, or login for sign-in.
     */
    private function socialFailure(bool $connecting, string $message): RedirectResponse
    {
        return $connecting
            ? redirect()->route('account.index')->with('social_error', $message)
            : redirect()->route('login')->withErrors(['social_auth' => $message]);
    }
}
