<?php

namespace App\Core\Http\Controllers\Auth;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\PlatformAuthenticationSessions;
use App\Core\Services\Auth\PlatformSsoHandoff;
use App\Core\Services\Auth\RegisterPlatformAccount;
use App\Core\Services\Workspaces\ResolveWorkspaceInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class PlatformRegistrationController
{
    public function create(
        Request $request,
        RegisterPlatformAccount $registration,
        ResolveWorkspaceInvitation $invitations,
        PlatformSsoHandoff $handoff,
    ): View|Response {
        $user = Auth::guard('platform')->user();
        if ($user instanceof PlatformUser) {
            return $handoff->respond($request, $user, route('core.home'));
        }

        $token = $request->query('invitation');
        abort_unless($token === null || is_string($token), 404);
        $invitation = is_string($token) ? $invitations->findValid($token) : null;
        abort_unless($token === null || $invitation !== null, 404);
        abort_unless($registration->available($token), 404);

        return view('core::auth.register', ['invitation' => $invitation, 'invitationToken' => $token]);
    }

    public function store(
        Request $request,
        RegisterPlatformAccount $registration,
        PlatformAuthenticationSessions $sessions,
        PlatformSsoHandoff $handoff,
    ): Response {
        $authenticatedUser = Auth::guard('platform')->user();
        if ($authenticatedUser instanceof PlatformUser) {
            return $handoff->respond($request, $authenticatedUser, route('core.home'));
        }

        $invitationToken = $request->input('invitation');
        if ($invitationToken === '') {
            $invitationToken = null;
        }
        abort_unless($invitationToken === null || is_string($invitationToken), 404);
        abort_unless($registration->available($invitationToken), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'],
            'workspace_name' => $invitationToken === null
                ? ['required', 'string', 'max:120']
                : ['nullable', 'string', 'max:120'],
            'invitation' => ['nullable', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);

        $user = $registration->handle(
            $data['name'],
            $data['email'],
            $data['password'],
            $data['workspace_name'] ?? null,
            $invitationToken,
        );

        Auth::guard('platform')->login($user);
        $request->session()->regenerate();
        $sessions->begin($user, $request, remember: false);

        if ($invitationToken !== null) {
            $workspace = $user->workspaceMemberships()->with('workspace')->firstOrFail()->workspace;

            return $handoff->respond(
                $request,
                $user,
                route('core.workspace.dashboard', $workspace),
            );
        }

        $user->sendEmailVerificationNotification();

        return redirect()->route('platform.verification.notice')->with(
            'status',
            __('Your workspace is ready. Verify your email address to complete account setup.'),
        );
    }
}
