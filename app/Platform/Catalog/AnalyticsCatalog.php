<?php

declare(strict_types=1);

namespace App\Platform\Catalog;

/** Analytics has never been billed. Paid tiers wait for the owner's pricing (see docs/phase-3-billing.md). */
final class AnalyticsCatalog
{
    /**
     * Get Analytics' catalogue: a single free tier. Analytics was never billed, and paid tiers wait for pricing.
     *
     * @return ServiceBilling
     */
    public static function billing(): ServiceBilling
    {
        return new ServiceBilling([
            new Tier('free', 'Free', 0, __('Privacy-friendly analytics while paid plans are being priced.'), [__('Unlimited sites'), __('90-day event history'), __('13 months of reports')], ['analytics.retention.days' => 90]),
        ]);
    }
}
