<?php

declare(strict_types=1);

namespace App\Domain\Api\Events;

use App\Domain\Api\Models\ApiToken;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ApiTokenCreated
{
    use Dispatchable;

    public function __construct(
        public ApiToken $token,
        public User $actor,
    ) {}
}
