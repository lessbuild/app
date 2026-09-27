<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use App\Domain\Accounts\Models\Account;
use App\Domain\Billing\Catalog\Tier;
use App\Domain\Billing\Contracts\PaymentProvider;
use App\Domain\Billing\Data\BillingOverview;
use App\Domain\Billing\Data\MeterUsage;
use App\Domain\Billing\Data\ServiceBillingCard;
use App\Domain\Billing\Data\TierOption;
use App\Domain\Billing\Enums\SelectionKind;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\BillingSelection;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Billing\Support\Entitlements;
use App\Domain\Billing\Support\PriceBook;
use App\Domain\Projects\Queries\ServicesInUseQuery;
use App\Platform\ServiceRegistry;
use Carbon\CarbonImmutable;

final class BillingOverviewQuery
{
    public function __construct(
        private readonly ServiceRegistry $services,
        private readonly Entitlements $entitlements,
        private readonly PriceBook $prices,
        private readonly ServicesInUseQuery $inUse,
        private readonly PaymentProvider $provider,
    ) {}

    public function handle(Account $account): BillingOverview
    {
        $entitlements = $this->entitlements->for($account);
        $selections = BillingSelection::query()->where('account_id', $account->id)->get();
        $inUse = $this->inUse->handle($account);
        $billing = BillingAccount::query()->find($account->id);
        $monthStart = now()->utc()->startOfMonth();
        $usage = UsageRecord::query()->where('account_id', $account->id)->where('period_start', '>=', $monthStart)
            ->selectRaw('meter, sum(quantity) as total')->groupBy('meter')->pluck('total', 'meter');

        $total = 0;
        $cards = [];
        foreach ($this->services->all() as $service) {
            $catalog = $service->billing();
            $selection = $selections->first(fn (BillingSelection $selection): bool => $selection->service === $service->key() && $selection->kind === SelectionKind::Tier);
            $tier = ($selection !== null ? $catalog->tier($selection->item_key) : null) ?? $catalog->defaultTier();
            $total += $tier->monthlyCents ?? 0;

            $cards[] = new ServiceBillingCard(
                key: $service->key(),
                name: $service->name(),
                icon: $service->icon(),
                tier: $tier,
                endsAt: $selection?->ends_at !== null ? CarbonImmutable::instance($selection->ends_at) : null,
                inUse: in_array($service->key(), $inUse, true),
                options: array_map(fn (Tier $option): TierOption => new TierOption($option, $option->key === $tier->key, $this->prices->purchasable($service->key(), $option)), $catalog->tiers),
                meters: array_map(fn ($meter): MeterUsage => new MeterUsage($meter->name, $meter->unit, (int) ($usage[$meter->key] ?? 0), $entitlements->limit($meter->allowanceKey) ?? $tier->limits[$meter->allowanceKey] ?? null), $catalog->meters),
            );
        }

        foreach ($selections->where('kind', SelectionKind::AddOn) as $selection) {
            $total += ($this->services->find($selection->service)?->billing()->addOn($selection->item_key)->monthlyCentsPerUnit ?? 0) * $selection->quantity;
        }

        return new BillingOverview(
            services: $cards,
            monthlyTotalCents: $total,
            currency: $this->prices->currency(),
            status: $billing->status ?? 'none',
            periodEnd: $billing?->current_period_end !== null ? CarbonImmutable::instance($billing->current_period_end) : null,
            hasCustomer: $billing?->stripe_customer_id !== null,
            paymentsAvailable: $this->provider->available(),
        );
    }
}
