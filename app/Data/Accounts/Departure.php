<?php

declare(strict_types=1);

namespace App\Data\Accounts;

use App\Models\Account;
use App\Models\Membership;

/** What happens to someone's accounts if they delete their user: which go with them, which they leave, which block it. */
final readonly class Departure
{
    /**
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
