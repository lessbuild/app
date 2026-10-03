<?php

declare(strict_types=1);

namespace App\Data\Accounts;

use App\Models\Account;
use App\Models\Membership;

/** What happens to someone's accounts if they delete their user: which go with them, which they leave, which block it. */
final readonly class Departure
{
    /**
     * Create a new Departure instance.
     *
     * What deleting a person would do to each account they belong to, worked out before anything changes so the
     * confirmation page can show it and blocked deletions can be explained.
     *
     * @param  list<Account>  $toDelete  accounts where they are the only member
     * @param  list<Membership>  $toLeave  shared accounts that keep another owner
     * @param  list<Account>  $blockedBy  shared accounts where they are the only owner
     */
    public function __construct(
        public array $toDelete,
        public array $toLeave,
        public array $blockedBy,
    ) {}
}
