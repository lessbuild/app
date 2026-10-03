<?php

declare(strict_types=1);

namespace App\Platform\Catalog;

/** Security's plans (priced 2026-09-30): tiers differ in how often projects are scanned and which protections they get. */
final class SecurityCatalog
{
    /**
     * The protections each tier adds, as entitlement flags.
     *
     * @var list<string>
     */
    public const FLAGS = ['security.secrets', 'security.servers', 'security.deploy_gate', 'security.autoblock', 'security.waf', 'security.access_reviews', 'security.compliance'];

    /**
     * Get Security's Free, Pro and Team tiers: Free scans dependencies and domains weekly; Pro scans daily and adds
     * secret scanning, server hardening, the deploy gate and automatic blocking; Team scans every six hours and adds
     * firewall controls, access reviews and compliance reports.
     *
     * @return ServiceBilling
     */
    public static function billing(): ServiceBilling
    {
        return new ServiceBilling(tiers: [
            new Tier('free', 'Free', 0, __('Know about vulnerable packages and domain problems.'),
                [__('Dependency and domain checks'), __('Weekly scans')], ['security.scan.hours' => 168], []),
            new Tier('pro', 'Pro', 1900, __('Keep production patched and protected.'),
                [__('Daily scans'), __('Secret scanning'), __('Server hardening and updates'), __('Block risky deploys'), __('Automatic attack blocking')],
                ['security.scan.hours' => 24], ['security.secrets', 'security.servers', 'security.deploy_gate', 'security.autoblock']),
            new Tier('team', 'Team', 4900, __('For teams with customers and auditors to answer to.'),
                [__('Scans every 6 hours'), __('Everything in Pro'), __('Firewall and bot controls'), __('Access reviews'), __('Compliance reports')],
                ['security.scan.hours' => 6], self::FLAGS),
        ]);
    }
}
