<?php

namespace App\Core\Http\Controllers\Auth;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\PlatformSsoHandoff;
use App\Core\Services\Workspaces\AcceptWorkspaceInvitation;
use App\Core\Services\Workspaces\ResolveWorkspaceInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class PlatformWorkspaceInvitationController
{
    public function show(Request $request, string $token, ResolveWorkspaceInvitation $invitations): Response
    {
        $invitation = $invitations->findValid($token);
        abort_unless($invitation !== null && $invitation->workspace?->status === 'active', 404);

        $user = Auth::guard('platform')->user();
        $email = $user instanceof PlatformUser
            ? strtolower(trim((string) ($user->email_normalized ?: $user->email)))
            : null;

        return response()->view('core::auth.workspace-invitation', [
            'invitation' => $invitation,
            'token' => $token,
            'user' => $user,
            'emailMatches' => $email !== null && hash_equals((string) $invitation->email_normalized, $email),
            'loginUrl' => route('platform.login', [
                'return_to' => route('platform.workspace-invitations.show', ['token' => $token]),
            ]),
        ])->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }

    public function accept(
        Request $request,
        string $token,
        AcceptWorkspaceInvitation $acceptInvitation,
        PlatformSsoHandoff $handoff,
    ): Response {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        $workspace = $acceptInvitation->handle($user, $token);
        $response = $handoff->respond(
            $request,
            $user,
            route('core.workspace.dashboard', $workspace),
        );

        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
