<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Laravel\Sanctum\PersonalAccessToken;

class PersonalAccessTokenPolicy
{
    /**
     * Allow only the current workspace owner to issue API credentials.
     */
    public function create(User $user): bool
    {
        return $user->currentOrganization?->owner?->is($user) ?? false;
    }

    /**
     * Allow rotation only for the authenticated token owner, concealing unrelated tokens as 404.
     */
    public function rotate(User $user, PersonalAccessToken $token): Response
    {
        return $this->belongsTo($user, $token) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Allow revocation only for the authenticated token owner, concealing unrelated tokens as 404.
     */
    public function delete(User $user, PersonalAccessToken $token): Response
    {
        return $this->belongsTo($user, $token) ? Response::allow() : Response::denyAsNotFound();
    }

    private function belongsTo(User $user, PersonalAccessToken $token): bool
    {
        return (string) $token->tokenable_id === (string) $user->getKey()
            && $token->tokenable_type === $user->getMorphClass();
    }
}
