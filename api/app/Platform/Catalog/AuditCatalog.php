<?php

declare(strict_types=1);

namespace App\Platform\Catalog;

/**
 * Audit's plans (priced 2026-10-03): tiers differ in audits a month, competitors compared, pages visited per site,
 * scheduling and white-label reports. Audits beyond the allowance are billed per audit on paid tiers.
 */
final class AuditCatalog
{
    /**
     * Get Audit's Free, Pro and Business tiers and the audits meter.
     *
     * @return ServiceBilling
     */
    public static function billing(): ServiceBilling
    {
        return new ServiceBilling(
            tiers: [
                new Tier('free', 'Free', 0, __('See how your site’s flows compare, once a month.'),
                    [__('1 audit / month'), __('1 competitor'), __('20 pages per site')],
                    ['audit.runs.monthly' => 1, 'audit.competitors' => 1, 'audit.pages' => 20, 'audit.audits.max' => 1], []),
                new Tier('pro', 'Pro', 2900, __('Improve your site with a fresh audit whenever you ship.'),
                    [__('10 audits / month'), __('3 competitors'), __('60 pages per site'), __('Monthly scheduled audits'), __('Mock-ups of the fixes')],
                    ['audit.runs.monthly' => 10, 'audit.competitors' => 3, 'audit.pages' => 60, 'audit.audits.max' => 10], ['audit.schedule.monthly']),
                new Tier('business', 'Business', 9900, __('For agencies and teams that audit many sites.'),
                    [__('40 audits / month'), __('5 competitors'), __('100 pages per site'), __('Weekly scheduled audits'), __('White-label PDF reports')],
                    ['audit.runs.monthly' => 40, 'audit.competitors' => 5, 'audit.pages' => 100, 'audit.audits.max' => null], ['audit.schedule.monthly', 'audit.schedule.weekly', 'audit.white_label']),
            ],
            meters: [new Meter('audit.runs', __('Audits'), __('audits'), 'audit.runs.monthly', config('billing.meters.audit.runs'), unitSize: 1, unitCents: 400)],
        );
    }
}
