<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class DomainRemoved
{
    use Dispatchable;

    /**
     * A domain was removed from a project. Recorded in the project's activity.
     *
     * @param  Domain  $domain  The removed domain.
     * @param  User  $actor  Who removed it.
     */
    public function __construct(
        public Domain $domain,
        public User $actor,
    ) {}
}
