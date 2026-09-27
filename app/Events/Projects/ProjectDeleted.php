<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\User;
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
