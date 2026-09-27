<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Users\ConnectSocialIdentity;
use App\Actions\Users\SignInWithSocialProfile;
use App\Enums\SocialProvider;
use App\Enums\SocialSignInOutcome;
use App\Exceptions\IdentityRuleViolation;
use App\Listeners\RecordSignInActivity;
use App\Models\User;
use App\Queries\Users\OwnsSocialIdentity;
use App\Services\SocialSignIn\ProviderIntents;
use App\Services\SocialSignIn\SocialSignInFailed;
use App\Services\SocialSignIn\SocialSignInGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Features;

/**
 * The OAuth callback. Guests are signed in (or signed up); signed-in people finish connecting a provider
 * or confirming identity, but only for a flow this browser started (ProviderIntents).
 */
final class HandleProviderCallbackController
{
    public function __construct(
        private readonly SocialSignInGateway $gateway,
        private readonly ProviderIntents $intents,
        private readonly SignInWithSocialProfile $signIn,
        private readonly ConnectSocialIdentity $connect,
        private readonly OwnsSocialIdentity $owns,
    ) {}

    public function __invoke(Request $request, SocialProvider $provider): RedirectResponse
    {
        $user = $request->user();

        return $user instanceof User ? $this->completeIntent($request, $user, $provider) : $this->signInGuest($request, $provider);
    }

    private function signInGuest(Request $request, SocialProvider $provider): RedirectResponse
    {
        $failed = fn (string $message): RedirectResponse => to_route('login')->withErrors(['social' => $message]);
        if ($request->has('error')) {
            return $failed(__('The :provider sign-in was cancelled.', ['provider' => $provider->label()]));
        }
        try {
            $profile = $this->gateway->profile($provider);
        } catch (SocialSignInFailed) {
            return $failed(__('We couldn’t sign you in with :provider. Please try again.', ['provider' => $provider->label()]));
        }

        $result = $this->signIn->handle($provider, $profile, Features::enabled(Features::registration()));
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
            $request->session()->put(['login.id' => $result->user->getKey(), 'login.remember' => false, RecordSignInActivity::PENDING_METHOD => $provider->value]);

            return to_route('two-factor.login');
        }

        Auth::guard('web')->login($result->user);

        return redirect()->intended(route('dashboard'));
    }

    private function completeIntent(Request $request, User $user, SocialProvider $provider): RedirectResponse
    {
        $intent = $this->intents->pull($request, $user, $provider);
        $confirming = ($intent['type'] ?? null) === ProviderIntents::CONFIRM;
        $failed = fn (string $message): RedirectResponse => to_route($confirming ? 'password.confirm' : 'settings.security')->withErrors(['social' => $message], 'social');

        if ($intent === null || ! $intent['valid']) {
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
            if (! $this->owns->handle($user, $provider, $profile)) {
                return $failed(__('That :provider account isn’t connected to you. Use the one you connected.', ['provider' => $provider->label()]));
            }
            $request->session()->passwordConfirmed();

            return redirect()->intended(route('settings.security'));
        }

        try {
            $connected = $this->connect->handle($user, $provider, $profile);
        } catch (IdentityRuleViolation $violation) {
            return $failed($violation->getMessage());
        }

        return to_route('settings.security')->with('status', $connected ? 'social-connected' : 'social-already-connected');
    }
}
