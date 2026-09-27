<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Catalog\Tier;
use App\Domain\Billing\Data\Decision;

/** What one account may do right now, from its tiers and add-ons. */
final readonly class AccountEntitlements
{
    /**
     * @param  array<string, Tier>  $tiers  service => the tier that applies
     * @param  array<string, int|null>  $limits  entitlement key => limit (null = unlimited); missing keys are unlimited
     * @param  list<string>  $flags
     */
    public function __construct(
        public array $tiers,
        public array $limits,
        public array $flags,
    ) {}

    public function limit(string $key): ?int
    {
        return $this->limits[$key] ?? null;
    }

    public function has(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    /** May the account have $wanted of something limited by $key (e.g. members after adding one)? */
    public function allows(string $key, int $wanted): Decision
    {
        $limit = $this->limit($key);
        if ($limit === null || $wanted <= $limit) {
            return new Decision(true, $limit);
        }

        return new Decision(false, $limit, __('Your plan allows :limit. Upgrade on the billing page to add more.', ['limit' => number_format($limit)]));
    }
}
