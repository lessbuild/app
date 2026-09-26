<?php

declare(strict_types=1);

namespace App\Domain\Projects\Events;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ProjectDeleted
{
    use Dispatchable;

    public function __construct(
        public string $projectId,
        public string $accountId,
        public string $name,
        public User $actor,
    ) {}
}
