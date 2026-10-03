<?php

declare(strict_types=1);

namespace App\Http\Controllers\Invitations;

use App\Actions\Accounts\AcceptInvitation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `POST /api/app/invitations/{token}`. */
final class AcceptInvitationController
{
    /**
     * Join the account the invitation is for, and go to its projects.
     *
     * @param  User  $user
     * @param  string  $token
     * @param  AcceptInvitation  $acceptInvitation
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, string $token, AcceptInvitation $acceptInvitation): JsonResponse
    {
        $membership = $acceptInvitation->handle($user, $token);

        return response()->json(['redirect' => route('dashboard', [], false), 'message' => __('You joined :account.', ['account' => $membership->account->name])]);
    }
}
