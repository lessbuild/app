<?php

declare(strict_types=1);

namespace App\Domain\Projects\Events;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ProjectUpdated
{
    use Dispatchable;

    public function __construct(
        public Project $project,
        public string $previousName,
        public User $actor,
    ) {}
}
