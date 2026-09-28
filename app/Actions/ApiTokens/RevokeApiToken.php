<?php

declare(strict_types=1);

namespace App\Actions\ApiTokens;

use App\Events\ApiTokens\ApiTokenRevoked;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RevokeApiToken
{
    /**
     * People who manage the account's tokens may revoke any of them; anyone may revoke their own.
     *
     * @param  User  $actor
     * @param  ApiToken  $token
     * @return void
     */
    public function handle(User $actor, ApiToken $token): void
    {
        if ($token->tokenable_id !== $actor->id) {
            Gate::forUser($actor)->authorize('manageApiTokens', $token->account);
        }

        $token->delete();
        ApiTokenRevoked::dispatch($token, $actor);
    }
}
