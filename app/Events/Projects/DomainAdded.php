<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class DomainAdded
{
    use Dispatchable;

    /**
     * Create a new DomainAdded instance.
     *
     * A domain was added to a project, unverified. Recorded in the project's activity.
     *
     * @param  Domain  $domain  The new domain.
     * @param  User  $actor  Who added it.
     */
    public function __construct(
        public Domain $domain,
        public User $actor,
    ) {}
}
