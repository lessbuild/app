<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class DomainAdded
{
    use Dispatchable;

    public function __construct(
        public Domain $domain,
        public User $actor,
    ) {}
}
