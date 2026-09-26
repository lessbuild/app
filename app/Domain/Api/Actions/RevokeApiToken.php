<?php

declare(strict_types=1);

namespace App\Domain\Api\Actions;

use App\Domain\Api\Events\ApiTokenRevoked;
use App\Domain\Api\Models\ApiToken;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;

final class RevokeApiToken
{
    /** People who manage the account's tokens may revoke any of them; anyone may revoke their own. */
    public function handle(User $actor, ApiToken $token): void
    {
        if ($token->tokenable_id !== $actor->id) {
            Gate::forUser($actor)->authorize('manageApiTokens', $token->account);
        }

        $token->delete();
        ApiTokenRevoked::dispatch($token, $actor);
    }
}
