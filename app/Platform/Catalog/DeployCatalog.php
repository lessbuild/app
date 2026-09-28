<?php

declare(strict_types=1);

namespace App\Platform\Catalog;

/** Deployer's plans, prices and limits, unchanged so nobody's bill moves at cutover. Servers count toward Infrastructure. */
final class DeployCatalog
{
    /**
     * Get Deployer's Free, Starter, Pro, Team and Business tiers with their website, preview, server and member limits
     * and feature flags.
     *
     * @return ServiceBilling
     */
    public static function billing(): ServiceBilling
    {
        return new ServiceBilling([
            self::tier('free', 'Free', 0, __('Start deploying on your own infrastructure.'), [__('1 server'), __('1 website'), __('Git deployments'), __('Scoped API tokens')], 1, 0, 1, 1, ['deploy.releases']),
            self::tier('starter', 'Starter', 9, __('For personal projects and small applications.'), [__('2 servers'), __('5 websites'), __('Health monitoring'), __('Workers and idle hibernation')], 5, 0, 2, 1, ['deploy.releases', 'deploy.workers']),
            self::tier('pro', 'Pro', 19, __('For developers running production workloads.'), [__('5 servers'), __('Unlimited websites'), __('Preview environments'), __('Managed backups')], null, 5, 5, 1, ['deploy.releases', 'deploy.workers', 'deploy.previews', 'deploy.backups', 'deploy.resources', 'deploy.cost_controls', 'deploy.scheduled']),
            self::tier('team', 'Team', 49, __('Collaboration and operations for growing teams.'), [__('20 servers'), __('Unlimited members'), __('Alert integrations'), __('Full audit history')], null, 10, 20, null, ['deploy.releases', 'deploy.workers', 'deploy.previews', 'deploy.backups', 'deploy.resources', 'deploy.cost_controls', 'deploy.scheduled', 'deploy.alerts']),
            self::tier('business', 'Business', 99, __('Automation and scale controls for serious operations.'), [__('50 servers'), __('Scaling automation'), __('High availability')], null, 20, 50, null, ['deploy.releases', 'deploy.workers', 'deploy.previews', 'deploy.backups', 'deploy.resources', 'deploy.cost_controls', 'deploy.scheduled', 'deploy.alerts', 'deploy.scaling', 'deploy.high_availability']),
            self::tier('unlimited', 'Unlimited', 199, __('No fixed resource limits for high-scale operations.'), [__('Unlimited servers and websites'), __('Priority support')], null, null, null, null, ['deploy.releases', 'deploy.workers', 'deploy.previews', 'deploy.backups', 'deploy.resources', 'deploy.cost_controls', 'deploy.scheduled', 'deploy.alerts', 'deploy.scaling', 'deploy.high_availability']),
        ]);
    }

    /**
     * Build a tier from Deployer's plan table, turning dollars into cents and the positional limits into entitlement
     * keys, so the table above stays readable.
     *
     * @param  string  $key
     * @param  string  $name
     * @param  int  $dollars
     * @param  string  $description
     * @param  list<string>  $features
     * @param  int|null  $websites
     * @param  int|null  $previews
     * @param  int|null  $servers
     * @param  int|null  $members
     * @param  list<string>  $flags
     * @return Tier
     */
    private static function tier(string $key, string $name, int $dollars, string $description, array $features, ?int $websites, ?int $previews, ?int $servers, ?int $members, array $flags): Tier
    {
        return new Tier($key, $name, $dollars * 100, $description, $features, ['deploy.websites.max' => $websites, 'deploy.previews.max' => $previews, 'infrastructure.servers.max' => $servers, 'account.members.max' => $members], $flags);
    }
}
