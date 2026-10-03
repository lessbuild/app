<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SelectionKind;
use App\Models\Account;
use App\Models\BillingSelection;
use App\Platform\Catalog\Meter;
use App\Platform\ServiceRegistry;

/**
 * Pay-as-you-go usage beyond a tier's monthly allowance. An account turns it on per meter (a Usage selection, with an
 * optional spend cap); until then the allowance is a hard limit.
 */
final class Overage
{
    /**
     * Create a new Overage instance.
     *
     * @param  ServiceRegistry  $services  Finds each meter's catalogue entry.
     * @param  Entitlements  $entitlements  Reads the account's allowances.
     */
    public function __construct(private readonly ServiceRegistry $services, private readonly Entitlements $entitlements) {}

    /**
     * Find a meter in the catalogue by its key, with the service that sells it.
     *
     * @param  string  $key
     * @return array{0: string, 1: Meter}|null
     */
    public function meter(string $key): ?array
    {
        foreach ($this->services->all() as $service) {
            foreach ($service->billing()->meters as $meter) {
                if ($meter->key === $key) {
                    return [$service->key(), $meter];
                }
            }
        }

        return null;
    }

    /**
     * Get the account's pay-as-you-go selection for a meter, or null when usage stops at the allowance.
     *
     * @param  string  $accountId
     * @param  string  $meterKey
     * @return BillingSelection|null
     */
    public function selection(string $accountId, string $meterKey): ?BillingSelection
    {
        return BillingSelection::query()->where('account_id', $accountId)->where('kind', SelectionKind::Usage)->where('item_key', $meterKey)->first();
    }

    /**
     * Get the account's monthly allowance for a meter; null when it's unlimited.
     *
     * @param  Account  $account
     * @param  Meter  $meter
     * @return int|null
     */
    public function allowance(Account $account, Meter $meter): ?int
    {
        return $this->entitlements->for($account)->limit($meter->allowanceKey);
    }

    /**
     * Determine whether the account may use more of a meter this month: within the allowance, or beyond it with
     * pay-as-you-go on and the spend cap not reached.
     *
     * @param  Account  $account
     * @param  string  $meterKey
     * @param  int  $used  already used this month
     * @param  int  $adding  about to be used
     * @return bool
     */
    public function allows(Account $account, string $meterKey, int $used, int $adding): bool
    {
        $found = $this->meter($meterKey);
        $allowance = $found === null ? null : $this->allowance($account, $found[1]);
        if ($found === null || $allowance === null || $used + $adding <= $allowance) {
            return true;
        }
        $selection = $this->selection($account->id, $meterKey);
        if ($selection === null) {
            return false;
        }
        $within = $found[1]->overageWithin($selection->spend_cap_cents);

        return $within === null || $used + $adding - $allowance <= $within;
    }
}
