<?php

declare(strict_types=1);

namespace App\Http\Controllers\Invitations;

use App\Data\Accounts\InvitationDetails;
use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /api/app/invitations/{token}`. */
final class ShowInvitationController
{
    /**
     * Return an invitation to join an account, or null when it's expired, revoked, used or unknown, with who's signed
     * in so the page can offer to accept, sign in or sign up.
     *
     * @param  Request  $request
     * @param  string  $token
     * @return JsonResponse
     */
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $invitation = AccountInvitation::query()->with(['account', 'invitedBy'])->where('token_hash', AccountInvitation::hashToken($token))->first();
        $user = $request->user();

        return response()->json([
            'invitation' => $invitation?->isPending() ? new InvitationDetails(
                $invitation->account->name, $invitation->role->label(), $invitation->email, $invitation->invitedBy?->name, $invitation->expires_at->toIso8601String(),
            ) : null,
            'signedInAs' => $user instanceof User ? $user->email : null,
        ]);
    }
}
