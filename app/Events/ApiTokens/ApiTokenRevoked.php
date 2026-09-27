<?php

declare(strict_types=1);

namespace App\Events\ApiTokens;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ApiTokenRevoked
{
    use Dispatchable;

    /**
     * An API token was revoked and stops working immediately. Recorded in the audit log.
     *
     * @param  ApiToken  $token  The revoked token.
     * @param  User  $actor  Who revoked it.
     */
    public function __construct(
        public ApiToken $token,
        public User $actor,
    ) {}
}
