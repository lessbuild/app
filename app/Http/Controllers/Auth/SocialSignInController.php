<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Actions\ConnectSocialIdentity;
use App\Domain\Identity\Actions\DisconnectSocialIdentity;
use App\Domain\Identity\Actions\SignInWithSocialProfile;
use App\Domain\Identity\Enums\SocialProvider;
use App\Domain\Identity\Enums\SocialSignInOutcome;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Queries\OwnsSocialIdentity;
use App\Services\SocialSignIn\SocialSignInFailed;
use App\Services\SocialSignIn\SocialSignInGateway;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;

/**
 * One OAuth callback serves three flows: signing in (guest), connecting a provider from settings,
 * and confirming identity for sudo mode. The last two only complete when this browser started them.
 */
final class SocialSignInController
{
    private const INTENT = 'social.intent';

    private const INTENT_TTL_SECONDS = 600;

    public function __construct(private readonly SocialSignInGateway $gateway) {}

    public function redirect(SocialProvider $provider): Response
    {
        if (! $this->gateway->configured($provider)) {
            return to_route('login')->withErrors(['social' => __(':provider sign-in isn’t available yet.', ['provider' => $provider->label()])]);
        }

        return $this->gateway->redirect($provider);
    }

    public function connect(Request $request, #[CurrentUser] User $user, SocialProvider $provider): Response
    {
        return $this->startIntent($request, $user, $provider, 'connect', 'settings.security');
    }

    public function confirm(Request $request, #[CurrentUser] User $user, SocialProvider $provider): Response
    {
        return $this->startIntent($request, $user, $provider, 'confirm', 'password.confirm');
    }

    public function disconnect(#[CurrentUser] User $user, SocialProvider $provider, DisconnectSocialIdentity $disconnect): RedirectResponse
    {
        try {
            $disconnected = $disconnect->handle($user, $provider);
        } catch (IdentityRuleViolation $violation) {
            return to_route('settings.security')->withErrors([$violation->field => $violation->getMessage()], 'social');
        }

        return to_route('settings.security')->with('status', $disconnected ? 'social-disconnected' : 'social-not-connected');
    }

    public function callback(
        Request $request,
        SocialProvider $provider,
        SignInWithSocialProfile $signIn,
        ConnectSocialIdentity $connect,
        OwnsSocialIdentity $owns,
    ): RedirectResponse {
        $intent = $request->session()->pull(self::INTENT);
        $user = $request->user();

        if ($user instanceof User) {
            return $this->completeIntent($request, $user, $provider, $intent, $connect, $owns);
        }

        $failed = fn (string $message): RedirectResponse => to_route('login')->withErrors(['social' => $message]);
        if ($request->has('error')) {
            return $failed(__('The :provider sign-in was cancelled.', ['provider' => $provider->label()]));
        }
        try {
            $profile = $this->gateway->profile($provider);
        } catch (SocialSignInFailed) {
            return $failed(__('We couldn’t sign you in with :provider. Please try again.', ['provider' => $provider->label()]));
        }

        $result = $signIn->handle($provider, $profile, Features::enabled(Features::registration()));
        if ($result->user === null) {
            return $failed(match ($result->outcome) {
                SocialSignInOutcome::EmailInUse => __('An account already uses this email address. Sign in another way, then connect :provider from Settings → Security.', ['provider' => $provider->label()]),
                SocialSignInOutcome::RegistrationClosed => __('No account is connected to this :provider account.', ['provider' => $provider->label()]),
                default => __('Your :provider account must have a verified email address.', ['provider' => $provider->label()]),
            });
        }

        $request->session()->regenerate();
        if ($result->user->hasEnabledTwoFactorAuthentication()) {
            // Hand over to Fortify's challenge, exactly as a password sign-in would.
            $request->session()->put(['login.id' => $result->user->getKey(), 'login.remember' => false]);

            return to_route('two-factor.login');
        }

        Auth::guard('web')->login($result->user);

        return redirect()->intended(route('dashboard'));
    }

    private function startIntent(Request $request, User $user, SocialProvider $provider, string $type, string $returnRoute): Response
    {
        if (! $this->gateway->configured($provider)) {
            return to_route($returnRoute)->withErrors(['social' => __(':provider sign-in isn’t available yet.', ['provider' => $provider->label()])], 'social');
        }

        $request->session()->put(self::INTENT, [
            'type' => $type,
            'provider' => $provider->value,
            'user_id' => $user->id,
            'at' => now()->getTimestamp(),
        ]);

        return $this->gateway->redirect($provider);
    }

    private function completeIntent(Request $request, User $user, SocialProvider $provider, mixed $intent, ConnectSocialIdentity $connect, OwnsSocialIdentity $owns): RedirectResponse
    {
        $valid = is_array($intent)
            && in_array($intent['type'] ?? null, ['connect', 'confirm'], true)
            && ($intent['provider'] ?? null) === $provider->value
            && ($intent['user_id'] ?? null) === $user->id
            && is_int($intent['at'] ?? null)
            && $intent['at'] >= now()->getTimestamp() - self::INTENT_TTL_SECONDS;
        $confirming = is_array($intent) && ($intent['type'] ?? null) === 'confirm';
        $back = $confirming ? 'password.confirm' : 'settings.security';
        $failed = fn (string $message): RedirectResponse => to_route($back)->withErrors(['social' => $message], 'social');

        if (! $valid) {
            return $failed(__('That request expired. Please start again.'));
        }
        if ($request->has('error')) {
            return $failed(__('The :provider request was cancelled.', ['provider' => $provider->label()]));
        }
        try {
            $profile = $this->gateway->profile($provider);
        } catch (SocialSignInFailed) {
            return $failed(__('We couldn’t reach :provider. Please try again.', ['provider' => $provider->label()]));
        }

        if ($confirming) {
            if (! $owns->handle($user, $provider, $profile)) {
                return $failed(__('That :provider account isn’t connected to you. Use the one you connected.', ['provider' => $provider->label()]));
            }
            $request->session()->passwordConfirmed();

            return redirect()->intended(route('settings.security'));
        }

        try {
            $connected = $connect->handle($user, $provider, $profile);
        } catch (IdentityRuleViolation $violation) {
            return $failed($violation->getMessage());
        }

        return to_route('settings.security')->with('status', $connected ? 'social-connected' : 'social-already-connected');
    }
}
