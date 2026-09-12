<?php

namespace App\Actions\Automation;

use Laravel\Sanctum\PersonalAccessToken;

class RevokePersonalAccessTokenAction
{
    /**
     * Revoke an already-authorized personal access token.
     */
    public function handle(PersonalAccessToken $token): void
    {
        $token->delete();
    }
}
