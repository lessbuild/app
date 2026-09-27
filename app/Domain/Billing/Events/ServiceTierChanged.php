<?php

declare(strict_types=1);

namespace App\Domain\Billing\Events;

use App\Domain\Accounts\Models\Account;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;

/** A service's tier changed, or will change at $effectiveAt (a downgrade at the end of the paid period). */
final readonly class ServiceTierChanged
{
    use Dispatchable;

    public function __construct(
        public Account $account,
        public string $service,
        public string $from,
        public string $to,
        public ?User $actor,
        public ?CarbonInterface $effectiveAt = null,
    ) {}
}
