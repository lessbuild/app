<?php

namespace App\Actions\Automation;

use App\Models\User;
use App\Services\Entitlements;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class RotatePersonalAccessTokenAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Issue a one-year replacement before revoking the existing owned token.
     *
     * The create-then-delete sequence intentionally remains outside a transaction to preserve
     * the existing credential replacement behavior and one-time plaintext response.
     *
     * @param  User  $actor  Account that owns the token.
     * @param  PersonalAccessToken  $token  Already-authorized token being replaced.
     * @return NewAccessToken The replacement plaintext token result.
     */
    public function handle(User $actor, PersonalAccessToken $token): NewAccessToken
    {
        $this->entitlements->enforce($actor, 'api');
        $replacement = $actor->createToken($token->name, $token->abilities, now()->addYear());
        $token->delete();

        return $replacement;
    }
}
