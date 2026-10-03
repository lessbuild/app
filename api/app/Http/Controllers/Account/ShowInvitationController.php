<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Models\AccountInvitation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowInvitationController
{
    /**
     * Show the invitation page. Guests are remembered here so they come back after signing in or registering;
     * invitations that can't be accepted show as unavailable.
     *
     * @param  Request  $request
     * @param  string  $token
     * @return View
     */
    public function __invoke(Request $request, string $token): View
    {
        $invitation = AccountInvitation::query()->where('token_hash', AccountInvitation::hashToken($token))->first();
        if ($request->user() === null) {
            // Bring the invitee back here after they sign in or register.
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return view('invitations.show', [
            'invitation' => $invitation?->isPending() ? $invitation : null,
            'token' => $token,
            'user' => $request->user(),
        ]);
    }
}
