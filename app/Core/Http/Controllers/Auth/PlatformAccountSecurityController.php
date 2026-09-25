<?php

namespace App\Core\Http\Controllers\Auth;

use App\Core\Http\Requests\BeginPlatformTwoFactorRequest;
use App\Core\Http\Requests\ConfirmPlatformTwoFactorRequest;
use App\Core\Http\Requests\DisablePlatformTwoFactorRequest;
use App\Core\Http\Requests\RegeneratePlatformRecoveryCodesRequest;
use App\Core\Http\Requests\UpdatePlatformPasswordRequest;
use App\Core\Models\Passkey;
use App\Core\Models\PlatformAuthSession;
use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\PlatformAuthenticationSessions;
use App\Core\Services\Auth\PlatformTwoFactorCredentials;
use App\Core\Services\Auth\PlatformTwoFactorSettings;
use App\Core\Services\Auth\UpdatePlatformPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class PlatformAccountSecurityController
{
    public function index(
        Request $request,
        PlatformTwoFactorCredentials $credentials,
        PlatformTwoFactorSettings $twoFactor,
    ): Response {
        $user = $this->user($request);
        $pendingSecret = $twoFactor->pendingSecret($user);
        $currentSessionId = $request->session()->get('platform.auth.session_id');
        $sessions = $user->platformAuthSessions()
            ->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'remembered', 'ip_address', 'user_agent', 'last_seen_at', 'created_at']);
        $passkeys = $user->passkeys()
            ->latest('created_at')
            ->get(['id', 'name', 'credential', 'last_used_at', 'created_at'])
            ->map(fn (Passkey $passkey): array => [
                'id' => (string) $passkey->getKey(),
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'last_used_at' => $passkey->last_used_at,
                'created_at' => $passkey->created_at,
            ]);

        return response()->view('core::account.security', [
            'user' => $user,
            'hasPassword' => $user->hasPassword(),
            'pendingSecret' => $pendingSecret,
            'provisioningUri' => $pendingSecret === null ? null : $credentials->provisioningUri($user, $pendingSecret),
            'activeSessions' => $sessions,
            'passkeys' => $passkeys,
            'currentSessionId' => is_string($currentSessionId) ? $currentSessionId : null,
            'recoveryCodes' => session('platform.auth.recovery_codes'),
        ])->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function beginTwoFactor(
        BeginPlatformTwoFactorRequest $request,
        PlatformTwoFactorSettings $twoFactor,
    ): RedirectResponse {
        $twoFactor->begin($this->user($request));

        return $this->privateRedirect(back()->with('security_status', __('Scan or enter the new authenticator secret, then confirm a current code.')));
    }

    public function confirmTwoFactor(
        ConfirmPlatformTwoFactorRequest $request,
        PlatformTwoFactorSettings $twoFactor,
    ): RedirectResponse {
        $codes = $twoFactor->confirm($this->user($request), $request->code());

        return $this->privateRedirect(back()
            ->with('security_status', __('Two-factor authentication is enabled. Save these recovery codes; they are shown once.'))
            ->with('platform.auth.recovery_codes', $codes));
    }

    public function cancelTwoFactorSetup(
        Request $request,
        PlatformTwoFactorSettings $twoFactor,
    ): RedirectResponse {
        $canceled = $twoFactor->cancelPendingSetup($this->user($request));

        return $this->privateRedirect(back()->with(
            'security_status',
            $canceled
                ? __('The authenticator setup was canceled.')
                : __('There was no pending authenticator setup to cancel.'),
        ));
    }

    public function disableTwoFactor(
        DisablePlatformTwoFactorRequest $request,
        PlatformTwoFactorSettings $twoFactor,
    ): RedirectResponse {
        $twoFactor->disable($this->user($request), $request->input('code'));

        return $this->privateRedirect(back()->with('security_status', __('Two-factor authentication was disabled.')));
    }

    public function regenerateRecoveryCodes(
        RegeneratePlatformRecoveryCodesRequest $request,
        PlatformTwoFactorSettings $twoFactor,
    ): RedirectResponse {
        $codes = $twoFactor->regenerateRecoveryCodes($this->user($request), $request->code());

        return $this->privateRedirect(back()
            ->with('security_status', __('New recovery codes were created. Previous codes no longer work.'))
            ->with('platform.auth.recovery_codes', $codes));
    }

    public function updatePassword(
        UpdatePlatformPasswordRequest $request,
        UpdatePlatformPassword $updatePassword,
    ): RedirectResponse {
        $user = $this->user($request);
        $currentSessionId = $request->session()->get('platform.auth.session_id');
        abort_unless(is_string($currentSessionId), 401);
        $updatePassword->handle(
            $user,
            $request->validated('password'),
            $currentSessionId,
            $request->validated('current_password'),
            $request->validated('code'),
        );
        $request->session()->regenerate(true);

        return $this->privateRedirect(back()->with('password_status', __('Your platform password was updated. Other signed-in browsers were logged out.')));
    }

    public function revokeSession(
        Request $request,
        PlatformAuthSession $session,
        PlatformAuthenticationSessions $sessions,
    ): RedirectResponse {
        $user = $this->user($request);
        abort_unless((string) $session->user_id === (string) $user->getKey(), 404);

        $currentSessionId = $request->session()->get('platform.auth.session_id');
        $result = $sessions->revokeOne(
            $user,
            (string) $session->getKey(),
            is_string($currentSessionId) ? $currentSessionId : null,
        );

        return $this->privateRedirect(match ($result) {
            'current' => back()->withErrors(['session' => __('You cannot log out the browser you are using now.')]),
            'revoked' => back()->with('security_status', __('That browser session was logged out.')),
            default => back()->with('security_status', __('That browser session is no longer active.')),
        });
    }

    public function revokeOtherSessions(
        Request $request,
        PlatformAuthenticationSessions $sessions,
    ): RedirectResponse {
        $currentSessionId = $request->session()->get('platform.auth.session_id');
        $revoked = $sessions->revokeOthers(
            $this->user($request),
            is_string($currentSessionId) ? $currentSessionId : null,
        );

        return $this->privateRedirect($revoked
            ? back()->with('security_status', __('Other signed-in browsers were logged out.'))
            : back()->withErrors(['session' => __('The current authentication session could not be verified. Sign in again before changing browser sessions.')]),
        );
    }

    private function user(Request $request): PlatformUser
    {
        $user = $request->user('platform') ?? Auth::guard('platform')->user();
        abort_unless($user instanceof PlatformUser && $user->status === 'active', 401);

        return $user;
    }

    private function privateRedirect(RedirectResponse $response): RedirectResponse
    {
        return $response->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
