<?php

declare(strict_types=1);

namespace App\Events\ApiTokens;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ApiTokenRevoked
{
    use Dispatchable;

    public function __construct(
        public ApiToken $token,
        public User $actor,
    ) {}
}
