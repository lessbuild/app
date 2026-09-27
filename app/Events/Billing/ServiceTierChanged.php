<?php

declare(strict_types=1);

namespace App\Events\Billing;

use App\Models\Account;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;

/** A service's tier changed, or will change at $effectiveAt (a downgrade at the end of the paid period). */
final readonly class ServiceTierChanged
{
    use Dispatchable;

    /**
     * An account moved to another tier of a service, now or at the end of the paid period. Recorded in the audit log.
     *
     * @param  Account  $account  The account whose plan changed.
     * @param  string  $service  The service's key.
     * @param  string  $from  The tier key it was on.
     * @param  string  $to  The tier key it's moving to.
     * @param  ?User  $actor  Who chose the change; null when it came from the payment provider or a scheduled downgrade taking effect.
     * @param  ?CarbonInterface  $effectiveAt  When a scheduled change takes effect; null when it applied immediately.
     */
    public function __construct(
        public Account $account,
        public string $service,
        public string $from,
        public string $to,
        public ?User $actor,
        public ?CarbonInterface $effectiveAt = null,
    ) {}
}
