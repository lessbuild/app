<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ProjectDeleted
{
    use Dispatchable;

    /**
     * Create a new ProjectDeleted instance.
     *
     * A project was deleted. It's gone when this fires, so the event carries plain values.
     *
     * @param  string  $projectId  The deleted project's ID.
     * @param  string  $accountId  The account it belonged to, where the audit entry goes.
     * @param  string  $name  Its name, for the record.
     * @param  User  $actor  Who deleted it.
     */
    public function __construct(
        public string $projectId,
        public string $accountId,
        public string $name,
        public User $actor,
    ) {}
}
