<?php

namespace App\Http\Controllers;

use App\Actions\Account\DisconnectSocialAccountAction;
use App\Actions\Account\RevokeOtherSessionsAction;
use App\Actions\Account\RevokeSessionAction;
use App\Actions\Account\UpdatePasswordAction;
use App\Actions\Account\UpdateProfileAction;
use App\Data\SocialAccountDisconnectResult;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Requests\DisconnectSocialRequest;
use App\Http\Requests\RevokeOtherSessionsRequest;
use App\Http\Requests\RevokeSessionRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\SignInEvent;
use App\Models\User;
use App\Services\BrowserSessionManager;
use App\Services\ClientMetadata;
use App\Services\TwoFactorAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UsersController extends Controller
{
    /**
     * Render the current account's sessions, bounded sign-in/activity history, linked providers, and two-factor setup details.
     */
    public function index(
        Request $request,
        BrowserSessionManager $browserSessions,
        ClientMetadata $clients,
        TwoFactorAuthentication $twoFactor,
    ): View {
        $connected = $request->user()->connectedSocialProviders();

        return view('scenes.users.index', [
            'browserSessionManagementAvailable' => $browserSessions->available(),
            'browserSessions' => $browserSessions->activeFor(
                $request->user(),
                $request->session()->getId(),
            ),
            'recentSignIns' => $request->user()
                ->signIns()
                ->select(['id', 'method', 'ip_address', 'user_agent', 'signed_in_at'])
                ->orderByDesc('signed_in_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (SignInEvent $event): array => [
                    'method' => $event->methodName(),
                    'device' => $clients->deviceName($event->user_agent),
                    'ip_address' => $clients->displayIp($event->ip_address),
                    'signed_in_at' => $event->signed_in_at,
                ]),
            'recentAccountEvents' => $request->user()
                ->accountEvents()
                ->select(['id', 'event', 'category', 'created_at'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(5)
                ->get(),
            'socialProviders' => collect(User::SOCIAL_PROVIDER_COLUMNS)
                ->keys()
                ->map(fn (string $provider): array => [
                    'key' => $provider,
                    'name' => $this->socialProviderName($provider),
                    'connected' => in_array($provider, $connected, true),
                    'configured' => SocialAuthController::configured($provider),
                    'can_disconnect' => $request->user()->hasLocalPassword() || count($connected) > 1,
                    'requires_password' => $request->user()->hasLocalPassword(),
                ]),
            'twoFactorProvisioningUri' => $twoFactor->provisioningUri($request->user()),
        ]);
    }

    /**
     * Validate name and email, requiring the local password when changing an established account email.
     *
     * @return RedirectResponse The saved profile result and any verification-email error; local-password email changes revoke other sessions.
     */
    public function updateProfile(UpdateProfileRequest $request, UpdateProfileAction $update): RedirectResponse
    {
        $result = $update->handle($request->user(), $request->profileData(), $request->session()->getId());
        $response = back()->with('profile_status', __('Profile updated.'));
        if (! $result->emailChanged) {
            return $response;
        }

        return $result->verificationSent
            ? $response->with('status', 'verification-link-sent')
            : $response->with('verification_error', __('The email address was updated, but the verification message could not be sent. Try sending it again below.'));
    }

    /**
     * Validate a confirmed replacement password and any existing local password, then revoke other sessions and redirect back.
     */
    public function updatePassword(UpdatePasswordRequest $request, UpdatePasswordAction $update): RedirectResponse
    {
        $update->handle($request->user(), $request->passwordData(), $request->session()->getId());

        return back()->with('password_status', __('Password updated.'));
    }

    /**
     * Validate the current password, revoke other browser sessions, regenerate this session, and redirect with an acknowledgement.
     */
    public function revokeOtherSessions(RevokeOtherSessionsRequest $request, RevokeOtherSessionsAction $revoke): RedirectResponse
    {
        $revoke->handle($request->user(), $request->currentPassword(), $request->session()->getId());

        return back()->with('sessions_status', __('Other browser sessions logged out.'));
    }

    /**
     * Validate the current password and matching route/form session IDs before attempting to revoke another owned session.
     *
     * @return RedirectResponse The revoked, current-session, unavailable, or already-inactive outcome.
     */
    public function revokeSession(RevokeSessionRequest $request, RevokeSessionAction $revoke): RedirectResponse
    {
        $result = $revoke->handle(
            $request->user(),
            $request->sessionId(),
            $request->session()->getId(),
        );

        return match ($result) {
            'revoked' => back()->with('sessions_status', __('Browser session logged out.')),
            'current' => back()->with('sessions_error', __('You cannot log out the browser you are using now.')),
            'unavailable' => back()->with('sessions_error', __('Individual session management is not available on this installation.')),
            default => back()->with('sessions_status', __('That browser session is no longer active.')),
        };
    }

    /**
     * Disconnect a route-allowed provider under an account lock, requiring a password when applicable and retaining a sign-in method.
     *
     * @return RedirectResponse The disconnected result or an explanation that the provider is missing or the only method.
     */
    public function disconnectSocial(DisconnectSocialRequest $request, DisconnectSocialAccountAction $disconnect): RedirectResponse
    {
        $result = $disconnect->handle($request->user(), $request->provider());

        return match ($result->status) {
            SocialAccountDisconnectResult::DISCONNECTED => back()->with('social_status', __(':provider disconnected.', [
                'provider' => $result->providerName,
            ])),
            SocialAccountDisconnectResult::LAST_METHOD => back()->with('social_error', __('Set a local password before disconnecting your only sign-in method.')),
            default => back()->with('social_status', __('That social account is not connected.')),
        };
    }

    /**
     * Return the display label for a supported GitHub, GitLab, or Bitbucket provider key.
     */
    private function socialProviderName(string $provider): string
    {
        return match ($provider) {
            'github' => 'GitHub',
            'gitlab' => 'GitLab',
            'bitbucket' => 'Bitbucket',
        };
    }
}
