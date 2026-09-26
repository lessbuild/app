<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounts;

use App\Domain\Accounts\Actions\AcceptInvitation;
use App\Domain\Accounts\Models\AccountInvitation;
use App\Domain\Identity\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class InvitationController
{
    public function show(Request $request, string $token): View
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

    public function store(#[CurrentUser] User $user, string $token, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $membership = $acceptInvitation->handle($user, $token);

        return redirect()->route('dashboard')->with('status', __('You joined :account.', ['account' => $membership->account->name]));
    }
}
