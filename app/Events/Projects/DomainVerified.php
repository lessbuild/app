<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class DomainVerified
{
    use Dispatchable;

    /**
     * Create a new DomainVerified instance.
     *
     * A domain's TXT record was found, proving the project controls it. Recorded in the project's activity.
     *
     * @param  Domain  $domain  The verified domain.
     * @param  User  $actor  Who asked for the check.
     */
    public function __construct(
        public Domain $domain,
        public User $actor,
    ) {}
}
