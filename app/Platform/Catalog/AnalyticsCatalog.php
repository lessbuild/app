<?php

declare(strict_types=1);

namespace App\Platform\Catalog;

/** Analytics' plans (priced 2026-09-28): tiers differ in sites, monthly pageviews and how long events are kept. */
final class AnalyticsCatalog
{
    /**
     * Get Analytics' Free, Pro and Business tiers and the pageviews meter that counts usage against each tier's monthly
     * allowance. Going over isn't charged per pageview and doesn't stop collection.
     *
     * @return ServiceBilling
     */
    public static function billing(): ServiceBilling
    {
        return new ServiceBilling(
            tiers: [
                self::tier('free', 'Free', 0, __('Privacy-friendly analytics for personal sites.'), [__('3 sites'), __('10K pageviews / month'), __('90-day event history')], 3, 10_000, 90),
                self::tier('pro', 'Pro', 9, __('For businesses that want to understand their visitors.'), [__('Unlimited sites'), __('100K pageviews / month'), __('1-year event history')], null, 100_000, 365),
                self::tier('business', 'Business', 29, __('For busy sites and agencies.'), [__('Unlimited sites'), __('1M pageviews / month'), __('2-year event history')], null, 1_000_000, 730),
            ],
            meters: [new Meter('analytics.pageviews', __('Pageviews'), __('pageviews'), 'analytics.pageviews.monthly')],
        );
    }

    /**
     * Build a tier, turning dollars into cents and the limits into entitlement keys.
     *
     * @param  string  $key
     * @param  string  $name
     * @param  int  $dollars
     * @param  string  $description
     * @param  list<string>  $features
     * @param  int|null  $sites  null for unlimited
     * @param  int  $pageviews  monthly allowance
     * @param  int  $retentionDays  how long raw events are kept
     * @return Tier
     */
    private static function tier(string $key, string $name, int $dollars, string $description, array $features, ?int $sites, int $pageviews, int $retentionDays): Tier
    {
        return new Tier($key, $name, $dollars * 100, $description, $features, ['analytics.sites.max' => $sites, 'analytics.pageviews.monthly' => $pageviews, 'analytics.retention.days' => $retentionDays]);
    }
}
