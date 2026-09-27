<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SelectionKind;
use App\Models\Account;
use App\Models\BillingSelection;
use App\Platform\Catalog\Tier;
use App\Platform\ServiceRegistry;
use App\Queries\Projects\ServicesInUseQuery;

/**
 * Entitlements::for($account)->allows('monitoring.checks.max', $count). Tiers apply for services the account has
 * chosen a tier for or uses on a project (their free tier by default). The same limit from several services
 * combines to the most generous one; add-ons add on top.
 */
final class Entitlements
{
    public function __construct(
        private readonly ServiceRegistry $services,
        private readonly ServicesInUseQuery $inUse,
    ) {}

    public function for(Account $account): AccountEntitlements
    {
        $selections = BillingSelection::query()->where('account_id', $account->id)->get();
        $inUse = $this->inUse->handle($account);

        $tiers = [];
        foreach ($this->services->all() as $service) {
            $billing = $service->billing();
            $selected = $selections->first(fn (BillingSelection $selection): bool => $selection->service === $service->key() && $selection->kind === SelectionKind::Tier);
            $tier = $selected !== null ? $billing->tier($selected->item_key) : null;
            if ($tier === null && in_array($service->key(), $inUse, true)) {
                $tier = $billing->defaultTier();
            }
            if ($tier !== null) {
                $tiers[$service->key()] = $tier;
            }
        }

        $limits = [];
        $flags = [];
        foreach ($tiers as $tier) {
            foreach ($tier->limits as $key => $limit) {
                $limits[$key] = array_key_exists($key, $limits) ? $this->moreGenerous($limits[$key], $limit) : $limit;
            }
            $flags = [...$flags, ...$tier->flags];
        }

        foreach ($selections->where('kind', SelectionKind::AddOn) as $selection) {
            $addOn = $this->services->find($selection->service)?->billing()->addOn($selection->item_key);
            foreach ($addOn->grants ?? [] as $key => $perUnit) {
                if (array_key_exists($key, $limits) && $limits[$key] !== null) {
                    $limits[$key] += $perUnit * $selection->quantity;
                }
            }
        }

        return new AccountEntitlements($tiers, $limits, array_values(array_unique($flags)));
    }

    private function moreGenerous(?int $a, ?int $b): ?int
    {
        return $a === null || $b === null ? null : max($a, $b);
    }

    /** The tier that applies to a service, even if the account doesn't use it yet. */
    public function tierFor(Account $account, string $service): ?Tier
    {
        return $this->for($account)->tiers[$service] ?? $this->services->find($service)?->billing()->defaultTier();
    }
}
