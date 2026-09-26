<?php

namespace App\Core\Http\Controllers\Auth;

use App\Core\Http\Requests\BeginPlatformSocialConnectionRequest;
use App\Core\Http\Requests\DisconnectPlatformSocialIdentityRequest;
use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\ConnectPlatformSocialIdentity;
use App\Core\Services\Auth\DisconnectPlatformSocialIdentity;
use App\Core\Services\Auth\PlatformAuthenticationSessions;
use App\Core\Services\Auth\PlatformRedirectTarget;
use App\Core\Services\Auth\PlatformSocialIdentityResult;
use App\Core\Services\Auth\PlatformSocialLoginResolution;
use App\Core\Services\Auth\PlatformSocialProviders;
use App\Core\Services\Auth\PlatformSsoHandoff;
use App\Core\Services\Auth\ResolvePlatformSocialLogin;
use App\Core\Services\Auth\VerifyPlatformTwoFactorCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class PlatformSocialAuthController
{
    private const SESSION_KEYS = [
        'platform.social.intent',
        'platform.social.provider',
        'platform.social.user_id',
        'platform.social.confirmed_at',
        'platform.social.return_to',
    ];

    public function redirect(
        Request $request,
        string $provider,
        PlatformSocialProviders $providers,
        PlatformRedirectTarget $redirects,
    ): RedirectResponse {
        abort_unless($providers->supports($provider), 404);
        $returnTo = $redirects->resolve($request->query('return_to'), $request);

        if ($request->user('platform') instanceof PlatformUser) {
            return redirect()->route('platform.account.security')->with(
                'social_error',
                __('You are already signed in. Start a provider connection from account security.'),
            );
        }

        if (! $providers->configured($provider)) {
            return redirect()->route('platform.login', $returnTo ? ['return_to' => $returnTo] : [])->withErrors([
                'social_auth' => __(':provider sign-in is not configured yet.', ['provider' => $providers->label($provider)]),
            ]);
        }

        $request->session()->forget(self::SESSION_KEYS);
        $request->session()->put('platform.social.return_to', $returnTo);

        return $providers->driver($provider)->redirect();
    }

    public function connect(
        BeginPlatformSocialConnectionRequest $request,
        string $provider,
        PlatformSocialProviders $providers,
        VerifyPlatformTwoFactorCode $verifyCode,
    ): RedirectResponse {
        abort_unless($providers->supports($provider), 404);
        $user = $this->user($request);

        if (! $providers->configured($provider)) {
            return $this->accountRedirect()->with('social_error', __(':provider connection is not configured yet.', [
                'provider' => $providers->label($provider),
            ]));
        }

        if ($user->identities()->where('provider', $provider)->where('status', 'active')->exists()) {
            return $this->accountRedirect()->with('social_status', __('That account is already connected.'));
        }

        if ($user->twoFactorEnabled() && ! $verifyCode->handle($user, (string) $request->validated('code'))) {
            throw ValidationException::withMessages([
                'code' => __('The authenticator or recovery code is invalid.'),
            ])->errorBag('social');
        }

        $request->session()->forget(self::SESSION_KEYS);
        $request->session()->put([
            'platform.social.intent' => 'connect',
            'platform.social.provider' => $provider,
            'platform.social.user_id' => (string) $user->getKey(),
            'platform.social.confirmed_at' => now()->getTimestamp(),
        ]);

        return $providers->driver($provider)->redirect();
    }

    public function callback(
        Request $request,
        string $provider,
        PlatformSocialProviders $providers,
        PlatformRedirectTarget $redirects,
        ResolvePlatformSocialLogin $resolve,
        ConnectPlatformSocialIdentity $connect,
        PlatformAuthenticationSessions $sessions,
        PlatformSsoHandoff $handoff,
    ): RedirectResponse|Response {
        abort_unless($providers->supports($provider), 404);

        $connecting = $request->session()->get('platform.social.intent') === 'connect';
        if ($connecting) {
            return $this->completeConnection($request, $provider, $providers, $connect);
        }

        if (Auth::guard('platform')->user() instanceof PlatformUser) {
            $request->session()->forget(self::SESSION_KEYS);

            return $this->accountRedirect()->with(
                'social_error',
                __('You are already signed in. Start a provider connection from account security.'),
            );
        }

        if ($request->has('error')) {
            $returnTo = $request->session()->pull('platform.social.return_to');
            $request->session()->forget(self::SESSION_KEYS);

            return redirect()->route('platform.login', is_string($returnTo) && $returnTo !== '' ? ['return_to' => $returnTo] : [])
                ->withErrors(['social_auth' => __('The :provider sign-in was canceled or could not be completed.', [
                    'provider' => $providers->label($provider),
                ])]);
        }

        try {
            $socialUser = $providers->driver($provider)->user();
        } catch (Throwable) {
            $returnTo = $request->session()->pull('platform.social.return_to');
            $request->session()->forget(self::SESSION_KEYS);

            return redirect()->route('platform.login', is_string($returnTo) && $returnTo !== '' ? ['return_to' => $returnTo] : [])
                ->withErrors(['social_auth' => __('Unable to authenticate with :provider. Please try again.', [
                    'provider' => $providers->label($provider),
                ])]);
        }

        $identity = $this->identityData($socialUser);
        $returnTo = $request->session()->pull('platform.social.return_to');
        $request->session()->forget(self::SESSION_KEYS);

        if ($identity === null) {
            return redirect()->route('platform.login', is_string($returnTo) && $returnTo !== '' ? ['return_to' => $returnTo] : [])
                ->withErrors(['social_auth' => __('Your :provider account must provide a verified email address.', [
                    'provider' => $providers->label($provider),
                ])]);
        }

        $safeReturnTo = $redirects->resolve(is_string($returnTo) ? $returnTo : null, $request);
        $invitationToken = $this->invitationToken($safeReturnTo);
        $resolution = $resolve->handle(
            $provider,
            $identity['id'],
            $identity['email'],
            $identity['name'],
            $invitationToken,
        );

        if ($resolution->status !== PlatformSocialLoginResolution::RESOLVED || ! $resolution->user) {
            $message = match ($resolution->status) {
                PlatformSocialLoginResolution::EMAIL_EXISTS => __('An account already uses this email. Sign in first, then connect :provider from account security.', ['provider' => $providers->label($provider)]),
                PlatformSocialLoginResolution::REGISTRATION_CLOSED => __('No account matches this :provider identity, and registration is closed.', ['provider' => $providers->label($provider)]),
                PlatformSocialLoginResolution::INVITATION_INVALID => __('This workspace invitation is no longer valid. Ask the workspace owner for a new invitation.'),
                PlatformSocialLoginResolution::INVITATION_EMAIL_MISMATCH => __('Use the provider email address that received this workspace invitation.'),
                default => __('This :provider identity is unavailable. Sign in with another method or contact support.', ['provider' => $providers->label($provider)]),
            };

            return redirect()->route('platform.login', $safeReturnTo ? ['return_to' => $safeReturnTo] : [])->withErrors([
                'social_auth' => $message,
            ]);
        }

        $user = $resolution->user;
        if ($user->twoFactorEnabled()) {
            $request->session()->regenerate();
            $request->session()->put([
                'platform.auth.two_factor_user_id' => $user->getKey(),
                'platform.auth.remember' => false,
                'platform.auth.return_to' => $safeReturnTo,
            ]);

            return to_route('platform.two-factor.create');
        }

        Auth::guard('platform')->login($user);
        $request->session()->regenerate();
        $sessions->begin($user, $request, remember: false);

        return $handoff->respond($request, $user, $safeReturnTo ?? route('core.home'));
    }

    public function disconnect(
        DisconnectPlatformSocialIdentityRequest $request,
        string $provider,
        PlatformSocialProviders $providers,
        VerifyPlatformTwoFactorCode $verifyCode,
        DisconnectPlatformSocialIdentity $disconnect,
    ): RedirectResponse {
        abort_unless($providers->supports($provider), 404);
        $user = $this->user($request);

        if ($user->twoFactorEnabled() && ! $verifyCode->handle($user, (string) $request->validated('code'))) {
            throw ValidationException::withMessages([
                'code' => __('The authenticator or recovery code is invalid.'),
            ])->errorBag('social');
        }

        $result = $disconnect->handle($user, $provider);

        return $this->accountRedirect()->with(match ($result->status) {
            PlatformSocialIdentityResult::DISCONNECTED => ['social_status' => __(':provider was disconnected.', ['provider' => $providers->label($provider)])],
            PlatformSocialIdentityResult::LAST_SIGN_IN_METHOD => ['social_error' => __('Add a password or another sign-in method before disconnecting this account.')],
            default => ['social_error' => __('That account is not currently connected.')],
        });
    }

    private function completeConnection(
        Request $request,
        string $provider,
        PlatformSocialProviders $providers,
        ConnectPlatformSocialIdentity $connect,
    ): RedirectResponse {
        $intentProvider = $request->session()->get('platform.social.provider');
        $userId = $request->session()->get('platform.social.user_id');
        $confirmedAt = $request->session()->get('platform.social.confirmed_at');
        $actor = $request->user('platform');
        $validIntent = $intentProvider === $provider
            && is_string($userId)
            && $actor instanceof PlatformUser
            && hash_equals($userId, (string) $actor->getKey())
            && is_numeric($confirmedAt)
            && (int) $confirmedAt <= now()->getTimestamp()
            && (int) $confirmedAt >= now()->subMinutes(5)->getTimestamp();

        if (! $validIntent) {
            $request->session()->forget(self::SESSION_KEYS);

            return redirect()->route($actor instanceof PlatformUser ? 'platform.account.security' : 'platform.login')
                ->with($actor instanceof PlatformUser ? 'social_error' : 'status', __('The connection request expired. Start again from account security.'));
        }

        if ($request->has('error')) {
            $request->session()->forget(self::SESSION_KEYS);

            return $this->accountRedirect()->with('social_error', __('The :provider connection was canceled.', [
                'provider' => $providers->label($provider),
            ]));
        }

        try {
            $socialUser = $providers->driver($provider)->user();
        } catch (Throwable) {
            $request->session()->forget(self::SESSION_KEYS);

            return $this->accountRedirect()->with('social_error', __('Unable to connect :provider. Please try again.', [
                'provider' => $providers->label($provider),
            ]));
        }

        $identity = $this->identityData($socialUser);
        $request->session()->forget(self::SESSION_KEYS);

        if ($identity === null) {
            return $this->accountRedirect()->with('social_error', __('Your :provider account must provide a verified email address.', [
                'provider' => $providers->label($provider),
            ]));
        }

        $result = $connect->handle($actor, $provider, $identity['id'], $identity['email']);

        return match ($result->status) {
            PlatformSocialIdentityResult::CONNECTED => $this->accountRedirect()->with('social_status', __(':provider connected.', ['provider' => $providers->label($provider)])),
            PlatformSocialIdentityResult::ALREADY_CONNECTED => $this->accountRedirect()->with('social_status', __('That account is already connected.')),
            PlatformSocialIdentityResult::PROVIDER_ALREADY_CONNECTED => $this->accountRedirect()->with('social_error', __('A different :provider account is already connected to this Buildpusher account.', ['provider' => $providers->label($provider)])),
            default => $this->accountRedirect()->with('social_error', __('That social identity is already connected to another Buildpusher account.')),
        };
    }

    /** @return array{id: string, email: string, name: string}|null */
    private function identityData(SocialiteUser $socialUser): ?array
    {
        $id = trim((string) $socialUser->getId());
        $email = mb_strtolower(trim((string) $socialUser->getEmail()));

        if ($id === '' || strlen($id) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $name = trim((string) ($socialUser->getName() ?: $socialUser->getNickname()));

        return [
            'id' => $id,
            'email' => $email,
            'name' => $name !== '' ? $name : Str::before($email, '@'),
        ];
    }

    private function invitationToken(?string $returnTo): ?string
    {
        if (! is_string($returnTo) || ! filled(config('platform.auth_host'))) {
            return null;
        }

        $parts = parse_url($returnTo);
        $authHost = parse_url(str_contains((string) config('platform.auth_host'), '://')
            ? (string) config('platform.auth_host')
            : 'https://'.config('platform.auth_host'));

        if (! is_array($parts) || ! is_array($authHost)
            || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($authHost['host'] ?? ''))) {
            return null;
        }

        return preg_match('#(?:\A|/)invitations/([a-f0-9]{64})\z#', (string) ($parts['path'] ?? ''), $matches)
            ? $matches[1]
            : null;
    }

    private function user(Request $request): PlatformUser
    {
        $user = $request->user('platform') ?? Auth::guard('platform')->user();
        abort_unless($user instanceof PlatformUser && $user->status === 'active', 401);

        return $user;
    }

    private function accountRedirect(): RedirectResponse
    {
        return redirect()->route('platform.account.security')
            ->withHeaders(['Cache-Control' => 'private, no-store, max-age=0', 'Referrer-Policy' => 'no-referrer']);
    }
}
