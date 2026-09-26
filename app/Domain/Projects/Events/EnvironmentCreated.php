<?php

declare(strict_types=1);

namespace App\Domain\Projects\Events;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Environment;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class EnvironmentCreated
{
    use Dispatchable;

    public function __construct(
        public Environment $environment,
        public User $actor,
    ) {}
}
