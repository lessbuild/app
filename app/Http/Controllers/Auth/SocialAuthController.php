<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Account\ConnectSocialAccountAction;
use App\Actions\Account\ResolveSocialLoginAction;
use App\Data\SocialAccountConnectionResult;
use App\Data\SocialIdentityData;
use App\Data\SocialLoginResolution;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SignInRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialAuthController extends Controller
{
    /**
     * Record completed provider sign-ins through the shared sign-in recorder.
     */
    public function __construct(
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
    public function callback(
        string $provider,
        Request $request,
        ResolveSocialLoginAction $resolve,
        ConnectSocialAccountAction $connect,
    ): RedirectResponse {
        $connecting = Auth::check()
            && $request->session()->pull('social_connect_provider') === $provider;

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

        if ($connecting) {
            /** @var User $actor */
            $actor = $request->user();
            $result = $connect->handle($actor, $provider, $providerId);

            return $result->status === SocialAccountConnectionResult::CONNECTED
                ? redirect()->route('account.index')->with('social_status', __(':provider connected.', [
                    'provider' => ucfirst($provider),
                ]))
                : redirect()->route('account.index')->with(
                    'social_error',
                    __('That social identity is already connected to another account.'),
                );
        }

        $resolution = $resolve->handle(new SocialIdentityData(
            provider: $provider,
            providerId: $providerId,
            email: $email,
            name: $socialUser->getName() ?: $socialUser->getNickname() ?: Str::before($email, '@'),
        ));

        if ($resolution->status === SocialLoginResolution::EXISTING_EMAIL) {
            return redirect()->route('login')->withErrors([
                'social_auth' => __('An account already uses this email. Sign in first, then connect :provider from account settings.', [
                    'provider' => ucfirst($provider),
                ]),
            ]);
        }

        if ($resolution->status === SocialLoginResolution::CLOSED) {
            return redirect()->route('login')->withErrors([
                'social_auth' => __('No account matches this :provider identity, and registration is closed.', [
                    'provider' => ucfirst($provider),
                ]),
            ]);
        }

        $user = $resolution->user;
        if (! $user) {
            return $this->socialFailure(
                false,
                __('Unable to authenticate with :provider. Please try again.', [
                    'provider' => ucfirst($provider),
                ]),
            );
        }

        Auth::login($user);
        $request->session()->regenerate();
        if ($user->twoFactorEnabled()) {
            $request->session()->put([
                'two_factor_login_user_id' => $user->id,
                'two_factor_login_remember' => false,
                'two_factor_login_method' => $provider,
            ]);
            Auth::logout();

            return redirect()->route('two-factor.login');
        }
        $this->signIns->record($user, $provider, $request);

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
     * Return the supplied authentication error to account settings for linking, or login for sign-in.
     */
    private function socialFailure(bool $connecting, string $message): RedirectResponse
    {
        return $connecting
            ? redirect()->route('account.index')->with('social_error', $message)
            : redirect()->route('login')->withErrors(['social_auth' => $message]);
    }
}
