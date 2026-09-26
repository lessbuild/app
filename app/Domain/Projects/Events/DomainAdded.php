<?php

declare(strict_types=1);

namespace App\Domain\Projects\Events;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Domain;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class DomainAdded
{
    use Dispatchable;

    public function __construct(
        public Domain $domain,
        public User $actor,
    ) {}
}
