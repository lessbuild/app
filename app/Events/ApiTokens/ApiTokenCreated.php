<?php

declare(strict_types=1);

namespace App\Events\ApiTokens;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ApiTokenCreated
{
    use Dispatchable;

    /**
     * An API token was created. Recorded in the audit log (never with its secret).
     *
     * @param  ApiToken  $token  The new token.
     * @param  User  $actor  Who created it.
     */
    public function __construct(
        public ApiToken $token,
        public User $actor,
    ) {}
}
