<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Data\Billing\Decision;
use App\Platform\Catalog\Tier;

/** What one account may do right now, from its tiers and add-ons. */
final readonly class AccountEntitlements
{
    /**
     * Create a new AccountEntitlements instance.
     *
     * One account's limits and features, worked out by Entitlements.
     *
     * @param  array<string, Tier>  $tiers  service => the tier that applies
     * @param  array<string, int|null>  $limits  entitlement key => limit (null = unlimited); missing keys are unlimited
     * @param  list<string>  $flags
     */
    public function __construct(
        public array $tiers,
        public array $limits,
        public array $flags,
    ) {}

    /**
     * Get the limit for a key, or null when the account has no limit there.
     *
     * @param  string  $key
     * @return int|null
     */
    public function limit(string $key): ?int
    {
        return $this->limits[$key] ?? null;
    }

    /**
     * Determine whether one of the account's tiers turns the feature on.
     *
     * @param  string  $flag
     * @return bool
     */
    public function has(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    /**
     * Decide whether the account may have `$wanted` of something limited by `$key` (for example, members after adding
     * one).
     *
     * @param  string  $key
     * @param  int  $wanted
     * @return Decision
     */
    public function allows(string $key, int $wanted): Decision
    {
        $limit = $this->limit($key);
        if ($limit === null || $wanted <= $limit) {
            return new Decision(true, $limit);
        }

        return new Decision(false, $limit, __('Your plan allows :limit. Upgrade on the billing page to add more.', ['limit' => number_format($limit)]));
    }
}
